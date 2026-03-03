<?php
/**
 * Page de remerciement après paiement Stripe
 *
 * Page publique affichée automatiquement après un paiement réussi via Stripe Checkout.
 * Met à jour le statut du loyer en "payé" si le webhook n'a pas encore traité l'événement.
 *
 * URL: /payment/merci.php?token=XXXXX&session_id=cs_XXXXX
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Charger l'autoloader Composer (Stripe SDK)
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

$token     = isset($_GET['token'])      ? trim($_GET['token'])      : '';
$sessionId = isset($_GET['session_id']) ? trim($_GET['session_id']) : '';

// Validation basique du token
if (empty($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    die('Lien invalide.');
}

// Récupérer la session de paiement depuis la base de données
try {
    $stmt = $pdo->prepare("
        SELECT sps.*,
               l.adresse,
               (SELECT GROUP_CONCAT(CONCAT(loc.prenom, ' ', loc.nom) SEPARATOR ', ')
                FROM locataires loc WHERE loc.contrat_id = sps.contrat_id) as locataires_noms
        FROM stripe_payment_sessions sps
        INNER JOIN logements l ON sps.logement_id = l.id
        WHERE sps.token_acces = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Stripe merci.php DB error: ' . $e->getMessage());
    http_response_code(500);
    die('Erreur interne. Veuillez réessayer plus tard.');
}

if (!$session) {
    http_response_code(404);
    die('Session de paiement introuvable.');
}

$nomsMois = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];
$periode = ($nomsMois[$session['mois']] ?? $session['mois']) . ' ' . $session['annee'];

// Si le statut n'est pas encore "paye", vérifier auprès de Stripe et mettre à jour
if ($session['statut'] !== 'paye') {
    $stripeVerified = false;
    $paymentIntentId = null;

    // Vérifier via l'API Stripe si une session_id est fournie
    if (!empty($sessionId) && class_exists('\Stripe\Stripe')) {
        $stripeMode      = getParameter('stripe_mode', 'test');
        $stripeSecretKey = ($stripeMode === 'live')
            ? getParameter('stripe_secret_key_live', '')
            : getParameter('stripe_secret_key_test', '');

        if (!empty($stripeSecretKey)) {
            try {
                \Stripe\Stripe::setApiKey($stripeSecretKey);
                $stripeSession = \Stripe\Checkout\Session::retrieve($sessionId);
                if ($stripeSession->payment_status === 'paid') {
                    $stripeVerified  = true;
                    $paymentIntentId = $stripeSession->payment_intent ?? null;
                }
            } catch (Exception $e) {
                error_log('Stripe merci.php session retrieve error: ' . $e->getMessage());
            }
        }
    }

    if ($stripeVerified) {
        try {
            $pdo->beginTransaction();

            // Mettre à jour stripe_payment_sessions (idempotent grâce à la condition)
            $pdo->prepare("
                UPDATE stripe_payment_sessions
                SET statut = 'paye',
                    stripe_payment_intent_id = COALESCE(stripe_payment_intent_id, ?),
                    stripe_session_id = COALESCE(stripe_session_id, ?),
                    date_paiement = COALESCE(date_paiement, NOW()),
                    updated_at = NOW()
                WHERE token_acces = ? AND statut != 'paye'
            ")->execute([$paymentIntentId, $sessionId, $token]);

            // Mettre à jour loyers_tracking
            $pdo->prepare("
                UPDATE loyers_tracking
                SET statut_paiement = 'paye',
                    mode_paiement = 'stripe',
                    stripe_session_id = COALESCE(stripe_session_id, ?),
                    date_paiement = COALESCE(date_paiement, NOW()),
                    updated_at = NOW()
                WHERE id = ? AND statut_paiement != 'paye'
            ")->execute([$sessionId, $session['loyer_tracking_id']]);

            $pdo->commit();
            $session['statut'] = 'paye';

            // Envoyer email de confirmation aux locataires (fallback si le webhook n'a pas encore traité)
            try {
                require_once __DIR__ . '/../includes/mail-templates.php';
                $locatairesStmt = $pdo->prepare("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre");
                $locatairesStmt->execute([$session['contrat_id']]);
                $locataires = $locatairesStmt->fetchAll(PDO::FETCH_ASSOC);
                $logementStmt = $pdo->prepare("SELECT adresse, reference, loyer, charges FROM logements WHERE id = ?");
                $logementStmt->execute([$session['logement_id']]);
                $logement = $logementStmt->fetch(PDO::FETCH_ASSOC);
                $loyer   = $logement['loyer']   ?? 0;
                $charges = $logement['charges'] ?? 0;
                foreach ($locataires as $locataire) {
                    sendTemplatedEmail('confirmation_paiement_loyer', $locataire['email'], [
                        'locataire_nom'    => $locataire['nom'],
                        'locataire_prenom' => $locataire['prenom'],
                        'adresse'          => $session['adresse'],
                        'reference'        => $logement['reference'] ?? '',
                        'periode'          => $periode,
                        'montant_loyer'    => number_format((float)$loyer, 2, ',', ' '),
                        'montant_charges'  => number_format((float)$charges, 2, ',', ' '),
                        'montant_total'    => number_format((float)$session['montant'], 2, ',', ' '),
                        'signature'        => getParameter('email_signature', ''),
                    ], null, false, false, ['contexte' => 'merci_paiement_confirmation']);
                }
            } catch (Exception $mailEx) {
                error_log('Stripe merci.php email confirmation error: ' . $mailEx->getMessage());
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Stripe merci.php update error: ' . $e->getMessage());
        }
    }
}

$isPaid = ($session['statut'] === 'paye');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isPaid ? 'Paiement confirmé' : 'Paiement'; ?> - <?php echo htmlspecialchars($config['COMPANY_NAME']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .card-merci { border-radius: 16px; border: none; }
        .icon-success { font-size: 72px; line-height: 1; }
        .icon-pending { font-size: 72px; line-height: 1; }
    </style>
</head>
<body>
    <div class="container mt-5 mb-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow card-merci">
                    <div class="card-body p-5 text-center">
                        <?php if ($isPaid): ?>
                            <div class="icon-success text-success mb-3">✅</div>
                            <h2 class="text-success mb-2">Merci pour votre paiement&nbsp;!</h2>
                            <p class="text-muted mb-4">
                                Votre loyer de <strong><?php echo htmlspecialchars($periode); ?></strong>
                                a bien été réglé.
                            </p>
                            <div class="alert alert-success text-start">
                                <strong>Logement :</strong> <?php echo htmlspecialchars($session['adresse']); ?><br>
                                <strong>Montant :</strong> <?php echo number_format((float)$session['montant'], 2, ',', ' '); ?> €<br>
                                <strong>Période :</strong> <?php echo htmlspecialchars($periode); ?>
                            </div>
                            <p class="text-muted small mt-3">
                                Votre quittance de loyer vous sera envoyée par email dans les plus brefs délais.
                            </p>
                        <?php else: ?>
                            <div class="icon-pending text-warning mb-3">⏳</div>
                            <h2 class="text-warning mb-2">Paiement en cours de traitement</h2>
                            <p class="text-muted mb-4">
                                Votre paiement pour le loyer de <strong><?php echo htmlspecialchars($periode); ?></strong>
                                est en cours de vérification.
                            </p>
                            <p class="text-muted small">
                                Vous recevrez une confirmation par email une fois le paiement validé.
                                Si vous avez une question, contactez votre propriétaire.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="text-center mt-3 text-muted small">
                    <?php echo htmlspecialchars($config['COMPANY_NAME']); ?>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
