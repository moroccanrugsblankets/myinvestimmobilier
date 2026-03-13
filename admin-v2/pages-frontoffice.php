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
        $id             = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $slug           = trim(strtolower(preg_replace('/[^a-z0-9\-]/', '-', $_POST['slug'] ?? '')));
        $slug           = preg_replace('/-{2,}/', '-', trim($slug, '-'));
        $titre          = trim($_POST['titre'] ?? '');
        $metaTitle      = trim($_POST['meta_title'] ?? '');
        $contenu        = $_POST['contenu_html'] ?? '';
        $metaDesc       = trim($_POST['meta_description'] ?? '');
        $actif          = isset($_POST['actif']) ? 1 : 0;
        $ordre          = isset($_POST['ordre']) ? (int)$_POST['ordre'] : 0;
        $isHomepage     = isset($_POST['is_homepage']) ? 1 : 0;
        $showTitreBloc  = isset($_POST['show_titre_bloc']) ? 1 : 0;

        if (empty($slug) || empty($titre)) {
            $_SESSION['error'] = 'Le slug et le titre sont obligatoires.';
        } else {
            // Ensure optional columns exist (auto-add if missing)
            $hasHomepageCol      = ensureHomepageColumn($pdo);
            $hasShowTitreBlocCol = ensureShowTitreBlocColumn($pdo);
            $hasMetaTitleCol     = ensureMetaTitleColumn($pdo);
            if ($isHomepage && $hasHomepageCol) {
                // Only one homepage at a time — clear existing before setting new
                $pdo->prepare("UPDATE frontend_pages SET is_homepage = 0 WHERE id != ?")->execute([$id]);
            }
            if ($id > 0) {
                $setClauses = "slug = ?, titre = ?, contenu_html = ?, meta_description = ?, actif = ?, ordre = ?";
                $params     = [$slug, $titre, $contenu, $metaDesc, $actif, $ordre];
                if ($hasMetaTitleCol) {
                    $setClauses .= ", meta_title = ?";
                    $params[]    = $metaTitle;
                }
                if ($hasHomepageCol) {
                    $setClauses .= ", is_homepage = ?";
                    $params[]    = $isHomepage;
                }
                if ($hasShowTitreBlocCol) {
                    $setClauses .= ", show_titre_bloc = ?";
                    $params[]    = $showTitreBloc;
                }
                $setClauses .= ", updated_at = NOW()";
                $params[]    = $id;
                $stmt = $pdo->prepare("UPDATE frontend_pages SET {$setClauses} WHERE id = ?");
                $stmt->execute($params);
                $_SESSION['success'] = 'Page mise à jour.';
            } else {
                $cols         = "slug, titre, contenu_html, meta_description, actif, ordre";
                $placeholders = "?, ?, ?, ?, ?, ?";
                $params       = [$slug, $titre, $contenu, $metaDesc, $actif, $ordre];
                if ($hasMetaTitleCol) {
                    $cols         .= ", meta_title";
                    $placeholders .= ", ?";
                    $params[]      = $metaTitle;
                }
                if ($hasHomepageCol) {
                    $cols         .= ", is_homepage";
                    $placeholders .= ", ?";
                    $params[]      = $isHomepage;
                }
                if ($hasShowTitreBlocCol) {
                    $cols         .= ", show_titre_bloc";
                    $placeholders .= ", ?";
                    $params[]      = $showTitreBloc;
                }
                $stmt = $pdo->prepare("INSERT INTO frontend_pages ({$cols}) VALUES ({$placeholders})");
                $stmt->execute($params);
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
        if ($id > 0) {
            if (ensureHomepageColumn($pdo)) {
                $pdo->prepare("UPDATE frontend_pages SET is_homepage = 0")->execute();
                $pdo->prepare("UPDATE frontend_pages SET is_homepage = 1, actif = 1 WHERE id = ?")
                    ->execute([$id]);
                $_SESSION['success'] = 'Page d\'accueil définie.';
            }
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

/**
 * Ensures the is_homepage column exists in frontend_pages, adding it if missing.
 * Returns true when the column is available, false otherwise.
 */
function ensureHomepageColumn(PDO $pdo): bool
{
    if (columnExists($pdo, 'frontend_pages', 'is_homepage')) {
        return true;
    }
    try {
        $pdo->exec("ALTER TABLE frontend_pages ADD COLUMN is_homepage TINYINT(1) NOT NULL DEFAULT 0 AFTER actif");
        return true;
    } catch (Exception $e) {
        // Column may have been added by a concurrent request — re-check
        return columnExists($pdo, 'frontend_pages', 'is_homepage');
    }
}

/**
 * Ensures the show_titre_bloc column exists in frontend_pages, adding it if missing.
 * Returns true when the column is available, false otherwise.
 */
function ensureShowTitreBlocColumn(PDO $pdo): bool
{
    if (columnExists($pdo, 'frontend_pages', 'show_titre_bloc')) {
        return true;
    }
    try {
        $pdo->exec("ALTER TABLE frontend_pages ADD COLUMN show_titre_bloc TINYINT(1) NOT NULL DEFAULT 1 AFTER is_homepage");
        return true;
    } catch (Exception $e) {
        // Column may have been added by a concurrent request — re-check
        return columnExists($pdo, 'frontend_pages', 'show_titre_bloc');
    }
}

/**
 * Ensures the meta_title column exists in frontend_pages, adding it if missing.
 * meta_title is used for the HTML <title> SEO tag; titre is used for the H1 heading.
 * Returns true when the column is available, false otherwise.
 */
function ensureMetaTitleColumn(PDO $pdo): bool
{
    if (columnExists($pdo, 'frontend_pages', 'meta_title')) {
        return true;
    }
    try {
        $pdo->exec("ALTER TABLE frontend_pages ADD COLUMN meta_title VARCHAR(255) NOT NULL DEFAULT '' AFTER titre");
        return true;
    } catch (Exception $e) {
        // Column may have been added by a concurrent request — re-check
        return columnExists($pdo, 'frontend_pages', 'meta_title');
    }
}

// ── Chargement des données ────────────────────────────────────────────────────
// Ensure optional columns exist before the main query so the UI is always
// functional regardless of whether the migrations have been run.
$hasHomepageCol      = ensureHomepageColumn($pdo);
$hasShowTitreBlocCol = ensureShowTitreBlocColumn($pdo);
$hasMetaTitleCol     = ensureMetaTitleColumn($pdo);

$pages = [];
try {
    $pages = $pdo->query("SELECT * FROM frontend_pages ORDER BY ordre ASC, id ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
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
                        <label class="form-label fw-semibold">Meta Title (SEO)</label>
                        <?php if ($hasMetaTitleCol): ?>
                        <input type="text" name="meta_title" class="form-control"
                               value="<?php echo htmlspecialchars($editPage['meta_title'] ?? ''); ?>"
                               placeholder="Titre affiché dans l'onglet du navigateur et les résultats Google (60 car. max)" maxlength="255">
                        <?php else: ?>
                        <input type="text" name="meta_title" class="form-control"
                               placeholder="Titre affiché dans l'onglet du navigateur et les résultats Google (60 car. max)" maxlength="255">
                        <?php endif; ?>
                        <div class="form-text">Si vide, le <strong>Titre</strong> de la page sera utilisé. Recommandé&nbsp;: 50–60 caractères.</div>
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
                            <?php if ($hasShowTitreBlocCol): ?>
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" name="show_titre_bloc" id="showTitreBlocSwitch"
                                       <?php echo (!$editPage || !empty($editPage['show_titre_bloc'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="showTitreBlocSwitch">
                                    <i class="bi bi-type-h1 me-1 text-info"></i>Afficher le bloc titre
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
                                <th class="text-center" style="width:90px;">Accueil</th>
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
                            <td class="text-center">
                                <?php if (!empty($page['is_homepage'] ?? 0)): ?>
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
        plugins: ['grapesjs-blocks-basic'],
        pluginsOpts: {
            'grapesjs-blocks-basic': { flexGrid: true }
        },
        assetManager: {
            // Upload images to the server so GrapesJS stores absolute URLs
            // instead of converting them to base64 data URIs.
            upload: 'upload-page-image.php',
            uploadName: 'files',
            credentials: 'same-origin',
            multiUpload: true,
            autoAdd: true,
        },
        canvas: {
            styles: [
                'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
                'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css'
            ],
            scripts: [
                'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'
            ]
        }
    });

    // ── Blocs Bootstrap personnalisés ─────────────────────────────────────
    var bm = editor.BlockManager;

    bm.add('bs-carousel', {
        label: 'Slider / Carrousel',
        category: 'Bootstrap',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><path d="M2 7h20v10H2z" opacity=".3"/><path d="M22 5H2C.9 5 0 5.9 0 7v10c0 1.1.9 2 2 2h20c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 12H2V7h20v10zM7.5 13.5L10 10l2.5 3.01L14 11l3 4H7l.5-1.5z"/></svg>',
        content: `<div id="heroCarousel" class="carousel slide" data-bs-ride="carousel">
  <div class="carousel-indicators">
    <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="0" class="active"></button>
    <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="1"></button>
    <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="2"></button>
  </div>
  <div class="carousel-inner">
    <div class="carousel-item active">
      <img src="https://placehold.co/1920x600/2c3e50/ffffff?text=Image+1" class="d-block w-100" alt="Slide 1" style="object-fit:cover;height:500px;">
      <div class="carousel-caption d-none d-md-block">
        <h2 class="fw-bold">Titre du slide 1</h2>
        <p>Description du slide 1</p>
      </div>
    </div>
    <div class="carousel-item">
      <img src="https://placehold.co/1920x600/3498db/ffffff?text=Image+2" class="d-block w-100" alt="Slide 2" style="object-fit:cover;height:500px;">
      <div class="carousel-caption d-none d-md-block">
        <h2 class="fw-bold">Titre du slide 2</h2>
        <p>Description du slide 2</p>
      </div>
    </div>
    <div class="carousel-item">
      <img src="https://placehold.co/1920x600/2ecc71/ffffff?text=Image+3" class="d-block w-100" alt="Slide 3" style="object-fit:cover;height:500px;">
      <div class="carousel-caption d-none d-md-block">
        <h2 class="fw-bold">Titre du slide 3</h2>
        <p>Description du slide 3</p>
      </div>
    </div>
  </div>
  <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev">
    <span class="carousel-control-prev-icon"></span>
  </button>
  <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next">
    <span class="carousel-control-next-icon"></span>
  </button>
</div>`
    });

    bm.add('bs-hero', {
        label: 'Bloc hero',
        category: 'Bootstrap',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="1" y="3" width="22" height="14" rx="1" opacity=".3"/><path d="M1 3h22v14H1z" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="3" y="19" width="18" height="2" rx="1" opacity=".5"/></svg>',
        content: `<section class="py-5 text-white text-center" style="background:linear-gradient(135deg,#2c3e50,#3498db);min-height:60vh;display:flex;align-items:center;">
  <div class="container">
    <h1 class="display-4 fw-bold mb-3">Titre principal</h1>
    <p class="lead mb-4">Sous-titre ou description de votre activité</p>
    <a href="#" class="btn btn-light btn-lg px-4 me-2">En savoir plus</a>
    <a href="#" class="btn btn-outline-light btn-lg px-4">Nous contacter</a>
  </div>
</section>`
    });

    bm.add('bs-cols-2', {
        label: '2 colonnes',
        category: 'Bootstrap',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="1" y="4" width="10" height="16" rx="1" opacity=".4"/><rect x="13" y="4" width="10" height="16" rx="1" opacity=".4"/></svg>',
        content: `<div class="container py-4">
  <div class="row g-4">
    <div class="col-md-6">
      <h3>Colonne gauche</h3>
      <p>Contenu de la colonne gauche. Modifiez ce texte selon vos besoins.</p>
    </div>
    <div class="col-md-6">
      <h3>Colonne droite</h3>
      <p>Contenu de la colonne droite. Modifiez ce texte selon vos besoins.</p>
    </div>
  </div>
</div>`
    });

    bm.add('bs-cols-3', {
        label: '3 colonnes',
        category: 'Bootstrap',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="1" y="4" width="6" height="16" rx="1" opacity=".4"/><rect x="9" y="4" width="6" height="16" rx="1" opacity=".4"/><rect x="17" y="4" width="6" height="16" rx="1" opacity=".4"/></svg>',
        content: `<div class="container py-4">
  <div class="row g-4">
    <div class="col-md-4 text-center">
      <div class="p-3"><i class="bi bi-star-fill fs-1 text-primary mb-3 d-block"></i><h4>Service 1</h4><p>Description du premier service ou avantage.</p></div>
    </div>
    <div class="col-md-4 text-center">
      <div class="p-3"><i class="bi bi-shield-check fs-1 text-primary mb-3 d-block"></i><h4>Service 2</h4><p>Description du deuxième service ou avantage.</p></div>
    </div>
    <div class="col-md-4 text-center">
      <div class="p-3"><i class="bi bi-people-fill fs-1 text-primary mb-3 d-block"></i><h4>Service 3</h4><p>Description du troisième service ou avantage.</p></div>
    </div>
  </div>
</div>`
    });

    bm.add('bs-cta', {
        label: "Appel à l'action",
        category: 'Bootstrap',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="2" y="8" width="20" height="8" rx="4" opacity=".4"/><path d="M12 10v4M10 12h4" stroke="currentColor" stroke-width="1.5"/></svg>',
        content: `<section class="py-5 bg-primary text-white text-center">
  <div class="container">
    <h2 class="fw-bold mb-3">Passez à l'action</h2>
    <p class="lead mb-4">Rejoignez-nous dès aujourd'hui et profitez de nos services.</p>
    <a href="#" class="btn btn-light btn-lg px-5">Commencer maintenant</a>
  </div>
</section>`
    });

    // ── Blocs Contenu ─────────────────────────────────────────────────────────
    bm.add('ct-heading', {
        label: 'Titre',
        category: 'Contenu',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><text x="2" y="18" font-size="16" font-weight="bold" fill="currentColor">H1</text></svg>',
        content: `<div class="container py-4 text-center">
  <h2 class="display-5 fw-bold mb-2">Votre titre ici</h2>
  <p class="lead text-muted">Sous-titre ou description courte.</p>
</div>`
    });

    bm.add('ct-paragraph', {
        label: 'Paragraphe',
        category: 'Contenu',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="2" y="4" width="20" height="2" rx="1"/><rect x="2" y="9" width="20" height="2" rx="1"/><rect x="2" y="14" width="14" height="2" rx="1"/></svg>',
        content: `<div class="container py-4">
  <p class="fs-5">Votre texte ici. Modifiez ce paragraphe pour y mettre votre contenu. Vous pouvez ajouter autant de texte que nécessaire pour décrire votre activité, vos services ou n'importe quelle information utile.</p>
</div>`
    });

    bm.add('ct-image', {
        label: 'Image',
        category: 'Contenu',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="2" y="4" width="20" height="16" rx="2" opacity=".3"/><path d="M2 14l5-5 4 4 3-3 5 6H2z" opacity=".8"/><circle cx="8" cy="9" r="1.5"/></svg>',
        content: `<div class="container py-4 text-center">
  <img src="https://placehold.co/800x400/eef4fb/3498db?text=Votre+image" class="img-fluid rounded-3 shadow-sm" alt="Image" style="max-width:100%;">
  <p class="text-muted small mt-2">Légende de l'image (optionnel)</p>
</div>`
    });

    bm.add('ct-image-text', {
        label: 'Image + Texte',
        category: 'Contenu',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="1" y="4" width="10" height="16" rx="1" opacity=".4"/><rect x="13" y="6" width="10" height="2" rx="1" opacity=".7"/><rect x="13" y="10" width="10" height="2" rx="1" opacity=".5"/><rect x="13" y="14" width="7" height="2" rx="1" opacity=".4"/></svg>',
        content: `<section class="py-5">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <img src="https://placehold.co/540x400/eef4fb/3498db?text=Image" class="img-fluid rounded-4 shadow" alt="Image">
      </div>
      <div class="col-lg-6">
        <h2 class="fw-bold mb-3">Titre de la section</h2>
        <p class="lead mb-4">Description principale. Expliquez ici votre activité, vos valeurs ou vos services.</p>
        <p class="mb-4">Texte complémentaire pour donner plus de détails à vos visiteurs.</p>
        <a href="#" class="btn btn-primary btn-lg">En savoir plus</a>
      </div>
    </div>
  </div>
</section>`
    });

    bm.add('ct-button', {
        label: 'Bouton',
        category: 'Contenu',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="2" y="8" width="20" height="8" rx="4" opacity=".5"/><text x="5" y="15" font-size="6" fill="currentColor">Bouton</text></svg>',
        content: `<div class="py-3 text-center">
  <a href="#" class="btn btn-primary btn-lg px-4 me-2">Bouton principal</a>
  <a href="#" class="btn btn-outline-secondary btn-lg px-4">Bouton secondaire</a>
</div>`
    });

    bm.add('ct-divider', {
        label: 'Séparateur',
        category: 'Contenu',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="2" y="11" width="20" height="2" rx="1"/></svg>',
        content: `<div class="container py-2"><hr class="my-4"></div>`
    });

    bm.add('ct-blockquote', {
        label: 'Citation',
        category: 'Contenu',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><path d="M4.58 2C3.1 2 2 3.1 2 4.58v4.84C2 10.9 3.1 12 4.58 12H7.4l-1.63 3.26A1 1 0 0 0 6.66 17h2.55a1 1 0 0 0 .9-.56L12 12.84V4.58C12 3.1 10.9 2 9.42 2H4.58zm12 0c-1.48 0-2.58 1.1-2.58 2.58v4.84c0 1.48 1.1 2.58 2.58 2.58H21.4l-1.63 3.26a1 1 0 0 0 .89 1.74h2.55a1 1 0 0 0 .9-.56L24 12.84V4.58C24 3.1 22.9 2 21.42 2H16.58z" opacity=".6"/></svg>',
        content: `<div class="container py-4">
  <blockquote class="blockquote p-4 bg-light rounded-3 border-start border-primary border-4">
    <p class="mb-3 fs-5 fst-italic">"Votre citation inspirante ici. Un témoignage client ou une pensée marquante qui illustre votre valeur ajoutée."</p>
    <footer class="blockquote-footer">Nom de la personne, <cite title="Titre">Titre ou entreprise</cite></footer>
  </blockquote>
</div>`
    });

    bm.add('ct-list', {
        label: 'Liste',
        category: 'Contenu',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><circle cx="4" cy="6" r="1.5"/><rect x="8" y="5" width="14" height="2" rx="1"/><circle cx="4" cy="12" r="1.5"/><rect x="8" y="11" width="14" height="2" rx="1"/><circle cx="4" cy="18" r="1.5"/><rect x="8" y="17" width="14" height="2" rx="1"/></svg>',
        content: `<div class="container py-4">
  <ul class="list-unstyled">
    <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>Premier élément de la liste</li>
    <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>Deuxième élément de la liste</li>
    <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>Troisième élément de la liste</li>
    <li class="mb-2"><i class="bi bi-check-circle-fill text-primary me-2"></i>Quatrième élément de la liste</li>
  </ul>
</div>`
    });

    // ── Blocs Composants ──────────────────────────────────────────────────────
    bm.add('cp-card', {
        label: 'Carte',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="2" y="3" width="20" height="18" rx="2" opacity=".3"/><rect x="2" y="3" width="20" height="6" rx="2" opacity=".5"/></svg>',
        content: `<div class="container py-4">
  <div class="card shadow-sm" style="max-width:360px;margin:0 auto;">
    <img src="https://placehold.co/360x200/eef4fb/3498db?text=Image" class="card-img-top" alt="Image">
    <div class="card-body">
      <h5 class="card-title">Titre de la carte</h5>
      <p class="card-text text-muted">Description courte du contenu de cette carte.</p>
      <a href="#" class="btn btn-primary">Voir plus</a>
    </div>
  </div>
</div>`
    });

    bm.add('cp-cards-3', {
        label: '3 Cartes',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="1" y="4" width="6" height="16" rx="1" opacity=".4"/><rect x="9" y="4" width="6" height="16" rx="1" opacity=".4"/><rect x="17" y="4" width="6" height="16" rx="1" opacity=".4"/></svg>',
        content: `<div class="container py-5">
  <div class="row g-4">
    <div class="col-md-4">
      <div class="card h-100 shadow-sm">
        <img src="https://placehold.co/360x200/eef4fb/3498db?text=Image+1" class="card-img-top" alt="">
        <div class="card-body"><h5 class="card-title">Carte 1</h5><p class="card-text text-muted">Description de la première carte.</p><a href="#" class="btn btn-outline-primary btn-sm">Lire plus</a></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card h-100 shadow-sm">
        <img src="https://placehold.co/360x200/e8f5e9/27ae60?text=Image+2" class="card-img-top" alt="">
        <div class="card-body"><h5 class="card-title">Carte 2</h5><p class="card-text text-muted">Description de la deuxième carte.</p><a href="#" class="btn btn-outline-primary btn-sm">Lire plus</a></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card h-100 shadow-sm">
        <img src="https://placehold.co/360x200/fff3e0/e67e22?text=Image+3" class="card-img-top" alt="">
        <div class="card-body"><h5 class="card-title">Carte 3</h5><p class="card-text text-muted">Description de la troisième carte.</p><a href="#" class="btn btn-outline-primary btn-sm">Lire plus</a></div>
      </div>
    </div>
  </div>
</div>`
    });

    bm.add('cp-alert', {
        label: 'Alerte / Info',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><path d="M12 2L1 21h22L12 2zm0 3l9 16H3L12 5zm-1 5v5h2v-5h-2zm0 7v2h2v-2h-2z" opacity=".7"/></svg>',
        content: `<div class="container py-3">
  <div class="alert alert-info d-flex align-items-center gap-2" role="alert">
    <i class="bi bi-info-circle-fill fs-5"></i>
    <div>Votre message d'information ici. Modifiez ce texte selon votre besoin.</div>
  </div>
  <div class="alert alert-success d-flex align-items-center gap-2" role="alert">
    <i class="bi bi-check-circle-fill fs-5"></i>
    <div>Message de succès ou de confirmation.</div>
  </div>
  <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
    <div>Avertissement ou information importante.</div>
  </div>
</div>`
    });

    bm.add('cp-badge', {
        label: 'Badges',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="2" y="8" width="8" height="8" rx="4" opacity=".6"/><rect x="12" y="8" width="10" height="8" rx="4" opacity=".4"/></svg>',
        content: `<div class="container py-3">
  <span class="badge bg-primary me-1">Nouveau</span>
  <span class="badge bg-success me-1">Disponible</span>
  <span class="badge bg-warning text-dark me-1">En cours</span>
  <span class="badge bg-danger me-1">Urgent</span>
  <span class="badge bg-secondary me-1">Archivé</span>
  <span class="badge bg-info text-dark me-1">Info</span>
</div>`
    });

    bm.add('cp-accordion', {
        label: 'Accordéon / FAQ',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="2" y="3" width="20" height="4" rx="1" opacity=".6"/><rect x="2" y="9" width="20" height="4" rx="1" opacity=".4"/><rect x="2" y="15" width="20" height="4" rx="1" opacity=".3"/></svg>',
        content: `<div class="container py-5">
  <h2 class="fw-bold text-center mb-5">Questions fréquentes</h2>
  <div class="accordion" id="faqAccordion">
    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
          Quelle est la première question fréquente ?
        </button>
      </h2>
      <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
        <div class="accordion-body">Réponse à la première question. Expliquez clairement et de manière concise.</div>
      </div>
    </div>
    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
          Quelle est la deuxième question ?
        </button>
      </h2>
      <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
        <div class="accordion-body">Réponse à la deuxième question. Ajoutez tous les détails nécessaires.</div>
      </div>
    </div>
    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
          Troisième question courante ?
        </button>
      </h2>
      <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
        <div class="accordion-body">Réponse complète à la troisième question.</div>
      </div>
    </div>
  </div>
</div>`
    });

    bm.add('cp-tabs', {
        label: 'Onglets',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="1" y="8" width="7" height="4" rx="1" opacity=".7"/><rect x="9" y="8" width="7" height="4" rx="1" opacity=".4"/><rect x="17" y="8" width="7" height="4" rx="1" opacity=".3"/><rect x="1" y="12" width="22" height="9" rx="1" opacity=".2"/></svg>',
        content: `<div class="container py-4">
  <ul class="nav nav-tabs" id="pageTabs">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab1">Onglet 1</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab2">Onglet 2</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab3">Onglet 3</button></li>
  </ul>
  <div class="tab-content border border-top-0 rounded-bottom p-4">
    <div class="tab-pane fade show active" id="tab1">
      <h4>Contenu de l'onglet 1</h4>
      <p>Contenu du premier onglet. Ajoutez votre texte, images ou autres éléments.</p>
    </div>
    <div class="tab-pane fade" id="tab2">
      <h4>Contenu de l'onglet 2</h4>
      <p>Contenu du deuxième onglet.</p>
    </div>
    <div class="tab-pane fade" id="tab3">
      <h4>Contenu de l'onglet 3</h4>
      <p>Contenu du troisième onglet.</p>
    </div>
  </div>
</div>`
    });

    bm.add('cp-testimonials', {
        label: 'Témoignages',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><circle cx="5" cy="8" r="3" opacity=".5"/><rect x="10" y="6" width="12" height="2" rx="1" opacity=".6"/><rect x="10" y="10" width="8" height="2" rx="1" opacity=".4"/><circle cx="5" cy="18" r="3" opacity=".5"/><rect x="10" y="16" width="12" height="2" rx="1" opacity=".6"/><rect x="10" y="20" width="8" height="2" rx="1" opacity=".4"/></svg>',
        content: `<section class="py-5 bg-light">
  <div class="container">
    <h2 class="fw-bold text-center mb-5">Ce que disent nos clients</h2>
    <div class="row g-4">
      <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm p-3">
          <div class="card-body">
            <div class="text-warning mb-3">★★★★★</div>
            <p class="fst-italic">"Excellent service, très professionnel et réactif. Je recommande vivement."</p>
          </div>
          <div class="card-footer bg-transparent border-0 d-flex align-items-center gap-2">
            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width:40px;height:40px;font-weight:700;">MR</div>
            <div><strong>Marie R.</strong><br><small class="text-muted">Cliente depuis 2 ans</small></div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm p-3">
          <div class="card-body">
            <div class="text-warning mb-3">★★★★★</div>
            <p class="fst-italic">"Une équipe à l'écoute et des services de grande qualité. Très satisfait."</p>
          </div>
          <div class="card-footer bg-transparent border-0 d-flex align-items-center gap-2">
            <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center" style="width:40px;height:40px;font-weight:700;">JD</div>
            <div><strong>Jean D.</strong><br><small class="text-muted">Client fidèle</small></div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm p-3">
          <div class="card-body">
            <div class="text-warning mb-3">★★★★☆</div>
            <p class="fst-italic">"Très bonne expérience globale. Un accompagnement personnalisé vraiment appréciable."</p>
          </div>
          <div class="card-footer bg-transparent border-0 d-flex align-items-center gap-2">
            <div class="rounded-circle bg-warning text-dark d-flex align-items-center justify-content-center" style="width:40px;height:40px;font-weight:700;">SL</div>
            <div><strong>Sophie L.</strong><br><small class="text-muted">Nouvelle cliente</small></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>`
    });

    bm.add('cp-pricing', {
        label: 'Tarifs',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="2" y="3" width="6" height="18" rx="1" opacity=".3"/><rect x="9" y="1" width="6" height="22" rx="1" opacity=".5"/><rect x="16" y="3" width="6" height="18" rx="1" opacity=".3"/></svg>',
        content: `<section class="py-5">
  <div class="container">
    <h2 class="fw-bold text-center mb-2">Nos offres</h2>
    <p class="text-muted text-center mb-5">Choisissez l'offre qui correspond à vos besoins.</p>
    <div class="row g-4 justify-content-center">
      <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm text-center p-4">
          <div class="card-body">
            <h4 class="fw-bold mb-1">Essentiel</h4>
            <div class="display-5 fw-bold my-3">29€<small class="fs-6 text-muted">/mois</small></div>
            <ul class="list-unstyled text-start mb-4">
              <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Fonctionnalité 1</li>
              <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Fonctionnalité 2</li>
              <li class="mb-2 text-muted"><i class="bi bi-x me-2"></i>Fonctionnalité 3</li>
            </ul>
            <a href="#" class="btn btn-outline-primary w-100">Choisir</a>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card h-100 border-primary shadow text-center p-4" style="border-width:2px;">
          <div class="card-body">
            <span class="badge bg-primary mb-2">Populaire</span>
            <h4 class="fw-bold mb-1">Pro</h4>
            <div class="display-5 fw-bold my-3">59€<small class="fs-6 text-muted">/mois</small></div>
            <ul class="list-unstyled text-start mb-4">
              <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Fonctionnalité 1</li>
              <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Fonctionnalité 2</li>
              <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Fonctionnalité 3</li>
            </ul>
            <a href="#" class="btn btn-primary w-100">Choisir</a>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm text-center p-4">
          <div class="card-body">
            <h4 class="fw-bold mb-1">Entreprise</h4>
            <div class="display-5 fw-bold my-3">Sur devis</div>
            <ul class="list-unstyled text-start mb-4">
              <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Fonctionnalité 1</li>
              <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Fonctionnalité 2</li>
              <li class="mb-2"><i class="bi bi-check text-success me-2"></i>Fonctionnalité 3+</li>
            </ul>
            <a href="#" class="btn btn-outline-secondary w-100">Nous contacter</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>`
    });

    bm.add('cp-stats', {
        label: 'Statistiques',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="2" y="14" width="4" height="8" rx="1" opacity=".4"/><rect x="8" y="8" width="4" height="14" rx="1" opacity=".5"/><rect x="14" y="4" width="4" height="18" rx="1" opacity=".6"/><rect x="20" y="10" width="4" height="12" rx="1" opacity=".4"/></svg>',
        content: `<section class="py-5 bg-primary text-white">
  <div class="container">
    <div class="row g-4 text-center">
      <div class="col-6 col-md-3">
        <div class="display-4 fw-bold">500+</div>
        <p class="mb-0 opacity-75">Clients satisfaits</p>
      </div>
      <div class="col-6 col-md-3">
        <div class="display-4 fw-bold">10</div>
        <p class="mb-0 opacity-75">Années d'expérience</p>
      </div>
      <div class="col-6 col-md-3">
        <div class="display-4 fw-bold">98%</div>
        <p class="mb-0 opacity-75">Taux de satisfaction</p>
      </div>
      <div class="col-6 col-md-3">
        <div class="display-4 fw-bold">24/7</div>
        <p class="mb-0 opacity-75">Support disponible</p>
      </div>
    </div>
  </div>
</section>`
    });

    bm.add('cp-team', {
        label: 'Équipe',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><circle cx="8" cy="8" r="3" opacity=".5"/><circle cx="16" cy="8" r="3" opacity=".5"/><path d="M2 20c0-4 3-6 6-6h8c3 0 6 2 6 6" opacity=".4"/></svg>',
        content: `<section class="py-5">
  <div class="container">
    <h2 class="fw-bold text-center mb-5">Notre équipe</h2>
    <div class="row g-4 justify-content-center">
      <div class="col-6 col-md-3 text-center">
        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mx-auto mb-3 fs-1 fw-bold" style="width:100px;height:100px;">AB</div>
        <h5 class="fw-bold mb-1">Alice Bernard</h5>
        <p class="text-muted small mb-2">Directrice Générale</p>
        <div class="d-flex justify-content-center gap-2"><a href="#" class="text-muted"><i class="bi bi-linkedin"></i></a><a href="#" class="text-muted"><i class="bi bi-envelope"></i></a></div>
      </div>
      <div class="col-6 col-md-3 text-center">
        <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mx-auto mb-3 fs-1 fw-bold" style="width:100px;height:100px;">PD</div>
        <h5 class="fw-bold mb-1">Pierre Dupont</h5>
        <p class="text-muted small mb-2">Responsable Technique</p>
        <div class="d-flex justify-content-center gap-2"><a href="#" class="text-muted"><i class="bi bi-linkedin"></i></a><a href="#" class="text-muted"><i class="bi bi-envelope"></i></a></div>
      </div>
      <div class="col-6 col-md-3 text-center">
        <div class="rounded-circle bg-warning text-dark d-flex align-items-center justify-content-center mx-auto mb-3 fs-1 fw-bold" style="width:100px;height:100px;">ML</div>
        <h5 class="fw-bold mb-1">Marie Laurent</h5>
        <p class="text-muted small mb-2">Chargée de clientèle</p>
        <div class="d-flex justify-content-center gap-2"><a href="#" class="text-muted"><i class="bi bi-linkedin"></i></a><a href="#" class="text-muted"><i class="bi bi-envelope"></i></a></div>
      </div>
    </div>
  </div>
</section>`
    });

    bm.add('cp-gallery', {
        label: 'Galerie photos',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="1" y="1" width="10" height="10" rx="1" opacity=".4"/><rect x="13" y="1" width="10" height="10" rx="1" opacity=".4"/><rect x="1" y="13" width="10" height="10" rx="1" opacity=".4"/><rect x="13" y="13" width="10" height="10" rx="1" opacity=".4"/></svg>',
        content: `<div class="container py-5">
  <h2 class="fw-bold text-center mb-5">Galerie</h2>
  <div class="row g-3">
    <div class="col-6 col-md-4"><img src="https://placehold.co/400x300/eef4fb/3498db?text=Photo+1" class="img-fluid rounded-3 w-100" alt="Photo 1" style="object-fit:cover;height:200px;"></div>
    <div class="col-6 col-md-4"><img src="https://placehold.co/400x300/e8f5e9/27ae60?text=Photo+2" class="img-fluid rounded-3 w-100" alt="Photo 2" style="object-fit:cover;height:200px;"></div>
    <div class="col-6 col-md-4"><img src="https://placehold.co/400x300/fff3e0/e67e22?text=Photo+3" class="img-fluid rounded-3 w-100" alt="Photo 3" style="object-fit:cover;height:200px;"></div>
    <div class="col-6 col-md-4"><img src="https://placehold.co/400x300/fce4ec/c62828?text=Photo+4" class="img-fluid rounded-3 w-100" alt="Photo 4" style="object-fit:cover;height:200px;"></div>
    <div class="col-6 col-md-4"><img src="https://placehold.co/400x300/ede7f6/6a1b9a?text=Photo+5" class="img-fluid rounded-3 w-100" alt="Photo 5" style="object-fit:cover;height:200px;"></div>
    <div class="col-6 col-md-4"><img src="https://placehold.co/400x300/e0f7fa/00838f?text=Photo+6" class="img-fluid rounded-3 w-100" alt="Photo 6" style="object-fit:cover;height:200px;"></div>
  </div>
</div>`
    });

    bm.add('cp-video', {
        label: 'Vidéo YouTube',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="2" y="4" width="20" height="16" rx="2" opacity=".3"/><path d="M10 8l6 4-6 4V8z" opacity=".8"/></svg>',
        content: `<div class="container py-4">
  <div class="ratio ratio-16x9 rounded-3 overflow-hidden shadow">
    <iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="Vidéo" allowfullscreen></iframe>
  </div>
</div>`
    });

    bm.add('cp-map', {
        label: 'Carte Google Maps',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" opacity=".7"/></svg>',
        content: `<div class="container py-4">
  <div class="ratio ratio-16x9 rounded-3 overflow-hidden shadow">
    <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2624.9916256937595!2d2.292292615!3d48.85837007928746!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x47e66e2964e34e2d%3A0x8ddca9ee380ef7e0!2sTour+Eiffel!5e0!3m2!1sfr!2sfr!4v1234567890" allowfullscreen loading="lazy" title="Carte"></iframe>
  </div>
</div>`
    });

    bm.add('cp-contact-section', {
        label: 'Section Contact',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z" opacity=".7"/></svg>',
        content: `<section class="py-5 bg-light">
  <div class="container">
    <div class="row g-5 justify-content-center">
      <div class="col-md-5">
        <h2 class="fw-bold mb-4">Contactez-nous</h2>
        <div class="d-flex align-items-start gap-3 mb-3">
          <i class="bi bi-geo-alt-fill text-primary fs-4 mt-1"></i>
          <div><strong>Adresse</strong><br>123 Rue de l'Exemple, 75000 Paris</div>
        </div>
        <div class="d-flex align-items-start gap-3 mb-3">
          <i class="bi bi-telephone-fill text-primary fs-4 mt-1"></i>
          <div><strong>Téléphone</strong><br>+33 1 23 45 67 89</div>
        </div>
        <div class="d-flex align-items-start gap-3 mb-3">
          <i class="bi bi-envelope-fill text-primary fs-4 mt-1"></i>
          <div><strong>Email</strong><br>contact@exemple.fr</div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card border-0 shadow-sm p-4">
          <form>
            <div class="mb-3"><label class="form-label fw-semibold">Nom complet</label><input type="text" class="form-control" placeholder="Votre nom"></div>
            <div class="mb-3"><label class="form-label fw-semibold">Email</label><input type="email" class="form-control" placeholder="votre@email.fr"></div>
            <div class="mb-3"><label class="form-label fw-semibold">Message</label><textarea class="form-control" rows="4" placeholder="Votre message…"></textarea></div>
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send me-2"></i>Envoyer</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>`
    });

    bm.add('cp-newsletter', {
        label: 'Newsletter',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="2" y="4" width="20" height="16" rx="2" opacity=".3"/><path d="M2 8l10 7 10-7" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>',
        content: `<section class="py-5 bg-primary text-white text-center">
  <div class="container" style="max-width:600px;">
    <h2 class="fw-bold mb-2">Restez informé</h2>
    <p class="lead opacity-75 mb-4">Inscrivez-vous à notre newsletter pour recevoir nos dernières nouvelles.</p>
    <div class="input-group input-group-lg">
      <input type="email" class="form-control" placeholder="votre@email.fr">
      <button class="btn btn-light fw-semibold px-4">S'inscrire</button>
    </div>
    <p class="small opacity-60 mt-2">Pas de spam. Désabonnement à tout moment.</p>
  </div>
</section>`
    });

    bm.add('cp-features', {
        label: 'Points forts (icônes)',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><circle cx="5" cy="5" r="3" opacity=".4"/><rect x="10" y="3" width="12" height="2" rx="1" opacity=".6"/><rect x="10" y="7" width="8" height="2" rx="1" opacity=".4"/><circle cx="5" cy="14" r="3" opacity=".4"/><rect x="10" y="12" width="12" height="2" rx="1" opacity=".6"/><rect x="10" y="16" width="8" height="2" rx="1" opacity=".4"/></svg>',
        content: `<section class="py-5">
  <div class="container">
    <div class="row g-4">
      <div class="col-md-6">
        <div class="d-flex align-items-start gap-3">
          <div class="bg-primary bg-opacity-10 rounded-3 p-3"><i class="bi bi-shield-check text-primary fs-3"></i></div>
          <div><h5 class="fw-bold">Sécurité garantie</h5><p class="text-muted mb-0">Description de ce point fort. Expliquez en quoi cela bénéficie à vos clients.</p></div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="d-flex align-items-start gap-3">
          <div class="bg-success bg-opacity-10 rounded-3 p-3"><i class="bi bi-clock-history text-success fs-3"></i></div>
          <div><h5 class="fw-bold">Disponible 24h/24</h5><p class="text-muted mb-0">Description de ce point fort. Ajoutez vos propres avantages ici.</p></div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="d-flex align-items-start gap-3">
          <div class="bg-warning bg-opacity-10 rounded-3 p-3"><i class="bi bi-star-fill text-warning fs-3"></i></div>
          <div><h5 class="fw-bold">Qualité premium</h5><p class="text-muted mb-0">Description de ce point fort. Mettez en valeur votre expertise.</p></div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="d-flex align-items-start gap-3">
          <div class="bg-info bg-opacity-10 rounded-3 p-3"><i class="bi bi-people-fill text-info fs-3"></i></div>
          <div><h5 class="fw-bold">Équipe dédiée</h5><p class="text-muted mb-0">Description de ce point fort. Parlez de votre équipe et de votre accompagnement.</p></div>
        </div>
      </div>
    </div>
  </div>
</section>`
    });

    bm.add('cp-breadcrumb', {
        label: 'Fil d\'Ariane',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><path d="M2 12l4-4v3h12v-3l4 4-4 4v-3H6v3L2 12z" opacity=".5"/></svg>',
        content: `<div class="container py-3">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="/">Accueil</a></li>
      <li class="breadcrumb-item"><a href="#">Section</a></li>
      <li class="breadcrumb-item active">Page actuelle</li>
    </ol>
  </nav>
</div>`
    });

    bm.add('cp-progress', {
        label: 'Barres de progression',
        category: 'Composants',
        media: '<svg viewBox="0 0 24 24" fill="currentColor" width="40" height="40"><rect x="2" y="5" width="20" height="4" rx="2" opacity=".2"/><rect x="2" y="5" width="14" height="4" rx="2" opacity=".6"/><rect x="2" y="12" width="20" height="4" rx="2" opacity=".2"/><rect x="2" y="12" width="18" height="4" rx="2" opacity=".5"/><rect x="2" y="19" width="20" height="4" rx="2" opacity=".2"/><rect x="2" y="19" width="9" height="4" rx="2" opacity=".4"/></svg>',
        content: `<div class="container py-4">
  <h4 class="fw-bold mb-4">Nos compétences</h4>
  <div class="mb-3">
    <div class="d-flex justify-content-between mb-1"><span class="fw-semibold">Gestion locative</span><span>95%</span></div>
    <div class="progress" style="height:10px;"><div class="progress-bar bg-primary" style="width:95%"></div></div>
  </div>
  <div class="mb-3">
    <div class="d-flex justify-content-between mb-1"><span class="fw-semibold">Service client</span><span>90%</span></div>
    <div class="progress" style="height:10px;"><div class="progress-bar bg-success" style="width:90%"></div></div>
  </div>
  <div class="mb-3">
    <div class="d-flex justify-content-between mb-1"><span class="fw-semibold">Expertise juridique</span><span>80%</span></div>
    <div class="progress" style="height:10px;"><div class="progress-bar bg-warning" style="width:80%"></div></div>
  </div>
</div>`
    });

    // Extract all <style> blocks from an HTML string and return { html, css }.
    function extractStyles(raw) {
        var css = '';
        var html = raw.replace(/<style[^>]*>([\s\S]*?)<\/style>/gi, function (match, content) {
            css += content + '\n';
            return '';
        });
        return { html: html.trim(), css: css.trim() };
    }

    // Build a combined string: <style>…</style>\n<html…>
    function buildCombined(html, css) {
        if (!css) return html;
        return '<style>\n' + css + '\n</style>\n' + html;
    }

    // Load initial content, preserving any saved CSS
    if (initialHtml) {
        var parsed = extractStyles(initialHtml);
        if (parsed.css) { editor.setStyle(parsed.css); }
        if (parsed.html) { editor.setComponents(parsed.html); }
    }

    // Toggle: Visual ↔ Raw HTML
    function switchToVisual() {
        var raw = rawTextarea.value;
        if (raw) {
            var parsed = extractStyles(raw);
            editor.setStyle(parsed.css || '');
            editor.setComponents(parsed.html);
        }
        gjsContainer.style.display = '';
        rawWrapper.style.display = 'none';
        btnVisual.classList.add('active');
        btnRaw.classList.remove('active');
    }

    function switchToRaw() {
        rawTextarea.value = buildCombined(editor.getHtml() || '', editor.getCss() || '');
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
                hiddenInput.value = buildCombined(editor.getHtml() || '', editor.getCss() || '');
            }
        });

        // Prevent Enter key from submitting the form when typing inside GrapesJS panels
        // (e.g. when adding a CSS class to an element via the selector/style manager)
        pageForm.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.closest('#gjs')) {
                e.preventDefault();
            }
        });
    }
}());
</script>
</body>
</html>
