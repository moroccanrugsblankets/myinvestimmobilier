<?php
/**
 * Garant – Étape 1 : Engagement
 *
 * Le garant confirme qu'il accepte d'être garant du locataire X.
 *
 * My Invest Immobilier
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// ------------------------------------------------------------------
// Validation du token
// ------------------------------------------------------------------
$token = trim($_GET['token'] ?? $_SESSION['garant_token'] ?? '');

if (empty($token)) {
    die('Token manquant. Veuillez utiliser le lien fourni dans votre email.');
}

$garant = getGarantByToken($token);

if (!$garant) {
    die('Lien invalide. Veuillez contacter My Invest Immobilier.');
}

if ($garant['type_garantie'] !== 'caution_solidaire') {
    die('Ce lien n\'est pas applicable à votre type de garantie.');
}

// Stocker le token en session pour les étapes suivantes
$_SESSION['garant_token']   = $token;
$_SESSION['garant_id']      = $garant['id'];
$_SESSION['garant_contrat'] = $garant['contrat_id'];

// Si déjà en cours ou terminé, autoriser la consultation mais pas la ré-soumission
$alreadyEngaged = in_array($garant['statut'], ['engage', 'signe', 'documents_recus'], true);

// Récupérer le locataire principal du contrat
$locataire = fetchOne("
    SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 1
", [$garant['contrat_id']]);

$error = '';

// ------------------------------------------------------------------
// Traitement POST
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyEngaged) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Token CSRF invalide.';
    } else {
        $choix = $_POST['choix'] ?? '';

        if ($choix === 'refuse') {
            updateGarantStatut($garant['id'], 'en_attente_garant');
            logAction($garant['contrat_id'], 'garant_refus', 'Le garant a refusé l\'engagement');

            // Emails de notification du refus
            $emailContact  = $config['COMPANY_EMAIL'] ?? getAdminEmail();
            $refusVarsBase   = [
                'prenom_garant'    => $garant['prenom'],
                'nom_garant'       => $garant['nom'],
                'prenom_locataire' => $locataire ? $locataire['prenom'] : '',
                'nom_locataire'    => $locataire ? $locataire['nom']    : '',
                'adresse_logement' => $garant['adresse_logement'],
                'email_contact'    => $emailContact,
            ];

            // Email au garant
            sendTemplatedEmail('garant_refus_notification', $garant['email'], array_merge($refusVarsBase, [
                'prenom_destinataire' => $garant['prenom'],
                'nom_destinataire'    => $garant['nom'],
            ]), null, false, true, ['contexte' => 'contrat_id=' . $garant['contrat_id']]);

            // Email au locataire
            if ($locataire && !empty($locataire['email'])) {
                sendTemplatedEmail('garant_refus_notification', $locataire['email'], array_merge($refusVarsBase, [
                    'prenom_destinataire' => $locataire['prenom'],
                    'nom_destinataire'    => $locataire['nom'],
                ]), null, false, true, ['contexte' => 'contrat_id=' . $garant['contrat_id']]);
            }

            // Use the session to pass the refusal message and redirect to confirmation
            $_SESSION['garant_refused'] = true;
            header('Location: confirmation.php');
            exit;
        } elseif ($choix === 'accepte') {
            updateGarantStatut($garant['id'], 'engage');
            logAction($garant['contrat_id'], 'garant_engagement', 'Le garant a accepté d\'être garant');
            header('Location: step2-info.php');
            exit;
        }
    }
}

if ($alreadyEngaged && !$error) {
    // Garant already engaged – redirect to the appropriate step
    if ($garant['statut'] === 'engage') {
        header('Location: step2-info.php');
        exit;
    } elseif ($garant['statut'] === 'signe') {
        header('Location: step4-documents.php');
        exit;
    } elseif ($garant['statut'] === 'documents_recus') {
        header('Location: confirmation.php');
        exit;
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Engagement garant – My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="container mt-5 mb-5">
    <div class="text-center mb-4">
        <img src="../assets/images/logo.png" alt="My Invest Immobilier" class="logo mb-3"
             onerror="this.style.display='none'">
        <h1 class="h2">Espace garant</h1>
    </div>

    <!-- Barre de progression -->
    <div class="mb-4">
        <div class="progress" style="height: 28px;">
            <div class="progress-bar bg-primary" role="progressbar" style="width:25%">
                Étape 1/4 – Engagement
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-body">
                    <h4 class="card-title mb-4">Confirmation d'engagement</h4>

                    <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <div class="alert alert-info">
                        <p class="mb-1">
                            Vous avez été désigné(e) comme <strong>garant(e)</strong> pour la location du logement suivant :
                        </p>
                        <ul class="mb-0">
                            <li><strong>Logement :</strong> <?= htmlspecialchars($garant['adresse_logement']) ?></li>
                            <?php if ($locataire): ?>
                            <li><strong>Locataire :</strong> <?= htmlspecialchars($locataire['prenom'] . ' ' . $locataire['nom']) ?></li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <p>En tant que <strong>caution solidaire</strong>, vous vous engagez à payer le loyer et les charges
                       si le locataire ne peut pas le faire. Veuillez lire attentivement les informations suivantes
                       avant d'accepter.</p>

                    <div class="alert alert-warning">
                        <strong>⚠️ Rappel de vos obligations :</strong>
                        <ul class="mb-0 mt-2">
                            <li>En cas de défaillance du locataire, le bailleur pourra se retourner directement contre vous.</li>
                            <li>Votre engagement est valable pour toute la durée du bail et ses renouvellements.</li>
                            <li>Un document de caution solidaire vous sera soumis à signature électronique.</li>
                        </ul>
                    </div>

                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <div class="d-flex gap-3 mt-4">
                            <button type="submit" name="choix" value="accepte" class="btn btn-primary btn-lg flex-grow-1">
                                J'accepte d'être garant →
                            </button>
                            <button type="submit" name="choix" value="refuse" class="btn btn-outline-danger btn-lg">
                                Refuser
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
