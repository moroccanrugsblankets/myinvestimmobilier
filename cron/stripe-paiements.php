#!/usr/bin/env php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail-templates.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    exit("Stripe SDK manquant\n");
}
require_once $autoload;

function logMsg(string $msg, bool $isError = false): void {
    $level = $isError ? '[ERROR]' : '[INFO]';
    echo "<pre>[".date('Y-m-d H:i:s')."] $level $msg</pre>";
}
function logSection(string $title): void { logMsg("========== $title =========="); }
function logStep(string $msg): void { logMsg("---- $msg ----"); }

$stripeActif = getParameter('stripe_actif', false);
if (!$stripeActif) { logMsg('Module Stripe inactif.'); exit; }

$stripeMode = getParameter('stripe_mode', 'test');
$stripeSecretKey = ($stripeMode === 'live')
    ? getParameter('stripe_secret_key_live', '')
    : getParameter('stripe_secret_key_test', '');
if (empty($stripeSecretKey)) { logMsg('Clé Stripe manquante', true); exit; }
\Stripe\Stripe::setApiKey($stripeSecretKey);

$aujourdHui = (int)date('j');
$moisActuel = (int)date('n');
$anneeActuelle = (int)date('Y');

/**
 * Retourne le jour calendaire correspondant au N-ième jour ouvrable du mois.
 * Les jours ouvrables sont du lundi au vendredi (ISO 1-5).
 */
function getNthWorkingDayOfMonth(int $n, int $year, int $month): int {
    $count = 0;
    $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dow = (int)date('N', mktime(0, 0, 0, $month, $day, $year));
        if ($dow <= 5) { // lundi(1) à vendredi(5)
            $count++;
            if ($count === $n) {
                return $day;
            }
        }
    }
    // fallback : dernier jour ouvrable du mois
    for ($day = $daysInMonth; $day >= 1; $day--) {
        $dow = (int)date('N', mktime(0, 0, 0, $month, $day, $year));
        if ($dow <= 5) {
            return $day;
        }
    }
    return $daysInMonth;
}

logSection("Démarrage du cron - mode=$stripeMode, jour=$aujourdHui");

// Use contrat_logement for frozen adresse/reference/loyer/charges with fallback to logements
$sqlContrats = "
    SELECT c.id as contrat_id, c.date_prise_effet,
           l.id as logement_id,
           COALESCE(cl.adresse, l.adresse) as adresse,
           COALESCE(cl.reference, l.reference) as reference,
           COALESCE(cl.loyer, l.loyer) as loyer,
           COALESCE(cl.charges, l.charges) as charges
    FROM contrats c
    INNER JOIN logements l ON c.logement_id = l.id
    LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
    INNER JOIN (
        SELECT logement_id, MAX(id) AS max_id
        FROM contrats
        WHERE statut = 'valide'
          AND date_prise_effet <= CURDATE()
        GROUP BY logement_id
    ) actifs ON c.id = actifs.max_id
";
$contrats = $pdo->query($sqlContrats)->fetchAll(PDO::FETCH_ASSOC);

$nomsMois = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];
$siteUrl = rtrim($config['SITE_URL'], '/');

