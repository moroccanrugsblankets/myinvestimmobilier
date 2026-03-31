<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_template') {
        // Check if parametres table exists and has bilan_logement_template_html
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM parametres WHERE cle = 'bilan_logement_template_html'");
        $stmt->execute();
        $exists = $stmt->fetchColumn() > 0;
        
        if ($exists) {
            // Update existing
            $stmt = $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'bilan_logement_template_html'");
            $stmt->execute([$_POST['template_html']]);
        } else {
            // Insert new
            $stmt = $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('bilan_logement_template_html', ?, 'text', 'bilan_logement', 'Template HTML du bilan de logement avec variables dynamiques')");
            $stmt->execute([$_POST['template_html']]);
        }
        
        $_SESSION['success'] = "Template du bilan de logement mis à jour avec succès";
        header('Location: bilan-logement-configuration.php');
        exit;
    }
}

// Get current template
$stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'bilan_logement_template_html'");
$stmt->execute();
$template = $stmt->fetchColumn();

// If no template exists, provide a default one
if (!$template) {
    $template = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.4; color: #333; margin: 20px; }
        .header { text-align: center; margin-bottom: 20px; }
        .header img { max-width: 200px; margin-bottom: 10px; }
        .header h1 { color: #2c3e50; margin: 10px 0; line-height: 1.2; }
        .info-section { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 15px; }
        .info-section h2 { color: #3498db; margin-top: 0; margin-bottom: 10px; line-height: 1.2; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .info-item { padding: 4px 0; line-height: 1.4; }
        .info-item strong { color: #2c3e50; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        table th { background: #3498db; color: white; padding: 8px; text-align: left; font-size: 10pt; line-height: 1.3; }
        table td { border: 1px solid #ddd; padding: 6px; font-size: 10pt; line-height: 1.3; }
        table tr:nth-child(even) { background: #f8f9fa; }
        .commentaire-section { margin: 15px 0; padding: 12px; background: #f9f9f9; }
        .total-section { margin-top: 20px; padding: 15px; background: #e8f4f8; border-radius: 5px; }
        .total-section h3 { color: #2c3e50; margin-top: 0; margin-bottom: 8px; line-height: 1.2; }
        .total-section p { margin: 4px 0; line-height: 1.4; }
        .signature-section { margin-top: 30px; padding: 15px; }
        .signature-section p { margin: 4px 0; line-height: 1.4; }
    </style>
</head>
<body>
    <div class="header">
        {{logo}}
        <h1>Bilan du Logement</h1>
        <p><strong>État de Sortie</strong></p>
    </div>
    
    <div class="info-section">
        <h2>Informations du Contrat</h2>
        <div class="info-grid">
            <div class="info-item"><strong>Locataire :</strong> {{locataire_nom}}</div>
            <div class="info-item"><strong>Référence :</strong> {{contrat_ref}}</div>
            <div class="info-item"><strong>Adresse :</strong> {{adresse}}</div>
            <div class="info-item"><strong>Date :</strong> {{date}}</div>
        </div>
    </div>
    
    <h2>Détail du Bilan</h2>
    {{bilan_rows}}
    
    {{commentaire_section}}
    
    <div class="total-section">
        <h3>Totaux</h3>
        <p><strong>Total Valeur:</strong> {{total_valeur}}</p>
        <p><strong>Total Solde Débiteur:</strong> {{total_solde_debiteur}}</p>
        <p><strong>Total Solde Créditeur:</strong> {{total_solde_crediteur}}</p>
    </div>
    
    <div class="total-section" style="margin-top: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">
        <h3>Récapitulatif Financier</h3>
        <p style="line-height: 1.6;"><strong>Dépôt de garantie :</strong> {{depot_garantie}} | <strong>Valeur estimative :</strong> {{valeur_estimative}} | <strong>Solde Débiteur:</strong> {{total_solde_debiteur}} | <strong>Solde Créditeur:</strong> {{total_solde_crediteur}} | <strong>Montant à restituer:</strong> {{montant_a_restituer}} | <strong>Reste dû:</strong> {{reste_du}}</p>
        <p style="line-height: 1.6; margin-top: 10px;"><strong>{{phrase_recap_financier}}</strong></p>
    </div>
    
    <div class="signature-section">
        <p><strong>Établi le :</strong> {{date}}</p>
        {{signature_agence}}
    </div>
</body>
</html>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration Bilan de Logement - My Invest Immobilier</title>
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
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>
    
    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-file-earmark-bar-graph"></i> Configuration du Template Bilan de Logement</h1>
                    <p class="text-muted mb-0">Personnalisez le template HTML du bilan de logement avec des variables dynamiques</p>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="config-card">
            <h5 class="mb-4"><i class="bi bi-file-code"></i> Template HTML du Bilan de Logement</h5>
            
            <div class="variables-info">
                <h6><i class="bi bi-tags"></i> Variables Disponibles</h6>
                <p class="small mb-2">Cliquez sur une variable pour la copier dans le presse-papier :</p>
                <div>
                    <span class="variable-tag" onclick="copyToClipboard('{{logo}}', event)" title="Logo de l'entreprise">{{logo}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{locataire_nom}}', event)" title="Nom complet du locataire">{{locataire_nom}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{contrat_ref}}', event)" title="Référence du contrat">{{contrat_ref}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{adresse}}', event)" title="Adresse du logement">{{adresse}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{date}}', event)" title="Date d'établissement">{{date}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{bilan_rows}}', event)" title="Lignes du tableau de bilan">{{bilan_rows}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{commentaire_section}}', event)" title="Section des observations">{{commentaire_section}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{total_montant}}', event)" title="Montant total">{{total_montant}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{signature_agence}}', event)" title="Signature de l'agence">{{signature_agence}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{depot_garantie}}', event)" title="Dépôt de garantie">{{depot_garantie}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{valeur_estimative}}', event)" title="Valeur estimative du bien">{{valeur_estimative}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{total_solde_debiteur}}', event)" title="Total Solde Débiteur">{{total_solde_debiteur}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{total_solde_crediteur}}', event)" title="Total Solde Créditeur">{{total_solde_crediteur}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{montant_a_restituer}}', event)" title="Montant à restituer au locataire">{{montant_a_restituer}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{reste_du}}', event)" title="Reste dû par le locataire">{{reste_du}}</span>
                    <span class="variable-tag" onclick="copyToClipboard('{{phrase_recap_financier}}', event)" title="Phrase récapitulative financière automatique">{{phrase_recap_financier}}</span>
                </div>
            </div>

            <form method="POST" id="templateForm">
                <input type="hidden" name="action" value="update_template">
                
                <div class="mb-3">
                    <label for="template_html" class="form-label">Code HTML</label>
                    <textarea name="template_html" id="template_html" class="form-control"><?php echo htmlspecialchars($template); ?></textarea>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Enregistrer le template
                    </button>
                    <a href="contrats.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Retour aux contrats
                    </a>
                </div>
            </form>
        </div>

        <div class="config-card">
            <h5 class="mb-3"><i class="bi bi-info-circle"></i> Instructions</h5>
            <ul>
                <li>Le template utilise HTML et CSS standard</li>
                <li>Les variables sont remplacées dynamiquement lors de la génération du PDF</li>
                <li>La variable <code>{{bilan_rows}}</code> sera remplacée par le tableau complet avec en-têtes et données</li>
                <li>La variable <code>{{commentaire_section}}</code> affiche les observations si présentes</li>
                <li>La variable <code>{{phrase_recap_financier}}</code> affiche automatiquement:
                    <ul>
                        <li>Si <code>{{reste_du}}</code> &gt; 0 : "Vous devez nous régler la somme de {{reste_du}} €."</li>
                        <li>Si <code>{{montant_a_restituer}}</code> &gt; 0 : "Vous recevrez prochainement la somme de {{montant_a_restituer}} €."</li>
                    </ul>
                </li>
                <li>Le PDF est généré avec TCPDF, certaines fonctionnalités CSS avancées peuvent ne pas fonctionner</li>
                <li>Les images doivent utiliser des chemins absolus ou des URLs complètes</li>
            </ul>
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
                { name: 'basicstyles', items: ['Bold', 'Italic', 'RemoveFormat'] },
                { name: 'paragraph',   items: ['JustifyLeft', 'JustifyCenter', 'JustifyRight', '-', 'BulletedList', 'NumberedList', '-', 'Outdent', 'Indent'] },
                { name: 'insert',      items: ['Link', 'Unlink', 'Table'] },
                { name: 'tools',       items: ['Maximize'] }
            ],
            contentsCss: 'body { font-family: Arial, sans-serif; font-size: 14px; }'
        });

        // Copy to clipboard function (wrapped in IIFE to avoid global scope pollution)
        (function() {
            'use strict';
            
            window.copyToClipboard = function(text, event) {
                navigator.clipboard.writeText(text).then(function() {
                    // Show temporary success message
                    const originalText = event.target.textContent;
                    event.target.textContent = '✓ Copié !';
                    setTimeout(function() {
                        event.target.textContent = originalText;
                    }, 1000);
                }, function(err) {
                    console.error('Erreur lors de la copie: ', err);
                });
            };
        })();
    </script>
</body>
</html>
