<?php
/**
 * Configuration du template PDF de décompte d'intervention
 * My Invest Immobilier
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../pdf/generate-decompte.php';

// ── Traitement des soumissions ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'update_template') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM parametres WHERE cle = 'decompte_template_html'");
        $stmt->execute();
        $exists = (int)$stmt->fetchColumn() > 0;

        if ($exists) {
            $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'decompte_template_html'")
                ->execute([$_POST['template_html']]);
        } else {
            $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('decompte_template_html', ?, 'text', 'decomptes', 'Template HTML du décompte d\'intervention')")
                ->execute([$_POST['template_html']]);
        }

        $_SESSION['success'] = 'Template de décompte mis à jour avec succès.';
        header('Location: decompte-configuration.php');
        exit;
    }

    if ($_POST['action'] === 'reset_template') {
        $defaultTemplate = getDefaultDecompteTemplate();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM parametres WHERE cle = 'decompte_template_html'");
        $stmt->execute();
        $exists = (int)$stmt->fetchColumn() > 0;

        if ($exists) {
            $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'decompte_template_html'")
                ->execute([$defaultTemplate]);
        } else {
            $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES ('decompte_template_html', ?, 'text', 'decomptes', 'Template HTML du décompte d\'intervention')")
                ->execute([$defaultTemplate]);
        }

        $_SESSION['success'] = 'Template de décompte réinitialisé au modèle par défaut.';
        header('Location: decompte-configuration.php');
        exit;
    }
}

// ── Récupérer le template courant ──────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'decompte_template_html'");
$stmt->execute();
$currentTemplate = $stmt->fetchColumn();

if (empty($currentTemplate)) {
    $currentTemplate = getDefaultDecompteTemplate();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration Décompte d'Intervention - Admin MyInvest</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- CKEditor 4 LTS -->
    <script src="<?= CKEDITOR_CDN_URL ?>"></script>
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
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
            border-bottom: 2px solid #e74c3c;
        }
        .variable-badge {
            background: #fde8e6;
            color: #c0392b;
            padding: 5px 10px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            margin: 3px;
            display: inline-block;
            cursor: pointer;
            transition: background 0.2s;
        }
        .variable-badge:hover { background: #f9c5c0; }
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
        <div class="config-card">
            <div class="d-flex justify-content-between align-items-center mb-0">
                <div>
                    <h4><i class="bi bi-receipt me-2"></i>Configuration du Décompte d'Intervention</h4>
                    <p class="mb-0 text-muted">Personnalisez le template PDF des décomptes d'intervention</p>
                </div>
                <a href="gestion-decomptes.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Retour aux décomptes
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-1"></i><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-1"></i><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Variables disponibles -->
        <div class="config-card">
            <h5><i class="bi bi-code-square me-2"></i>Variables Disponibles</h5>
            <p>Cliquez sur une variable pour la copier dans le presse-papier :</p>

            <div class="row">
                <div class="col-md-6">
                    <h6 class="mt-2">Informations Décompte</h6>
                    <span class="variable-badge" onclick="copyToClipboard('{{reference_decompte}}', event)">{{reference_decompte}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{reference_signalement}}', event)">{{reference_signalement}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{titre}}', event)">{{titre}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{date_facture}}', event)">{{date_facture}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{contrat_ref}}', event)">{{contrat_ref}}</span>

                    <h6 class="mt-3">Montants</h6>
                    <span class="variable-badge" onclick="copyToClipboard('{{montant_total}}', event)">{{montant_total}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{lignes_tableau}}', event)">{{lignes_tableau}}</span>
                </div>

                <div class="col-md-6">
                    <h6 class="mt-2">Locataire</h6>
                    <span class="variable-badge" onclick="copyToClipboard('{{prenom}}', event)">{{prenom}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{nom}}', event)">{{nom}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{locataire_prenom}}', event)">{{locataire_prenom}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{locataire_nom}}', event)">{{locataire_nom}}</span>

                    <h6 class="mt-3">Logement</h6>
                    <span class="variable-badge" onclick="copyToClipboard('{{adresse}}', event)">{{adresse}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{logement_reference}}', event)">{{logement_reference}}</span>

                    <h6 class="mt-3">Société</h6>
                    <span class="variable-badge" onclick="copyToClipboard('{{nom_societe}}', event)">{{nom_societe}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{adresse_societe}}', event)">{{adresse_societe}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{tel_societe}}', event)">{{tel_societe}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{email_societe}}', event)">{{email_societe}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{company}}', event)">{{company}}</span>
                    <span class="variable-badge" onclick="copyToClipboard('{{signature_societe}}', event)">{{signature_societe}}</span>
                </div>
            </div>
        </div>

        <!-- Éditeur CKEditor du template -->
        <div class="config-card">
            <h5><i class="bi bi-file-code me-2"></i>Template HTML du Décompte</h5>

            <div class="info-box">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Information :</strong> Le template utilise HTML et CSS compatible TCPDF.
                Évitez les propriétés CSS avancées (flexbox, grid, position absolute).
                La variable <code>{{lignes_tableau}}</code> est remplacée par le tableau HTML des lignes du décompte.
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
                        <i class="bi bi-save me-1"></i>Enregistrer le Template
                    </button>
                    <button type="button" class="btn btn-warning" id="resetTemplateBtn">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Réinitialiser au modèle par défaut
                    </button>
                </div>
            </form>

            <!-- Formulaire caché pour la réinitialisation -->
            <form id="resetForm" method="POST" action="" style="display:none;">
                <input type="hidden" name="action" value="reset_template">
            </form>
        </div>

        <!-- Guide d'utilisation -->
        <div class="config-card">
            <h5><i class="bi bi-book me-2"></i>Guide d'Utilisation</h5>

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
    &lt;title&gt;Décompte d'Intervention&lt;/title&gt;
    &lt;style&gt;/* Votre CSS ici */&lt;/style&gt;
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
                            <pre><code>&lt;p&gt;Locataire : {{prenom}} {{nom}}&lt;/p&gt;
