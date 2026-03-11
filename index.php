<?php
/**
 * Page d'accueil dynamique
 * My Invest Immobilier
 *
 * Affiche la page marquée comme "page d'accueil" dans la table frontend_pages
 * (champ is_homepage = 1). Si aucune page d'accueil n'est définie, redirige
 * vers le portail locataire (/locataire/).
 *
 * Le portail locataire est désormais accessible sur /locataire/.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header-frontoffice.php';

$companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
$siteUrl     = rtrim($config['SITE_URL'] ?? '', '/');

// ── Charger la page d'accueil définie en base ─────────────────────────────────
$homePage = null;
try {
    $stmt = $pdo->query("
        SELECT titre, contenu_html, meta_description
        FROM frontend_pages
        WHERE is_homepage = 1 AND actif = 1
        LIMIT 1
    ");
    $homePage = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
} catch (Exception $e) {
    // Table may not exist yet (migration pending) — fall through to redirect
    error_log('index.php homepage query error: ' . $e->getMessage());
}

// If no homepage is defined in the CMS, redirect to the tenant portal
if (!$homePage) {
    header('Location: /locataire/');
    exit;
}

$pageTitle = htmlspecialchars($homePage['titre']);
$metaDesc  = htmlspecialchars($homePage['meta_description'] ?? '');

/**
 * Process [contact-form id=N] shortcodes embedded in page content.
 * Returns the HTML with shortcodes replaced by rendered forms.
 */
function processShortcodes(string $html, \PDO $pdo, string $siteUrl): string
{
    return preg_replace_callback(
        '/\[contact-form\s+id=["\']?(\d+)["\']?\]/i',
        function (array $m) use ($pdo, $siteUrl): string {
            $formId = (int)$m[1];
            try {
                $stmt = $pdo->prepare("SELECT * FROM contact_forms WHERE id = ? AND actif = 1 LIMIT 1");
                $stmt->execute([$formId]);
                $form = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$form) {
                    return '<!-- contact-form #' . $formId . ' not found -->';
                }
                $stmtF = $pdo->prepare("SELECT * FROM contact_form_fields WHERE form_id = ? ORDER BY ordre ASC, id ASC");
                $stmtF->execute([$formId]);
                $fields = $stmtF->fetchAll(\PDO::FETCH_ASSOC);
                return renderContactFormHtml($form, $fields, $siteUrl);
            } catch (\Exception $e) {
                return '<!-- contact-form error: ' . htmlspecialchars($e->getMessage()) . ' -->';
            }
        },
        $html
    );
}

/**
 * Renders the HTML for a contact form.
 */
function renderContactFormHtml(array $form, array $fields, string $siteUrl): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $csrfKey = 'csrf_contact_form_' . (int)$form['id'];
    if (empty($_SESSION[$csrfKey])) {
        $_SESSION[$csrfKey] = bin2hex(random_bytes(16));
    }
    $csrfToken = $_SESSION[$csrfKey];

    $formId = (int)$form['id'];
    $html  = '<form method="POST" action="' . htmlspecialchars($siteUrl . '/contact-form-submit.php') . '" ';
    $html .= 'class="contact-form-shortcode" data-form-id="' . $formId . '">';
    $html .= '<input type="hidden" name="form_id" value="' . $formId . '">';
    $html .= '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
    foreach ($fields as $field) {
        $name  = htmlspecialchars($field['nom_champ']);
        $label = htmlspecialchars($field['label']);
        $ph    = htmlspecialchars($field['placeholder'] ?? '');
        $req   = $field['requis'] ? ' required' : '';
        $html .= '<div class="mb-3">';
        $html .= '<label class="form-label">' . $label . ($field['requis'] ? ' <span class="text-danger">*</span>' : '') . '</label>';
        switch ($field['type_champ']) {
            case 'textarea':
                $html .= '<textarea name="' . $name . '" class="form-control" rows="4" placeholder="' . $ph . '"' . $req . '></textarea>';
                break;
            case 'select':
                $opts = array_filter(array_map('trim', explode('|', $field['options'] ?? '')));
                $html .= '<select name="' . $name . '" class="form-select"' . $req . '>';
                $html .= '<option value="">— Choisir —</option>';
                foreach ($opts as $opt) {
                    $html .= '<option value="' . htmlspecialchars($opt) . '">' . htmlspecialchars($opt) . '</option>';
                }
                $html .= '</select>';
                break;
            case 'checkbox':
                $html .= '<div class="form-check">';
                $html .= '<input class="form-check-input" type="checkbox" name="' . $name . '" id="cf_' . $formId . '_' . $name . '" value="1"' . $req . '>';
                $html .= '<label class="form-check-label" for="cf_' . $formId . '_' . $name . '">' . $label . '</label>';
                $html .= '</div>';
                break;
            default:
                $html .= '<input type="' . htmlspecialchars($field['type_champ']) . '" name="' . $name . '" class="form-control" placeholder="' . $ph . '"' . $req . '>';
        }
        $html .= '</div>';
    }
    $html .= '<button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Envoyer</button>';
    $html .= '</form>';
    return $html;
}