foreach ($contrats as $contrat) {
    $contratId  = $contrat['contrat_id'];
    logSection("Contrat $contratId");

    $sqlLocataires = "SELECT * FROM locataires WHERE contrat_id = ?";
    $locatairesStmt = $pdo->prepare($sqlLocataires);
    $locatairesStmt->execute([$contratId]);
    $locataires = $locatairesStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($locataires)) { logStep("Pas de locataires"); continue; }

    $sqlImpayes = "
        SELECT id, mois, annee, montant_attendu, statut_paiement
        FROM loyers_tracking
        WHERE contrat_id = ? 
          AND statut_paiement != 'paye'
          AND deleted_at IS NULL
        ORDER BY annee, mois
    ";
    $impayesStmt = $pdo->prepare($sqlImpayes);
    $impayesStmt->execute([$contratId]);
    $monthsToProcess = $impayesStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($monthsToProcess as $monthEntry) {
        $mois   = (int)$monthEntry['mois'];
        $annee  = (int)$monthEntry['annee'];
        $periode = $nomsMois[$mois] . ' ' . $annee;

        logStep("Traitement période $periode");

        // Sélectionner le template selon le statut du mois
        $statut = $monthEntry['statut_paiement'];
        if ($statut === 'attente') {
            $templateId = 'stripe_invitation_paiement';
            logStep("Template choisi = INVITATION (statut=attente)");
        } elseif ($statut === 'impaye') {
            $templateId = 'stripe_rappel_paiement';
            logStep("Template choisi = RAPPEL (statut=impaye)");
        } else {
            logStep("Ignoré : statut '$statut' non traité");
            continue;
        }

        foreach ($locataires as $locataire) {
            logStep("Préparation envoi pour {$locataire['email']} (contrat=$contratId, période=$periode)");

            // Récupérer ou créer une session de paiement avec un vrai token
            $liensHeures = (int)getParameter('stripe_lien_expiration_heures', 168);
            $montant = (float)($monthEntry['montant_attendu'] ?? ((float)$contrat['loyer'] + (float)$contrat['charges']));
            $token = null;
            $expirationTimestamp = time() + $liensHeures * 3600;

            // Chercher une session existante non expirée et non payée
            $existingStmt = $pdo->prepare("
                SELECT token_acces, token_expiration FROM stripe_payment_sessions
                WHERE contrat_id = ? AND mois = ? AND annee = ?
                  AND statut NOT IN ('paye','annule')
                  AND token_expiration > NOW()
                ORDER BY created_at DESC LIMIT 1
            ");
            $existingStmt->execute([$contratId, $mois, $annee]);
            $existingSession = $existingStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingSession) {
                $token = $existingSession['token_acces'];
                $expirationTimestamp = strtotime($existingSession['token_expiration']);
            } else {
                // Créer une nouvelle session de paiement
                $token = bin2hex(random_bytes(32));
                $expiration = date('Y-m-d H:i:s', $expirationTimestamp);
                $insertStmt = $pdo->prepare("
                    INSERT INTO stripe_payment_sessions
                        (loyer_tracking_id, contrat_id, logement_id, mois, annee, montant,
                         token_acces, token_expiration, statut)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'en_attente')
                ");
                $insertStmt->execute([
                    $monthEntry['id'],
                    $contratId,
                    $contrat['logement_id'],
                    $mois,
                    $annee,
                    $montant,
                    $token,
                    $expiration,
                ]);
            }

            $lienPaiement = $siteUrl . '/payment/pay.php?token=' . urlencode($token);
            $dateExpiration = date('d/m/Y à H:i', $expirationTimestamp);
            // Mode test : décommenter la ligne ci-dessous pour forcer l'envoi vers une adresse de test
            // $locataire['email'] = "salaheddinet@gmail.com";

            $sent = sendTemplatedEmail(
                $templateId,
                $locataire['email'],
                [
                    'locataire_nom'     => $locataire['nom'],
                    'locataire_prenom'  => $locataire['prenom'],
                    'adresse'           => $contrat['adresse'],
                    'reference'         => $contrat['reference'],
                    'periode'           => $periode,
                    'montant_loyer'     => number_format((float)$contrat['loyer'], 2, ',', ' '),
                    'montant_charges'   => number_format((float)$contrat['charges'], 2, ',', ' '),
                    'montant_total'     => number_format((float)$contrat['loyer'] + (float)$contrat['charges'], 2, ',', ' '),
                    'lien_paiement'     => $lienPaiement,
                    'date_expiration'   => $dateExpiration,
                    'signature'         => getParameter('email_signature', ''),
                ],
                null,       // attachmentPath
                false,      // isAdminEmail
                true,       // addAdminBcc
                ['debug' => "contrat=$contratId;periode=$periode;locataire={$locataire['email']}"]
            );

            if ($sent) {
                logStep("✅ Email $templateId envoyé à {$locataire['email']} pour $periode (admins en BCC)");
            } else {
                logStep("❌ Échec envoi email $templateId à {$locataire['email']} pour $periode");
            }
        }
    }
}

logSection('Cron stripe-paiements terminé');
