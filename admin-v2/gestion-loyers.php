<?php
/**
 * INTERFACE DE GESTION DES LOYERS
 * 
 * Affiche un tableau coloré de l'état des paiements de loyers
 * pour tous les biens en location, mois par mois.
 * 
 * Fonctionnalités:
 * - Vue synthétique avec code couleur (vert=payé, rouge=impayé, orange=attente)
 * - Affichage côte à côte des biens (vue globale)
 * - Filtrage par contrat spécifique (vue détaillée)
 * - Modification manuelle du statut de paiement
 * - Envoi de rappels manuels aux locataires
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/mail-templates.php';
require_once '../includes/rappel-loyers-functions.php';
require_once '../pdf/generate-quittance.php';

// Filtre SQL pour les contrats actifs (utilisé dans plusieurs requêtes)
// Un contrat est considéré actif si :
// - Son statut est 'valide' (contrat validé uniquement, selon cahier des charges section 8)
// - Sa date de prise d'effet est dans le passé ou aujourd'hui (contrat déjà en cours)
define('CONTRAT_ACTIF_FILTER', "c.statut = 'valide' AND c.date_prise_effet IS NOT NULL AND c.date_prise_effet <= CURDATE()");

// Déterminer la période à afficher
$anneeActuelle = (int)date('Y');
$moisActuel = (int)date('n');

// Vérifier si un filtre par contrat est appliqué
$contratIdFilter = isset($_GET['contrat_id']) ? (int)$_GET['contrat_id'] : null;
$vueDetaillee = ($contratIdFilter !== null);

// Si un contrat_id est spécifié, récupérer uniquement ce contrat
// Sinon, récupérer tous les logements en location
if ($vueDetaillee) {
    // Use contrat_logement for frozen data (loyer, charges, reference, adresse) with fallback to logements
    $stmtLogements = $pdo->prepare("
        SELECT DISTINCT l.id,
               COALESCE(cl.reference, l.reference) as reference,
               COALESCE(cl.adresse, l.adresse) as adresse,
               COALESCE(cl.loyer, l.loyer) as loyer,
               COALESCE(cl.charges, l.charges) as charges,
               c.id as contrat_id, c.date_prise_effet, c.reference_unique as contrat_reference,
               (SELECT GROUP_CONCAT(CONCAT(prenom, ' ', nom) SEPARATOR ', ')
                FROM locataires 
                WHERE contrat_id = c.id) as locataires
        FROM logements l
        INNER JOIN contrats c ON c.logement_id = l.id
        LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
        WHERE c.id = ?
        AND " . CONTRAT_ACTIF_FILTER . "
        ORDER BY COALESCE(cl.reference, l.reference)
    ");
    $stmtLogements->execute([$contratIdFilter]);
    $logements = $stmtLogements->fetchAll(PDO::FETCH_ASSOC);
    
    // Si aucun contrat trouvé, rediriger vers la vue globale
    if (empty($logements)) {
        header('Location: gestion-loyers.php');
        exit;
    }
} else {
    // Récupérer tous les logements avec leur dernier contrat actif
    // Selon cahier des charges section 1: afficher le dernier contrat validé pour chaque logement
    // Note: On ne filtre PAS par statut du logement car un logement peut être marqué "disponible" 
    // alors qu'il a encore un contrat actif (par exemple si le locataire va partir bientôt)
    // Use contrat_logement for frozen data (loyer, charges, reference, adresse) with fallback to logements
    $stmtLogements = $pdo->query("
        SELECT l.id,
               COALESCE(cl.reference, l.reference) as reference,
               COALESCE(cl.adresse, l.adresse) as adresse,
               COALESCE(cl.loyer, l.loyer) as loyer,
               COALESCE(cl.charges, l.charges) as charges,
               c.id AS contrat_id,
               c.date_prise_effet,
               c.reference_unique AS contrat_reference,
               (SELECT GROUP_CONCAT(CONCAT(prenom, ' ', nom) SEPARATOR ', ')
                FROM locataires 
                WHERE contrat_id = c.id) AS locataires
        FROM logements l
        INNER JOIN contrats c ON c.logement_id = l.id
        LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
        INNER JOIN (
            -- Sous-requête pour obtenir le dernier contrat valide par id
            SELECT logement_id, MAX(id) AS max_contrat_id
            FROM contrats c WHERE " . CONTRAT_ACTIF_FILTER . "
            GROUP BY logement_id
        ) derniers_contrats ON c.id = derniers_contrats.max_contrat_id
        ORDER BY COALESCE(cl.reference, l.reference)
    ");
    $logements = $stmtLogements->fetchAll(PDO::FETCH_ASSOC);
}

// Récupérer la liste de tous les contrats actifs pour le sélecteur
// Afficher uniquement le dernier contrat valide pour chaque logement
// Use contrat_logement for frozen reference/adresse with fallback to logements
$stmtTousContrats = $pdo->query("
    SELECT c.id, c.reference_unique,
           COALESCE(cl.reference, l.reference) as logement_ref,
           COALESCE(cl.adresse, l.adresse) as adresse,
           (SELECT GROUP_CONCAT(CONCAT(prenom, ' ', nom) SEPARATOR ', ')
            FROM locataires 
            WHERE contrat_id = c.id) as locataires
    FROM contrats c
    INNER JOIN logements l ON c.logement_id = l.id
    LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
    INNER JOIN (
        -- Sous-requête pour obtenir le dernier contrat valide pour chaque logement
        SELECT logement_id, MAX(id) as max_contrat_id
        FROM contrats c
        WHERE " . CONTRAT_ACTIF_FILTER . "
        GROUP BY logement_id
    ) derniers_contrats ON c.id = derniers_contrats.max_contrat_id
    ORDER BY COALESCE(cl.reference, l.reference)
");
$tousContrats = $stmtTousContrats->fetchAll(PDO::FETCH_ASSOC);

// Trouver la date de prise d'effet la plus ancienne parmi tous les contrats actifs
$earliestDate = null;
foreach ($logements as $logement) {
    if (!empty($logement['date_prise_effet'])) {
        $dateEffet = new DateTime($logement['date_prise_effet']);
        if ($earliestDate === null || $dateEffet < $earliestDate) {
            $earliestDate = $dateEffet;
        }
    }
}

// Si aucune date de prise d'effet n'est trouvée, utiliser 12 mois en arrière comme fallback
if ($earliestDate === null) {
    $earliestDate = new DateTime();
    $earliestDate->modify('-11 months');
    $earliestDate->modify('first day of this month');
}

// Nom des mois en français
$nomsMois = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];

// Icônes pour les statuts
$iconesStatut = [
    'paye' => '✓',
    'impaye' => '✗',
    'attente' => '⏳'
];

// Constantes d'affichage
define('MAX_ADRESSE_LENGTH', 50);

// Générer la liste des mois depuis la date de prise d'effet la plus ancienne jusqu'au mois actuel
$mois = [];
$currentDate = new DateTime();
$currentDate->modify('first day of this month'); // Normaliser au premier du mois

$iterDate = clone $earliestDate;
$iterDate->modify('first day of this month'); // Normaliser au premier du mois

while ($iterDate <= $currentDate) {
    $moisNum = (int)$iterDate->format('n');
    $mois[] = [
        'num' => $moisNum,
        'annee' => (int)$iterDate->format('Y'),
        'nom' => $nomsMois[$moisNum],
        'nom_court' => substr($nomsMois[$moisNum], 0, 3)
    ];
    $iterDate->modify('+1 month');
}

// Créer automatiquement les entrées de tracking pour le mois courant avec statut "attente"
// Cela garantit que le mois courant est toujours affiché comme "En attente" par défaut
if (!empty($logements)) {
    foreach ($logements as $logement) {
        if (!empty($logement['contrat_id'])) {
            $montantTotal = $logement['loyer'] + $logement['charges'];
            creerEntryTracking($pdo, $logement['id'], $logement['contrat_id'], $moisActuel, $anneeActuelle, $montantTotal);
        }
    }
}

// Appliquer la règle: mettre à jour automatiquement les mois en "impaye" s'ils sont en "attente"
// - Mois antérieurs au mois actuel → toujours impayé
// - Mois courant → impayé si on est après le 5 du mois
// Note: Les entrées du mois courant doivent être créées d'abord (ci-dessus) pour que
// la mise à jour fonctionne dès le premier chargement.
updatePreviousMonthsToImpaye($pdo);

// Récupérer les statuts de paiement pour tous les logements et mois
$statutsPaiement = [];
if (!empty($logements)) {
    $logementIds = array_column($logements, 'id');
    $placeholders = implode(',', array_fill(0, count($logementIds), '?'));
    
    $stmtStatuts = $pdo->prepare("
        SELECT logement_id, mois, annee, statut_paiement, montant_attendu, date_paiement, notes
        FROM loyers_tracking
        WHERE logement_id IN ($placeholders)
        AND deleted_at IS NULL
    ");
    $stmtStatuts->execute($logementIds);
    
    while ($row = $stmtStatuts->fetch(PDO::FETCH_ASSOC)) {
        $key = $row['logement_id'] . '_' . $row['mois'] . '_' . $row['annee'];
        $statutsPaiement[$key] = $row;
    }
}

/**
 * Détermine le statut par défaut d'un mois en fonction de sa date
 * 
 * @param int $mois Numéro du mois (1-12)
 * @param int $annee Année
 * @param object|null $statut Enregistrement de statut existant (ou null)
 * @return string Le statut: 'paye', 'impaye', ou 'attente'
 * 
 * Règle métier:
 * - Si un enregistrement existe, utilise son statut
 * - Sinon, les mois passés sont considérés comme impayés
 * - Le mois en cours est considéré comme en attente
 */