// ── Menu de navigation ────────────────────────────────────────────────────────
$menuItems = getFrontOfficeMenuItems();
$currentUri = '/';

$navHtml = '';
if ($menuItems) {
    $navHtml = '<nav class="fo-nav d-none d-lg-flex align-items-center gap-1">';
    foreach ($menuItems as $item) {
        $isActive = ($item['url'] === $currentUri) ? ' active' : '';
        $targetAttr = ($item['target'] === '_blank') ? ' target="_blank" rel="noopener"' : '';
        $iconHtml = !empty($item['icone']) ? '<i class="bi ' . htmlspecialchars($item['icone']) . ' me-1"></i>' : '';
        $navHtml .= '<a class="fo-nav-link' . $isActive . '" href="' . htmlspecialchars($item['url']) . '"' . $targetAttr . '>'
                  . $iconHtml . htmlspecialchars($item['label']) . '</a>';
    }
    $navHtml .= '</nav>';
}
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
        footer { background: #2c3e50; color: rgba(255,255,255,0.7); padding: 24px 0; text-align: center; font-size: 0.85rem; margin-top: 40px; }
        footer a { color: rgba(255,255,255,0.8); text-decoration: none; }
        footer a:hover { color: #fff; }
    </style>
</head>
<body>
<?php renderFrontOfficeHeader($siteUrl, $companyName, $navHtml ?: false); ?>

<main>
    <div class="page-content-wrapper">
        <?php if (isset($_GET['cf_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4">
            <i class="bi bi-check-circle me-2"></i>
            <?php
            $cfFormId = isset($_GET['cf_form']) ? (int)$_GET['cf_form'] : 0;
            $cfMsg = 'Votre message a bien été envoyé. Nous vous répondrons dans les plus brefs délais.';
            if ($cfFormId > 0) {
                try {
                    $stmtCf = $pdo->prepare("SELECT message_confirmation FROM contact_forms WHERE id = ? LIMIT 1");
                    $stmtCf->execute([$cfFormId]);
                    $cfRow = $stmtCf->fetch(PDO::FETCH_ASSOC);
                    if ($cfRow && trim($cfRow['message_confirmation']) !== '') {
                        $cfMsg = $cfRow['message_confirmation'];
                    }
                } catch (Exception $e) {}
            }
            echo htmlspecialchars($cfMsg);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php elseif (isset($_GET['cf_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($_GET['cf_error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php
        // The HTML content is stored by authenticated admin users only.
        // Only trusted admin users should have access to the pages-frontoffice.php editor.
        // Shortcodes like [contact-form id=N] are processed before output.
        echo processShortcodes($homePage['contenu_html'], $pdo, $siteUrl);
        ?>
    </div>
</main>

<footer>
    <div class="container">
        <p class="mb-1">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($companyName); ?> — Tous droits réservés</p>
        <p class="mb-0">
            <?php
            $footerLinks = [];
            foreach ($menuItems as $item) {
                $footerLinks[] = '<a href="' . htmlspecialchars($item['url']) . '">' . htmlspecialchars($item['label']) . '</a>';
            }
            echo implode('&nbsp;·&nbsp;', $footerLinks);
            ?>
        </p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
