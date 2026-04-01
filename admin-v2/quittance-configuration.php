<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../pdf/generate-quittance.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_template') {
        // Check if parametres table exists and has quittance_template_html
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM parametres WHERE cle = 'quittance_template_html'");
        $stmt->execute();
        $exists = $stmt->fetchColumn() > 0;
        
        if ($exists) {
            // Update existing
            $stmt = $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'quittance_template_html'");
            $stmt->execute([$_POST['template_html']]);
        } else {
            // Insert new
            $stmt = $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('quittance_template_html', ?, 'text', 'quittances', 'Template HTML de la quittance avec variables dynamiques')");
            $stmt->execute([$_POST['template_html']]);
        }
        
        $_SESSION['success'] = "Template de quittance mis à jour avec succès";
        header('Location: quittance-configuration.php');
        exit;
    }
    elseif ($_POST['action'] === 'reset_template') {
        // Reset to default template
        $defaultTemplate = getDefaultQuittanceTemplate();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM parametres WHERE cle = 'quittance_template_html'");
        $stmt->execute();
        $exists = $stmt->fetchColumn() > 0;
        
        if ($exists) {
            $stmt = $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'quittance_template_html'");
            $stmt->execute([$defaultTemplate]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('quittance_template_html', ?, 'text', 'quittances', 'Template HTML de la quittance avec variables dynamiques')");
            $stmt->execute([$defaultTemplate]);
        }
        
        $_SESSION['success'] = "Template de quittance réinitialisé au modèle par défaut";
        header('Location: quittance-configuration.php');
        exit;
    }
}

// Get current template
$stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'quittance_template_html'");
$stmt->execute();
$currentTemplate = $stmt->fetchColumn();

if (empty($currentTemplate)) {
    $currentTemplate = getDefaultQuittanceTemplate();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration des Quittances - Admin MyInvest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- CKEditor 4 LTS -->
    <script src="<?= CKEDITOR_CDN_URL ?>"></script>
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .header {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .config-card {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .config-card h5 {
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
        }
        .variable-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 5px 10px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            margin: 3px;
            display: inline-block;
            cursor: pointer;
            transition: background 0.2s;
        }
        .variable-badge:hover {
            background: #bbdefb;
        }
        
        /* Fallback styling for textarea in case CKEditor fails to load */
        #template_html {
            min-height: 500px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }

        .info-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4><i class="bi bi-receipt"></i> Configuration des Quittances de Loyer</h4>
                    <p class="mb-0 text-muted">Personnalisez le template PDF des quittances</p>
                </div>
                <a href="contrats.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Retour aux contrats
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Variables disponibles -->
        <div class="config-card">
            <h5><i class="bi bi-code-square"></i> Variables Disponibles</h5>
            <p>Cliquez sur une variable pour la copier dans le presse-papier :</p>
            
            <div class="row">
                <div class="col-md-6">
                    <h6 class="mt-3">Informations Quittance</h6>
                    <span class="variable-badge" onclick="copyToClipboard('{{reference_quittance}}', event)" title="Référence de la quittance">{{reference_quittance}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{periode}}', event)" title="Période (mois et année)">{{periode}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{mois}}', event)" title="Mois">{{mois}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{annee}}', event)" title="Année">{{annee}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{date_generation}}', event)" title="Date de génération">{{date_generation}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{date_debut_periode}}', event)" title="Date de début de période">{{date_debut_periode}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{date_fin_periode}}', event)" title="Date de fin de période">{{date_fin_periode}}</span>
                    
                    <h6 class="mt-3">Montants</h6>
                    <span class="variable-badge" onclick="copyToClipboard('{{montant_loyer}}', event)" title="Montant du loyer">{{montant_loyer}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{montant_charges}}', event)" title="Montant des charges">{{montant_charges}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{montant_total}}', event)" title="Montant total">{{montant_total}}</span>
                </div>
                
                <div class="col-md-6">
                    <h6 class="mt-3">Locataires</h6>
                    <span class="variable-badge" onclick="copyToClipboard('{{locataires_noms}}', event)" title="Noms complets des locataires">{{locataires_noms}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{locataire_nom}}', event)" title="Nom du locataire">{{locataire_nom}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{locataire_prenom}}', event)" title="Prénom du locataire">{{locataire_prenom}}</span>
                    
                    <h6 class="mt-3">Logement</h6>
                    <span class="variable-badge" onclick="copyToClipboard('{{adresse}}', event)" title="Adresse du logement">{{adresse}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{logement_reference}}', event)" title="Référence du logement">{{logement_reference}}</span>
                    
                    <h6 class="mt-3">Société</h6>
                    <span class="variable-badge" onclick="copyToClipboard('{{nom_societe}}', event)" title="Nom de la société">{{nom_societe}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{adresse_societe}}', event)" title="Adresse de la société">{{adresse_societe}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{tel_societe}}', event)" title="Téléphone de la société">{{tel_societe}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{email_societe}}', event)" title="Email de la société">{{email_societe}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{signature_societe}}', event)" title="Signature de la société (image base64)">{{signature_societe}}</span>
                </div>
            </div>
        </div>

        <!-- Template HTML Editor -->
        <div class="config-card">
            <h5><i class="bi bi-file-code"></i> Template HTML de la Quittance</h5>
            
            <div class="info-box">
                <i class="bi bi-info-circle"></i>
                <strong>Information:</strong> Le template utilise HTML et CSS standard. 
                TCPDF supporte un sous-ensemble du CSS. Évitez les propriétés CSS avancées comme flexbox ou grid.
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_template">
                
                <div class="mb-3">
                    <label for="template_html" class="form-label">Code HTML du Template</label>
                    <textarea 
                        id="template_html" 
                        name="template_html" 
                        aria-describedby="template_help"
                        required><?php echo htmlspecialchars($currentTemplate); ?></textarea>
                    <div id="template_help" class="form-text">
                        Le template doit être un document HTML complet avec les balises &lt;html&gt;, &lt;head&gt;, &lt;body&gt;.
                    </div>
                </div>
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Enregistrer le Template
                    </button>
                    <button type="button" class="btn btn-warning" id="resetTemplateBtn">
                        <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser au modèle par défaut
                    </button>
                </div>
            </form>
            
            <!-- Hidden form for reset -->
            <form id="resetForm" method="POST" action="" style="display: none;">
                <input type="hidden" name="action" value="reset_template">
            </form>
        </div>

        <!-- Guide d'utilisation -->
        <div class="config-card">
            <h5><i class="bi bi-book"></i> Guide d'Utilisation</h5>
            
            <div class="accordion" id="guideAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#guide1">
                            Structure du Template
                        </button>
                    </h2>
                    <div id="guide1" class="accordion-collapse collapse show" data-bs-parent="#guideAccordion">
                        <div class="accordion-body">
                            <p>Le template doit suivre la structure HTML standard :</p>
                            <pre><code>&lt;!DOCTYPE html&gt;