function determinerStatutPaiement($mois, $annee, $statut) {
    // Si un enregistrement existe, utiliser son statut
    if ($statut) {
        return $statut['statut_paiement'];
    }
    
    // Sinon, déterminer le statut par défaut selon la date
    $currentYear = (int)date('Y');
    $currentMonth = (int)date('n');
    
    // Mois passés : impayé par défaut
    if ($annee < $currentYear || ($annee == $currentYear && $mois < $currentMonth)) {
        return 'impaye';
    }
    
    // Mois courant : en attente par défaut
    return 'attente';
}

/**
 * Récupère le statut de paiement pour un logement et un mois donnés
 */
function getStatutPaiement($logementId, $mois, $annee) {
    global $statutsPaiement;
    $key = $logementId . '_' . $mois . '_' . $annee;
    return $statutsPaiement[$key] ?? null;
}

/**
 * Détermine le statut global d'un logement basé sur tous ses mois
 * 
 * @param int $logementId L'identifiant du logement
 * @param array $mois Tableau des mois à analyser (chaque élément contient 'num' et 'annee')
 * @param string|null $datePriseEffet Date de prise d'effet du contrat (format Y-m-d), pour ignorer les mois antérieurs
 * @return string Le statut global: 'paye' (vert), 'impaye' (rouge), ou 'attente' (orange)
 * 
 * Logique:
 * - Retourne 'impaye' si au moins un mois est impayé (priorité la plus haute)
 * - Retourne 'attente' si aucun impayé mais au moins un mois en attente
 * - Retourne 'paye' si tous les mois sont payés
 */
function getStatutGlobalLogement($logementId, $mois, $datePriseEffet = null) {
    $hasImpaye = false;
    $hasAttente = false;
    $hasPaye = false;
    
    // Déterminer le premier mois du contrat pour ignorer les mois antérieurs
    $contractStartYear = null;
    $contractStartMonth = null;
    if ($datePriseEffet !== null) {
        $d = new DateTime($datePriseEffet);
        $contractStartYear = (int)$d->format('Y');
        $contractStartMonth = (int)$d->format('n');
    }
    
    foreach ($mois as $m) {
        // Ignorer les mois antérieurs à la date de prise d'effet du contrat
        if ($contractStartYear !== null && ($m['annee'] < $contractStartYear || ($m['annee'] == $contractStartYear && $m['num'] < $contractStartMonth))) {
            continue;
        }
        
        $statut = getStatutPaiement($logementId, $m['num'], $m['annee']);
        $statutPaiement = determinerStatutPaiement($m['num'], $m['annee'], $statut);
        
        if ($statutPaiement === 'impaye') {
            $hasImpaye = true;
        } elseif ($statutPaiement === 'attente') {
            $hasAttente = true;
        } elseif ($statutPaiement === 'paye') {
            $hasPaye = true;
        }
    }
    
    // Rouge si au moins une non payée
    if ($hasImpaye) {
        return 'impaye';
    }
    // Orange si seulement en attente (pas d'impayé)
    if ($hasAttente) {
        return 'attente';
    }
    // Vert si tout est payé
    return 'paye';
}

/**
 * Créer automatiquement une entrée de tracking pour un logement/mois
 */