&lt;p&gt;Décompte N° : {{reference_decompte}}&lt;/p&gt;
&lt;p&gt;Total : {{montant_total}} €&lt;/p&gt;
{{lignes_tableau}}</code></pre>
                            <p class="mt-3"><strong>Note :</strong> <code>{{lignes_tableau}}</code> génère automatiquement un tableau HTML des lignes du décompte, y compris le total.</p>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#guide3">
                            Bonnes Pratiques CSS (TCPDF)
                        </button>
                    </h2>
                    <div id="guide3" class="accordion-collapse collapse" data-bs-parent="#guideAccordion">
                        <div class="accordion-body">
                            <p>TCPDF supporte un sous-ensemble limité de CSS. Utilisez :</p>
                            <ul>
                                <li>Propriétés de base : <code>font-family</code>, <code>font-size</code>, <code>color</code>, <code>background-color</code></li>
                                <li>Marges et padding : <code>margin</code>, <code>padding</code></li>
                                <li>Bordures : <code>border</code>, <code>border-collapse</code></li>
                                <li>Tables : <code>&lt;table&gt;</code> avec CSS inline ou dans &lt;style&gt;</li>
                                <li>Texte : <code>text-align</code>, <code>font-weight</code>, <code>font-style</code></li>
                            </ul>
                            <p><strong>Évitez :</strong> flexbox, grid, position absolute, float complexe.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialiser CKEditor
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
            contentsCss: 'body { font-family: Arial, sans-serif; font-size: 14px; }',
            removePlugins: 'notification'
        });

        // Copier dans le presse-papier
        (function () {
            'use strict';
            window.copyToClipboard = function (text, event) {
                if (!navigator.clipboard) {
                    alert('Copie non disponible. Copiez manuellement : ' + text);
                    return;
                }
                navigator.clipboard.writeText(text).then(function () {
                    var original = event.target.textContent;
                    event.target.textContent = '✓ Copié !';
                    setTimeout(function () { event.target.textContent = original; }, 1000);
                }, function () {
                    alert('Erreur lors de la copie. Copiez manuellement : ' + text);
                });
            };
        })();

        // Réinitialiser le template
        document.getElementById('resetTemplateBtn').addEventListener('click', function () {
            if (confirm('Êtes-vous sûr de vouloir réinitialiser le template au modèle par défaut ?')) {
                document.getElementById('resetForm').submit();
            }
        });
    </script>
</body>
</html>
