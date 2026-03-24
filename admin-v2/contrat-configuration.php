<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

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
        // Check if parametres table exists and has contrat_template_html
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM parametres WHERE cle = 'contrat_template_html'");
        $stmt->execute();
        $exists = $stmt->fetchColumn() > 0;
        
        if ($exists) {
            // Update existing
            $stmt = $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'contrat_template_html'");
            $stmt->execute([$_POST['template_html']]);
        } else {
            // Insert new
            $stmt = $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('contrat_template_html', ?, 'text', 'contrats', 'Template HTML du contrat avec variables dynamiques')");
            $stmt->execute([$_POST['template_html']]);
        }
        
        $_SESSION['success'] = "Template de contrat mis à jour avec succès";
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
}

// Get current template
$stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'contrat_template_html'");
$stmt->execute();
$template = $stmt->fetchColumn();

// If no template exists, create a default one
if (!$template) {
    $template = getDefaultContractTemplate();
}

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

function getDefaultContractTemplate() {
    return <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Contrat de Bail - {{reference_unique}}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.5;
            color: #000;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        h1 {
            text-align: center;
            font-size: 14pt;
            margin-bottom: 10px;
            font-weight: bold;
        }
        h2 {
            font-size: 11pt;
            margin-top: 20px;
            margin-bottom: 10px;
            font-weight: bold;
        }
        h3 {
            font-size: 10pt;
            margin-top: 15px;
            margin-bottom: 8px;
            font-weight: bold;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .subtitle {
            text-align: center;
            font-style: italic;
            margin-bottom: 30px;
        }
        p {
            margin: 8px 0;
            text-align: justify;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>MY INVEST IMMOBILIER</h1>
    </div>
    
    <div class="subtitle">
        CONTRAT DE BAIL<br>
        (Location meublée – résidence principale)
    </div>

    <h2>1. Parties</h2>
    
    <h3>Bailleur</h3>
    <p>My Invest Immobilier (SCI)<br>
    Représentée par : Maxime ALEXANDRE<br>
    Adresse électronique de notification : contact@myinvest-immobilier.com</p>
    
    <h3>Locataire(s)</h3>
    <p>{{locataires_info}}</p>

    <h2>2. Désignation du logement</h2>
    
    <p><strong>Adresse :</strong><br>
    {{adresse}}</p>
    
    <p><strong>Type :</strong> {{type}} - Logement meublé<br>
    <strong>Surface habitable :</strong> ~ {{surface}} m²<br>
    <strong>Usage :</strong> Résidence principale<br>
    <strong>Parking :</strong> {{parking}}</p>

    <h2>3. Durée</h2>
    
    <p>Le présent contrat est conclu pour une durée de 1 an, à compter du : <strong>{{date_prise_effet}}</strong></p>
    
    <p>Il est renouvelable par tacite reconduction.</p>

    <h2>4. Conditions financières</h2>
    
    <p><strong>Loyer mensuel hors charges :</strong> {{loyer}} €<br>
    <strong>Provision sur charges mensuelles :</strong> {{charges}} €<br>
    <strong>Total mensuel :</strong> {{loyer_total}} €</p>
    
    <p><strong>Modalité de paiement :</strong> mensuel, payable d'avance, au plus tard le 5 de chaque mois.</p>

    <h2>5. Dépôt de garantie</h2>
    
    <p>Le dépôt de garantie, d'un montant de <strong>{{depot_garantie}} €</strong> (correspondant à deux mois de loyer hors charges), est versé à la signature du présent contrat.</p>

    <h2>6. Coordonnées bancaires</h2>
    
    <p><strong>IBAN :</strong> {{iban}}<br>
    <strong>BIC :</strong> {{bic}}<br>
    <strong>Titulaire :</strong> MY INVEST IMMOBILIER</p>

    <h2>7. Signatures</h2>
    
    <p>Fait à Annemasse, le {{date_signature}}</p>
    
    {{signatures_table}}

    <div class="footer" style="margin-top: 40px; font-size: 8pt; text-align: center; color: #666;">
        <p>Document généré électroniquement par My Invest Immobilier</p>
        <p>Contrat de bail - Référence : {{reference_unique}}</p>
    </div>
</body>
</html>
HTML;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration du Contrat - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- TinyMCE Cloud - API key is public and domain-restricted -->
    <script src="https://cdn.tiny.cloud/1/odjqanpgdv2zolpduplee65ntoou1b56hg6gvgxvrt8dreh0/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
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

        <!-- Template Configuration Card -->
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
                </div>
                <p class="mt-3 mb-0 small"><strong>Note importante :</strong> Ce template HTML est utilisé pour l'affichage et la configuration uniquement. <strong>Le PDF du contrat est généré par un processus séparé</strong> qui utilise une mise en page prédéfinie. Les modifications de ce template n'auront aucun effet sur le format du PDF final.</p>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="action" value="update_template">
                
                <div class="mb-3">
                    <label for="template_html" class="form-label">
                        <strong>Template HTML du Contrat</strong>
                    </label>
                    <textarea 
                        class="form-control code-editor" 
                        id="template_html" 
                        name="template_html" 
                        required><?= htmlspecialchars($template) ?></textarea>
                    <small class="form-text text-muted">
                        Modifiez le code HTML ci-dessus. Les variables seront remplacées automatiquement lors de la génération du contrat.
                    </small>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Enregistrer le Template
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="showPreview()">
                        <i class="bi bi-eye"></i> Prévisualiser
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="resetToDefault()">
                        <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser par défaut
                    </button>
                </div>
            </form>
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

        function showPreview() {
            const template = document.getElementById('template_html').value;
            const previewCard = document.getElementById('preview-card');
            const previewContent = document.getElementById('preview-content');
            
            // Replace variables with sample data
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
                .replace(/\{\{date_signature\}\}/g, '15/12/2023');
            
            previewContent.innerHTML = preview;
            previewCard.style.display = 'block';
            previewCard.scrollIntoView({ behavior: 'smooth' });
        }

        function resetToDefault() {
            if (confirm('Êtes-vous sûr de vouloir réinitialiser le template à sa valeur par défaut ? Toutes vos modifications seront perdues.')) {
                location.href = '?reset=1';
            }
        }

        // Handle reset
        <?php if (isset($_GET['reset'])): ?>
        document.getElementById('template_html').value = <?= json_encode(getDefaultContractTemplate()) ?>;
        <?php endif; ?>

        // Initialize TinyMCE on the contract template editor
        tinymce.init({
            selector: 'textarea.code-editor',
            height: 500,
            menubar: true,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'help', 'wordcount'
            ],
            toolbar: 'undo redo | blocks | bold italic forecolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | code | help',
            content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; }',
            branding: false,
            promotion: false,
            // Preserve full HTML document structure including <html>, <head>, <style> tags
            verify_html: false,
            extended_valid_elements: 'style,link[href|rel],head,html[lang],meta[*],body[*]',
            valid_children: '+body[style],+head[style]',
            // Don't remove tags or attributes
            forced_root_block: false,
            // Preserve DOCTYPE and full document structure
            doctype: '<!DOCTYPE html>',
            setup: function(editor) {
                editor.on('init', function() {
                    console.log('TinyMCE initialized successfully on contract template');
                });
            }
        });
    </script>
</body>
</html>
