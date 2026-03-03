<?php
/**
 * Configuration de la page "Guide des réparations locatives" — Interface admin
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {

    $slug    = trim($_POST['guide_slug']    ?? '');
    $contenu = trim($_POST['guide_contenu'] ?? '');
    $lien    = trim($_POST['guide_lien']    ?? '');

    // Validate slug: only lowercase alphanumeric and hyphens
    if (!empty($slug) && !preg_match('/^[a-z0-9\-]+$/', $slug)) {
        $errors[] = 'Le slug ne doit contenir que des lettres minuscules, chiffres et tirets.';
    }

    // Validate URL if provided
    if (!empty($lien) && !preg_match('#^https?://#i', $lien)) {
        $errors[] = 'Le lien doit commencer par http:// ou https://.';
    }

    if (empty($errors)) {
        $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'guide_reparations_slug'")->execute([$slug]);
        $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'guide_reparations_contenu'")->execute([$contenu]);

        // Auto-generate the lien from site URL + slug if not provided manually
        if (empty($lien) && !empty($slug)) {
            $siteUrl = rtrim($config['SITE_URL'] ?? '', '/');
            $lien = $siteUrl . '/guide-reparations.php';
        }
        $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'guide_reparations_lien'")->execute([$lien]);

        $success = true;
    }
}

// Fetch current values
$slug    = getParameter('guide_reparations_slug',    'guide-reparations-locatives');
$contenu = getParameter('guide_reparations_contenu', '');
$lien    = getParameter('guide_reparations_lien',    '');

if (empty($lien)) {
    $siteUrl = rtrim($config['SITE_URL'] ?? '', '/');
    $lien = $siteUrl . '/guide-reparations.php';
}

$guidePageUrl = rtrim($config['SITE_URL'] ?? '', '/') . '/guide-reparations.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guide des réparations — Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="container-fluid mt-4" style="max-width: 960px;">

            <div class="d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h1><i class="bi bi-book me-2"></i>Guide des réparations locatives</h1>
                    <p class="text-muted mb-0">Configurez le contenu et l'URL de la page publique du guide.</p>
                </div>
                <a href="signalements.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Signalements
                </a>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Guide mis à jour avec succès.</div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $e): ?>
                            <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Aperçu URL -->
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-link-45deg me-2"></i>Page publique</div>
                <div class="card-body">
                    <p class="text-muted small mb-2">
                        La page du guide est accessible à l'URL suivante. Partagez ce lien avec vos locataires.
                    </p>
                    <div class="input-group input-group-sm" style="max-width: 560px;">
                        <input type="text" class="form-control font-monospace" id="guide-url-input"
                               value="<?php echo htmlspecialchars($guidePageUrl); ?>" readonly>
                        <button class="btn btn-outline-secondary" type="button" onclick="
                            navigator.clipboard.writeText(document.getElementById('guide-url-input').value);
                            this.textContent='Copié !';
                            setTimeout(()=>{this.textContent='Copier';},2000);
                        ">Copier</button>
                        <a href="<?php echo htmlspecialchars($guidePageUrl); ?>" target="_blank" rel="noopener"
                           class="btn btn-outline-primary">
                            <i class="bi bi-eye"></i> Voir
                        </a>
                    </div>
                </div>
            </div>

            <!-- Formulaire de configuration -->
            <form method="POST">
                <input type="hidden" name="action" value="update">

                <div class="card mb-4">
                    <div class="card-header"><i class="bi bi-gear me-2"></i>Paramètres de la page</div>
                    <div class="card-body">

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="guide_slug">
                                Slug de la page
                            </label>
                            <div class="input-group">
                                <span class="input-group-text text-muted">/</span>
                                <input type="text" class="form-control font-monospace" id="guide_slug"
                                       name="guide_slug"
                                       value="<?php echo htmlspecialchars($slug); ?>"
                                       placeholder="guide-reparations-locatives"
                                       pattern="[a-z0-9\-]+"
                                       title="Lettres minuscules, chiffres et tirets uniquement">
                            </div>
                            <div class="form-text">
                                Identifiant URL de la page (lettres minuscules, chiffres, tirets).
                                Ce slug est utilisé dans le lien affiché sur le formulaire de signalement.
                            </div>
                        </div>

                        <div class="mb-0">
                            <label class="form-label fw-semibold" for="guide_lien">
                                Lien affiché sur le formulaire de signalement
                            </label>
                            <input type="url" class="form-control" id="guide_lien"
                                   name="guide_lien"
                                   value="<?php echo htmlspecialchars($lien); ?>"
                                   placeholder="https://votre-site.com/guide-reparations.php">
                            <div class="form-text">
                                URL complète du guide, affichée sur le formulaire de signalement locataire.
                                Laissez vide pour générer automatiquement depuis le slug.
                            </div>
                        </div>

                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-pencil-square me-2"></i>Contenu de la page</span>
                        <small class="text-muted">HTML accepté</small>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <label class="form-label fw-semibold" for="guide_contenu">Contenu HTML</label>
                            <textarea class="form-control font-monospace" id="guide_contenu"
                                      name="guide_contenu" rows="30"
                                      style="font-size:0.82rem;"><?php echo htmlspecialchars($contenu); ?></textarea>
                            <div class="form-text">
                                Vous pouvez utiliser du HTML : &lt;h1&gt;, &lt;h2&gt;, &lt;h3&gt;, &lt;p&gt;, &lt;ul&gt;, &lt;li&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;hr&gt;, etc.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mb-5">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-2"></i>Enregistrer
                    </button>
                    <a href="<?php echo htmlspecialchars($guidePageUrl); ?>" target="_blank" rel="noopener"
                       class="btn btn-outline-secondary">
                        <i class="bi bi-eye me-2"></i>Voir la page
                    </a>
                </div>

            </form>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