function creerEntryTracking($pdo, $logementId, $contratId, $mois, $annee, $montantAttendu) {
    try {
        // Pour le mois en cours: si le contrat a changé (nouveau contrat pour le même logement),
        // réinitialiser le statut à "attente" car l'ancien statut appartient à l'ancien contrat.
        // Si le contrat est le même, conserver le statut existant (ne pas écraser les modifications admin).
        $stmt = $pdo->prepare("
            INSERT INTO loyers_tracking 
            (logement_id, contrat_id, mois, annee, montant_attendu, statut_paiement)
            VALUES (?, ?, ?, ?, ?, 'attente')
            ON DUPLICATE KEY UPDATE
                statut_paiement = IF(contrat_id != VALUES(contrat_id), 'attente', statut_paiement),
                contrat_id = VALUES(contrat_id),
                montant_attendu = VALUES(montant_attendu)
        ");
        return $stmt->execute([$logementId, $contratId, $mois, $annee, $montantAttendu]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Mettre à jour automatiquement les mois en "impaye" s'ils sont toujours en "attente"
 * 
 * Règle métier:
 * - Tous les mois antérieurs au mois actuel doivent être soit "paye" soit "impaye", pas "attente"
 * - Le mois courant est en "attente" du 1er au N du mois (N configurable, défaut: 5) ; au-delà,
 *   s'il est encore en "attente" (non payé), il passe automatiquement en "impaye"
 * 
 * @param PDO $pdo Connexion à la base de données
 * @return int Nombre de lignes mises à jour
 * 
 * Optimisation: Vérifie d'abord s'il y a des mois à mettre à jour avant d'exécuter l'UPDATE
 */
function updatePreviousMonthsToImpaye($pdo) {
    try {
        $currentYear = (int)date('Y');
        $currentMonth = (int)date('n');
        $currentDay = (int)date('j');
        
        // Récupérer le nombre de jours configuré avant de passer en impayé (défaut: 5)
        $joursAvantImpaye = (int)getParameter('jours_avant_impaye', 5);
        if ($joursAvantImpaye < 1) $joursAvantImpaye = 1;
        
        // Mettre à jour les mois strictement antérieurs au mois actuel
        $stmt = $pdo->prepare("
            UPDATE loyers_tracking
            SET statut_paiement = 'impaye',
                updated_at = NOW()
            WHERE statut_paiement = 'attente'
            AND deleted_at IS NULL
            AND (
                annee < ? 
                OR (annee = ? AND mois < ?)
            )
        ");
        $stmt->execute([$currentYear, $currentYear, $currentMonth]);
        $updated = $stmt->rowCount();
        
        // Si on est après le Nème jour du mois, le mois courant en "attente" passe en "impaye"
        if ($currentDay > $joursAvantImpaye) {
            $stmt2 = $pdo->prepare("
                UPDATE loyers_tracking
                SET statut_paiement = 'impaye',
                    updated_at = NOW()
                WHERE statut_paiement = 'attente'
                AND deleted_at IS NULL
                AND annee = ? AND mois = ?
            ");
            $stmt2->execute([$currentYear, $currentMonth]);
            $updated += $stmt2->rowCount();
        }
        
        return $updated;
    } catch (Exception $e) {
        error_log("Erreur lors de la mise à jour des mois précédents: " . $e->getMessage());
        return 0;
    }
}

// Gestion des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        // Changement de statut de paiement
        if (isset($_POST['action']) && $_POST['action'] === 'update_statut') {
            $logementId = (int)$_POST['logement_id'];
            $mois = (int)$_POST['mois'];
            $annee = (int)$_POST['annee'];
            $nouveauStatut = $_POST['statut'];
            
            // Valider le statut
            if (!in_array($nouveauStatut, ['paye', 'impaye', 'attente'])) {
                throw new Exception('Statut invalide');
            }
            
            // Vérifier si l'entrée existe
            $check = $pdo->prepare("
                SELECT id FROM loyers_tracking 
                WHERE logement_id = ? AND mois = ? AND annee = ?
            ");
            $check->execute([$logementId, $mois, $annee]);
            
            if ($check->fetch()) {
                // Mettre à jour
                $update = $pdo->prepare("
                    UPDATE loyers_tracking 
                    SET statut_paiement = ?,
                        date_paiement = IF(? = 'paye', CURDATE(), NULL),
                        updated_at = NOW()
                    WHERE logement_id = ? AND mois = ? AND annee = ?
                ");
                $update->execute([$nouveauStatut, $nouveauStatut, $logementId, $mois, $annee]);
            } else {
                // Créer l'entrée
                // Récupérer le contrat actif pour ce logement (utilise les mêmes critères que la requête principale)
                $contrat = $pdo->prepare("SELECT id FROM contrats c WHERE logement_id = ? AND " . CONTRAT_ACTIF_FILTER . " LIMIT 1");
                $contrat->execute([$logementId]);
                $contratInfo = $contrat->fetch(PDO::FETCH_ASSOC);

                // Use contrat_logement for frozen loyer/charges with fallback to logements
                $contratIdForLog = $contratInfo['id'] ?? null;
                if ($contratIdForLog) {
                    $logement = $pdo->prepare("
                        SELECT COALESCE(cl.loyer, l.loyer) as loyer, COALESCE(cl.charges, l.charges) as charges
                        FROM logements l
                        LEFT JOIN contrat_logement cl ON cl.contrat_id = ?
                        WHERE l.id = ?
                    ");
                    $logement->execute([$contratIdForLog, $logementId]);
                } else {
                    $logement = $pdo->prepare("SELECT loyer, charges FROM logements WHERE id = ?");
                    $logement->execute([$logementId]);
                }
                $logInfo = $logement->fetch(PDO::FETCH_ASSOC);
                
                $montantTotal = $logInfo['loyer'] + $logInfo['charges'];
                
                $insert = $pdo->prepare("
                    INSERT INTO loyers_tracking 
                    (logement_id, contrat_id, mois, annee, montant_attendu, statut_paiement, date_paiement)
                    VALUES (?, ?, ?, ?, ?, ?, IF(? = 'paye', CURDATE(), NULL))
                ");
                $insert->execute([
                    $logementId,
                    $contratIdForLog,
                    $mois,
                    $annee,
                    $montantTotal,
                    $nouveauStatut,
                    $nouveauStatut
                ]);
            }
            
            // Si le paiement est marqué comme payé, envoyer uniquement l'email de confirmation
            if ($nouveauStatut === 'paye') {
                // Récupérer les informations du contrat et du logement depuis loyers_tracking
                $stmtPayment = $pdo->prepare("
                    SELECT lt.contrat_id,
                           COALESCE(cl.adresse, l.adresse) as adresse,
                           COALESCE(cl.reference, l.reference) as logement_ref,
                           COALESCE(cl.loyer, l.loyer) as loyer,
                           COALESCE(cl.charges, l.charges) as charges
                    FROM loyers_tracking lt
                    INNER JOIN logements l ON l.id = lt.logement_id
                    LEFT JOIN contrat_logement cl ON cl.contrat_id = lt.contrat_id
                    WHERE lt.logement_id = ? AND lt.mois = ? AND lt.annee = ?
                ");
                $stmtPayment->execute([$logementId, $mois, $annee]);
                $paymentInfo = $stmtPayment->fetch(PDO::FETCH_ASSOC);

                if ($paymentInfo && !empty($paymentInfo['contrat_id'])) {
                    $contratIdPaiement = (int)$paymentInfo['contrat_id'];

                    // Récupérer les locataires du contrat
                    $stmtLoc = $pdo->prepare("SELECT email, nom, prenom FROM locataires WHERE contrat_id = ?");
                    $stmtLoc->execute([$contratIdPaiement]);
                    $locatairesPaiement = $stmtLoc->fetchAll(PDO::FETCH_ASSOC);

                    $periodeNom = $nomsMois[$mois] . ' ' . $annee;
                    $montantLoyerFmt = number_format((float)$paymentInfo['loyer'], 2, ',', ' ');
                    $montantChargesFmt = number_format((float)$paymentInfo['charges'], 2, ',', ' ');
                    $montantTotalFmt = number_format((float)$paymentInfo['loyer'] + (float)$paymentInfo['charges'], 2, ',', ' ');

                    // Email de confirmation de réception de paiement uniquement
                    // La quittance doit être envoyée manuellement via le bouton dédié
                    foreach ($locatairesPaiement as $loc) {
                        if (!empty($loc['email'])) {
                            sendTemplatedEmail(
                                'confirmation_paiement_loyer',
                                $loc['email'],
                                [
                                    'locataire_nom'     => $loc['nom'],
                                    'locataire_prenom'  => $loc['prenom'],
                                    'periode'           => $periodeNom,
                                    'adresse'           => $paymentInfo['adresse'],
                                    'reference'         => $paymentInfo['logement_ref'] ?? '',
                                    'montant_loyer'     => $montantLoyerFmt,
                                    'montant_charges'   => $montantChargesFmt,
                                    'montant_total'     => $montantTotalFmt,
                                    'signature'         => getParameter('email_signature', '')
                                ],
                                null,
                                false,
                                true  // addAdminBcc: copie automatique à l'administrateur
                            );
                        }
                    }
                }
            }

            echo json_encode(['success' => true, 'message' => 'Statut mis à jour']);
            exit;
        }

        // Envoi manuel de la quittance au locataire
        if (isset($_POST['action']) && $_POST['action'] === 'envoyer_quittance') {
            $logementId = (int)$_POST['logement_id'];
            $contratId  = (int)$_POST['contrat_id'];
            $mois       = (int)$_POST['mois'];
            $annee      = (int)$_POST['annee'];

            // Récupérer les informations du logement/contrat (données figées depuis contrat_logement)
            $stmtInfo = $pdo->prepare("
                SELECT COALESCE(cl.adresse, l.adresse) as adresse,
                       COALESCE(cl.reference, l.reference) as logement_ref,
                       COALESCE(cl.loyer, l.loyer) as loyer,
                       COALESCE(cl.charges, l.charges) as charges
                FROM logements l
                LEFT JOIN contrat_logement cl ON cl.contrat_id = ?
                WHERE l.id = ?
            ");
            $stmtInfo->execute([$contratId, $logementId]);
            $infoQuittance = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            if (!$infoQuittance) {
                throw new Exception('Logement introuvable');
            }

            // Récupérer les locataires du contrat
            $stmtLoc = $pdo->prepare("SELECT email, nom, prenom FROM locataires WHERE contrat_id = ?");
            $stmtLoc->execute([$contratId]);
            $locatairesQuittance = $stmtLoc->fetchAll(PDO::FETCH_ASSOC);

            if (empty($locatairesQuittance)) {
                throw new Exception('Aucun locataire trouvé pour ce contrat');
            }

            $periodeNom = $nomsMois[$mois] . ' ' . $annee;
            $montantLoyerFmt   = number_format((float)$infoQuittance['loyer'], 2, ',', ' ');
            $montantChargesFmt = number_format((float)$infoQuittance['charges'], 2, ',', ' ');
            $montantTotalFmt   = number_format((float)$infoQuittance['loyer'] + (float)$infoQuittance['charges'], 2, ',', ' ');

            // Générer la quittance PDF
            $quittanceResult = generateQuittancePDF($contratId, $mois, $annee);
            if ($quittanceResult === false) {
                throw new Exception('Erreur lors de la génération du PDF de quittance');
            }

            $lienQuittance = documentPathToUrl($quittanceResult['filepath']);
            $nbEnvoyes = 0;
            foreach ($locatairesQuittance as $loc) {
                if (!empty($loc['email'])) {
                    $emailSent = sendTemplatedEmail(
                        'quittance_envoyee',
                        $loc['email'],
                        [
                            'locataire_nom'                 => $loc['nom'],
                            'locataire_prenom'              => $loc['prenom'],
                            'adresse'                       => $infoQuittance['adresse'],
                            'periode'                       => $periodeNom,
                            'montant_loyer'                 => $montantLoyerFmt,
                            'montant_charges'               => $montantChargesFmt,
                            'montant_total'                 => $montantTotalFmt,
                            'signature'                     => getParameter('email_signature', ''),
                            'lien_telechargement_quittance' => $lienQuittance,
                        ],
                        null,
                        false,
                        true  // addAdminBcc
                    );
                    if ($emailSent) {
                        $nbEnvoyes++;
                    } else {
                        error_log("Erreur envoi quittance manuelle à " . $loc['email']);
                    }
                }
            }

            // Marquer la quittance comme envoyée par email
            $stmtMaj = $pdo->prepare("UPDATE quittances SET email_envoye = 1, date_envoi_email = NOW() WHERE id = ?");
            $stmtMaj->execute([$quittanceResult['quittance_id']]);

            if ($nbEnvoyes > 0) {
                echo json_encode(['success' => true, 'message' => "Quittance envoyée à $nbEnvoyes locataire(s)"]);
            } else {
                throw new Exception('Échec de l\'envoi de la quittance');
            }
            exit;
        }
        
        // Envoi de rappel manuel au locataire
        if (isset($_POST['action']) && $_POST['action'] === 'envoyer_rappel_locataire') {
            $logementId = (int)$_POST['logement_id'];
            $contratId = (int)$_POST['contrat_id'];
            $mois = (int)$_POST['mois'];
            $annee = (int)$_POST['annee'];
            
            // Récupérer les informations du logement et du locataire
            // Use contrat_logement for frozen loyer/charges/adresse/reference with fallback to logements
            $stmt = $pdo->prepare("
                SELECT l.*, c.id as contrat_id,
                       COALESCE(cl.loyer, l.loyer) as loyer,
                       COALESCE(cl.charges, l.charges) as charges,
                       COALESCE(cl.adresse, l.adresse) as adresse,
                       COALESCE(cl.reference, l.reference) as reference,
                       (SELECT email FROM locataires WHERE contrat_id = c.id LIMIT 1) as email_locataire,
                       (SELECT nom FROM locataires WHERE contrat_id = c.id LIMIT 1) as nom_locataire,
                       (SELECT prenom FROM locataires WHERE contrat_id = c.id LIMIT 1) as prenom_locataire
                FROM logements l
                INNER JOIN contrats c ON c.logement_id = l.id
                LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
                WHERE l.id = ? AND c.id = ? AND " . CONTRAT_ACTIF_FILTER . "
            ");
            $stmt->execute([$logementId, $contratId]);
            $info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$info || !$info['email_locataire']) {
                throw new Exception('Locataire introuvable ou email manquant');
            }
            
            // Préparer les variables pour le template
            $moisNom = $nomsMois[$mois];
            $montantTotal = $info['loyer'] + $info['charges'];
            
            // Envoyer l'email via le template (avec copie automatique à l'administrateur en BCC)
            $result = sendTemplatedEmail(
                'rappel_loyer_impaye_locataire',
                $info['email_locataire'],
                [
                    'locataire_nom' => $info['nom_locataire'],
                    'locataire_prenom' => $info['prenom_locataire'],
                    'periode' => $moisNom . ' ' . $annee,
                    'adresse' => $info['adresse'],
                    'reference' => $info['reference'],
                    'montant_total' => number_format($montantTotal, 2, ',', ' '),
                    'signature' => getParameter('email_signature', '')
                ],
                null,
                false,
                true  // addAdminBcc: copie automatique à l'administrateur
            );
            
            if ($result) {
                // Enregistrer l'envoi dans le tracking
                $pdo->prepare("
                    UPDATE loyers_tracking 
                    SET rappel_envoye = TRUE, date_rappel = NOW(), nb_rappels = nb_rappels + 1
                    WHERE logement_id = ? AND mois = ? AND annee = ?
                ")->execute([$logementId, $mois, $annee]);
                
                echo json_encode(['success' => true, 'message' => 'Rappel envoyé au locataire']);
            } else {
                throw new Exception('Échec de l\'envoi de l\'email');
            }
            exit;
        }
        
        // Envoi de rappel manuel aux administrateurs
        if (isset($_POST['action']) && $_POST['action'] === 'envoyer_rappel_administrateurs') {
            $moisCourant = (int)date('n');
            $anneeCourante = (int)date('Y');
            
            // Récupérer les destinataires configurés
            $destinataires = getParameter('rappel_loyers_destinataires', []);
            if (empty($destinataires)) {
                $adminEmail = getAdminEmail();
                if (!empty($adminEmail)) {
                    $destinataires = [$adminEmail];
                }
            }
            
            if (empty($destinataires)) {
                throw new Exception('Aucun administrateur configuré pour recevoir les rappels');
            }
            
            // Générer le message de statut via la fonction centralisée (même résultat que le cron)
            $statusInfo = genererMessageStatutLoyers($pdo, $moisCourant, $anneeCourante);
            $tousPayes = $statusInfo['tous_payes'];
            $templateId = $tousPayes ? 'confirmation_loyers_payes' : 'rappel_loyers_impaye';
            
            $boutonHtml = '';
            if (getParameter('rappel_loyers_inclure_bouton', true)) {
                $urlInterface = rtrim($config['SITE_URL'], '/') . '/admin-v2/gestion-loyers.php';
                $boutonHtml = '<div style="text-align: center;">
                    <a href="' . htmlspecialchars($urlInterface) . '" class="btn" style="display: inline-block; padding: 12px 30px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0;">
                        📊 Accéder à l\'interface de gestion
                    </a>
                </div>';
            }
            
            $envoyesOk = 0;
            foreach ($destinataires as $dest) {
                if (filter_var($dest, FILTER_VALIDATE_EMAIL)) {
                    $result = sendTemplatedEmail($templateId, $dest, [
                        'status_paiements' => $statusInfo['message'],
                        'bouton_interface' => $boutonHtml,
                        'signature' => getParameter('email_signature', '')
                    ]);
                    if ($result) $envoyesOk++;
                }
            }
            
            if ($envoyesOk > 0) {
                echo json_encode(['success' => true, 'message' => 'Rappel envoyé aux administrateurs']);
            } else {
                throw new Exception('Échec de l\'envoi du rappel aux administrateurs');
            }
            exit;
        }
        
        // Envoi d'un lien de paiement Stripe au locataire
        if (isset($_POST['action']) && $_POST['action'] === 'envoyer_lien_stripe') {
            $logementId = (int)$_POST['logement_id'];
            $contratId  = (int)$_POST['contrat_id'];
            $mois       = (int)$_POST['mois'];
            $annee      = (int)$_POST['annee'];

            if (!getParameter('stripe_actif', false)) {
                throw new Exception('Le paiement en ligne Stripe n\'est pas activé. Configurez-le dans Paramètres → Paiement Stripe.');
            }

            // Récupérer les infos du logement et du contrat (données figées depuis contrat_logement)
            $stmtInfo = $pdo->prepare("
                SELECT l.*, c.id as contrat_id,
                       COALESCE(cl.loyer, l.loyer) as loyer,
                       COALESCE(cl.charges, l.charges) as charges
                FROM logements l
                INNER JOIN contrats c ON c.logement_id = l.id
                LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
                WHERE l.id = ? AND c.id = ? AND " . CONTRAT_ACTIF_FILTER . "
            ");
            $stmtInfo->execute([$logementId, $contratId]);
            $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            if (!$info) {
                throw new Exception('Contrat ou logement introuvable.');
            }

            $montant = (float)$info['loyer'] + (float)$info['charges'];

            // Créer ou récupérer l'entrée loyers_tracking
            $pdo->prepare("
                INSERT INTO loyers_tracking (logement_id, contrat_id, mois, annee, montant_attendu, statut_paiement)
                VALUES (?, ?, ?, ?, ?, 'attente')
                ON DUPLICATE KEY UPDATE montant_attendu = VALUES(montant_attendu)
            ")->execute([$logementId, $contratId, $mois, $annee, $montant]);

            $ltStmt = $pdo->prepare("SELECT id FROM loyers_tracking WHERE contrat_id = ? AND mois = ? AND annee = ? AND deleted_at IS NULL LIMIT 1");
            $ltStmt->execute([$contratId, $mois, $annee]);
            $lt = $ltStmt->fetch(PDO::FETCH_ASSOC);
            $ltId = $lt['id'];

            // Créer ou réutiliser la session de paiement Stripe
            $sessStmt = $pdo->prepare("
                SELECT * FROM stripe_payment_sessions
                WHERE contrat_id = ? AND mois = ? AND annee = ?
                  AND statut NOT IN ('paye', 'annule')
                  AND token_expiration > NOW()
                ORDER BY created_at DESC LIMIT 1
            ");
            $sessStmt->execute([$contratId, $mois, $annee]);
            $paySession = $sessStmt->fetch(PDO::FETCH_ASSOC);

            if (!$paySession) {
                $liensHeures = (int)getParameter('stripe_lien_expiration_heures', 168);
                $token = bin2hex(random_bytes(32));
                $expiration = date('Y-m-d H:i:s', time() + $liensHeures * 3600);
                $pdo->prepare("
                    INSERT INTO stripe_payment_sessions
                        (loyer_tracking_id, contrat_id, logement_id, mois, annee, montant, token_acces, token_expiration, statut)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'en_attente')
                ")->execute([$ltId, $contratId, $logementId, $mois, $annee, $montant, $token, $expiration]);
                $sessStmt->execute([$contratId, $mois, $annee]);
                $paySession = $sessStmt->fetch(PDO::FETCH_ASSOC);
            }

            $lienPaiement  = rtrim($config['SITE_URL'], '/') . '/payment/pay.php?token=' . urlencode($paySession['token_acces']);
            $dateExpiration = date('d/m/Y à H:i', strtotime($paySession['token_expiration']));
            $periode = $nomsMois[$mois] . ' ' . $annee;
            $montantLoyer   = number_format((float)$info['loyer'], 2, ',', ' ');
            $montantCharges = number_format((float)$info['charges'], 2, ',', ' ');
            $montantTotal   = number_format($montant, 2, ',', ' ');

            // Récupérer tous les locataires du contrat
            $locatairesStmt = $pdo->prepare("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre");
            $locatairesStmt->execute([$contratId]);
            $locataires = $locatairesStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($locataires)) {
                throw new Exception('Aucun locataire trouvé pour ce contrat.');
            }

            $nbEnvoyes = 0;
            foreach ($locataires as $locataire) {
                $sent = sendTemplatedEmail('stripe_invitation_paiement', $locataire['email'], [
                    'locataire_nom'     => $locataire['nom'],
                    'locataire_prenom'  => $locataire['prenom'],
                    'adresse'           => $info['adresse'],
                    'reference'         => $info['reference'],
                    'periode'           => $periode,
                    'montant_loyer'     => $montantLoyer,
                    'montant_charges'   => $montantCharges,
                    'montant_total'     => $montantTotal,
                    'lien_paiement'     => $lienPaiement,
                    'date_expiration'   => $dateExpiration,
                    'signature'         => getParameter('email_signature', ''),
                ], null, false, true, ['contexte' => 'stripe_manuel']);

                if ($sent) $nbEnvoyes++;
            }

            if ($nbEnvoyes > 0) {
                // Marquer l'invitation envoyée
                $pdo->prepare("
                    UPDATE stripe_payment_sessions SET email_invitation_envoye = 1, date_email_invitation = NOW()
                    WHERE id = ?
                ")->execute([$paySession['id']]);
                echo json_encode(['success' => true, 'message' => "Lien de paiement Stripe envoyé à $nbEnvoyes locataire(s)."]);
            } else {
                throw new Exception('Échec de l\'envoi du lien de paiement.');
            }
            exit;
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Charger le SDK Stripe si disponible (pour vérifier si le module est actif)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
$stripeActif = function_exists('getParameter') ? getParameter('stripe_actif', false) : false;

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Loyers - Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .payment-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            margin-top: 20px;
        }
        
        .payment-table th,
        .payment-table td {
            border: 1px solid #dee2e6;
            padding: 12px 8px;
            text-align: center;
        }
        
        .payment-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .payment-table .property-cell {
            text-align: left;
            font-weight: 600;
            background-color: #f8f9fa;
            position: sticky;
            left: 0;
            z-index: 5;
            min-width: 200px;
        }
        
        .payment-cell {
            cursor: pointer;
            transition: opacity 0.2s;
            min-width: 80px;
            position: relative;
        }
        
        .payment-cell:hover {
            opacity: 0.8;
        }
        
        .payment-cell.paye {
            background-color: #28a745;
            color: white;
        }
        
        .payment-cell.impaye {
            background-color: #dc3545;
            color: white;
        }
        
        .payment-cell.attente {
            background-color: #ffc107;
            color: #333;
        }
        
        .payment-cell .status-icon {
            font-size: 20px;
            display: block;
        }
        
        .payment-cell .amount {
            font-size: 11px;
            margin-top: 4px;
            opacity: 0.9;
        }
        
        .legend {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 8px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .legend-box {
            width: 30px;
            height: 30px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }
        
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            padding: 15px;
            border-radius: 8px;
            color: white;
            text-align: center;
        }
        
        .stat-card.total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-card.paye { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); }
        .stat-card.impaye { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); }
        .stat-card.attente { background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); }
        
        .stat-value {
            font-size: 32px;
            font-weight: bold;
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
            margin-top: 5px;
        }
        
        .table-container {
            overflow-x: auto;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .current-month {
            background-color: #e3f2fd !important;
        }
        
        .action-buttons {
            margin-top: 5px;
        }
        
        .action-buttons button {
            font-size: 11px;
            padding: 2px 6px;
        }
        
        /* Styles pour la vue détaillée (flexbox) */
        .months-flex-container {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 20px;
        }
        
        .month-block {
            flex: 1 1 calc(20% - 15px);
            min-width: 160px;
            max-width: 220px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }
        
        .month-actions {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-top: 10px;
        }
        
        .month-actions .btn {
            font-size: 11px;
            padding: 4px 6px;
            white-space: nowrap;
        }
        
        .month-block.paye {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border-color: #28a745;
            color: white;
        }
        
        .month-block.impaye {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            border-color: #dc3545;
            color: white;
        }
        
        .month-block.attente {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            border-color: #ffc107;
            color: #333;
        }
        
        .month-block .month-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .month-block .month-year {
            font-size: 13px;
            opacity: 0.9;
            margin-bottom: 10px;
        }
        
        .month-block .status-icon {
            font-size: 48px;
            display: block;
            margin: 15px 0;
        }
        
        .month-block .amount {
            font-size: 16px;
            font-weight: bold;
            margin-top: 10px;
        }
        
        .month-block .payment-date {
            font-size: 12px;
            margin-top: 5px;
            opacity: 0.9;
        }
        
        .month-block.current-month-block {
            border: 3px solid #007bff;
            box-shadow: 0 0 15px rgba(0, 123, 255, 0.3);
        }
        
        /* Styles pour la grille de statut des logements */
        .properties-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .property-card {
            border: 3px solid;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
        }
        
        .property-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
        }
        
        .property-card.status-paye {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border-color: #28a745;
            color: white;
        }
        
        .property-card.status-impaye {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            border-color: #dc3545;
            color: white;
        }
        
        .property-card.status-attente {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            border-color: #ffc107;
            color: #333;
        }
        
        .property-card .property-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
        
        .property-card .property-reference {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .property-card .property-address {
            font-size: 13px;
            margin-bottom: 8px;
            opacity: 0.95;
        }
        
        .property-card .property-tenants {
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 10px;
        }
        
        .property-card .property-status-text {
            font-size: 14px;
            font-weight: bold;
            margin-top: 10px;
            padding: 5px 10px;
            border-radius: 5px;
            display: inline-block;
        }
        
        .property-card.status-paye .property-status-text {
            background-color: rgba(255,255,255,0.2);
        }
        
        .property-card.status-impaye .property-status-text {
            background-color: rgba(255,255,255,0.2);
        }
        
        .property-card.status-attente .property-status-text {
            background-color: rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>
    
    <div class="main-content">
    <div class="container-fluid mt-4">
        <div class="header-actions">
            <div>
                <h1><i class="bi bi-cash-stack"></i> Gestion des Loyers</h1>
                <?php if ($vueDetaillee && !empty($logements)): ?>
                    <h5 class="mb-2">
                        <span class="badge bg-primary"><?= htmlspecialchars($logements[0]['reference']) ?></span>
                        <?= htmlspecialchars($logements[0]['adresse']) ?>
                    </h5>
                    <p class="text-muted mb-2">
                        <strong>Contrat:</strong> <?= htmlspecialchars($logements[0]['contrat_reference']) ?> | 
                        <strong>Locataire(s):</strong> <?= htmlspecialchars($logements[0]['locataires'] ?: 'Non assigné') ?>
                    </p>
                <?php else: ?>
                    <p class="text-muted">Vue synthétique de l'état des paiements mensuels</p>
                <?php endif; ?>
            </div>
            <div>
                <a href="configuration-rappels-loyers.php" class="btn btn-primary">
                    <i class="bi bi-gear"></i> Configuration
                </a>
                <a href="stripe-configuration.php" class="btn btn-outline-secondary" title="Configuration Paiement Stripe">
                    <i class="bi bi-credit-card"></i> Stripe
                </a>
                <button class="btn btn-success" onclick="envoyerRappelManuel()">
                    <i class="bi bi-envelope"></i> Envoyer rappel maintenant
                </button>
            </div>
        </div>
        
        <!-- Sélecteur de contrat/logement -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label for="contrat_select" class="form-label">
                            <i class="bi bi-funnel"></i> Filtrer par contrat/logement
                        </label>
                        <select name="contrat_id" id="contrat_select" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Vue globale (tous les logements) --</option>
                            <?php foreach ($tousContrats as $contrat): ?>
                                <option value="<?= $contrat['id'] ?>" <?= ($contratIdFilter == $contrat['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($contrat['logement_ref']) ?> - 
                                    <?= htmlspecialchars(substr($contrat['adresse'], 0, MAX_ADRESSE_LENGTH)) ?> 
                                    (<?= htmlspecialchars($contrat['locataires'] ?: 'Sans locataire') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel-fill"></i> Appliquer
                        </button>
                    </div>
                    <div class="col-md-2">
                        <a href="gestion-loyers.php" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-x-circle"></i> Réinitialiser
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <?php
        // Calculer les statistiques selon le cahier des charges
        // Section 4: Vue globale doit agréger tous les mois de tous les logements
        $totalBiens = count($logements);
        $nbPaye = 0;      // Total de tous les loyers payés (tous les mois, tous les logements)
        $nbImpaye = 0;    // Total de tous les loyers impayés (tous les mois, tous les logements)
        $nbAttente = 0;   // Total de tous les loyers en attente (normalement = nombre de logements, car seul le mois en cours devrait être en attente)
        
        // Pour chaque logement, analyser tous les mois
        foreach ($logements as $logement) {
            foreach ($mois as $m) {
                $statut = getStatutPaiement($logement['id'], $m['num'], $m['annee']);
                $isMoisCourant = ($m['num'] == $moisActuel && $m['annee'] == $anneeActuelle);
                
                if ($statut) {
                    // Un enregistrement existe dans loyers_tracking
                    switch ($statut['statut_paiement']) {
                        case 'paye': 
                            $nbPaye++; 
                            break;
                        case 'impaye': 
                            $nbImpaye++; 
                            break;
                        case 'attente': 
                            $nbAttente++; 
                            break;
                    }
                } else {
                    // Aucun enregistrement dans loyers_tracking
                    // Cela devrait uniquement arriver pour le mois en cours
                    // Les mois passés devraient avoir été créés ou ne pas être affichés
                    if ($isMoisCourant) {
                        // Mois courant sans enregistrement = en attente
                        $nbAttente++;
                    } else {
                        // Mois passé sans enregistrement = traité comme impayé pour cohérence
                        // (normalement ne devrait pas arriver si le contrat existe depuis ce mois)
                        $nbImpaye++;
                    }
                }
            }
        }
        ?>

        <?php /*
        <div class="stats-summary">
            <div class="stat-card total">
                <div class="stat-value"><?= $totalBiens ?></div>
                <div class="stat-label">Biens en location</div>
            </div>
            <div class="stat-card paye">
                <div class="stat-value"><?= $nbPaye ?></div>
                <div class="stat-label">Loyers payés</div>
            </div>
            <div class="stat-card impaye">
                <div class="stat-value"><?= $nbImpaye ?></div>
                <div class="stat-label">Loyers impayés</div>
            </div>
            <div class="stat-card attente">
                <div class="stat-value"><?= $nbAttente ?></div>
                <div class="stat-label">En attente</div>
            </div>
        </div>
        */ ?>
        
        <?php if (!$vueDetaillee && !empty($logements)): ?>
        <!-- Grille de statut des logements (vue globale uniquement) -->
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-grid-3x3-gap"></i> État des Logements</h5>
            </div>
            <div class="card-body">
                <div class="properties-grid">
                    <?php foreach ($logements as $logement): 
                        $statutGlobal = getStatutGlobalLogement($logement['id'], $mois, $logement['date_prise_effet'] ?? null);
                        $statusIcon = $iconesStatut[$statutGlobal];
                        
                        $statusText = [
                            'paye' => 'Tous les loyers payés',
                            'impaye' => 'Au moins un loyer impayé',
                            'attente' => 'Loyers en attente'
                        ];
                    ?>
                        <div class="property-card status-<?= $statutGlobal ?>" 
                             onclick="window.location.href='?contrat_id=<?= $logement['contrat_id'] ?>'">
                            <div class="property-icon"><?= $statusIcon ?></div>
                            <div class="property-reference"><?= htmlspecialchars($logement['reference']) ?></div>
                            <div class="property-address"><?= htmlspecialchars($logement['adresse']) ?></div>
                            <div class="property-tenants">
                                <?= htmlspecialchars($logement['locataires'] ?: 'Sans locataire') ?>
                            </div>
                            <div class="property-status-text"><?= $statusText[$statutGlobal] ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="legend">
            <div class="legend-item">
                <div class="legend-box" style="background-color: #28a745;"></div>
                <span><strong>Payé</strong> - Loyer reçu</span>
            </div>
            <div class="legend-item">
                <div class="legend-box" style="background-color: #dc3545;"></div>
                <span><strong>Impayé</strong> - Loyer non reçu</span>
            </div>
            <div class="legend-item">
                <div class="legend-box" style="background-color: #ffc107;"></div>
                <span><strong>En attente</strong> - Statut non défini</span>
            </div>
            <div class="ms-auto">
                <small class="text-muted"><i class="bi bi-info-circle"></i> Utilisez les boutons de chaque bloc pour gérer les paiements</small>
            </div>
        </div>
        
        <?php if (empty($logements)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> Aucun bien en location actuellement.
            </div>
        <?php elseif ($vueDetaillee): ?>
            <!-- Vue détaillée avec flexbox pour un seul contrat -->
            <div class="months-flex-container">
                <?php 
                $logement = $logements[0]; // Un seul logement en vue détaillée
                $montantTotal = $logement['loyer'] + $logement['charges'];
                foreach ($mois as $m): 
                    $statut = getStatutPaiement($logement['id'], $m['num'], $m['annee']);
                    $statutClass = determinerStatutPaiement($m['num'], $m['annee'], $statut);
                    $icon = $iconesStatut[$statutClass];
                    $isCurrentMonth = ($m['num'] == $moisActuel && $m['annee'] == $anneeActuelle);
                    $datePaiement = $statut && $statut['date_paiement'] ? date('d/m/Y', strtotime($statut['date_paiement'])) : '';
                ?>
                    <div class="month-block <?= $statutClass ?> <?= $isCurrentMonth ? 'current-month-block' : '' ?>">
                        <div class="month-name"><?= htmlspecialchars($nomsMois[$m['num']]) ?></div>
                        <div class="month-year"><?= $m['annee'] ?></div>
                        <div class="status-icon"><?= $icon ?></div>
                        <div class="amount"><?= number_format($montantTotal, 2, ',', ' ') ?>€</div>
                        <?php if ($datePaiement): ?>
                            <div class="payment-date">Payé le <?= $datePaiement ?></div>
                        <?php endif; ?>
                        <div class="month-actions">
                        <?php if ($statutClass === 'paye'): ?>
                            <button class="btn btn-sm btn-outline-light"
                                    onclick="marquerNonPaye(<?= $logement['id'] ?>, <?= $m['num'] ?>, <?= $m['annee'] ?>)">
                                <i class="bi bi-x-circle"></i> Marquer non payé
                            </button>
                            <button class="btn btn-sm btn-outline-light"
                                    onclick="envoyerQuittance(<?= $logement['id'] ?>, <?= $logement['contrat_id'] ?>, <?= $m['num'] ?>, <?= $m['annee'] ?>)">
                                <i class="bi bi-file-earmark-text"></i> Envoyer la quittance
                            </button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-success"
                                    onclick="confirmerPaiement(<?= $logement['id'] ?>, <?= $m['num'] ?>, <?= $m['annee'] ?>)">
                                <i class="bi bi-check-circle"></i> Confirmer le paiement
                            </button>
                            <button class="btn btn-sm btn-primary"
                                    onclick="envoyerRappelLocataire(<?= $logement['id'] ?>, <?= $logement['contrat_id'] ?>, <?= $m['num'] ?>, <?= $m['annee'] ?>)">
                                <i class="bi bi-envelope"></i> Envoyer un rappel
                            </button>
                            <?php if ($stripeActif): ?>
                            <button class="btn btn-sm btn-warning"
                                    onclick="envoyerLienStripe(<?= $logement['id'] ?>, <?= $logement['contrat_id'] ?>, <?= $m['num'] ?>, <?= $m['annee'] ?>)">
                                <i class="bi bi-credit-card"></i> Lien de paiement
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-light"
                                    onclick="envoyerQuittance(<?= $logement['id'] ?>, <?= $logement['contrat_id'] ?>, <?= $m['num'] ?>, <?= $m['annee'] ?>)">
                                <i class="bi bi-file-earmark-text"></i> Envoyer la quittance
                            </button>
                        <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmerPaiement(logementId, mois, annee) {
            if (!confirm('Confirmer le paiement ?\n\nUn email de confirmation sera envoyé au locataire.\nLa quittance devra être envoyée séparément via le bouton dédié.')) {
                return;
            }
            updateStatut(logementId, mois, annee, 'paye');
        }

        function marquerNonPaye(logementId, mois, annee) {
            if (!confirm('Marquer ce loyer comme non payé ?\n\nAucun email ne sera envoyé.')) {
                return;
            }
            updateStatut(logementId, mois, annee, 'impaye');
        }

        function updateStatut(logementId, mois, annee, statut) {
            logementId = parseInt(logementId, 10);
            mois = parseInt(mois, 10);
            annee = parseInt(annee, 10);
            if (!logementId || mois < 1 || mois > 12 || !annee) {
                alert('Paramètres invalides');
                return;
            }
            const statutsValides = ['paye', 'impaye', 'attente'];
            if (!statutsValides.includes(statut)) {
                alert('Statut invalide');
                return;
            }
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'update_statut',
                    logement_id: logementId,
                    mois: mois,
                    annee: annee,
                    statut: statut
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Erreur: ' + (data.error || 'Échec de la mise à jour'));
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur de communication avec le serveur');
            });
        }

        function envoyerQuittance(logementId, contratId, mois, annee) {
            logementId = parseInt(logementId, 10);
            contratId = parseInt(contratId, 10);
            mois = parseInt(mois, 10);
            annee = parseInt(annee, 10);
            if (!logementId || !contratId || mois < 1 || mois > 12 || !annee) {
                alert('Paramètres invalides');
                return;
            }
            if (!confirm('Envoyer la quittance au locataire pour ce mois ?')) {
                return;
            }
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'envoyer_quittance',
                    logement_id: logementId,
                    contrat_id: contratId,
                    mois: mois,
                    annee: annee
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                } else {
                    alert('❌ Erreur: ' + (data.error || 'Échec de l\'envoi'));
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur de communication avec le serveur');
            });
        }
        
        function envoyerRappelLocataire(logementId, contratId, mois, annee) {
            if (!confirm('Envoyer un rappel de paiement au locataire pour ce mois ?')) {
                return;
            }
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'envoyer_rappel_locataire',
                    logement_id: logementId,
                    contrat_id: contratId,
                    mois: mois,
                    annee: annee
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                    location.reload();
                } else {
                    alert('❌ Erreur: ' + (data.error || 'Échec de l\'envoi'));
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur de communication avec le serveur');
            });
        }

        function envoyerLienStripe(logementId, contratId, mois, annee) {
            if (!confirm('Envoyer un lien de paiement Stripe au locataire pour ce mois ?')) {
                return;
            }
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'envoyer_lien_stripe',
                    logement_id: logementId,
                    contrat_id: contratId,
                    mois: mois,
                    annee: annee
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                } else {
                    alert('❌ Erreur: ' + (data.error || 'Échec de l\'envoi'));
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur de communication avec le serveur');
            });
        }

        function envoyerRappelManuel() {
            if (!confirm('Envoyer un rappel immédiat aux administrateurs concernant l\'état des loyers ?')) {
                return;
            }

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'envoyer_rappel_administrateurs'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                    location.reload();
                } else {
                    alert('❌ Erreur: ' + (data.error || 'Échec de l\'envoi'));
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur de communication avec le serveur');
            });
        }
    </script>
    </div><!-- end main-content -->
</body>
</html>
