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

// Charger le template par défaut depuis le générateur PDF pour affichage de référence
$defaultTemplateNote = 'Si aucun template personnalisé n\'est défini, le document utilise le modèle HTML intégré dans le générateur PDF.';

$successMsg = $_SESSION['success'] ?? null;
$errorMsg   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration Caution – Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php include 'includes/menu.php'; ?>

<div class="main-content">
    <div class="container-fluid py-4">

        <div class="d-flex align-items-center mb-4">
            <a href="contrats.php" class="btn btn-outline-secondary btn-sm me-3">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
            <h2 class="mb-0"><i class="bi bi-shield-lock"></i> Configuration Caution solidaire</h2>
        </div>

        <?php if ($successMsg): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($successMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($errorMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Info variables -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-info-circle"></i> Variables disponibles dans le template</h5>
            </div>
            <div class="card-body">
                <p class="mb-2">Utilisez les variables suivantes dans votre template HTML (entre accolades doubles) :</p>
                <div class="row">
                    <div class="col-md-6">
                        <h6>Garant</h6>
                        <ul class="small mb-3">
                            <li><code>{{prenom_garant}}</code> – Prénom du garant</li>
                            <li><code>{{nom_garant}}</code> – Nom du garant</li>
                            <li><code>{{date_naissance_garant}}</code> – Date de naissance du garant</li>
                            <li><code>{{adresse_garant}}</code> – Adresse du garant</li>
                            <li><code>{{cp_garant}}</code> – Code postal du garant</li>
                            <li><code>{{ville_garant}}</code> – Ville du garant</li>
                            <li><code>{{email_garant}}</code> – Email du garant</li>
                            <li><code>{{signature_garant}}</code> – Image de la signature</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Contrat &amp; Logement</h6>
                        <ul class="small mb-3">
                            <li><code>{{reference_contrat}}</code> – Référence du contrat</li>
                            <li><code>{{adresse_logement}}</code> – Adresse du logement</li>
                            <li><code>{{loyer}}</code> – Loyer (€)</li>
                            <li><code>{{charges}}</code> – Charges (€)</li>
                            <li><code>{{loyer_total}}</code> – Loyer + charges (€)</li>
                            <li><code>{{date_prise_effet}}</code> – Date d'entrée</li>
                            <li><code>{{prenom_locataire}}</code> – Prénom du locataire</li>
                            <li><code>{{nom_locataire}}</code> – Nom du locataire</li>
                            <li><code>{{date_document}}</code> – Date de génération du PDF</li>
                            <li><code>{{company}}</code> – Nom de la société</li>
                        </ul>
                    </div>
                </div>
                <div class="alert alert-light mb-0">
                    <i class="bi bi-lightbulb"></i> <?= htmlspecialchars($defaultTemplateNote) ?>
                </div>
            </div>
        </div>

        <!-- Template HTML -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-file-earmark-code"></i> Template HTML du document de caution solidaire</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_template">

                    <div class="mb-3">
                        <label for="template_html" class="form-label">
                            Contenu HTML du document
                            <?php if ($currentTemplate): ?>
                            <span class="badge bg-success ms-2">Template personnalisé actif</span>
                            <?php else: ?>
                            <span class="badge bg-secondary ms-2">Modèle par défaut utilisé</span>
                            <?php endif; ?>
                        </label>
                        <textarea class="form-control font-monospace"
                                  id="template_html"
                                  name="template_html"
                                  rows="30"
                                  style="font-size:0.82rem;"
                                  placeholder="Entrez votre template HTML ici. Utilisez les variables listées ci-dessus entre {{double accolades}}."
                                  ><?= htmlspecialchars($currentTemplate ?? '') ?></textarea>
                        <div class="form-text">
                            Le template doit être un document HTML complet (avec balises <code>&lt;html&gt;</code>, <code>&lt;head&gt;</code> et <code>&lt;body&gt;</code>) ou un fragment HTML. Il sera rendu par TCPDF.
                        </div>
                    </div>

                    <div class="d-flex gap-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Enregistrer le template
                        </button>
                        <?php if ($currentTemplate): ?>
                        <button type="button" class="btn btn-outline-danger"
                                onclick="if(confirm('Remettre le template par défaut ?')) { document.getElementById('resetForm').submit(); }">
                            <i class="bi bi-arrow-counterclockwise"></i> Remettre le modèle par défaut
                        </button>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($currentTemplate): ?>
                <form method="POST" action="" id="resetForm">
                    <input type="hidden" name="action" value="reset_template">
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Aperçu du modèle par défaut -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-eye"></i> Aperçu du modèle par défaut (structure de référence)</h5>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">Structure HTML du document généré par défaut si aucun template personnalisé n'est défini :</p>
                <pre class="bg-light p-3 rounded" style="font-size:0.78rem;max-height:400px;overflow-y:auto;">&lt;!DOCTYPE html&gt;
&lt;html lang="fr"&gt;
&lt;head&gt;
&lt;meta charset="UTF-8"&gt;
&lt;style&gt;
  body { font-family: Arial, sans-serif; font-size: 11pt; color: #222; }
  h1   { font-size: 16pt; text-align: center; text-transform: uppercase; }
  h2   { font-size: 13pt; color: #2c3e50; border-bottom: 1px solid #2c3e50; }
  table.info td { padding: 2mm 3mm; border: 1px solid #ccc; font-size: 10pt; }
  table.info td.label { background: #f0f4f8; font-weight: bold; width: 35%; }
&lt;/style&gt;
&lt;/head&gt;
&lt;body&gt;

&lt;h1&gt;Acte de caution solidaire&lt;/h1&gt;
&lt;p style="text-align:center"&gt;Article 22-1 de la loi n°89-462 du 6 juillet 1989&lt;/p&gt;
&lt;p style="text-align:center"&gt;Référence : {{reference_contrat}} | Date : {{date_document}}&lt;/p&gt;

&lt;h2&gt;1. Parties&lt;/h2&gt;
&lt;table class="info"&gt;
  &lt;tr&gt;&lt;td class="label"&gt;Bailleur&lt;/td&gt;&lt;td&gt;{{company}}&lt;/td&gt;&lt;/tr&gt;
  &lt;tr&gt;&lt;td class="label"&gt;Locataire&lt;/td&gt;&lt;td&gt;{{prenom_locataire}} {{nom_locataire}}&lt;/td&gt;&lt;/tr&gt;
  &lt;tr&gt;&lt;td class="label"&gt;Logement&lt;/td&gt;&lt;td&gt;{{adresse_logement}}&lt;/td&gt;&lt;/tr&gt;
  &lt;tr&gt;&lt;td class="label"&gt;Loyer mensuel&lt;/td&gt;&lt;td&gt;{{loyer}} € + {{charges}} € = {{loyer_total}} € / mois&lt;/td&gt;&lt;/tr&gt;
&lt;/table&gt;

&lt;h2&gt;2. Caution solidaire&lt;/h2&gt;
&lt;table class="info"&gt;
  &lt;tr&gt;&lt;td class="label"&gt;Nom&lt;/td&gt;&lt;td&gt;{{prenom_garant}} {{nom_garant}}&lt;/td&gt;&lt;/tr&gt;
  &lt;tr&gt;&lt;td class="label"&gt;Naissance&lt;/td&gt;&lt;td&gt;{{date_naissance_garant}}&lt;/td&gt;&lt;/tr&gt;
  &lt;tr&gt;&lt;td class="label"&gt;Adresse&lt;/td&gt;&lt;td&gt;{{adresse_garant}}, {{cp_garant}} {{ville_garant}}&lt;/td&gt;&lt;/tr&gt;
&lt;/table&gt;

&lt;h2&gt;3. Engagement&lt;/h2&gt;
&lt;p&gt;Je soussigné(e) {{prenom_garant}} {{nom_garant}} me porte caution solidaire...&lt;/p&gt;

&lt;h2&gt;4. Signature&lt;/h2&gt;
&lt;p&gt;Fait le {{date_document}}&lt;/p&gt;
{{signature_garant}}

&lt;/body&gt;
&lt;/html&gt;</pre>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
