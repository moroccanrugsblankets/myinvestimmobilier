<?php
/**
 * Rendu dynamique des pages frontoffice
 * My Invest Immobilier
 *
 * URL : /page.php?slug=<slug>
 *
 * Les pages sont stockées dans la table `frontend_pages` et gérables
 * depuis Admin → Site public → Pages.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/header-frontoffice.php';

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';

// Valider le slug (alphanumérique + tirets uniquement)
if (!preg_match('/^[a-z0-9\-]{1,100}$/', $slug)) {
    http_response_code(404);
    $slug = '';
}

$page = null;
if ($slug !== '') {
    try {
        $stmt = $pdo->prepare("
            SELECT titre, contenu_html, meta_description
            FROM frontend_pages
            WHERE slug = ? AND actif = 1
            LIMIT 1
        ");
        $stmt->execute([$slug]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('page.php DB error: ' . $e->getMessage());
    }
}

if (!$page) {
    http_response_code(404);
}

$companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
$siteUrl     = rtrim($config['SITE_URL'] ?? '', '/');
$pageTitle   = $page ? htmlspecialchars($page['titre']) : 'Page introuvable';
$metaDesc    = $page ? htmlspecialchars($page['meta_description'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> — <?php echo htmlspecialchars($companyName); ?></title>
    <?php if ($metaDesc): ?>
    <meta name="description" content="<?php echo $metaDesc; ?>">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f0f4f8; color: #2c3e50; }
        .site-header { background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 15px 0; margin-bottom: 0; }
        .site-header .brand { font-size: 1.2rem; font-weight: 700; color: #2c3e50; }
        .header-logo { max-height: 50px; max-width: 160px; object-fit: contain; }
        .nav-frontoffice { display: flex; gap: 0.25rem; flex-wrap: wrap; align-items: center; }
        .nav-frontoffice .nav-link { color: #555; font-weight: 500; padding: 6px 12px; border-radius: 6px; transition: background .2s, color .2s; }
        .nav-frontoffice .nav-link:hover,
        .nav-frontoffice .nav-link.active { background: #eef4fb; color: #3498db; }
        .page-content-wrapper {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            padding: 40px;
            margin: 30px auto;
            max-width: 960px;
        }
        .page-content-wrapper h1 { color: #2c3e50; }
        .page-content-wrapper h2 { color: #2c3e50; font-size: 1.5rem; }
        .page-content-wrapper h3 { color: #3498db; font-size: 1.2rem; }
        .page-content-wrapper a { color: #3498db; }
        .not-found-box {
            text-align: center;
            padding: 60px 20px;
            color: #888;
        }
        footer { background: #2c3e50; color: rgba(255,255,255,0.7); padding: 24px 0; text-align: center; font-size: 0.85rem; margin-top: 40px; }
        footer a { color: rgba(255,255,255,0.8); text-decoration: none; }
        footer a:hover { color: #fff; }
    </style>
</head>
<body>
<?php
// Load configurable menu for navigation
$menuItems = [];
try {
    $stmtMenu = $pdo->query("SELECT label, url, target, icone FROM frontend_menu_items WHERE actif = 1 ORDER BY ordre ASC");
    $menuItems = $stmtMenu->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // menu table might not exist yet
}

$currentUrl = '/page.php?slug=' . urlencode($slug);

$navHtml = '';
if ($menuItems) {
    $navHtml = '<nav class="nav-frontoffice">';
    foreach ($menuItems as $item) {
        $isActive = ($item['url'] === $currentUrl) ? ' active' : '';
        $targetAttr = ($item['target'] === '_blank') ? ' target="_blank" rel="noopener"' : '';
        $iconHtml = !empty($item['icone']) ? '<i class="bi ' . htmlspecialchars($item['icone']) . ' me-1"></i>' : '';
        $navHtml .= '<a class="nav-link' . $isActive . '" href="' . htmlspecialchars($item['url']) . '"' . $targetAttr . '>'
                  . $iconHtml . htmlspecialchars($item['label']) . '</a>';
    }
    $navHtml .= '</nav>';
}

renderFrontOfficeHeader($siteUrl, $companyName, $navHtml);
?>

<main>
<?php if ($page): ?>
    <div class="page-content-wrapper">
        <?php
        // The HTML content is stored by authenticated admin users only.
        // It is displayed as-is since it is administrator-controlled content (CMS-style).
        // Only trusted admin users should have access to the pages-frontoffice.php editor.
        echo $page['contenu_html'];
        ?>
    </div>
<?php else: ?>
    <div class="container">
        <div class="not-found-box">
            <i class="bi bi-exclamation-triangle" style="font-size: 4rem; color: #e74c3c;"></i>
            <h1 class="mt-3">Page introuvable</h1>
            <p>La page demandée n'existe pas ou n'est plus disponible.</p>
            <a href="<?php echo htmlspecialchars($siteUrl); ?>/logements.php" class="btn btn-primary mt-2">
                <i class="bi bi-house me-1"></i>Retour à l'accueil
            </a>
        </div>
    </div>
<?php endif; ?>
</main>

<footer>
    <div class="container">
        <p class="mb-1">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($companyName); ?> — Tous droits réservés</p>
        <p class="mb-0">
            <a href="<?php echo htmlspecialchars($siteUrl); ?>/logements.php">Logements</a>
            <?php foreach ($menuItems as $item): ?>
            &nbsp;·&nbsp;
            <a href="<?php echo htmlspecialchars($item['url']); ?>"><?php echo htmlspecialchars($item['label']); ?></a>
            <?php endforeach; ?>
        </p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
