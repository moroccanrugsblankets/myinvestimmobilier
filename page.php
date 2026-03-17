<?php
/**
 * Rendu dynamique des pages frontoffice
 * My Invest Immobilier
 *
 * URL legacy  : /page.php?slug=<slug>
 * URL SEO     : /<slug>  (redirigé par .htaccess)
 *
 * Les pages sont stockées dans la table `frontend_pages` et gérables
 * depuis Admin → Site public → Pages.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/header-frontoffice.php';

// Support both legacy (?slug=) and SEO-friendly URL (rewritten by .htaccess)
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
            SELECT titre, meta_title, contenu_html, meta_description, show_titre_bloc
            FROM frontend_pages
            WHERE slug = ? AND actif = 1
            LIMIT 1
        ");
        $stmt->execute([$slug]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('page.php DB error: ' . $e->getMessage());
        // Retry without meta_title / show_titre_bloc in case columns don't exist yet
        try {
            $stmt = $pdo->prepare("
                SELECT titre, contenu_html, meta_description
                FROM frontend_pages
                WHERE slug = ? AND actif = 1
                LIMIT 1
            ");
            $stmt->execute([$slug]);
            $page = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {
            error_log('page.php DB error (retry): ' . $e2->getMessage());
        }
    }
}

if (!$page) {
    http_response_code(404);
}

$companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
$siteUrl     = rtrim($config['SITE_URL'] ?? '', '/');
$pageTitle   = $page ? htmlspecialchars($page['titre']) : 'Page introuvable';
$metaTitle   = $page ? htmlspecialchars(!empty($page['meta_title']) ? $page['meta_title'] : $page['titre']) : 'Page introuvable';
$metaDesc    = $page ? htmlspecialchars($page['meta_description'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $metaTitle; ?> — <?php echo htmlspecialchars($companyName); ?></title>
    <?php if ($metaDesc): ?>
    <meta name="description" content="<?php echo $metaDesc; ?>">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(rtrim($siteUrl, '/') . '/assets/css/frontoffice.css'); ?>">
    <style>
        .page-content-wrapper {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            padding: 0px 48px;
            margin: 20px auto;
            max-width: 1400px;
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
// Support both legacy (/page.php?slug=X) and SEO-friendly (/X/) URL formats for active detection
$currentUrlSeoNoSlash = '/' . $slug;

/**
 * Loads global reCAPTCHA configuration from the parametres table.
 * Returns an array with keys: enabled (bool), type (string), site_key (string).
 */
function loadRcConfig(\PDO $pdo): array
{
    $rc = ['enabled' => false, 'type' => 'v2', 'site_key' => ''];
    try {
        $stmt = $pdo->prepare("SELECT cle, valeur FROM parametres WHERE groupe = 'recaptcha'");
        $stmt->execute();
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            switch ($row['cle']) {
                case 'recaptcha_enabled':
                    $rc['enabled'] = ($row['valeur'] === '1' || $row['valeur'] === 'true');
                    break;
                case 'recaptcha_type':
                    $rc['type'] = $row['valeur'];
                    break;
                case 'recaptcha_site_key':
                    $rc['site_key'] = $row['valeur'];
                    break;
            }
        }
    } catch (\Exception $e) {
        // parametres table may not exist yet — degrade gracefully
    }
    return $rc;
}

/**
 * Process shortcodes embedded in page content.
 *
 * Supported shortcodes:
 *   [contact-form id=N]   — renders a dynamic contact form
 *   [search-logements]    — renders a property-search form pointing to logements.php
 *
 * Returns the HTML with shortcodes replaced by rendered HTML.
 */
function processShortcodes(string $html, \PDO $pdo, string $siteUrl): string
{
    // [search-logements] — search box that redirects to the properties listing page
    $html = preg_replace_callback(
        '/\[search-logements(?:\s[^\]]*)?\]/i',
        function () use ($siteUrl): string {
            return renderSearchLogementsHtml($siteUrl);
        },
        $html
    );

    // Load reCAPTCHA config once for all contact-form shortcodes on this page
    $rcConfig = loadRcConfig($pdo);

    // [contact-form id=N]
    $html = preg_replace_callback(
        '/\[contact-form\s+id=["\']?(\d+)["\']?\]/i',
        function (array $m) use ($pdo, $siteUrl, $rcConfig): string {
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
                return renderContactFormHtml($form, $fields, $siteUrl, $rcConfig);
            } catch (\Exception $e) {
                return '<!-- contact-form error: ' . htmlspecialchars($e->getMessage()) . ' -->';
            }
        },
        $html
    );

    return $html;
}

