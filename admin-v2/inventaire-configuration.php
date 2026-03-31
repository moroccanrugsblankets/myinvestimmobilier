<?php
/**
 * Inventaire Configuration - Template Management
 * My Invest Immobilier
 */
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Update inventory templates
        if (isset($_POST['inventaire_template_html'])) {
            $stmt = $pdo->prepare("
                UPDATE parametres SET valeur = ? WHERE cle = 'inventaire_template_html'
            ");
            $stmt->execute([$_POST['inventaire_template_html']]);
        }
        
        if (isset($_POST['inventaire_sortie_template_html'])) {
            $stmt = $pdo->prepare("
                UPDATE parametres SET valeur = ? WHERE cle = 'inventaire_sortie_template_html'
            ");
            $stmt->execute([$_POST['inventaire_sortie_template_html']]);
        }
        
        $_SESSION['success'] = "Configuration mise à jour avec succès";
        header('Location: inventaire-configuration.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Erreur lors de la mise à jour: " . $e->getMessage();
        error_log("Erreur config inventaire: " . $e->getMessage());
    }
}

// Get current templates
$stmt = $pdo->query("SELECT cle, valeur FROM parametres WHERE cle IN ('inventaire_template_html', 'inventaire_sortie_template_html')");
$templates = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $templates[$row['cle']] = $row['valeur'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration Inventaire - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- CKEditor 4 -->
    <script src="https://cdn.ckeditor.com/4.22.1/full/ckeditor.js"></script>
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
        .preview-section table {
            border-collapse: collapse;
            width: 100%;
        }
        .preview-section th,
        .preview-section td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .preview-section th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .copy-tooltip {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            z-index: 10000;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4><i class="bi bi-gear"></i> Configuration de l'Inventaire</h4>
                    <p class="text-muted mb-0">Personnalisation des templates PDF d'inventaire</p>
                </div>
                <a href="inventaires.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Retour aux inventaires
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="config-card">
                <h5 class="mb-4">
                    <i class="bi bi-box-arrow-in-right text-success"></i> Template d'inventaire d'entrée
                </h5>
                <div class="variables-info">
                    <h6><i class="bi bi-info-circle"></i> Variables disponibles</h6>
                    <p class="mb-2">Cliquez sur une variable pour la copier. Utilisez ces variables dans le template HTML :</p>
                    <div>
                        <span class="variable-tag" onclick="copyVariable('{{reference}}')">{{reference}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{date}}')">{{date}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{adresse}}')">{{adresse}}</span>
                        
                        <span class="variable-tag" onclick="copyVariable('{{locataire_nom}}')">{{locataire_nom}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{equipements}}')">{{equipements}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{observations}}')">{{observations}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{lieu_signature}}')">{{lieu_signature}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{date_signature}}')">{{date_signature}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{signatures_table}}')">{{signatures_table}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{signature_agence}}')">{{signature_agence}}</span>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="inventaire_template_html" class="form-label"><strong>Template HTML de l'Inventaire d'Entrée</strong></label>
                    <textarea 
                        class="form-control code-editor" 
                        id="inventaire_template_html" 
                        name="inventaire_template_html"><?php echo htmlspecialchars($templates['inventaire_template_html'] ?? ''); ?></textarea>
                    <small class="form-text text-muted">
                        Modifiez le code HTML ci-dessus. Les variables seront remplacées automatiquement lors de la génération de l'inventaire d'entrée.
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-save"></i> Enregistrer le Template d'Entrée
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="showPreview('inventaire_template_html', 'preview-card-entree')">
                        <i class="bi bi-eye"></i> Prévisualiser
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="resetToDefault('inventaire_template_html')">
                        <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser par défaut
                    </button>
                </div>
            </div>

            <div class="config-card">
                <h5 class="mb-4">
                    <i class="bi bi-box-arrow-right text-danger"></i> Template d'inventaire de sortie
                </h5>
                <div class="variables-info">
                    <h6><i class="bi bi-info-circle"></i> Variables disponibles</h6>
                    <p class="mb-2">Cliquez sur une variable pour la copier. Utilisez ces variables dans le template HTML :</p>
                    <div>
                        <span class="variable-tag" onclick="copyVariable('{{reference}}')">{{reference}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{date}}')">{{date}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{adresse}}')">{{adresse}}</span>
                        
                        <span class="variable-tag" onclick="copyVariable('{{locataire_nom}}')">{{locataire_nom}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{equipements}}')">{{equipements}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{comparaison}}')">{{comparaison}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{observations}}')">{{observations}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{lieu_signature}}')">{{lieu_signature}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{date_signature}}')">{{date_signature}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{signatures_table}}')">{{signatures_table}}</span>
                        <span class="variable-tag" onclick="copyVariable('{{signature_agence}}')">{{signature_agence}}</span>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="inventaire_sortie_template_html" class="form-label"><strong>Template HTML de l'Inventaire de Sortie</strong></label>
                    <textarea 
                        class="form-control code-editor" 
                        id="inventaire_sortie_template_html" 
                        name="inventaire_sortie_template_html"><?php echo htmlspecialchars($templates['inventaire_sortie_template_html'] ?? ''); ?></textarea>
                    <small class="form-text text-muted">
                        Modifiez le code HTML ci-dessus. Les variables seront remplacées automatiquement lors de la génération de l'inventaire de sortie.
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-save"></i> Enregistrer le Template de Sortie
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="showPreview('inventaire_sortie_template_html', 'preview-card-sortie')">
                        <i class="bi bi-eye"></i> Prévisualiser
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="resetToDefault('inventaire_sortie_template_html')">
                        <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser par défaut
                    </button>
                </div>
            </div>


            <div class="d-flex justify-content-between">
                <a href="inventaires.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Annuler
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Enregistrer la configuration
                </button>
            </div>
        </form>

        <div class="config-card" id="preview-card-entree" style="display: none;">
            <h5><i class="bi bi-eye"></i> Prévisualisation - Inventaire d'Entrée</h5>
            <div class="preview-section" id="preview-content-entree"></div>
        </div>

        <div class="config-card" id="preview-card-sortie" style="display: none;">
            <h5><i class="bi bi-eye"></i> Prévisualisation - Inventaire de Sortie</h5>
            <div class="preview-section" id="preview-content-sortie"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize CKEditor for entry template
        CKEDITOR.replace('inventaire_template_html', {
            height: 500,
            language: 'fr',
            allowedContent: true,
            toolbar: [
                { name: 'document',    items: ['Source', '-', 'Undo', 'Redo'] },
                { name: 'styles',      items: ['Format'] },
                { name: 'basicstyles', items: ['Bold', 'Italic', 'BGColor', 'RemoveFormat'] },
                { name: 'paragraph',   items: ['JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyBlock', '-', 'BulletedList', 'NumberedList', '-', 'Outdent', 'Indent'] },
                { name: 'insert',      items: ['Link', 'Unlink', 'Table'] },
                { name: 'tools',       items: ['Maximize'] }
            ],
            contentsCss: 'body { font-family: Arial, sans-serif; font-size: 10pt; } table { border-collapse: collapse; width: 100%; } th, td { border: 1px solid #ddd; padding: 8px; text-align: left; } th { background-color: #f2f2f2; font-weight: bold; }'
        });

        // Initialize CKEditor for exit template
        CKEDITOR.replace('inventaire_sortie_template_html', {
            height: 500,
            language: 'fr',
            allowedContent: true,
            toolbar: [
                { name: 'document',    items: ['Source', '-', 'Undo', 'Redo'] },
                { name: 'styles',      items: ['Format'] },
                { name: 'basicstyles', items: ['Bold', 'Italic', 'BGColor', 'RemoveFormat'] },
                { name: 'paragraph',   items: ['JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyBlock', '-', 'BulletedList', 'NumberedList', '-', 'Outdent', 'Indent'] },
                { name: 'insert',      items: ['Link', 'Unlink', 'Table'] },
                { name: 'tools',       items: ['Maximize'] }
            ],
            contentsCss: 'body { font-family: Arial, sans-serif; font-size: 10pt; } table { border-collapse: collapse; width: 100%; } th, td { border: 1px solid #ddd; padding: 8px; text-align: left; } th { background-color: #f2f2f2; font-weight: bold; }'
        });

        function copyVariable(variable) {
            if (!navigator.clipboard) {
                alert('La copie dans le presse-papiers n\'est pas disponible dans ce navigateur.');
                return;
            }
            
            navigator.clipboard.writeText(variable).then(() => {
                // Show a small tooltip notification
                const tooltip = document.createElement('div');
                tooltip.textContent = 'Copié !';
                tooltip.className = 'copy-tooltip';
                document.body.appendChild(tooltip);
                
                setTimeout(() => {
                    document.body.removeChild(tooltip);
                }, 1000);
            }).catch(err => {
                console.error('Erreur lors de la copie:', err);
                alert('Impossible de copier dans le presse-papiers. Veuillez copier manuellement: ' + variable);
            });
        }

        function showPreview(editorId, previewCardId) {
            const editor = CKEDITOR.instances[editorId];
            if (!editor) {
                alert('L\'éditeur n\'est pas encore chargé. Veuillez réessayer dans quelques instants.');
                return;
            }
            
            const content = editor.getData();
            // Map preview card IDs to their corresponding content IDs
            const contentIdMap = {
                'preview-card-entree': 'preview-content-entree',
                'preview-card-sortie': 'preview-content-sortie'
            };
            const previewContentId = contentIdMap[previewCardId];
            
            if (!previewContentId) {
                console.error('Invalid preview card ID:', previewCardId);
                return;
            }
            
            const previewElement = document.getElementById(previewContentId);
            if (previewElement) {
                // Note: content rendered from CKEditor; be aware this renders HTML
                // For production, consider additional sanitization if needed
                previewElement.innerHTML = content;
                document.getElementById(previewCardId).style.display = 'block';
                document.getElementById(previewCardId).scrollIntoView({ behavior: 'smooth' });
            }
        }

        function resetToDefault(editorId) {
            if (confirm('Êtes-vous sûr de vouloir réinitialiser le template avec la version par défaut ? Toutes vos modifications seront perdues.')) {
                const editor = CKEDITOR.instances[editorId];
                if (!editor) {
                    alert('L\'éditeur n\'est pas encore chargé. Veuillez réessayer dans quelques instants.');
                    return;
                }
                
                editor.setData('');
                alert('Template réinitialisé. N\'oubliez pas de sauvegarder pour appliquer les changements.');
            }
        }
    </script>
</body>
</html>
