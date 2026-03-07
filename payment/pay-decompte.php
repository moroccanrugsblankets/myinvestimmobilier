<?php
/**
 * Page de paiement d'un décompte d'intervention via Stripe
 *
 * Page publique accessible via un lien sécurisé unique envoyé par email au locataire.
 * URL: /payment/pay-decompte.php?token=XXXXX
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(503);
    die('Service temporairement indisponible. Veuillez contacter le propriétaire.');
}
require_once $autoload;

$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if (empty($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    die('Lien de paiement invalide ou expiré.');
}

// Récupérer le décompte associé au token
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
    error_log('pay-decompte.php DB error: ' . $e->getMessage());
    http_response_code(500);
    die('Erreur interne. Veuillez réessayer plus tard.');
}

if (!$decompte) {
    http_response_code(404);
    die('Lien de paiement introuvable.');
}

// Vérifier si déjà payé
if ($decompte['statut_paiement'] === 'paye') {
    $alreadyPaid = true;
} elseif (!empty($decompte['token_paiement_expiration'])
    && strtotime($decompte['token_paiement_expiration']) < time()
    && $decompte['statut_paiement'] === 'non_genere') {
    // Le lien a expiré et aucun paiement n'a jamais été initié
    http_response_code(410);
    die('Ce lien de paiement a expiré. Veuillez contacter votre propriétaire pour obtenir un nouveau lien.');
} else {
    $alreadyPaid = false;
}

// Si déjà payé, afficher confirmation
if ($alreadyPaid) {
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Paiement confirmé - <?php echo htmlspecialchars($config['COMPANY_NAME']); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card shadow text-center">
                        <div class="card-body p-5">
                            <div class="text-success mb-4" style="font-size: 64px;">✅</div>
                            <h2 class="text-success">Paiement déjà effectué</h2>
                            <p class="text-muted">Le décompte <strong><?php echo htmlspecialchars($decompte['reference']); ?></strong> a été réglé.</p>
                            <p>Montant : <strong><?php echo number_format((float)$decompte['montant_total'], 2, ',', ' '); ?> €</strong></p>
                            <?php if ($decompte['date_paiement']): ?>
                            <p class="text-muted small">Payé le <?php echo date('d/m/Y', strtotime($decompte['date_paiement'])); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p class="text-center mt-3 text-muted small"><?php echo htmlspecialchars($config['COMPANY_NAME']); ?></p>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Vérifier que Stripe est configuré
$stripeActif = getParameter('stripe_actif', false);
if (!$stripeActif) {
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Paiement - <?php echo htmlspecialchars($config['COMPANY_NAME']); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card shadow">
                        <div class="card-body p-5 text-center">
                            <div style="font-size: 48px;" class="mb-3">🏦</div>
                            <h3>Paiement en ligne temporairement indisponible</h3>
                            <p class="text-muted">Le paiement en ligne n'est pas encore activé. Merci de contacter votre propriétaire pour les modalités de paiement.</p>
                            <hr>
                            <p><strong>Montant dû :</strong> <?php echo number_format((float)$decompte['montant_total'], 2, ',', ' '); ?> €</p>
                            <p class="text-muted small">Réf. décompte : <?php echo htmlspecialchars($decompte['reference']); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Récupérer les clés Stripe
$stripeMode = getParameter('stripe_mode', 'test');
$stripeSecretKey = ($stripeMode === 'live')
    ? getParameter('stripe_secret_key_live', '')
    : getParameter('stripe_secret_key_test', '');

if (empty($stripeSecretKey)) {
    error_log('Stripe: clé secrète non configurée (pay-decompte)');
    die('Configuration de paiement incomplète. Contactez votre propriétaire.');
}

\Stripe\Stripe::setApiKey($stripeSecretKey);

// Créer ou récupérer la Checkout Session Stripe
$checkoutUrl = null;
if (!empty($decompte['stripe_session_id'])) {
    try {
        $existingSession = \Stripe\Checkout\Session::retrieve($decompte['stripe_session_id']);
        if ($existingSession->status === 'open') {
            $checkoutUrl = $existingSession->url;
        }
    } catch (Exception $e) {
        $checkoutUrl = null;
    }
}

if (!$checkoutUrl) {
    try {
        $methodesAcceptees = getParameter('stripe_methodes_paiement', ['card', 'sepa_debit']);
        if (!is_array($methodesAcceptees)) {
            $methodesAcceptees = ['card'];
        }

        $siteUrl    = rtrim($config['SITE_URL'], '/');
        $successUrl = $siteUrl . '/payment/merci-decompte.php?token=' . urlencode($token) . '&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl  = $siteUrl . '/payment/pay-decompte.php?token=' . urlencode($token) . '&stripe_cancel=1';

        $expiresAt = time() + (24 * 60 * 60); // 24 heures maximum (limite Stripe Checkout)
        if (!empty($decompte['token_paiement_expiration'])) {
            $expiresAt = min($expiresAt, strtotime($decompte['token_paiement_expiration']));
        }

        $checkoutParams = [
            'payment_method_types' => $methodesAcceptees,
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name'        => 'Décompte ' . $decompte['reference'] . ' — ' . $decompte['adresse'],
                        'description' => $decompte['sig_titre'] ?? 'Intervention locative',
                    ],
                    'unit_amount' => (int)round((float)$decompte['montant_total'] * 100),
                ],
                'quantity' => 1,
            ]],
            'mode'        => 'payment',
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
            'metadata'    => [
                'decompte_token'    => $token,
                'decompte_id'       => $decompte['id'],
                'decompte_ref'      => $decompte['reference'],
                'logement_adresse'  => $decompte['adresse'],
            ],
            'locale'     => 'fr',
            'expires_at' => $expiresAt,
        ];

        if (!empty($decompte['locataire_email'])) {
            $checkoutParams['customer_email'] = $decompte['locataire_email'];
        }

        $stripeCheckout = \Stripe\Checkout\Session::create($checkoutParams);
        $checkoutUrl    = $stripeCheckout->url;

        // Enregistrer l'ID de session Stripe et passer en statut en_attente
        $pdo->prepare("
            UPDATE signalements_decomptes
            SET stripe_session_id = ?, statut_paiement = 'en_attente', updated_at = NOW()
            WHERE token_paiement = ?
        ")->execute([$stripeCheckout->id, $token]);

    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('Stripe Checkout Session error (decompte): ' . $e->getMessage());
        die('Erreur lors de la création du lien de paiement. Veuillez réessayer ou contacter votre propriétaire.');
    }
}

if ($checkoutUrl) {
    header('Location: ' . $checkoutUrl);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paiement - <?php echo htmlspecialchars($config['COMPANY_NAME']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-body p-5 text-center">
                        <div class="spinner-border text-primary mb-3" role="status"></div>
                        <h4>Redirection vers le paiement sécurisé...</h4>
                        <p class="text-muted">Vous allez être redirigé vers Stripe pour régler votre décompte d'intervention.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
