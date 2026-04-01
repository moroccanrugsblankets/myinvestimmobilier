<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/contract-templates.php';

// Constants for file upload limits
define('MAX_SIGNATURE_SIZE', 2 * 1024 * 1024); // 2 MB

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_delai_expiration') {
        $heures = max(1, (int)$_POST['delai_expiration_lien_contrat']);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM parametres WHERE cle = 'delai_expiration_lien_contrat'");
        $stmt->execute();
        if ($stmt->fetchColumn() > 0) {
            $stmt = $pdo->prepare("UPDATE parametres SET valeur = ?, groupe = 'contrats', updated_at = NOW() WHERE cle = 'delai_expiration_lien_contrat'");
            $stmt->execute([$heures]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('delai_expiration_lien_contrat', ?, 'integer', 'contrats', 'Délai d\'expiration du lien de signature (en heures)')");
            $stmt->execute([$heures]);
        }
        $_SESSION['success'] = "Paramètre mis à jour avec succès";
        header('Location: contrat-configuration.php');
        exit;
    }
    elseif ($_POST['action'] === 'update_jours_avant_impaye') {
        $jours = max(1, (int)$_POST['jours_avant_impaye']);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM parametres WHERE cle = 'jours_avant_impaye'");
        $stmt->execute();
        if ($stmt->fetchColumn() > 0) {
            $stmt = $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'jours_avant_impaye'");
            $stmt->execute([$jours]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('jours_avant_impaye', ?, 'integer', 'loyers', 'Nombre de jours du mois après lequel un loyer en attente passe automatiquement en impayé')");
            $stmt->execute([$jours]);
        }
        $_SESSION['success'] = "Paramètre mis à jour avec succès";
        header('Location: contrat-configuration.php');
        exit;
    }
    elseif ($_POST['action'] === 'update_template') {
        $type = in_array($_POST['template_type'] ?? '', ['meuble', 'non_meuble', 'sur_mesure'])
            ? $_POST['template_type']
            : 'meuble';
        $cle = 'contrat_template_html_' . $type;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM parametres WHERE cle = ?");
        $stmt->execute([$cle]);
        $exists = $stmt->fetchColumn() > 0;

        if ($exists) {
            $stmt = $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = ?");
            $stmt->execute([$_POST['template_html'], $cle]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES (?, ?, 'text', 'contrats', 'Template HTML du contrat avec variables dynamiques')");
            $stmt->execute([$cle, $_POST['template_html']]);
        }

        $_SESSION['success'] = "Template de contrat mis à jour avec succès";
        header('Location: contrat-configuration.php?tab=' . $type);
        exit;
    }
    elseif ($_POST['action'] === 'update_preavis') {
        $jours = max(0, (int)$_POST['preavis_annulation_jours']);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM parametres WHERE cle = 'preavis_annulation_jours'");
        $stmt->execute();
        if ($stmt->fetchColumn() > 0) {
            $stmt = $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'preavis_annulation_jours'");
            $stmt->execute([$jours]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('preavis_annulation_jours', ?, 'integer', 'contrats', 'Préavis d''annulation en jours (0 = suppression de la période)')");
            $stmt->execute([$jours]);
        }
        $_SESSION['success'] = "Critère d'annulation mis à jour avec succès";
        header('Location: contrat-configuration.php');
        exit;
    }
    elseif ($_POST['action'] === 'upload_signature') {
        // Handle signature image upload
        if (isset($_FILES['signature_image']) && $_FILES['signature_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['signature_image'];
            
            // Validate file type
            $allowed_types = ['image/png', 'image/jpeg', 'image/jpg'];
            if (!in_array($file['type'], $allowed_types)) {
                $_SESSION['error'] = "Format d'image non valide. Utilisez PNG ou JPEG.";
                header('Location: contrat-configuration.php');
                exit;
            }
            
            // Validate file size (max 2MB)
            if ($file['size'] > MAX_SIGNATURE_SIZE) {
                $_SESSION['error'] = "La taille de l'image ne doit pas dépasser 2 MB.";
                header('Location: contrat-configuration.php');
                exit;
            }
            
            // Read and resize image for optimal display
            // Maximum width for signature image (in pixels)
            $maxWidth = 600;
            $maxHeight = 300;
            
            // Create image resource from uploaded file
            $sourceImage = null;
            if ($file['type'] === 'image/png') {
                $sourceImage = imagecreatefrompng($file['tmp_name']);
            } elseif ($file['type'] === 'image/jpeg' || $file['type'] === 'image/jpg') {
                $sourceImage = imagecreatefromjpeg($file['tmp_name']);
            }
            
            if ($sourceImage === false || $sourceImage === null) {
                $_SESSION['error'] = "Impossible de traiter l'image. Veuillez réessayer avec un autre fichier.";
                header('Location: contrat-configuration.php');
                exit;
            }
            
            // Get original dimensions
            $originalWidth = imagesx($sourceImage);
            $originalHeight = imagesy($sourceImage);
            
            // Validate dimensions to prevent division by zero and ensure reasonable image size
            // Minimum 10x10 pixels to avoid edge cases with extremely small images
            if ($originalWidth < 10 || $originalHeight < 10) {
                imagedestroy($sourceImage);
                $_SESSION['error'] = "L'image téléchargée est trop petite. Taille minimum : 10x10 pixels.";
                header('Location: contrat-configuration.php');
                exit;
            }
            
            // Calculate new dimensions maintaining aspect ratio
            $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
            
            // Only resize if image is larger than max dimensions
            if ($ratio < 1) {
                $newWidth = round($originalWidth * $ratio);
                $newHeight = round($originalHeight * $ratio);
            } else {
                $newWidth = $originalWidth;
                $newHeight = $originalHeight;
            }
            
            // Create new image with white background for JPEG
            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Fill with white background (JPEG doesn't support transparency)
            $white = imagecolorallocate($resizedImage, 255, 255, 255);
            imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $white);
            
            // Resize the image
            imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
            
            // Save resized image data as JPEG
            ob_start();
            imagejpeg($resizedImage, null, 90); // High quality JPEG
            $imageData = ob_get_clean();
            
            // Clean up resources
            imagedestroy($sourceImage);
            imagedestroy($resizedImage);
            
            // Create uploads directory if it doesn't exist
            $baseDir = dirname(__DIR__);
            $uploadsDir = $baseDir . '/uploads/signatures';
            if (!is_dir($uploadsDir)) {
                if (!mkdir($uploadsDir, 0755, true)) {
                    $_SESSION['error'] = "Impossible de créer le répertoire des signatures";
                    header('Location: contrat-configuration.php');
                    exit;
                }
            }
            
            // Delete old signature file if exists
            $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'signature_societe_image'");
            $stmt->execute();
            $oldSignature = $stmt->fetchColumn();
            if (!empty($oldSignature) && strpos($oldSignature, 'data:') !== 0 && strpos($oldSignature, 'uploads/signatures/') !== false) {
                $oldFilePath = $baseDir . '/' . $oldSignature;
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                    error_log("Deleted old company signature file: $oldFilePath");
                }
            }
            
            // Generate unique filename for company signature (always .jpg)
            $filename = "company_signature_" . time() . ".jpg";
            $filepath = $uploadsDir . '/' . $filename;
            
            // Save physical file
            if (file_put_contents($filepath, $imageData) === false) {
                $_SESSION['error'] = "Impossible de sauvegarder le fichier de signature";
                header('Location: contrat-configuration.php');
                exit;
            }
            
            // Store relative path instead of base64
            $relativePath = 'uploads/signatures/' . $filename;
            error_log("Company signature saved as physical file: $relativePath");
            
            // Update or insert signature parameter with file path
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM parametres WHERE cle = 'signature_societe_image'");
            $stmt->execute();
            $exists = $stmt->fetchColumn() > 0;
            
            if ($exists) {
                $stmt = $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'signature_societe_image'");
                $stmt->execute([$relativePath]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('signature_societe_image', ?, 'string', 'contrats', 'Chemin du fichier de la signature électronique de la société')");
                $stmt->execute([$relativePath]);
            }
            
            // Update enabled status
            $enabled = isset($_POST['signature_enabled']) ? 'true' : 'false';
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM parametres WHERE cle = 'signature_societe_enabled'");
            $stmt->execute();
            $exists = $stmt->fetchColumn() > 0;
            
            if ($exists) {
                $stmt = $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'signature_societe_enabled'");
                $stmt->execute([$enabled]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('signature_societe_enabled', ?, 'boolean', 'contrats', 'Activer l''ajout automatique de la signature société')");
                $stmt->execute([$enabled]);
            }
            
            $_SESSION['success'] = "Signature de la société mise à jour avec succès";
        } else {
            $_SESSION['error'] = "Erreur lors du téléchargement de l'image";
        }
        header('Location: contrat-configuration.php');
        exit;
    }
    elseif ($_POST['action'] === 'delete_signature') {
        // Get current signature path and delete physical file if exists
        $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'signature_societe_image'");
        $stmt->execute();
        $signaturePath = $stmt->fetchColumn();
        
        if (!empty($signaturePath)) {
            // If it's a file path (not a base64 data URI), delete the physical file
            // A file path should not start with 'data:' and should contain 'uploads/signatures/'
            if (strpos($signaturePath, 'data:') !== 0 && strpos($signaturePath, 'uploads/signatures/') !== false) {
                $baseDir = dirname(__DIR__);
                $filepath = $baseDir . '/' . $signaturePath;
                if (file_exists($filepath)) {
                    unlink($filepath);
                    error_log("Deleted company signature file: $filepath");
                }
            }
        }
        
        // Delete signature reference from database
        $stmt = $pdo->prepare("UPDATE parametres SET valeur = '', updated_at = NOW() WHERE cle = 'signature_societe_image'");
        $stmt->execute();
        
        $_SESSION['success'] = "Signature supprimée avec succès";
        header('Location: contrat-configuration.php');
        exit;
    }
    elseif ($_POST['action'] === 'upload_dpe') {
        $logement_id_dpe = (int)($_POST['logement_id'] ?? 0);
        if ($logement_id_dpe <= 0) {
            $_SESSION['error'] = "Logement invalide.";
            header('Location: contrat-configuration.php#dpe');
            exit;
        }
        if (!isset($_FILES['dpe_file']) || $_FILES['dpe_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['error'] = "Erreur lors du téléchargement du fichier DPE.";
            header('Location: contrat-configuration.php#dpe');
            exit;
        }
        $file = $_FILES['dpe_file'];
        // Validate MIME type (PDF only)
        $mimeType = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
        if ($mimeType !== 'application/pdf') {
            $_SESSION['error'] = "Seuls les fichiers PDF sont acceptés pour le DPE.";
            header('Location: contrat-configuration.php#dpe');
            exit;
        }
        // Validate magic bytes (PDF signature: %PDF)
        $handle = fopen($file['tmp_name'], 'rb');
        $magic = $handle ? fread($handle, 4) : '';
        if ($handle) fclose($handle);
        if ($magic !== '%PDF') {
            $_SESSION['error'] = "Le fichier fourni n'est pas un PDF valide.";
            header('Location: contrat-configuration.php#dpe');
            exit;
        }
        // Max 10 MB
        if ($file['size'] > 10 * 1024 * 1024) {
            $_SESSION['error'] = "Le fichier DPE ne doit pas dépasser 10 Mo.";
            header('Location: contrat-configuration.php#dpe');
            exit;
        }
        $baseDir = dirname(__DIR__);
        $uploadsDir = $baseDir . '/uploads/dpe';
        if (!is_dir($uploadsDir)) {
            if (!mkdir($uploadsDir, 0755, true)) {
                $_SESSION['error'] = "Impossible de créer le répertoire pour les fichiers DPE.";
                header('Location: contrat-configuration.php#dpe');
                exit;
            }
        }
        // Delete old DPE file if one exists
        $stmtOld = $pdo->prepare("SELECT dpe_file FROM logements WHERE id = ?");
        $stmtOld->execute([$logement_id_dpe]);
        $oldDpe = $stmtOld->fetchColumn();
        if (!empty($oldDpe)) {
            $oldPath = $baseDir . '/' . $oldDpe;
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }
        // Save new DPE file with a random component to prevent filename enumeration
        $filename = 'dpe_' . $logement_id_dpe . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.pdf';
        $filepath = $uploadsDir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            $_SESSION['error'] = "Impossible de sauvegarder le fichier DPE.";
            header('Location: contrat-configuration.php#dpe');
            exit;
        }
        $relativePath = 'uploads/dpe/' . $filename;
        $pdo->prepare("UPDATE logements SET dpe_file = ? WHERE id = ?")->execute([$relativePath, $logement_id_dpe]);
        $_SESSION['success'] = "Fichier DPE enregistré avec succès.";
        header('Location: contrat-configuration.php#dpe');
        exit;
    }
    elseif ($_POST['action'] === 'delete_dpe') {
        $logement_id_dpe = (int)($_POST['logement_id'] ?? 0);
        if ($logement_id_dpe > 0) {
            $stmtOld = $pdo->prepare("SELECT dpe_file FROM logements WHERE id = ?");
            $stmtOld->execute([$logement_id_dpe]);
            $oldDpe = $stmtOld->fetchColumn();
            if (!empty($oldDpe)) {
                $oldPath = dirname(__DIR__) . '/' . $oldDpe;
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $pdo->prepare("UPDATE logements SET dpe_file = NULL WHERE id = ?")->execute([$logement_id_dpe]);
            $_SESSION['success'] = "Fichier DPE supprimé avec succès.";
        }
        header('Location: contrat-configuration.php#dpe');
        exit;
    }
}

// Get current templates (one per contract type)
function fetchTemplate($pdo, $cle) {
    $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = ?");
    $stmt->execute([$cle]);
    $val = $stmt->fetchColumn();
    return $val ?: '';
}

$templateMeuble    = fetchTemplate($pdo, 'contrat_template_html_meuble');
$templateNonMeuble = fetchTemplate($pdo, 'contrat_template_html_non_meuble');
$templateSurMesure = fetchTemplate($pdo, 'contrat_template_html_sur_mesure');

// Fallback: if no type-specific template yet, use the legacy template for Meublé
if (empty($templateMeuble)) {
    $legacy = fetchTemplate($pdo, 'contrat_template_html');
    $templateMeuble = $legacy ?: getDefaultContractTemplateMeuble();
}
if (empty($templateNonMeuble)) {
    $templateNonMeuble = getDefaultContractTemplateNonMeuble();
}
if (empty($templateSurMesure)) {
    $templateSurMesure = getDefaultContractTemplateSurMesure();
}

// Active tab (from query string after save, default to meuble)
$activeTab = in_array($_GET['tab'] ?? '', ['meuble', 'non_meuble', 'sur_mesure'])
    ? $_GET['tab']
    : 'meuble';

// Préavis annulation
$preavisAnnulation = (int)getParameter('preavis_annulation_jours', 0);
if ($preavisAnnulation < 0) $preavisAnnulation = 0;

// Get jours_avant_impaye parameter
$joursAvantImpaye = (int)getParameter('jours_avant_impaye', 5);
if ($joursAvantImpaye < 1) $joursAvantImpaye = 1;

// Get delai_expiration_lien_contrat parameter
$delaiExpirationLienContrat = (int)getParameter('delai_expiration_lien_contrat', 24);
if ($delaiExpirationLienContrat < 1) $delaiExpirationLienContrat = 1;

// Get signature settings
$stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'signature_societe_image'");
$stmt->execute();
$signatureImage = $stmt->fetchColumn() ?: '';
// If it's a relative file path (not a data URI, absolute URL, or absolute path), prepend '/' to make it absolute from web root
if (!empty($signatureImage) && 
    strpos($signatureImage, 'data:') !== 0 && 
    strpos($signatureImage, 'http://') !== 0 && 
    strpos($signatureImage, 'https://') !== 0 && 
    strpos($signatureImage, '//') !== 0 && 
    strpos($signatureImage, '/') !== 0) {
    $signatureImage = '/' . $signatureImage;
}

$stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'signature_societe_enabled'");
$stmt->execute();
$signatureEnabled = $stmt->fetchColumn() === 'true';

// Fetch all logements with their DPE info for the DPE upload section
$logementsDpe = $pdo->query("SELECT id, reference, adresse, COALESCE(dpe_file, '') as dpe_file FROM logements WHERE deleted_at IS NULL ORDER BY reference ASC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration du Contrat - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- CKEditor 4 LTS -->
    <script src="<?= CKEDITOR_CDN_URL ?>"></script>
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .config-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .variables-info {
            background: #e8f4f8;
            border-left: 4px solid #3498db;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .variables-info h6 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-weight: 600;
        }
        .variable-tag {
            display: inline-block;
            background: #3498db;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.85rem;
            margin: 3px;
            font-family: 'Courier New', monospace;
            cursor: pointer;
            transition: background 0.2s;
        }
        .variable-tag:hover {
            background: #2980b9;
        }
        .code-editor {
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            min-height: 500px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
        }
        .preview-section {
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            padding: 20px;
            background: white;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>
    
    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-file-earmark-code"></i> Configuration du Template de Contrat</h1>
                    <p class="text-muted mb-0">Personnalisez le template HTML du contrat de bail avec des variables dynamiques</p>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Paramètres de gestion des loyers -->
        <div class="config-card">
            <h5 class="mb-3"><i class="bi bi-calendar-check"></i> Paramètres de Gestion des Loyers</h5>
            <p class="text-muted">
                Configurez les règles automatiques de gestion des statuts de paiement.
            </p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_jours_avant_impaye">
                <div class="row align-items-end">
                    <div class="col-md-6">
                        <label for="jours_avant_impaye" class="form-label">
                            <strong>Nombre de jours avant passage en "Impayé"</strong>
                        </label>
                        <input
                            type="number"
                            class="form-control"
                            id="jours_avant_impaye"
                            name="jours_avant_impaye"
                            min="1"
                            max="31"
                            value="<?= htmlspecialchars($joursAvantImpaye) ?>"
                            required>
                        <small class="form-text text-muted">
                            Nombre de jours dans le mois après lequel un loyer dont le statut est "En attente" passe automatiquement en "Impayé". Par défaut : 5.
                        </small>
                    </div>
                    <div class="col-md-6">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Enregistrer
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Délai d'expiration du lien de signature -->
        <div class="config-card">
            <h5 class="mb-3"><i class="bi bi-clock-history"></i> Lien de Signature Électronique</h5>
            <p class="text-muted">
                Configurez la durée de validité du lien envoyé au locataire pour signer le contrat en ligne.
            </p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_delai_expiration">
                <div class="row align-items-end">
                    <div class="col-md-6">
                        <label for="delai_expiration_lien_contrat" class="form-label">
                            <strong>Délai d'expiration du lien de signature (en heures)</strong>
                        </label>
                        <input
                            type="number"
                            class="form-control"
                            id="delai_expiration_lien_contrat"
                            name="delai_expiration_lien_contrat"
                            min="1"
                            max="720"
                            value="<?= htmlspecialchars($delaiExpirationLienContrat) ?>"
                            required>
                        <small class="form-text text-muted">
                            Durée (en heures) pendant laquelle le lien de signature reste valide après son envoi. Par défaut : 24 heures.
                        </small>
                    </div>
                    <div class="col-md-6">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Enregistrer
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Signature Configuration Card — centralisée dans Paramètres -->
        <div class="config-card">
            <h5 class="mb-3"><i class="bi bi-pen"></i> Signature Électronique de la Société</h5>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                La configuration de la signature de la société a été centralisée dans
                <a href="parametres.php#signature-societe" class="alert-link">
                    <i class="bi bi-gear me-1"></i>Paramètres → Signature Société
                </a>.
            </div>
        </div>

        <!-- Critères d'annulation -->
        <div class="config-card">
            <h5 class="mb-3"><i class="bi bi-x-octagon"></i> Critères d'annulation</h5>
            <p class="text-muted">
                Configurez le préavis requis pour l'annulation d'un contrat. Mettez la valeur à 0 pour supprimer la période de préavis obligatoire.
            </p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_preavis">
                <div class="row align-items-end">
                    <div class="col-md-6">
                        <label for="preavis_annulation_jours" class="form-label">
                            <strong>Préavis d'annulation (en jours)</strong>
                        </label>
                        <input
                            type="number"
                            class="form-control"
                            id="preavis_annulation_jours"
                            name="preavis_annulation_jours"
                            min="0"
                            max="365"
                            value="<?= htmlspecialchars($preavisAnnulation) ?>"
                            required>
                        <small class="form-text text-muted">
                            Nombre de jours de préavis requis avant l'annulation effective d'un contrat.
                            <strong>0 = suppression de la période</strong> (annulation immédiate possible).
                        </small>
                    </div>
                    <div class="col-md-6">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Enregistrer
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- DPE (Diagnostic de Performance Énergétique) -->
        <div class="config-card" id="dpe">
            <h5 class="mb-3"><i class="bi bi-file-earmark-pdf text-danger"></i> DPE — Diagnostic de Performance Énergétique</h5>
            <p class="text-muted">
                Associez le fichier DPE (PDF) à chaque logement. Ce fichier sera automatiquement joint en pièce jointe lors de l'envoi de l'email d'invitation à signer le contrat.
            </p>
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Référence</th>
                            <th>Adresse</th>
                            <th>DPE actuel</th>
                            <th>Uploader / Remplacer</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logementsDpe as $log): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($log['reference']) ?></strong></td>
                            <td><?= htmlspecialchars($log['adresse']) ?></td>
                            <td>
                                <?php if (!empty($log['dpe_file'])): ?>
                                    <a href="/<?= htmlspecialchars($log['dpe_file']) ?>" target="_blank" class="text-success">
                                        <i class="bi bi-file-earmark-pdf me-1"></i>Voir le DPE
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted"><i class="bi bi-dash"></i> Aucun</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" enctype="multipart/form-data" class="d-flex align-items-center gap-2">
                                    <input type="hidden" name="action" value="upload_dpe">
                                    <input type="hidden" name="logement_id" value="<?= (int)$log['id'] ?>">
                                    <input type="file" name="dpe_file" accept="application/pdf" class="form-control form-control-sm" required style="max-width:250px;">
                                    <button type="submit" class="btn btn-sm btn-primary text-nowrap">
                                        <i class="bi bi-upload"></i> Enregistrer
                                    </button>
                                </form>
                            </td>
                            <td>
                                <?php if (!empty($log['dpe_file'])): ?>
                                    <form method="POST" onsubmit="return confirm('Supprimer le DPE de ce logement ?');">
                                        <input type="hidden" name="action" value="delete_dpe">
                                        <input type="hidden" name="logement_id" value="<?= (int)$log['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i> Supprimer
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($logementsDpe)): ?>
                        <tr><td colspan="5" class="text-center text-muted">Aucun logement trouvé.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Template Configuration Card — onglets par type de contrat -->
        <div class="config-card">
            <div class="variables-info">
                <h6><i class="bi bi-info-circle"></i> Variables disponibles</h6>
                <p class="mb-2">Cliquez sur une variable pour la copier. Utilisez ces variables dans le template HTML :</p>
                <div>
                    <span class="variable-tag" onclick="copyVariable('{{reference_unique}}')">{{reference_unique}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{locataires_info}}')">{{locataires_info}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{signatures_table}}')">{{signatures_table}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{locataires_signatures}}')">{{locataires_signatures}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{signature_agence}}')">{{signature_agence}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{adresse}}')">{{adresse}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{type}}')">{{type}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{surface}}')">{{surface}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{parking}}')">{{parking}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{date_prise_effet}}')">{{date_prise_effet}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{loyer}}')">{{loyer}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{charges}}')">{{charges}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{loyer_total}}')">{{loyer_total}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{depot_garantie}}')">{{depot_garantie}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{iban}}')">{{iban}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{bic}}')">{{bic}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{date_signature}}')">{{date_signature}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{duree_garantie}}')">{{duree_garantie}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{dpe_classe}}')">{{dpe_classe}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{ges_classe}}')">{{ges_classe}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{dpe_numero}}')">{{dpe_numero}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{dpe_validite}}')">{{dpe_validite}}</span>
                </div>
            </div>

            <!-- Tab navigation -->
            <ul class="nav nav-tabs mt-3 mb-3" id="templateTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $activeTab === 'meuble' ? 'active' : '' ?>"
                            id="tab-meuble" data-bs-toggle="tab" data-bs-target="#pane-meuble"
                            type="button" role="tab">
                        <i class="bi bi-house-fill"></i> Meublé
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $activeTab === 'non_meuble' ? 'active' : '' ?>"
                            id="tab-non-meuble" data-bs-toggle="tab" data-bs-target="#pane-non-meuble"
                            type="button" role="tab">
                        <i class="bi bi-house"></i> Non meublé
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $activeTab === 'sur_mesure' ? 'active' : '' ?>"
                            id="tab-sur-mesure" data-bs-toggle="tab" data-bs-target="#pane-sur-mesure"
                            type="button" role="tab">
                        <i class="bi bi-pencil-square"></i> Sur mesure
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="templateTabContent">
                <!-- Meublé -->
                <div class="tab-pane fade <?= $activeTab === 'meuble' ? 'show active' : '' ?>"
                     id="pane-meuble" role="tabpanel">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_template">
                        <input type="hidden" name="template_type" value="meuble">
                        <div class="mb-3">
                            <textarea class="form-control code-editor" id="template_meuble"
                                      name="template_html" required><?= htmlspecialchars($templateMeuble) ?></textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Enregistrer
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="showPreview('template_meuble')">
                                <i class="bi bi-eye"></i> Prévisualiser
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="resetToDefault('meuble')">
                                <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Non meublé -->
                <div class="tab-pane fade <?= $activeTab === 'non_meuble' ? 'show active' : '' ?>"
                     id="pane-non-meuble" role="tabpanel">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_template">
                        <input type="hidden" name="template_type" value="non_meuble">
                        <div class="mb-3">
                            <textarea class="form-control code-editor" id="template_non_meuble"
                                      name="template_html" required><?= htmlspecialchars($templateNonMeuble) ?></textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Enregistrer
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="showPreview('template_non_meuble')">
                                <i class="bi bi-eye"></i> Prévisualiser
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="resetToDefault('non_meuble')">
                                <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Sur mesure -->
                <div class="tab-pane fade <?= $activeTab === 'sur_mesure' ? 'show active' : '' ?>"
                     id="pane-sur-mesure" role="tabpanel">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_template">
                        <input type="hidden" name="template_type" value="sur_mesure">
                        <div class="mb-3">
                            <textarea class="form-control code-editor" id="template_sur_mesure"
                                      name="template_html" required><?= htmlspecialchars($templateSurMesure) ?></textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Enregistrer
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="showPreview('template_sur_mesure')">
                                <i class="bi bi-eye"></i> Prévisualiser
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="resetToDefault('sur_mesure')">
                                <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="config-card" id="preview-card" style="display: none;">
            <h5><i class="bi bi-eye"></i> Prévisualisation</h5>
            <div class="preview-section" id="preview-content"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deleteSignature() {
            if (confirm('Êtes-vous sûr de vouloir supprimer la signature de la société ?\n\nCette action ne peut pas être annulée.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_signature';
                
                form.appendChild(actionInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function copyVariable(variable) {
            navigator.clipboard.writeText(variable).then(() => {
                // Show temporary success message
                const toast = document.createElement('div');
                toast.className = 'position-fixed bottom-0 end-0 p-3';
                toast.style.zIndex = '9999';
                toast.innerHTML = `
                    <div class="toast show" role="alert">
                        <div class="toast-header">
                            <i class="bi bi-check-circle text-success me-2"></i>
                            <strong class="me-auto">Copié!</strong>
                            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
                        </div>
                        <div class="toast-body">
                            ${variable} copié dans le presse-papier
                        </div>
                    </div>
                `;
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 3000);
            });
        }

        function showPreview(editorId) {
            const editorInstance = CKEDITOR.instances[editorId];
            const template = editorInstance ? editorInstance.getData() : (document.getElementById(editorId) ? document.getElementById(editorId).value : '');
            const previewCard = document.getElementById('preview-card');
            const previewContent = document.getElementById('preview-content');

            let preview = template
                .replace(/\{\{reference_unique\}\}/g, 'BAIL-2024-001')
                .replace(/\{\{locataires_info\}\}/g, 'Jean DUPONT, né(e) le 01/01/1990<br>Email : jean.dupont@example.com')
                .replace(/\{\{signatures_table\}\}/g, '<table style="width: 100%; border-collapse: collapse; border: none;"><tr style="vertical-align: top;"><td style="width: 50%; padding: 10px; border: none;"><p style="margin: 0 0 10px 0;"><strong>Le bailleur</strong></p><p style="margin: 0;">MY INVEST IMMOBILIER<br>Représenté par M. ALEXANDRE<br>Lu et approuvé</p></td><td style="width: 50%; padding: 10px; border: none;"><p style="margin: 0 0 10px 0;"><strong>Locataire :</strong></p><p style="margin: 0;">Jean DUPONT<br>Lu et approuvé</p></td></tr></table>')
                .replace(/\{\{locataires_signatures\}\}/g, 'Jean DUPONT - Lu et approuvé')
                .replace(/\{\{signature_agence\}\}/g, '<p><strong>MY INVEST IMMOBILIER</strong><br>Représenté par M. ALEXANDRE<br>Lu et approuvé</p>')
                .replace(/\{\{adresse\}\}/g, '123 Rue de la République, 74100 Annemasse')
                .replace(/\{\{type\}\}/g, 'T2')
                .replace(/\{\{surface\}\}/g, '45')
                .replace(/\{\{parking\}\}/g, 'Place n°12')
                .replace(/\{\{date_prise_effet\}\}/g, '01/01/2024')
                .replace(/\{\{loyer\}\}/g, '850.00')
                .replace(/\{\{charges\}\}/g, '100.00')
                .replace(/\{\{loyer_total\}\}/g, '950.00')
                .replace(/\{\{depot_garantie\}\}/g, '1,700.00')
                .replace(/\{\{iban\}\}/g, 'FR76 1027 8021 6000 0206 1834 585')
                .replace(/\{\{bic\}\}/g, 'CMCIFR')
                .replace(/\{\{date_signature\}\}/g, '15/12/2023')
                .replace(/\{\{dpe_classe\}\}/g, 'C')
                .replace(/\{\{ges_classe\}\}/g, 'D')
                .replace(/\{\{dpe_numero\}\}/g, '2024-XXXX-XXXX-XXXX')
                .replace(/\{\{dpe_validite\}\}/g, '01/01/2034');

            previewContent.innerHTML = preview;
            previewCard.style.display = 'block';
            previewCard.scrollIntoView({ behavior: 'smooth' });
        }

        const defaultTemplates = {
            meuble:     <?= json_encode(getDefaultContractTemplateMeuble()) ?>,
            non_meuble: <?= json_encode(getDefaultContractTemplateNonMeuble()) ?>,
            sur_mesure: <?= json_encode(getDefaultContractTemplateSurMesure()) ?>
        };

        function resetToDefault(type) {
            if (confirm('Réinitialiser ce template à sa valeur par défaut ? Toutes vos modifications seront perdues.')) {
                const idMap = { meuble: 'template_meuble', non_meuble: 'template_non_meuble', sur_mesure: 'template_sur_mesure' };
                const editorId = idMap[type];
                const editorInstance = CKEDITOR.instances[editorId];
                if (editorInstance) {
                    editorInstance.setData(defaultTemplates[type]);
                } else {
                    document.getElementById(editorId).value = defaultTemplates[type];
                }
            }
        }

        // Initialize CKEditor on all three contract template editors
        const ckConfig = {
            height: 500,
            language: 'fr',
            allowedContent: true,
            toolbar: [
                { name: 'document',    items: ['Source', '-', 'Undo', 'Redo'] },
                { name: 'styles',      items: ['Format'] },
                { name: 'basicstyles', items: ['Bold', 'Italic', 'Underline', 'Strikethrough', 'TextColor', 'BGColor', 'RemoveFormat'] },
                { name: 'paragraph',   items: ['JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyBlock', '-', 'BulletedList', 'NumberedList', '-', 'Outdent', 'Indent'] },
                { name: 'insert',      items: ['Link', 'Unlink', 'Image', 'Table', 'HorizontalRule', 'SpecialChar'] },
                { name: 'tools',       items: ['Maximize'] }
            ],
            contentsCss: 'body { font-family: Arial, sans-serif; font-size: 14px; }',
            removePlugins: 'notification'
        };

        ['template_meuble', 'template_non_meuble', 'template_sur_mesure'].forEach(function(id) {
            CKEDITOR.replace(id, ckConfig);
        });
    </script>
</body>
</html>
