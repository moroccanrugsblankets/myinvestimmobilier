<?php
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_template') {
        $cle = 'caution_template_html';
        $html = $_POST['template_html'] ?? '';

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM parametres WHERE cle = ?");
        $stmt->execute([$cle]);
        $exists = $stmt->fetchColumn() > 0;

        if ($exists) {
            $stmt = $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = ?");
            $stmt->execute([$html, $cle]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO parametres (cle, valeur, type, groupe, description) VALUES (?, ?, 'text', 'contrats', 'Template HTML du document de caution solidaire avec variables dynamiques')");
            $stmt->execute([$cle, $html]);
        }

        $_SESSION['success'] = "Template de caution solidaire mis à jour avec succès";
        header('Location: caution-configuration.php');
        exit;
    }

    if ($_POST['action'] === 'reset_template') {
        $pdo->prepare("DELETE FROM parametres WHERE cle = 'caution_template_html'")->execute();
        $_SESSION['success'] = "Template réinitialisé au modèle par défaut";
        header('Location: caution-configuration.php');
        exit;
    }
}

// Charger le template actuel
$stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'caution_template_html'");
$stmt->execute();
$currentTemplate = $stmt->fetchColumn();

$successMsg = $_SESSION['success'] ?? null;
$errorMsg   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

// Template par défaut
$defaultTemplate = '<p style="text-align:center;"><strong style="font-size:16pt;">ACTE DE CAUTION SOLIDAIRE</strong></p>
<p style="text-align:center;">Article 22-1 de la loi n°89-462 du 6 juillet 1989</p>
<p style="text-align:center;">Référence : {{reference_contrat}} &nbsp;|&nbsp; Date : {{date_document}}</p>
<p>&nbsp;</p>
<p><strong>1. PARTIES</strong></p>
<p><strong>Bailleur :</strong> {{company}}</p>
<p><strong>Locataire :</strong> {{prenom_locataire}} {{nom_locataire}}</p>
<p><strong>Logement :</strong> {{adresse_logement}}</p>
<p><strong>Loyer mensuel :</strong> {{loyer}} € + {{charges}} € de charges = {{loyer_total}} € / mois</p>
<p><strong>Date d\'entrée :</strong> {{date_prise_effet}}</p>
<p>&nbsp;</p>
<p><strong>2. CAUTION SOLIDAIRE</strong></p>
<p><strong>Nom :</strong> {{prenom_garant}} {{nom_garant}}</p>
<p><strong>Date de naissance :</strong> {{date_naissance_garant}}</p>
<p><strong>Adresse :</strong> {{adresse_garant}}, {{cp_garant}} {{ville_garant}}</p>
<p><strong>Email :</strong> {{email_garant}}</p>
<p>&nbsp;</p>
<p><strong>3. ENGAGEMENT</strong></p>
<p>Je soussigné(e) <strong>{{prenom_garant}} {{nom_garant}}</strong>, demeurant au {{adresse_garant}}, {{cp_garant}} {{ville_garant}}, me porte caution solidaire et indivisible, sans bénéfice de discussion ni de division, pour le paiement des loyers, charges et accessoires dus par <strong>{{prenom_locataire}} {{nom_locataire}}</strong> au titre du bail portant sur le logement situé à <strong>{{adresse_logement}}</strong>.</p>
<p>&nbsp;</p>
<p>Le montant total de l\'engagement est limité à <strong>{{loyer_total}} € par mois</strong>. Cet engagement est consenti pour toute la durée du bail et de ses renouvellements.</p>
<p>&nbsp;</p>
<p><strong>4. SIGNATURE</strong></p>
<p>Fait le {{date_document}}</p>
<p>&nbsp;</p>
<p>{{signature_garant}}</p>';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration Caution Solidaire – Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
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
                    <h1 class="h3 mb-0"><i class="bi bi-shield-lock"></i> Configuration de la Caution Solidaire</h1>
                    <p class="text-muted mb-0">Personnalisez le template HTML du document de caution solidaire avec des variables dynamiques</p>
                </div>
                <a href="contrats.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Retour aux contrats
                </a>
            </div>
        </div>

        <?php if ($successMsg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Template Editor -->
        <div class="config-card">
            <div class="variables-info">
                <h6><i class="bi bi-info-circle"></i> Variables disponibles</h6>
                <p class="mb-2">Cliquez sur une variable pour la copier. Utilisez ces variables dans le template HTML :</p>
                <div>
                    <strong class="d-block mb-1" style="font-size:0.8rem;color:#2c3e50;">Garant</strong>
                    <span class="variable-tag" onclick="copyVariable('{{prenom_garant}}')">{{prenom_garant}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{nom_garant}}')">{{nom_garant}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{date_naissance_garant}}')">{{date_naissance_garant}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{adresse_garant}}')">{{adresse_garant}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{cp_garant}}')">{{cp_garant}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{ville_garant}}')">{{ville_garant}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{email_garant}}')">{{email_garant}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{signature_garant}}')">{{signature_garant}}</span>
                    <strong class="d-block mb-1 mt-2" style="font-size:0.8rem;color:#2c3e50;">Contrat &amp; Logement</strong>
                    <span class="variable-tag" onclick="copyVariable('{{reference_contrat}}')">{{reference_contrat}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{adresse_logement}}')">{{adresse_logement}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{loyer}}')">{{loyer}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{charges}}')">{{charges}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{loyer_total}}')">{{loyer_total}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{date_prise_effet}}')">{{date_prise_effet}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{prenom_locataire}}')">{{prenom_locataire}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{nom_locataire}}')">{{nom_locataire}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{date_document}}')">{{date_document}}</span>
                    <span class="variable-tag" onclick="copyVariable('{{company}}')">{{company}}</span>
                </div>
            </div>

            <h5 class="mb-3">
                <i class="bi bi-file-earmark-code"></i> Template HTML du document de caution solidaire
                <?php if ($currentTemplate): ?>
                <span class="badge bg-success ms-2">Template personnalisé actif</span>
                <?php else: ?>
                <span class="badge bg-secondary ms-2">Modèle par défaut utilisé</span>
                <?php endif; ?>
            </h5>

            <form method="POST" action="" id="mainForm">
                <input type="hidden" name="action" value="update_template">
                <div class="mb-3">
                    <textarea class="form-control code-editor"
                              id="template_html"
                              name="template_html"><?= htmlspecialchars($currentTemplate ?: $defaultTemplate) ?></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Enregistrer
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="showPreview()">
                        <i class="bi bi-eye"></i> Prévisualiser
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="resetToDefault()">
                        <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser
                    </button>
                </div>
            </form>

            <?php if ($currentTemplate): ?>
            <form method="POST" action="" id="resetForm">
                <input type="hidden" name="action" value="reset_template">
            </form>
            <?php endif; ?>
        </div>

        <div class="config-card" id="preview-card" style="display: none;">
            <h5><i class="bi bi-eye"></i> Prévisualisation</h5>
            <div class="preview-section" id="preview-content"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const defaultTemplate = <?= json_encode($defaultTemplate) ?>;

        function copyVariable(variable) {
            navigator.clipboard.writeText(variable).then(() => {
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
            const editorInstance = tinymce.get('template_html');
            const template = editorInstance ? editorInstance.getContent() : document.getElementById('template_html').value;
            const previewCard = document.getElementById('preview-card');
            const previewContent = document.getElementById('preview-content');

            let preview = template
                .replace(/\{\{reference_contrat\}\}/g, 'BAIL-2024-001')
                .replace(/\{\{date_document\}\}/g, '30/03/2024')
                .replace(/\{\{company\}\}/g, 'MY INVEST IMMOBILIER')
                .replace(/\{\{prenom_locataire\}\}/g, 'Jean')
                .replace(/\{\{nom_locataire\}\}/g, 'DUPONT')
                .replace(/\{\{adresse_logement\}\}/g, '123 Rue de la République, 74100 Annemasse')
                .replace(/\{\{loyer\}\}/g, '850.00')
                .replace(/\{\{charges\}\}/g, '100.00')
                .replace(/\{\{loyer_total\}\}/g, '950.00')
                .replace(/\{\{date_prise_effet\}\}/g, '01/04/2024')
                .replace(/\{\{prenom_garant\}\}/g, 'Marie')
                .replace(/\{\{nom_garant\}\}/g, 'MARTIN')
                .replace(/\{\{date_naissance_garant\}\}/g, '15/06/1975')
                .replace(/\{\{adresse_garant\}\}/g, '45 Avenue des Fleurs')
                .replace(/\{\{cp_garant\}\}/g, '75001')
                .replace(/\{\{ville_garant\}\}/g, 'Paris')
                .replace(/\{\{email_garant\}\}/g, 'marie.martin@example.com')
                .replace(/\{\{signature_garant\}\}/g, '<p><em>[Signature du garant]</em></p>');

            previewContent.innerHTML = preview;
            previewCard.style.display = 'block';
            previewCard.scrollIntoView({ behavior: 'smooth' });
        }

        function resetToDefault() {
            if (confirm('Réinitialiser le template au modèle par défaut ?\n\nLes modifications non enregistrées seront perdues.')) {
                const editorInstance = tinymce.get('template_html');
                if (editorInstance) {
                    editorInstance.setContent(defaultTemplate);
                } else {
                    document.getElementById('template_html').value = defaultTemplate;
                }
                <?php if ($currentTemplate): ?>
                document.getElementById('resetForm').submit();
                <?php endif; ?>
            }
        }

        // Initialize TinyMCE
        tinymce.init({
            selector: '#template_html',
            height: 600,
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
            verify_html: false,
            extended_valid_elements: 'style,link[href|rel],head,html[lang],meta[*],body[*]',
            valid_children: '+body[style],+head[style]',
            forced_root_block: false,
            doctype: '<!DOCTYPE html>'
        });
    </script>
</body>
</html>
