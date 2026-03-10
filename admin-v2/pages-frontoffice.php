<?php
/**
 * Administration — Pages frontoffice
 * My Invest Immobilier
 *
 * Permet de créer, modifier et supprimer les pages publiques du site (À propos, Services…).
 * Chaque page est accessible via /page.php?slug=<slug>.
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';

// ── Actions POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_page') {
        $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $slug        = trim(strtolower(preg_replace('/[^a-z0-9\-]/', '-', $_POST['slug'] ?? '')));
        $slug        = preg_replace('/-{2,}/', '-', trim($slug, '-'));
        $titre       = trim($_POST['titre'] ?? '');
        $contenu     = $_POST['contenu_html'] ?? '';
        $metaDesc    = trim($_POST['meta_description'] ?? '');
        $actif       = isset($_POST['actif']) ? 1 : 0;
        $ordre       = isset($_POST['ordre']) ? (int)$_POST['ordre'] : 0;

        if (empty($slug) || empty($titre)) {
            $_SESSION['error'] = 'Le slug et le titre sont obligatoires.';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE frontend_pages
                    SET slug = ?, titre = ?, contenu_html = ?, meta_description = ?, actif = ?, ordre = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$slug, $titre, $contenu, $metaDesc, $actif, $ordre, $id]);
                $_SESSION['success'] = 'Page mise à jour.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO frontend_pages (slug, titre, contenu_html, meta_description, actif, ordre)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$slug, $titre, $contenu, $metaDesc, $actif, $ordre]);
                $_SESSION['success'] = 'Page créée.';
            }
        }
        header('Location: pages-frontoffice.php');
        exit;

    } elseif ($action === 'delete_page') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $pdo->prepare("DELETE FROM frontend_pages WHERE id = ?")->execute([$id]);
            $_SESSION['success'] = 'Page supprimée.';
        }
        header('Location: pages-frontoffice.php');
        exit;

    } elseif ($action === 'toggle_actif') {
        $id    = isset($_POST['id'])    ? (int)$_POST['id']    : 0;
        $actif = isset($_POST['actif']) ? (int)$_POST['actif'] : 0;
        if ($id > 0) {
            $pdo->prepare("UPDATE frontend_pages SET actif = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$actif, $id]);
        }
        header('Location: pages-frontoffice.php');
        exit;
    }
}

// ── Chargement des données ────────────────────────────────────────────────────
$pages = [];
try {
    $pages = $pdo->query("SELECT * FROM frontend_pages ORDER BY ordre ASC, id ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $tableError = true;
}

$editPage = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($pages as $p) {
        if ($p['id'] === $editId) {
            $editPage = $p;
            break;
        }
    }
}

$siteUrl = rtrim($config['SITE_URL'] ?? '', '/');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pages frontoffice — My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
</head>
<body>
<?php require_once __DIR__ . '/includes/menu.php'; ?>
<div class="main-content">
    <!-- Alerts -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show m-3" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show m-3" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="container-fluid p-4">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h2 class="mb-0"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Pages du site public</h2>
                <p class="text-muted mb-0 mt-1">Créez et modifiez les pages accessibles sur le front office (À propos, Services, FAQ…).</p>
            </div>
            <div class="d-flex gap-2">
                <a href="menu-frontoffice.php" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul me-1"></i>Gérer le menu
                </a>
                <?php if (!$editPage): ?>
                <a href="pages-frontoffice.php?edit=new" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>Nouvelle page
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($tableError)): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            La table <code>frontend_pages</code> n'existe pas encore.
            Veuillez <a href="../run-migrations.php">exécuter les migrations</a> pour l'initialiser.
        </div>
        <?php endif; ?>

        <?php if ($editPage || isset($_GET['edit'])): ?>
        <!-- ── Formulaire d'édition ── -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-<?php echo $editPage ? 'pencil' : 'plus-circle'; ?> me-2 text-primary"></i>
                <?php echo $editPage ? 'Modifier : ' . htmlspecialchars($editPage['titre']) : 'Nouvelle page'; ?>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_page">
                    <?php if ($editPage): ?>
                        <input type="hidden" name="id" value="<?php echo $editPage['id']; ?>">
                    <?php endif; ?>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Titre <span class="text-danger">*</span></label>
                            <input type="text" name="titre" class="form-control"
                                   value="<?php echo htmlspecialchars($editPage['titre'] ?? ''); ?>"
                                   id="titreInput" placeholder="Ex: À propos de nous" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Slug URL <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text text-muted small">/page.php?slug=</span>
                                <input type="text" name="slug" class="form-control"
                                       value="<?php echo htmlspecialchars($editPage['slug'] ?? ''); ?>"
                                       id="slugInput" placeholder="a-propos" required pattern="[a-z0-9\-]+">
                            </div>
                            <div class="form-text">Uniquement lettres minuscules, chiffres et tirets.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Meta description (SEO)</label>
                        <input type="text" name="meta_description" class="form-control"
                               value="<?php echo htmlspecialchars($editPage['meta_description'] ?? ''); ?>"
                               placeholder="Description courte pour les moteurs de recherche (160 car. max)" maxlength="320">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contenu HTML</label>
                        <textarea name="contenu_html" class="form-control font-monospace"
                                  rows="20" style="font-size:.82rem;"><?php echo htmlspecialchars($editPage['contenu_html'] ?? ''); ?></textarea>
                        <div class="form-text">HTML complet du corps de la page (sections, balises, styles inline…).</div>
                    </div>

                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Ordre d'affichage</label>
                            <input type="number" name="ordre" class="form-control"
                                   value="<?php echo (int)($editPage['ordre'] ?? 10); ?>" min="0" step="10">
                        </div>
                        <div class="col-md-3 d-flex align-items-center">
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" name="actif" id="actifSwitch"
                                       <?php echo (!$editPage || $editPage['actif']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="actifSwitch">Page visible publiquement</label>
                            </div>
                        </div>
                        <div class="col text-end">
                            <a href="pages-frontoffice.php" class="btn btn-outline-secondary me-2">Annuler</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i>Enregistrer
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Liste des pages ── -->
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-collection me-2 text-primary"></i>Pages existantes (<?php echo count($pages); ?>)
            </div>
            <div class="card-body p-0">
                <?php if (empty($pages)): ?>
                <p class="text-muted text-center py-4">Aucune page pour l'instant. Cliquez sur « Nouvelle page » pour commencer.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Titre</th>
                                <th>URL</th>
                                <th class="text-center" style="width:80px;">Ordre</th>
                                <th class="text-center" style="width:90px;">Visible</th>
                                <th style="width:140px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pages as $page): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($page['titre']); ?></strong>
                                <?php if (!empty($page['meta_description'])): ?>
                                    <div class="text-muted small mt-1"><?php echo htmlspecialchars(mb_strimwidth($page['meta_description'], 0, 80, '…')); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code class="small">/page.php?slug=<?php echo htmlspecialchars($page['slug']); ?></code>
                                <a href="<?php echo htmlspecialchars($siteUrl . '/page.php?slug=' . $page['slug']); ?>"
                                   target="_blank" class="ms-1 text-muted small" title="Voir la page">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            </td>
                            <td class="text-center text-muted small"><?php echo $page['ordre']; ?></td>
                            <td class="text-center">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_actif">
                                    <input type="hidden" name="id" value="<?php echo $page['id']; ?>">
                                    <input type="hidden" name="actif" value="<?php echo $page['actif'] ? 0 : 1; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $page['actif'] ? 'btn-success' : 'btn-secondary'; ?> border-0 py-0 px-2" title="<?php echo $page['actif'] ? 'Désactiver' : 'Activer'; ?>">
                                        <?php echo $page['actif'] ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>'; ?>
                                    </button>
                                </form>
                            </td>
                            <td class="text-end">
                                <a href="pages-frontoffice.php?edit=<?php echo $page['id']; ?>" class="btn btn-sm btn-outline-primary me-1" title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer définitivement cette page ?');">
                                    <input type="hidden" name="action" value="delete_page">
                                    <input type="hidden" name="id" value="<?php echo $page['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-generate slug from title
(function () {
    var titreInput = document.getElementById('titreInput');
    var slugInput  = document.getElementById('slugInput');
    if (!titreInput || !slugInput) return;

    // Only auto-fill if slug is empty (new page)
    if (slugInput.value !== '') return;

    titreInput.addEventListener('input', function () {
        if (slugInput.dataset.manualEdit) return;
        slugInput.value = this.value
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // strip accents
            .replace(/[^a-z0-9\s\-]/g, '')
            .trim()
            .replace(/\s+/g, '-')
            .replace(/-{2,}/g, '-');
    });

    slugInput.addEventListener('input', function () {
        this.dataset.manualEdit = '1';
    });
}());
</script>
</body>
</html>
