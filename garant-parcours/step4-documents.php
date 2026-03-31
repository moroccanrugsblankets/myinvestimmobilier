<?php
/**
 * Garant – Étape 4 : Justificatifs (pièce d'identité)
 *
 * My Invest Immobilier
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// ------------------------------------------------------------------
// Vérification de session
// ------------------------------------------------------------------
if (empty($_SESSION['garant_token']) || empty($_SESSION['garant_id'])) {
    header('Location: index.php');
    exit;
}

$garantId = (int)$_SESSION['garant_id'];
$garant   = fetchOne("
    SELECT g.*,
           c.reference_unique AS reference_contrat,
           l.adresse          AS adresse_logement
    FROM garants g
    INNER JOIN contrats c ON g.contrat_id = c.id
    INNER JOIN logements l ON c.logement_id = l.id
    WHERE g.id = ?
", [$garantId]);

if (!$garant) {
    die('Session invalide. Veuillez utiliser le lien fourni dans votre email.');
}

if (!in_array($garant['statut'], ['signe', 'documents_recus'], true)) {
    header('Location: step3-signature.php');
    exit;
}

// Récupérer le locataire principal
$locataire = fetchOne("
    SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 1
", [$garant['contrat_id']]);

$error   = '';
$success = false;

// ------------------------------------------------------------------
// Traitement POST
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Token CSRF invalide.';
    } else {
        $pieceFile = $_FILES['piece_identite'] ?? null;

        if (!$pieceFile || $pieceFile['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Veuillez télécharger votre pièce d\'identité.';
        } else {
            $validation = validateUploadedFile($pieceFile);
            if (!$validation['success']) {
                $error = $validation['error'];
            } else {
                if (saveUploadedFile($pieceFile, $validation['filename'])) {
                    executeQuery(
                        "UPDATE garants SET piece_identite = ?, statut = 'documents_recus', date_documents = NOW() WHERE id = ?",
                        [$validation['filename'], $garantId]
                    );

                    logAction($garant['contrat_id'], 'garant_documents_recus', "Pièce identité reçue pour garant ID $garantId");

                    // -- Envoi emails de finalisation --
                    $emailContact    = $config['COMPANY_EMAIL'] ?? getAdminEmail();
                    $dateFinalisation = date('d/m/Y à H:i');

                    // Lien vers le document de caution (chemin physique du PDF)
                    $lienDocument = '';
                    if (!empty($garant['document_caution'])) {
                        $lienDocument = documentPathToUrl($garant['document_caution']);
                    }
                    if (empty($lienDocument)) {
                        $lienDocument = $config['SITE_URL'] . '/admin-v2/contrat-detail.php?id=' . $garant['contrat_id'];
                    }

                    $vars = [
                        'prenom_garant'       => $garant['prenom'],
                        'nom_garant'          => $garant['nom'],
                        'prenom_locataire'    => $locataire ? $locataire['prenom'] : '',
                        'nom_locataire'       => $locataire ? $locataire['nom']    : '',
                        'adresse_logement'    => $garant['adresse_logement'],
                        'date_finalisation'   => $dateFinalisation,
                        'lien_document'       => $lienDocument,
                        'email_contact'       => $emailContact,
                    ];

                    // Email au garant
                    sendTemplatedEmail('garant_finalisation', $garant['email'], array_merge($vars, [
                        'prenom_destinataire' => $garant['prenom'],
                        'nom_destinataire'    => $garant['nom'],
                    ]), null, false, false, ['contexte' => 'contrat_id=' . $garant['contrat_id']]);

                    // Email au locataire
                    if ($locataire && !empty($locataire['email'])) {
                        sendTemplatedEmail('garant_finalisation', $locataire['email'], array_merge($vars, [
                            'prenom_destinataire' => $locataire['prenom'],
                            'nom_destinataire'    => $locataire['nom'],
                        ]), null, false, false, ['contexte' => 'contrat_id=' . $garant['contrat_id']]);
                    }

                    // Notification admin
                    sendTemplatedEmail('garant_notification_admin', getAdminEmail(), [
                        'reference'         => $garant['reference_contrat'],
                        'adresse_logement'  => $garant['adresse_logement'],
                        'prenom_locataire'  => $locataire ? $locataire['prenom'] : '',
                        'nom_locataire'     => $locataire ? $locataire['nom']    : '',
                        'type_garantie'     => 'Caution solidaire',
                        'prenom_garant'     => $garant['prenom'],
                        'nom_garant'        => $garant['nom'],
                        'email_garant'      => $garant['email'],
                        'date_envoi'        => $dateFinalisation,
                        'lien_admin'        => $config['SITE_URL'] . '/admin-v2/contrat-detail.php?id=' . $garant['contrat_id'],
                    ], null, true, false, ['contexte' => 'contrat_id=' . $garant['contrat_id']]);

                    $success = true;
                } else {
                    $error = 'Erreur lors de la sauvegarde du fichier.';
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Justificatifs garant – My Invest Immobilier</title>
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
            <div class="progress-bar bg-success" role="progressbar" style="width:100%">
                Étape 4/4 – Justificatifs
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-8">
            <?php if ($success): ?>
            <div class="card shadow border-success">
                <div class="card-body text-center p-5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="70" height="70" fill="currentColor"
                         class="bi bi-check-circle-fill text-success mb-4" viewBox="0 0 16 16">
                        <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                    </svg>
                    <h2 class="text-success mb-3">Dossier finalisé !</h2>
                    <p class="lead">Votre dossier garant a été transmis avec succès.</p>
                    <p>Vous recevrez un email de confirmation avec un lien vers votre document de caution solidaire.</p>
                    <a href="confirmation.php" class="btn btn-success mt-3">Voir la confirmation →</a>
                </div>
            </div>
            <?php else: ?>
            <div class="card shadow">
                <div class="card-body">
                    <h4 class="card-title mb-4">Pièce d'identité</h4>

                    <div class="alert alert-secondary mb-4">
                        <strong>Logement :</strong> <?= htmlspecialchars($garant['adresse_logement']) ?><br>
                        <strong>Garant :</strong> <?= htmlspecialchars($garant['prenom'] . ' ' . $garant['nom']) ?>
                    </div>

                    <p>Veuillez télécharger votre pièce d'identité (carte nationale d'identité ou passeport en cours de validité).</p>

                    <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                        <div class="mb-4">
                            <label for="piece_identite" class="form-label">
                                Pièce d'identité <span class="text-danger">*</span>
                            </label>
                            <input type="file" class="form-control" id="piece_identite" name="piece_identite"
                                   accept=".jpg,.jpeg,.png,.pdf" required>
                            <small class="form-text text-muted">
                                JPG, PNG ou PDF – taille max : 5 Mo
                            </small>
                        </div>

                        <div class="alert alert-info">
                            <strong>Documents acceptés :</strong>
                            <ul class="mb-0 mt-1">
                                <li>Carte nationale d'identité (recto)</li>
                                <li>Passeport (page d'identité)</li>
                                <li>Carte de résident ou titre de séjour</li>
                            </ul>
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-success btn-lg">
                                Finaliser mon dossier ✓
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