&lt;html lang="fr"&gt;
&lt;head&gt;
    &lt;meta charset="UTF-8"&gt;
    &lt;title&gt;Quittance de Loyer&lt;/title&gt;
    &lt;style&gt;
        /* Votre CSS ici */
    &lt;/style&gt;
&lt;/head&gt;
&lt;body&gt;
    &lt;!-- Votre contenu avec les variables --&gt;
&lt;/body&gt;
&lt;/html&gt;</code></pre>
                        </div>
                    </div>
                </div>
                
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#guide2">
                            Utilisation des Variables
                        </button>
                    </h2>
                    <div id="guide2" class="accordion-collapse collapse" data-bs-parent="#guideAccordion">
                        <div class="accordion-body">
                            <p>Utilisez les variables avec la syntaxe <code>{{nom_variable}}</code> :</p>
                            <pre><code>&lt;p&gt;Locataire : {{locataires_noms}}&lt;/p&gt;
&lt;p&gt;Montant total : {{montant_total}} €&lt;/p&gt;
&lt;p&gt;Période : {{periode}}&lt;/p&gt;</code></pre>
                            <p class="mt-3"><strong>Pour afficher la signature de la société :</strong></p>
                            <pre><code>&lt;img src="{{signature_societe}}" style="width: 150px; height: auto;" alt="Signature" /&gt;</code></pre>
                            <p><small class="text-muted">Note: La signature doit être configurée dans Configuration des Contrats.</small></p>
                        </div>
                    </div>
                </div>
                
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#guide3">
                            Bonnes Pratiques CSS
                        </button>
                    </h2>
                    <div id="guide3" class="accordion-collapse collapse" data-bs-parent="#guideAccordion">
                        <div class="accordion-body">
                            <p>TCPDF supporte un sous-ensemble limité de CSS. Utilisez :</p>
                            <ul>
                                <li>Propriétés de base : <code>font-family</code>, <code>font-size</code>, <code>color</code>, <code>background-color</code></li>
                                <li>Marges et padding : <code>margin</code>, <code>padding</code></li>
                                <li>Bordures : <code>border</code>, <code>border-collapse</code></li>
                                <li>Tables : <code>&lt;table&gt;</code> avec CSS inline ou style</li>
                                <li>Texte : <code>text-align</code>, <code>font-weight</code>, <code>font-style</code></li>
                            </ul>
                            <p><strong>Évitez :</strong> flexbox, grid, position absolute, float complexe</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize CKEditor
        CKEDITOR.replace('template_html', {
            height: 600,
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
        });

        // Copy to clipboard function (namespaced to avoid global pollution)
        (function() {
            'use strict';
            
            window.copyToClipboard = function(text, event) {
                // Sanitize text to prevent XSS (escape HTML entities)
                var sanitizedText = String(text).replace(/[&<>"']/g, function(match) {
                    var escapeChars = {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#39;'
                    };
                    return escapeChars[match];
                });
                
                // Check if clipboard API is available
                if (!navigator.clipboard) {
                    // Create a temporary element to show the text safely
                    var tempDiv = document.createElement('div');
                    tempDiv.textContent = 'La copie automatique n\'est pas disponible. Veuillez copier manuellement: ' + text;
                    alert(tempDiv.textContent);
                    return;
                }
                
                navigator.clipboard.writeText(text).then(function() {
                    // Show temporary success message
                    var originalText = event.target.textContent;
                    event.target.textContent = '✓ Copié !';
                    setTimeout(function() {
                        event.target.textContent = originalText;
                    }, 1000);
                }, function(err) {
                    console.error('Erreur lors de la copie: ', err);
                    var tempDiv = document.createElement('div');
                    tempDiv.textContent = 'Erreur lors de la copie. Veuillez copier manuellement: ' + text;
                    alert(tempDiv.textContent);
                });
            };
        })();

        // Handle reset template button
        document.getElementById('resetTemplateBtn').addEventListener('click', function() {
            if(confirm('Êtes-vous sûr de vouloir réinitialiser le template au modèle par défaut ?')) {
                document.getElementById('resetForm').submit();
            }
        });
    </script>
</body>
</html>
