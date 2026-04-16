<?php
/**
 * Demandes & Documents — Portail locataire
 *
 * URL: /locataire/demande-document.php
 *
 * Permet au locataire authentifié de soumettre une demande :
 *  - Objet (obligatoire)
 *  - Message (optionnel)
 *  - Pièce jointe (optionnel)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail-templates.php';

// ── Vérification de session ────────────────────────────────────────────────────
$locataire = $_SESSION['portal_locataire'] ?? null;

if (!$locataire || empty($locataire['id']) || empty($locataire['contrat_id'])) {
    header('Location: /locataire/');
    exit;
}

$errors     = [];
$submitted  = false;
$reference  = '';

$companyName  = $config['COMPANY_NAME']  ?? 'My Invest Immobilier';
$siteUrl      = rtrim($config['SITE_URL'] ?? '', '/');
$companyEmail = $config['COMPANY_EMAIL'] ?? '';

// ── Traitement du formulaire ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Soumission de la demande ───────────────────────────────────────────
    if ($action === 'submit_demande') {
        $objet   = trim($_POST['objet']   ?? '');
        $message = trim($_POST['message'] ?? '');

        if (empty($objet)) {
            $errors[] = "L'objet de la demande est obligatoire.";
        } elseif (mb_strlen($objet) > 500) {
            $errors[] = "L'objet ne doit pas dépasser 500 caractères.";
        }

        // Traitement du fichier joint (optionnel)
        $fichierPath = null;
        $fichierNom  = null;

        if (!empty($_FILES['fichier']['name'])) {
            $allowedMimes = [
                'image/jpeg', 'image/jpg', 'image/png', 'image/webp',
                'application/pdf',
                'video/mp4', 'video/quicktime', 'video/x-msvideo',
            ];
            $maxSize  = 20 * 1024 * 1024; // 20 Mo
            $tmpName  = $_FILES['fichier']['tmp_name'];
            $fileSize = $_FILES['fichier']['size'];
            $origName = $_FILES['fichier']['name'];

            if ($_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "Erreur lors de l'upload du fichier.";
            } elseif ($fileSize > $maxSize) {
                $errors[] = "Le fichier dépasse la taille maximale autorisée (20 Mo).";
            } else {
                $mime = mime_content_type($tmpName);
                if (!in_array($mime, $allowedMimes)) {
                    $errors[] = "Format de fichier non supporté. Formats acceptés : JPG, PNG, WebP, PDF, MP4, MOV.";
                } else {
                    $uploadDir = __DIR__ . '/../uploads/demandes-documents/';
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                        $errors[] = "Erreur serveur : impossible de créer le répertoire d'upload.";
                    } elseif (!is_writable($uploadDir)) {
                        $errors[] = "Erreur serveur : le répertoire d'upload n'est pas accessible en écriture.";
                    } else {
                        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                        $newName  = 'dem_' . bin2hex(random_bytes(12)) . '.' . $ext;
                        $destPath = $uploadDir . $newName;
                        if (!move_uploaded_file($tmpName, $destPath)) {
                            $errors[] = "Erreur lors de l'enregistrement du fichier.";
                        } else {
                            $fichierPath = 'uploads/demandes-documents/' . $newName;
                            $fichierNom  = $origName;
                        }
                    }
                }
            }
        }

        if (empty($errors)) {
            try {
                $reference = 'DEM-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

                $pdo->prepare("
                    INSERT INTO demandes_documents
                        (reference, contrat_id, logement_id, locataire_id, email_locataire,
                         objet, message, fichier_path, fichier_nom, statut)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'nouveau')
                ")->execute([
                    $reference,
                    $locataire['contrat_id'],
                    $locataire['logement_id'],
                    $locataire['id'],
                    strtolower(trim($locataire['email'])),
                    $objet,
                    $message ?: null,
                    $fichierPath,
                    $fichierNom,
                ]);
                $newDemandeId = $pdo->lastInsertId();

                // ── Notification email aux admins ──────────────────────────
                $messageHtml = '';
                if (!empty($message)) {
                    $messageHtml = '<div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:15px;margin-bottom:20px;">'
                        . '<p style="margin:0 0 6px;font-weight:bold;font-size:13px;">Message :</p>'
                        . '<p style="margin:0;font-size:13px;white-space:pre-wrap;">' . htmlspecialchars($message) . '</p>'
                        . '</div>';
                }

                $adminVars = [
                    'reference'      => $reference,
                    'locataire'      => $locataire['prenom'] . ' ' . $locataire['nom'],
                    'email_locataire'=> strtolower(trim($locataire['email'])),
                    'adresse'        => $locataire['adresse'] ?? '',
                    'objet'          => $objet,
                    'message_html'   => $messageHtml,
                    'date'           => date('d/m/Y à H:i'),
                    'lien_admin'     => $siteUrl . '/admin-v2/demandes-documents.php?id=' . $newDemandeId,
                    'company'        => $companyName,
                ];

                // Pièce jointe pour l'email
                $attachmentArg = null;
                if ($fichierPath) {
                    $attachmentArg = [['path' => __DIR__ . '/../' . $fichierPath, 'name' => $fichierNom]];
                }

                // Envoyer aux admins
                $allAdminEmails = [];
                try {
                    $stmtAdm = $pdo->query("SELECT email FROM administrateurs WHERE actif = 1 AND email IS NOT NULL AND email != ''");
                    $allAdminEmails = $stmtAdm->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e) {}
                $configAdminEmail = getAdminEmail();
                if (!empty($configAdminEmail) && !in_array(strtolower($configAdminEmail), array_map('strtolower', $allAdminEmails))) {
                    array_unshift($allAdminEmails, $configAdminEmail);
                }

                foreach (array_unique($allAdminEmails) as $aEmail) {
                    if (!empty($aEmail) && filter_var($aEmail, FILTER_VALIDATE_EMAIL)) {
                        // Reply-To défini sur l'email du locataire pour que l'admin puisse répondre directement
                        sendEmail(
                            $aEmail,
                            replaceTemplateVariables(
                                getEmailTemplate('demande_document_admin')['sujet'] ?? '📄 Nouvelle demande — ' . $objet,
                                $adminVars
                            ),
                            replaceTemplateVariables(
                                getEmailTemplate('demande_document_admin')['corps_html'] ?? '<p>Nouvelle demande de ' . htmlspecialchars($locataire['prenom'] . ' ' . $locataire['nom']) . ' : ' . htmlspecialchars($objet) . '</p>',
                                $adminVars
                            ),
                            $attachmentArg,
                            true,
                            false,
                            strtolower(trim($locataire['email'])),
                            $locataire['prenom'] . ' ' . $locataire['nom'],
                            false,
                            ['contexte' => "demande_document_admin;dem_id=$newDemandeId"]
                        );
                    }
                }

                // ── Email de confirmation au locataire ─────────────────────
                $locataireEmail = strtolower(trim($locataire['email'] ?? ''));
                if (!empty($locataireEmail)) {
                    sendTemplatedEmail('demande_document_locataire', $locataireEmail, [
                        'prenom'    => $locataire['prenom'],
                        'nom'       => $locataire['nom'],
                        'reference' => $reference,
                        'objet'     => $objet,
                        'company'   => $companyName,
                    ], null, false, false, ['contexte' => "demande_document_confirmation;dem_id=$newDemandeId"]);
                }

                $submitted = true;

            } catch (Exception $e) {
                error_log('locataire/demande-document.php error: ' . $e->getMessage());
                $errors[] = "Une erreur est survenue lors de l'envoi de votre demande. Veuillez réessayer.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demandes &amp; Documents — <?php echo htmlspecialchars($companyName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($siteUrl . '/assets/css/frontoffice.css'); ?>">
    <style>
        .portal-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .portal-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: #fff;
            padding: 32px 36px 26px;
        }
        .portal-body { padding: 36px; }
    </style>
</head>
<body>
<?php
require_once __DIR__ . '/../includes/header-frontoffice.php';
renderFrontOfficeHeader($siteUrl, $companyName);
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">

            <div class="portal-card">

                <div class="portal-header">
                    <h2 class="mb-1 fs-4">
                        <i class="bi bi-file-earmark-text me-2"></i>Demandes &amp; Documents
                    </h2>
                    <p class="mb-0 opacity-75" style="font-size:.92rem;">
                        <i class="bi bi-house me-1"></i><?php echo htmlspecialchars($locataire['adresse']); ?>
                        <?php if (!empty($locataire['logement_ref'])): ?>
                            &nbsp;—&nbsp;<span class="font-monospace"><?php echo htmlspecialchars($locataire['logement_ref']); ?></span>
                        <?php endif; ?>
                        &nbsp;—&nbsp;<i class="bi bi-person me-1"></i><?php echo htmlspecialchars($locataire['prenom'] . ' ' . $locataire['nom']); ?>
                    </p>
                </div>

                <div class="portal-body">

                    <?php if ($submitted): ?>

                        <div class="text-center py-4">
                            <div class="text-success mb-3" style="font-size:60px;">✅</div>
                            <h4 class="text-success mb-2">Demande envoyée !</h4>
                            <p class="text-muted mb-3">
                                Votre demande a bien été transmise à l'équipe de gestion.<br>
                                Vous recevrez une réponse dans les meilleurs délais.
                            </p>
                            <div class="alert alert-light border text-start d-inline-block">
                                <strong>Référence :</strong>
                                <span class="font-monospace"><?php echo htmlspecialchars($reference); ?></span>
                            </div>
                            <div class="mt-4">
                                <a href="/locataire/" class="btn btn-primary">
                                    <i class="bi bi-arrow-left-circle me-1"></i>Retour à l'espace locataire
                                </a>
                            </div>
                        </div>

                    <?php else: ?>

                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger mb-4">
                                <i class="bi bi-exclamation-circle-fill me-2"></i>
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($errors as $e): ?>
                                        <li><?php echo htmlspecialchars($e); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <p class="text-muted mb-4">
                            Utilisez ce formulaire pour adresser une demande ou réclamer un document à votre gestionnaire.
                        </p>

                        <form method="POST" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="action" value="submit_demande">

                            <div class="mb-4">
                                <label class="form-label fw-semibold" for="objet">
                                    Objet de la demande <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="objet" name="objet"
                                       required maxlength="500"
                                       placeholder="Ex : Attestation de loyer, Quittance de loyer, Reçu de caution…"
                                       value="<?php echo htmlspecialchars($_POST['objet'] ?? ''); ?>">
                                <div class="form-text">Décrivez brièvement l'objet de votre demande.</div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold" for="message">
                                    Message <span class="text-muted fw-normal">(optionnel)</span>
                                </label>
                                <textarea class="form-control" id="message" name="message"
                                          rows="5"
                                          placeholder="Précisions supplémentaires, contexte, période concernée…"><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold" for="fichier">
                                    Pièce jointe <span class="text-muted fw-normal">(optionnel)</span>
                                </label>
                                <input type="file" class="form-control" id="fichier" name="fichier"
                                       accept=".jpg,.jpeg,.png,.webp,.pdf,.mp4,.mov,.avi">
                                <div class="form-text">Formats acceptés : JPG, PNG, WebP, PDF, MP4, MOV — 20 Mo max.</div>
                            </div>

                            <div class="d-flex gap-3 flex-wrap">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-send me-2"></i>Envoyer la demande
                                </button>
                                <a href="/locataire/" class="btn btn-outline-secondary btn-lg">
                                    <i class="bi bi-arrow-left me-1"></i>Retour
                                </a>
                            </div>

                        </form>

                    <?php endif; ?>

                </div>
            </div>

            <p class="text-center mt-3 text-muted small">
                <?php echo htmlspecialchars($companyName); ?>
                <?php if (!empty($companyEmail)): ?>
                    &nbsp;—&nbsp;<a href="mailto:<?php echo htmlspecialchars($companyEmail); ?>" class="text-muted"><?php echo htmlspecialchars($companyEmail); ?></a>
                <?php endif; ?>
            </p>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
