<?php
/**
 * Administration — Pages frontoffice
 * My Invest Immobilier
 *
 * Permet de créer, modifier et supprimer les pages publiques du site (À propos, Services…).
 * Chaque page est accessible via /<slug> (SEO-friendly) ou /page.php?slug=<slug>.
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
        $isHomepage  = isset($_POST['is_homepage']) ? 1 : 0;

        if (empty($slug) || empty($titre)) {
            $_SESSION['error'] = 'Le slug et le titre sont obligatoires.';
        } else {
            if ($isHomepage) {
                // Only one homepage at a time — clear existing before setting new
                $pdo->prepare("UPDATE frontend_pages SET is_homepage = 0 WHERE id != ?")->execute([$id]);
            }
            // Check if is_homepage column exists (graceful degradation)
            $hasHomepageCol = columnExists($pdo, 'frontend_pages', 'is_homepage');
            if ($id > 0) {
                if ($hasHomepageCol) {
                    $stmt = $pdo->prepare("
                        UPDATE frontend_pages
                        SET slug = ?, titre = ?, contenu_html = ?, meta_description = ?,
                            actif = ?, ordre = ?, is_homepage = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$slug, $titre, $contenu, $metaDesc, $actif, $ordre, $isHomepage, $id]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE frontend_pages
                        SET slug = ?, titre = ?, contenu_html = ?, meta_description = ?,
                            actif = ?, ordre = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$slug, $titre, $contenu, $metaDesc, $actif, $ordre, $id]);
                }
                $_SESSION['success'] = 'Page mise à jour.';
            } else {
                if ($hasHomepageCol) {
                    $stmt = $pdo->prepare("
                        INSERT INTO frontend_pages (slug, titre, contenu_html, meta_description, actif, ordre, is_homepage)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$slug, $titre, $contenu, $metaDesc, $actif, $ordre, $isHomepage]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO frontend_pages (slug, titre, contenu_html, meta_description, actif, ordre)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$slug, $titre, $contenu, $metaDesc, $actif, $ordre]);
                }
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

    } elseif ($action === 'set_homepage') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0 && columnExists($pdo, 'frontend_pages', 'is_homepage')) {
            $pdo->prepare("UPDATE frontend_pages SET is_homepage = 0")->execute();
            $pdo->prepare("UPDATE frontend_pages SET is_homepage = 1, actif = 1 WHERE id = ?")
                ->execute([$id]);
            $_SESSION['success'] = 'Page d\'accueil définie.';
        }
        header('Location: pages-frontoffice.php');
        exit;
    }
}

/**
 * Helper: check if a column exists in a table.
 * Only allows whitelisted table names to prevent SQL injection.
 */
