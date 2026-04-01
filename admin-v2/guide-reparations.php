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

    $contenu = trim($_POST['guide_contenu'] ?? '');

    if (empty($errors)) {
        $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = 'guide_reparations_contenu'")->execute([$contenu]);
        $success = true;
    }
}

// Fetch current values
$contenu = getParameter('guide_reparations_contenu', '');
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
                    <p class="text-muted mb-0">Configurez le contenu du guide intégré au portail locataire.</p>
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

            <!-- Formulaire de configuration -->
            <form method="POST">
                <input type="hidden" name="action" value="update">

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-pencil-square me-2"></i>Contenu de la page</span>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <label class="form-label fw-semibold" for="guide_contenu">Contenu HTML</label>
                            <textarea class="form-control" id="guide_contenu"
                                      name="guide_contenu" rows="30"><?php echo htmlspecialchars($contenu); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mb-5">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-2"></i>Enregistrer
                    </button>
                </div>

            </form>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= CKEDITOR_CDN_URL ?>"></script>
    <script>
    CKEDITOR.replace('guide_contenu', {
        height: 500,
        language: 'fr',
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
    </script>
</body>
</html>
