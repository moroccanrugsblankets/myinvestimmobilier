<?php
/**
 * Page de remerciement après paiement d'un décompte d'intervention via Stripe
 *
 * URL: /payment/merci-decompte.php?token=XXXXX&session_id=YYYY
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if (empty($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    die('Lien invalide.');
}

// Récupérer le décompte
try {
    $stmt = $pdo->prepare("
        SELECT d.*,
               sig.reference    AS sig_reference,
               sig.titre        AS sig_titre,
               l.adresse,
               l.reference      AS logement_reference,
               CONCAT(loc.prenom, ' ', loc.nom) AS locataire_nom,
               loc.email        AS locataire_email,
               loc.prenom       AS locataire_prenom
        FROM signalements_decomptes d
        INNER JOIN signalements sig ON d.signalement_id = sig.id
        INNER JOIN logements l ON sig.logement_id = l.id
        LEFT JOIN contrats c ON sig.contrat_id = c.id
        LEFT JOIN locataires loc ON sig.locataire_id = loc.id
        WHERE d.token_paiement = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $decompte = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('merci-decompte.php DB error: ' . $e->getMessage());
    http_response_code(500);
    die('Erreur interne.');
}

if (!$decompte) {
    http_response_code(404);
    die('Décompte introuvable.');
}

$isPaid = ($decompte['statut_paiement'] === 'paye');

// Vérifier via Stripe si paiement non encore confirmé
if (!$isPaid && !empty($_GET['session_id']) && class_exists('\Stripe\Stripe')) {
    $sessionId = trim($_GET['session_id']);
    if (preg_match('/^cs_[a-zA-Z0-9_]+$/', $sessionId)) {
        try {
            $stripeMode = getParameter('stripe_mode', 'test');
            $stripeSecretKey = ($stripeMode === 'live')
                ? getParameter('stripe_secret_key_live', '')
                : getParameter('stripe_secret_key_test', '');

            if (!empty($stripeSecretKey)) {
                \Stripe\Stripe::setApiKey($stripeSecretKey);
                $stripeSession = \Stripe\Checkout\Session::retrieve($sessionId);

                if ($stripeSession->payment_status === 'paid') {
                    $paymentIntentId = $stripeSession->payment_intent ?? null;

                    $pdo->prepare("
                        UPDATE signalements_decomptes
                        SET statut_paiement = 'paye',
                            stripe_payment_intent_id = ?,
                            date_paiement = NOW(),
                            updated_at = NOW()
                        WHERE token_paiement = ? AND statut_paiement != 'paye'
                    ")->execute([$paymentIntentId, $token]);

                    $isPaid = true;
                    $decompte['statut_paiement'] = 'paye';
                    $decompte['date_paiement']   = date('Y-m-d H:i:s');
                }
            }
        } catch (Exception $e) {
            error_log('merci-decompte.php Stripe check error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isPaid ? 'Paiement confirmé' : 'Paiement en cours'; ?> - <?php echo htmlspecialchars($config['COMPANY_NAME']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .payment-card { max-width: 540px; margin: 60px auto; }
        .icon-big { font-size: 72px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="payment-card">
            <div class="card shadow-sm">
                <div class="card-body p-5 text-center">

                    <?php if ($isPaid): ?>
                    <!-- ✅ Paiement confirmé -->
                    <div class="text-success icon-big mb-3">✅</div>
                    <h2 class="text-success mb-2">Merci pour votre paiement !</h2>
                    <p class="text-muted mb-4">Votre décompte d'intervention a bien été réglé.</p>
                    <div class="alert alert-success text-start">
                        <strong>Décompte :</strong> <?php echo htmlspecialchars($decompte['reference']); ?><br>
                        <strong>Logement :</strong> <?php echo htmlspecialchars($decompte['adresse']); ?><br>
                        <strong>Montant :</strong> <?php echo number_format((float)$decompte['montant_total'], 2, ',', ' '); ?> €
                        <?php if ($decompte['date_paiement']): ?>
                        <br><strong>Date :</strong> <?php echo date('d/m/Y', strtotime($decompte['date_paiement'])); ?>
                        <?php endif; ?>
                    </div>
                    <p class="text-muted small mt-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Un justificatif vous sera transmis par email si nécessaire.
                    </p>

                    <?php else: ?>
                    <!-- ⏳ En attente de confirmation -->
                    <div class="icon-big mb-3">⏳</div>
                    <h2 class="mb-2">Paiement en cours de traitement</h2>
                    <p class="text-muted mb-4">
                        Votre paiement est en cours de vérification. Vous recevrez une confirmation dès que le paiement sera validé.
                    </p>
                    <div class="alert alert-info text-start">
                        <strong>Décompte :</strong> <?php echo htmlspecialchars($decompte['reference']); ?><br>
                        <strong>Logement :</strong> <?php echo htmlspecialchars($decompte['adresse']); ?><br>
                        <strong>Montant :</strong> <?php echo number_format((float)$decompte['montant_total'], 2, ',', ' '); ?> €
                    </div>
                    <p class="text-muted small">
                        Si vous avez des questions, contactez votre propriétaire.
                    </p>
                    <?php endif; ?>

                </div>
            </div>
            <p class="text-center mt-3 text-muted small"><?php echo htmlspecialchars($config['COMPANY_NAME']); ?></p>
        </div>
    </div>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</body>
</html>