function columnExists(PDO $pdo, string $table, string $column): bool
{
    $allowedTables = ['frontend_pages', 'frontend_menu_items', 'contact_forms', 'contact_form_fields'];
    if (!in_array($table, $allowedTables, true)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

// ── Chargement des données ────────────────────────────────────────────────────
$pages = [];
$hasHomepageCol = false;
try {
    $pages = $pdo->query("SELECT * FROM frontend_pages ORDER BY ordre ASC, id ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
    $hasHomepageCol = columnExists($pdo, 'frontend_pages', 'is_homepage');
} catch (Exception $e) {
    $tableError = true;
}

$editPage = null;
if (isset($_GET['edit'])) {
    $editId = $_GET['edit'] === 'new' ? 0 : (int)$_GET['edit'];
    if ($editId > 0) {
        foreach ($pages as $p) {
            if ((int)$p['id'] === $editId) {
                $editPage = $p;
                break;
            }
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
    <!-- GrapesJS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/grapesjs@0.21.13/dist/css/grapes.min.css">
    <style>
        #gjs { height: 600px; border: 1px solid #dee2e6; border-radius: 6px; }
        .gjs-cv-canvas { width: 100%; }
        /* Hide GrapesJS when raw HTML mode is active */
        body.raw-html-mode #gjs { display: none; }
        body.raw-html-mode #rawHtmlWrapper { display: block !important; }
        #rawHtmlWrapper { display: none; }
    </style>
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
                <a href="formulaires-contact.php" class="btn btn-outline-secondary">
                    <i class="bi bi-envelope-at me-1"></i>Formulaires de contact
                </a>
                <a href="menu-frontoffice.php" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul me-1"></i>Gérer le menu
                </a>
                <?php if (!$editPage && !isset($_GET['edit'])): ?>
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
                <form method="POST" id="pageForm">
                    <input type="hidden" name="action" value="save_page">
                    <?php if ($editPage): ?>
                        <input type="hidden" name="id" value="<?php echo $editPage['id']; ?>">
                    <?php endif; ?>
                    <!-- Hidden field that GrapesJS fills before submit -->
                    <input type="hidden" name="contenu_html" id="contenHtmlHidden"
                           value="<?php echo htmlspecialchars($editPage['contenu_html'] ?? ''); ?>">

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
                                <span class="input-group-text text-muted small">/</span>
                                <input type="text" name="slug" class="form-control"
                                       value="<?php echo htmlspecialchars($editPage['slug'] ?? ''); ?>"
                                       id="slugInput" placeholder="a-propos" required pattern="[a-z0-9\-]+">
                            </div>
                            <div class="form-text">Lettres minuscules, chiffres et tirets uniquement. Ex&nbsp;: <code>a-propos</code> → <code>/a-propos</code>.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Meta description (SEO)</label>
                        <input type="text" name="meta_description" class="form-control"
                               value="<?php echo htmlspecialchars($editPage['meta_description'] ?? ''); ?>"
                               placeholder="Description courte pour les moteurs de recherche (160 car. max)" maxlength="320">
                    </div>

                    <!-- ── Éditeur visuel GrapesJS ── -->
                    <div class="mb-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="form-label fw-semibold mb-0">Contenu de la page</label>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Mode éditeur">
                                <button type="button" class="btn btn-outline-primary active" id="btnVisual">
                                    <i class="bi bi-easel me-1"></i>Éditeur visuel
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="btnRaw">
                                    <i class="bi bi-code-slash me-1"></i>HTML brut
                                </button>
                            </div>
                        </div>
                        <!-- GrapesJS canvas -->
                        <div id="gjs"></div>
                        <!-- Raw HTML fallback textarea -->
                        <div id="rawHtmlWrapper">
                            <textarea id="rawHtmlTextarea" class="form-control font-monospace"
                                      rows="20" style="font-size:.82rem;"></textarea>
                            <div class="form-text">HTML complet du corps de la page (sections, balises, styles inline…).</div>
                        </div>
                    </div>

                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Ordre d'affichage</label>
                            <input type="number" name="ordre" class="form-control"
                                   value="<?php echo (int)($editPage['ordre'] ?? 10); ?>" min="0" step="10">
                        </div>
                        <div class="col-md-3 d-flex align-items-center gap-3 flex-wrap">
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" name="actif" id="actifSwitch"
                                       <?php echo (!$editPage || $editPage['actif']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="actifSwitch">Page visible</label>
                            </div>
                            <?php if ($hasHomepageCol): ?>
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" name="is_homepage" id="homepageSwitch"
                                       <?php echo (!empty($editPage['is_homepage'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="homepageSwitch">
                                    <i class="bi bi-house-heart me-1 text-warning"></i>Page d'accueil
                                </label>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col text-end">
                            <a href="pages-frontoffice.php" class="btn btn-outline-secondary me-2">Annuler</a>
                            <button type="submit" class="btn btn-primary" id="btnSave">
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
                                <?php if ($hasHomepageCol): ?>
                                <th class="text-center" style="width:90px;">Accueil</th>
                                <?php endif; ?>
                                <th style="width:160px;"></th>
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
                                <code class="small">/<?php echo htmlspecialchars($page['slug']); ?></code>
                                <a href="<?php echo htmlspecialchars($siteUrl . '/' . $page['slug']); ?>"
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
                            <?php if ($hasHomepageCol): ?>
                            <td class="text-center">
                                <?php if (!empty($page['is_homepage'])): ?>
                                    <span class="badge bg-warning text-dark" title="Page d'accueil actuelle">
                                        <i class="bi bi-house-heart-fill"></i> Accueil
                                    </span>
                                <?php else: ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="set_homepage">
                                        <input type="hidden" name="id" value="<?php echo $page['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning border-0 py-0 px-2"
                                                title="Définir comme page d'accueil">
                                            <i class="bi bi-house"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
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
<!-- GrapesJS -->
<script src="https://cdn.jsdelivr.net/npm/grapesjs@0.21.13/dist/grapes.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/grapesjs-blocks-basic@1.0.2/dist/index.js"></script>
<script src="https://cdn.jsdelivr.net/npm/grapesjs-preset-webpage@1.0.3/dist/index.js"></script>
<script>
// ── Auto-generate slug from title ─────────────────────────────────────────
(function () {
    var titreInput = document.getElementById('titreInput');
    var slugInput  = document.getElementById('slugInput');
    if (!titreInput || !slugInput) return;
    if (slugInput.value !== '') return;
    titreInput.addEventListener('input', function () {
        if (slugInput.dataset.manualEdit) return;
        slugInput.value = this.value
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s\-]/g, '')
            .trim()
            .replace(/\s+/g, '-')
            .replace(/-{2,}/g, '-');
    });
    slugInput.addEventListener('input', function () {
        this.dataset.manualEdit = '1';
    });
}());

// ── GrapesJS visual editor ────────────────────────────────────────────────
(function () {
    var gjsContainer = document.getElementById('gjs');
    if (!gjsContainer) return; // Not in edit mode

    var hiddenInput    = document.getElementById('contenHtmlHidden');
    var rawWrapper     = document.getElementById('rawHtmlWrapper');
    var rawTextarea    = document.getElementById('rawHtmlTextarea');
    var btnVisual      = document.getElementById('btnVisual');
    var btnRaw         = document.getElementById('btnRaw');
    var pageForm       = document.getElementById('pageForm');

    var initialHtml = hiddenInput ? hiddenInput.value : '';

    var editor = grapesjs.init({
        container: '#gjs',
        fromElement: false,
        height: '600px',
        width: '100%',
        storageManager: false,
        plugins: [
            typeof gjsBlocksBasic !== 'undefined' ? gjsBlocksBasic : null,
            typeof gjsPresetWebpage !== 'undefined' ? gjsPresetWebpage : null
        ].filter(Boolean),
        pluginsOpts: {
            gjsBlocksBasic: {},
            gjsPresetWebpage: {}
        },
        canvas: {
            styles: [
                'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'
            ]
        }
    });

    // Load initial content
    if (initialHtml) {
        editor.setComponents(initialHtml);
    }

    // Toggle: Visual ↔ Raw HTML
    function switchToVisual() {
        var raw = rawTextarea.value;
        if (raw) { editor.setComponents(raw); }
        gjsContainer.style.display = '';
        rawWrapper.style.display = 'none';
        btnVisual.classList.add('active');
        btnRaw.classList.remove('active');
    }

    function switchToRaw() {
        rawTextarea.value = editor.getHtml() || '';
        gjsContainer.style.display = 'none';
        rawWrapper.style.display = 'block';
        btnVisual.classList.remove('active');
        btnRaw.classList.add('active');
    }

    if (btnVisual) {
        btnVisual.addEventListener('click', function(e) {
            e.preventDefault();
            switchToVisual();
        });
    }
    if (btnRaw) {
        btnRaw.addEventListener('click', function(e) {
            e.preventDefault();
            switchToRaw();
        });
    }

    // On form submit: synchronise hidden field from active editor
    if (pageForm) {
        pageForm.addEventListener('submit', function () {
            var isRaw = rawWrapper && rawWrapper.style.display === 'block';
            if (isRaw) {
                hiddenInput.value = rawTextarea.value;
            } else {
                hiddenInput.value = editor.getHtml() || '';
            }
        });
    }
}());
</script>
</body>
</html>
