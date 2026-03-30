<?php
/**
 * Upload documents assurance habitation et Visale
 * My Invest Immobilier
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mail-templates.php';

// Récupérer le token
$token = $_GET['token'] ?? '';

if (empty($token)) {
    die('Token manquant. Veuillez utiliser le lien fourni dans votre email.');
}

// Récupérer le contrat via le token assurance
$contrat = fetchOne("
    SELECT c.*, l.adresse, l.reference as ref_logement
    FROM contrats c
    INNER JOIN logements l ON c.logement_id = l.id
    WHERE c.token_assurance = ?
", [$token]);

if (!$contrat) {
    die('Lien invalide ou expiré. Veuillez contacter My Invest Immobilier.');
}

// Vérifier que le contrat est validé
if ($contrat['statut'] !== 'valide') {
    die('Ce lien n\'est plus valide.');
}

$error = '';
$success = '';

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $error = 'Token CSRF invalide.';
    } else {
        $assuranceFile = $_FILES['assurance_habitation'] ?? null;
        $visaCertifieFile = $_FILES['visa_certifie'] ?? null;
        $numeroVisale = trim($_POST['numero_visale'] ?? '');

        // Validate: at least insurance certificate is required
        if (!$assuranceFile || $assuranceFile['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Veuillez télécharger votre attestation d\'assurance habitation.';
        } else {
            // Validate insurance file
            $validationAssurance = validateUploadedFile($assuranceFile);
            if (!$validationAssurance['success']) {
                $error = 'Attestation d\'assurance : ' . $validationAssurance['error'];
            } else {
                // Validate optional visa certifié file
                $validationVisa = null;
                if ($visaCertifieFile && $visaCertifieFile['error'] !== UPLOAD_ERR_NO_FILE) {
                    $validationVisa = validateUploadedFile($visaCertifieFile);
                    if (!$validationVisa['success']) {
                        $error = 'Visa certifié : ' . $validationVisa['error'];
                    }
                }

                if (empty($error)) {
                    // Save insurance file
                    if (saveUploadedFile($assuranceFile, $validationAssurance['filename'])) {
                        // Save optional visa certifié file
                        $visaCertifieFilename = null;
                        if ($validationVisa && saveUploadedFile($visaCertifieFile, $validationVisa['filename'])) {
                            $visaCertifieFilename = $validationVisa['filename'];
                        }

                        // Update contrat
                        $stmt = $pdo->prepare("
                            UPDATE contrats
                            SET assurance_habitation = ?,
                                numero_visale = ?,
                                visa_certifie = ?,
                                date_envoi_assurance = NOW()
                            WHERE id = ?
                        ");

                        if ($stmt->execute([
                            $validationAssurance['filename'],
                            $numeroVisale,
                            $visaCertifieFilename,
                            $contrat['id']
                        ])) {
                            logAction($contrat['id'], 'assurance_visale_recu', 'Documents assurance/Visale uploadés');

                            // Notify admins and send tenant confirmation
                            $locataires = getTenantsByContract($contrat['id']);
                            if (!empty($locataires)) {
                                $locatairesNoms = array_map(function($loc) {
                                    return $loc['prenom'] . ' ' . $loc['nom'];
                                }, $locataires);
                                $locatairesStr = implode(', ', $locatairesNoms);

                                $lienAdmin = $config['SITE_URL'] . '/admin-v2/contrat-detail.php?id=' . $contrat['id'];

                                $adminVariables = [
                                    'reference' => $contrat['reference_unique'],
                                    'logement' => $contrat['adresse'],
                                    'locataires' => $locatairesStr,
                                    'date_envoi' => date('d/m/Y à H:i'),
                                    'lien_admin' => $lienAdmin
                                ];

                                sendTemplatedEmail('notification_assurance_visale_admin', getAdminEmail(), $adminVariables, null, true);

                                // Send confirmation email to each tenant (with admin BCC)
                                foreach ($locataires as $locataire) {
                                    if (!empty($locataire['email'])) {
                                        sendTemplatedEmail('confirmation_assurance_visale_locataire', $locataire['email'], [
                                            'nom' => $locataire['nom'],
                                            'prenom' => $locataire['prenom'],
                                            'reference' => $contrat['reference_unique']
                                        ], null, false, true);
                                    }
                                }
                            }

                            $success = true;
                        } else {
                            $error = 'Erreur lors de l\'enregistrement des informations.';
                        }
                    } else {
                        $error = 'Erreur lors de la sauvegarde du fichier.';
                    }
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
    <title>Documents assurance et Visale - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container mt-5">
        <div class="text-center mb-4">
            <img src="assets/images/logo.png" alt="My Invest Immobilier" class="logo mb-3"
                 onerror="this.style.display='none'">
            <h1 class="h2">Envoi des documents requis</h1>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                <?php if ($success): ?>
                    <div class="card shadow border-success">
                        <div class="card-body text-center p-5">
                            <div class="mb-4">
                                <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" fill="currentColor"
                                     class="bi bi-check-circle-fill text-success" viewBox="0 0 16 16">
                                    <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                                </svg>
                            </div>

                            <h2 class="text-success mb-4">Documents envoyés avec succès !</h2>

                            <p class="lead mb-4">
                                Vos documents ont bien été transmis à notre équipe.
                            </p>

                            <div class="alert alert-info text-start">
                                <h5>Prochaines étapes :</h5>
                                <ol class="mb-0">
                                    <li class="mb-2">Notre équipe va vérifier vos documents</li>
                                    <li class="mb-2">Vous serez contacté(e) pour organiser l'entrée dans les lieux</li>
                                </ol>
                            </div>

                            <p class="mt-4">
                                Pour toute question, n'hésitez pas à nous contacter :
                            </p>
                            <p>
                                <strong><?= htmlspecialchars($config['COMPANY_NAME']) ?></strong><br>
                                Email : <a href="mailto:<?= htmlspecialchars($config['COMPANY_EMAIL']) ?>"><?= htmlspecialchars($config['COMPANY_EMAIL']) ?></a>
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card shadow">
                        <div class="card-body">
                            <h4 class="card-title mb-3">
                                Attestation d'assurance habitation &amp; Visale
                            </h4>

                            <div class="alert alert-info mb-4">
                                <p class="mb-2">
                                    <strong>📋 Référence du contrat :</strong> <?= htmlspecialchars($contrat['reference_unique']) ?>
                                </p>
                                <p class="mb-0">
                                    Merci de transmettre les documents suivants afin de finaliser définitivement votre dossier et valider votre entrée dans les lieux.
                                </p>
                            </div>

                            <?php if ($error): ?>
                                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                            <?php endif; ?>

                            <form method="POST" action="" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                                <div class="mb-3">
                                    <label for="assurance_habitation" class="form-label">
                                        Attestation d'assurance habitation <span class="text-danger">*</span>
                                    </label>
                                    <input type="file" class="form-control" id="assurance_habitation"
                                           name="assurance_habitation"
                                           accept=".jpg,.jpeg,.png,.pdf" required>
                                    <small class="form-text text-muted">
                                        Formats acceptés : JPG, PNG, PDF - Taille max : 5 Mo
                                    </small>
                                </div>

                                <div class="mb-3">
                                    <label for="numero_visale" class="form-label">
                                        Numéro de garantie Visale
                                    </label>
                                    <input type="text" class="form-control" id="numero_visale"
                                           name="numero_visale"
                                           placeholder="Ex: VS-XXXXXXXX"
                                           value="<?= htmlspecialchars($_POST['numero_visale'] ?? '') ?>"
                                           required>
                                </div>

                                <div class="mb-3">
                                    <label for="visa_certifie" class="form-label">
                                        Visa certifié Visale <span class="text-muted">(optionnel)</span>
                                    </label>
                                    <input type="file" class="form-control" id="visa_certifie"
                                           name="visa_certifie"
                                           accept=".jpg,.jpeg,.png,.pdf">
                                    <small class="form-text text-muted">
                                        Formats acceptés : JPG, PNG, PDF - Taille max : 5 Mo
                                    </small>
                                </div>

                                <div class="alert alert-warning">
                                    <h5>Documents obligatoires :</h5>
                                    <ul class="mb-0">
                                        <li>Attestation d'assurance habitation en cours de validité couvrant le logement loué</li>
                                        <li>Numéro de garantie Visale</li>
                                    </ul>
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        Envoyer mes documents →
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