/**
 * Renders a property-search form that redirects to /logements.php?ref=<value>.
 * Submits on button click OR pressing the Enter key.
 */
function renderSearchLogementsHtml(string $siteUrl): string
{
    $action = htmlspecialchars(rtrim($siteUrl, '/') . '/logements.php');
    return '<form method="GET" action="' . $action . '" class="search-logements-form d-flex gap-2" role="search">'
        . '<input type="text" name="ref" class="form-control form-control-lg"'
        . ' placeholder="Référence du logement (ex : T2-PARIS-01)"'
        . ' aria-label="Référence du logement">'
        . '<button type="submit" class="btn btn-warning btn-lg px-4">'
        . '<i class="bi bi-search me-1"></i>Rechercher'
        . '</button>'
        . '</form>';
}

/**
 * Renders the HTML for a contact form.
 *
 * @param array  $rcConfig  reCAPTCHA config from loadRcConfig() — keys: enabled, type, site_key
 */
function renderContactFormHtml(array $form, array $fields, string $siteUrl, array $rcConfig = []): string
{
    // Generate a CSRF token for this form and store it in session
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
        // reCAPTCHA field is rendered differently — no wrapper label/div from the standard path
        if ($field['type_champ'] === 'recaptcha') {
            $rcEnabled = !empty($rcConfig['enabled']);
            $rcSiteKey = $rcConfig['site_key'] ?? '';
            $rcType    = $rcConfig['type'] ?? 'v2';
            if ($rcEnabled && $rcSiteKey !== '') {
                if ($rcType === 'v3') {
                    // V3: invisible — inject a hidden input populated via JS before submit
                    $html .= '<input type="hidden" name="recaptcha_response" id="rcResp_' . $formId . '" value="">';
                    $html .= '<script src="https://www.google.com/recaptcha/api.js?render='
                           . htmlspecialchars($rcSiteKey) . '"></script>';
                    $html .= '<script>(function(){'
                           . 'var f=document.querySelector(\'.contact-form-shortcode[data-form-id="' . $formId . '"]\');'
                           . 'if(!f)return;'
                           . 'var tokenReady=false;'
                           . 'f.addEventListener("submit",function(e){'
                           . 'if(tokenReady)return;'
                           . 'e.preventDefault();var s=this;'
                           . 'grecaptcha.ready(function(){'
                           . 'grecaptcha.execute(' . json_encode($rcSiteKey) . ',{action:"contact_form_' . $formId . '"}).then(function(t){'
                           . 'document.getElementById("rcResp_' . $formId . '").value=t;'
                           . 'tokenReady=true;'
                           . 's.requestSubmit?s.requestSubmit():s.submit();'
                           . '});});});'
                           . '}());</script>';
                } else {
                    // V2: visible "I'm not a robot" checkbox widget
                    $html .= '<div class="mb-3">'
                           . '<div class="g-recaptcha" data-sitekey="' . htmlspecialchars($rcSiteKey) . '"></div>'
                           . '</div>';
                    $html .= '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
                }
            }
            // If reCAPTCHA is not yet configured/enabled, skip the field silently
            continue;
        }

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

// Load menu items for footer (header menu is auto-loaded via renderFrontOfficeHeader)
$menuItems = [];
try {
    $stmtMenu = $pdo->query("SELECT label, url FROM frontend_menu_items WHERE actif = 1 ORDER BY ordre ASC");
    $menuItems = $stmtMenu->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // menu table might not exist yet
}

renderFrontOfficeHeader($siteUrl, $companyName, null, $currentUrlSeoNoSlash);
?>

<main>
<?php if ($page):
    $showTitreBloc = !array_key_exists('show_titre_bloc', $page) || (int)$page['show_titre_bloc'] !== 0;
?>
<?php if ($showTitreBloc): ?>
<div class="page-titre-bloc">
    <div class="container">
        <h1><?php echo htmlspecialchars($page['titre']); ?></h1>
    </div>
</div>
<?php endif; ?>
    <div class="page-content-wrapper">
        <?php
        // Show contact form submission feedback (success/error from contact-form-submit.php redirect)
        if (isset($_GET['cf_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4">
            <i class="bi bi-check-circle me-2"></i>
            <?php
            // Fetch the confirmation message from the submitted form
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
        // It is displayed as-is since it is administrator-controlled content (CMS-style).
        // Only trusted admin users should have access to the pages-frontoffice.php editor.
        // Shortcodes like [contact-form id=N] are processed before output.
        echo processShortcodes($page['contenu_html'], $pdo, $siteUrl);
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

<?php renderFrontOfficeFooter($companyName); ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
