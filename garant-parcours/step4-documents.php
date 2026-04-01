<?php
/**
 * Garant – Étape 4 : Justificatifs (pièce d'identité + documents caution solidaire)
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
        // Liste des champs de fichiers obligatoires : clé => libellé d'erreur
        $requiredFiles = [
            'piece_identite'       => 'Veuillez télécharger votre pièce d\'identité (recto/verso).',
            'bulletin_salaire_1'   => 'Veuillez télécharger votre 1er bulletin de salaire.',
            'bulletin_salaire_2'   => 'Veuillez télécharger votre 2ème bulletin de salaire.',
            'bulletin_salaire_3'   => 'Veuillez télécharger votre 3ème bulletin de salaire.',
            'fiche_imposition'     => 'Veuillez télécharger votre dernière fiche d\'imposition.',
            'justificatif_domicile'=> 'Veuillez télécharger votre justificatif de domicile (moins de 3 mois).',
        ];

        $validatedFiles = [];

        foreach ($requiredFiles as $field => $errorMsg) {
            $file = $_FILES[$field] ?? null;
            if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                $error = $errorMsg;
                break;
            }
            $validation = validateUploadedFile($file);
            if (!$validation['success']) {
                $error = $validation['error'] . ' (' . $field . ')';
                break;
            }
            $validatedFiles[$field] = ['file' => $file, 'filename' => $validation['filename']];
        }

        if (!$error) {
            // Sauvegarder tous les fichiers
            $savedFilenames = [];
            foreach ($validatedFiles as $field => $data) {
                if (!saveUploadedFile($data['file'], $data['filename'])) {
                    $error = 'Erreur lors de la sauvegarde du fichier (' . $field . ').';
                    break;
                }
                $savedFilenames[$field] = $data['filename'];
            }

            if (!$error) {
                executeQuery(
                    "UPDATE garants
                     SET piece_identite        = ?,
                         bulletin_salaire_1    = ?,
                         bulletin_salaire_2    = ?,
                         bulletin_salaire_3    = ?,
                         fiche_imposition      = ?,
                         justificatif_domicile = ?,
                         statut                = 'documents_recus',
                         date_documents        = NOW()
                     WHERE id = ?",
                    [
                        $savedFilenames['piece_identite'],
                        $savedFilenames['bulletin_salaire_1'],
                        $savedFilenames['bulletin_salaire_2'],
                        $savedFilenames['bulletin_salaire_3'],
                        $savedFilenames['fiche_imposition'],
                        $savedFilenames['justificatif_domicile'],
                        $garantId,
                    ]
                );

                logAction($garant['contrat_id'], 'garant_documents_recus', "Documents caution solidaire reçus pour garant ID $garantId");

                // -- Envoi emails de finalisation --
                $emailContact     = $config['COMPANY_EMAIL'] ?? getAdminEmail();
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
                    'prenom_garant'     => $garant['prenom'],
                    'nom_garant'        => $garant['nom'],
                    'prenom_locataire'  => $locataire ? $locataire['prenom'] : '',
                    'nom_locataire'     => $locataire ? $locataire['nom']    : '',
                    'adresse_logement'  => $garant['adresse_logement'],
                    'date_finalisation' => $dateFinalisation,
                    'lien_document'     => $lienDocument,
                    'email_contact'     => $emailContact,
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
                    'reference'        => $garant['reference_contrat'],
                    'adresse_logement' => $garant['adresse_logement'],
                    'prenom_locataire' => $locataire ? $locataire['prenom'] : '',
                    'nom_locataire'    => $locataire ? $locataire['nom']    : '',
                    'type_garantie'    => 'Caution solidaire',
                    'prenom_garant'    => $garant['prenom'],
                    'nom_garant'       => $garant['nom'],
                    'email_garant'     => $garant['email'],
                    'date_envoi'       => $dateFinalisation,
                    'lien_admin'       => $config['SITE_URL'] . '/admin-v2/contrat-detail.php?id=' . $garant['contrat_id'],
                ], null, true, false, ['contexte' => 'contrat_id=' . $garant['contrat_id']]);

                $success = true;
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
                    <h4 class="card-title mb-4">Justificatifs garant</h4>

                    <div class="alert alert-secondary mb-4">
                        <strong>Logement :</strong> <?= htmlspecialchars($garant['adresse_logement']) ?><br>
                        <strong>Garant :</strong> <?= htmlspecialchars($garant['prenom'] . ' ' . $garant['nom']) ?>
                    </div>

                    <p>Merci de télécharger l'ensemble des justificatifs ci-dessous. Tous les champs sont obligatoires.</p>

                    <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                        <!-- Pièce d'identité -->
                        <div class="mb-4">
                            <label for="piece_identite" class="form-label fw-semibold">
                                Pièce d'identité (recto/verso) <span class="text-danger">*</span>
                            </label>
                            <input type="file" class="form-control" id="piece_identite" name="piece_identite"
                                   accept=".jpg,.jpeg,.png,.pdf" required>
                            <small class="form-text text-muted">
                                Carte nationale d'identité, passeport ou titre de séjour – JPG, PNG ou PDF – max 5 Mo
                            </small>
                        </div>

                        <!-- 3 derniers bulletins de salaire -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Bulletins de salaire (3 derniers) <span class="text-danger">*</span>
                            </label>
                            <div class="mb-2">
                                <small class="text-muted d-block mb-1">1er bulletin de salaire</small>
                                <input type="file" class="form-control" id="bulletin_salaire_1" name="bulletin_salaire_1"
                                       accept=".jpg,.jpeg,.png,.pdf" required
                                       aria-label="1er bulletin de salaire">
                            </div>
                            <div class="mb-2">
                                <small class="text-muted d-block mb-1">2ème bulletin de salaire</small>
                                <input type="file" class="form-control" id="bulletin_salaire_2" name="bulletin_salaire_2"
                                       accept=".jpg,.jpeg,.png,.pdf" required
                                       aria-label="2ème bulletin de salaire">
                            </div>
                            <div>
                                <small class="text-muted d-block mb-1">3ème bulletin de salaire</small>
                                <input type="file" class="form-control" id="bulletin_salaire_3" name="bulletin_salaire_3"
                                       accept=".jpg,.jpeg,.png,.pdf" required
                                       aria-label="3ème bulletin de salaire">
                            </div>
                            <small class="form-text text-muted mt-1 d-block">
                                JPG, PNG ou PDF – max 5 Mo par fichier
                            </small>
                        </div>

                        <!-- Dernière fiche d'imposition -->
                        <div class="mb-4">
                            <label for="fiche_imposition" class="form-label fw-semibold">
                                Dernière fiche d'imposition <span class="text-danger">*</span>
                            </label>
                            <input type="file" class="form-control" id="fiche_imposition" name="fiche_imposition"
                                   accept=".jpg,.jpeg,.png,.pdf" required>
                            <small class="form-text text-muted">
                                Avis d'imposition ou de non-imposition – JPG, PNG ou PDF – max 5 Mo
                            </small>
                        </div>

                        <!-- Justificatif de domicile -->
                        <div class="mb-4">
                            <label for="justificatif_domicile" class="form-label fw-semibold">
                                Justificatif de domicile (moins de 3 mois) <span class="text-danger">*</span>
                            </label>
                            <input type="file" class="form-control" id="justificatif_domicile" name="justificatif_domicile"
                                   accept=".jpg,.jpeg,.png,.pdf" required>
                            <small class="form-text text-muted">
                                Facture d'énergie, quittance de loyer ou relevé bancaire – JPG, PNG ou PDF – max 5 Mo
                            </small>
                        </div>

                        <div class="alert alert-info">
                            <strong>Documents acceptés pour chaque champ :</strong>
                            <ul class="mb-0 mt-1">
                                <li>Format : JPG, PNG ou PDF</li>
                                <li>Taille maximale : 5 Mo par fichier</li>
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
