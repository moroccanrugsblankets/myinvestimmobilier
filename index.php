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
        SELECT titre, meta_title, contenu_html, meta_description
        FROM frontend_pages
        WHERE is_homepage = 1 AND actif = 1
        LIMIT 1
    ");
    $homePage = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
} catch (Exception $e) {
    // meta_title column may not exist yet — retry without it
    try {
        $stmt = $pdo->query("
            SELECT titre, contenu_html, meta_description
            FROM frontend_pages
            WHERE is_homepage = 1 AND actif = 1
            LIMIT 1
        ");
        $homePage = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    } catch (Exception $e2) {
        error_log('index.php homepage query error: ' . $e2->getMessage());
    }
}

// If no homepage is defined in the CMS, redirect to the tenant portal
if (!$homePage) {
    header('Location: /locataire/');
    exit;
}

$pageTitle = htmlspecialchars($homePage['titre']);
$metaTitle = htmlspecialchars(!empty($homePage['meta_title']) ? $homePage['meta_title'] : $homePage['titre']);
$metaDesc  = htmlspecialchars($homePage['meta_description'] ?? '');

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

    // [contact-form id=N]
    $html = preg_replace_callback(
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

    return $html;
}

/**
 * Renders a property-search form that redirects to /logements.php?ref=<value>.
 * Submits on button click OR pressing the Enter key.
 */
/*
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
*/

function renderSearchLogementsHtml(string $siteUrl): string
{
    $action = htmlspecialchars(rtrim($siteUrl, '/') . '/logements.php');
    return '<form method="GET" action="' . $action . '" class="search-logements-form" role="search">'
        . '<div class="search-icon">🔍</div>'
        . '<div class="search-text">'
        . '<label>Référence logement :</label>'
        . '<input type="text" name="ref" class="form-control" placeholder="Ex: RF-001" aria-label="Référence">'
        . '</div>'
        . '<button type="submit" class="search-btn"></button>'
        . '</form>';
}
/**
 * Renders the HTML for a contact form.
 */
function renderContactFormHtml(array $form, array $fields, string $siteUrl): string
{
    global $pdo;

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $csrfKey = 'csrf_contact_form_' . (int)$form['id'];
    if (empty($_SESSION[$csrfKey])) {
        $_SESSION[$csrfKey] = bin2hex(random_bytes(16));
    }
    $csrfToken = $_SESSION[$csrfKey];

    // Load reCAPTCHA global settings
    $rcEnabled   = false;
    $rcType      = 'v2';
    $rcSiteKey   = '';
    $hasRcField  = false;
    try {
        $stmtRc = $pdo->prepare("SELECT cle, valeur FROM parametres WHERE groupe = 'recaptcha'");
        $stmtRc->execute();
        foreach ($stmtRc->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            switch ($row['cle']) {
                case 'recaptcha_enabled':
                    $rcEnabled = ($row['valeur'] === '1' || $row['valeur'] === 'true');
                    break;
                case 'recaptcha_type':
                    $rcType = $row['valeur'];
                    break;
                case 'recaptcha_site_key':
                    $rcSiteKey = $row['valeur'];
                    break;
            }
        }
    } catch (\Exception $e) { /* ignore — reCAPTCHA simply won't show */ }

    // Check if this form has a recaptcha field
    foreach ($fields as $field) {
        if ($field['type_champ'] === 'recaptcha') {
            $hasRcField = true;
            break;
        }
    }

    $showRecaptcha = $hasRcField && $rcEnabled && $rcSiteKey !== '';
    $formId = (int)$form['id'];

    $html  = '<form method="POST" action="' . htmlspecialchars($siteUrl . '/contact-form-submit.php') . '" ';
    if ($showRecaptcha && $rcType === 'v3') {
        $html .= 'id="cf_form_' . $formId . '" ';
    }
    $html .= 'class="contact-form-shortcode" data-form-id="' . $formId . '">';
    $html .= '<input type="hidden" name="form_id" value="' . $formId . '">';
    $html .= '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
    if ($showRecaptcha && $rcType === 'v3') {
        $html .= '<input type="hidden" name="recaptcha_response" id="cf_rc_token_' . $formId . '">';
    }
    foreach ($fields as $field) {
        if ($field['type_champ'] === 'recaptcha') {
            if (!$showRecaptcha) {
                continue; // reCAPTCHA disabled globally — skip field silently
            }
            $html .= '<div class="mb-3">';
            if ($rcType === 'v2') {
                $html .= '<div class="g-recaptcha" data-sitekey="' . htmlspecialchars($rcSiteKey) . '"></div>';
            }
            // V3: the token is submitted via the hidden field populated by JS
            $html .= '</div>';
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

    // Submit button — V3: intercept submit to get token first
    if ($showRecaptcha && $rcType === 'v3') {
        $siteKeyJs = json_encode($rcSiteKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $html .= '<button type="button" class="btn btn-primary" onclick="cfSubmitV3_' . $formId . '(this)">';
        $html .= '<i class="bi bi-send me-1"></i>Envoyer</button>';
        $html .= '<script>function cfSubmitV3_' . $formId . '(btn){btn.disabled=true;';
        $html .= 'grecaptcha.ready(function(){grecaptcha.execute(' . $siteKeyJs . ',{action:\'contact_form_' . $formId . '\'}).then(function(token){';
        $html .= 'document.getElementById(\'cf_rc_token_' . $formId . '\').value=token;';
        $html .= 'document.getElementById(\'cf_form_' . $formId . '\').submit();});});}</script>';
    } else {
        $html .= '<button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Envoyer</button>';
    }

    $html .= '</form>';
    return $html;
}

// ── Menu de navigation ────────────────────────────────────────────────────────
$menuItems = getFrontOfficeMenuItems();
$currentUri = '/';

// ── reCAPTCHA: check if we need to load the script ───────────────────────────
$rcEnabledGlobal = false;
$rcTypeGlobal    = 'v2';
$rcSiteKeyGlobal = '';
try {
    $stmtRcGlobal = $pdo->prepare("SELECT cle, valeur FROM parametres WHERE groupe = 'recaptcha'");
    $stmtRcGlobal->execute();
    foreach ($stmtRcGlobal->fetchAll(PDO::FETCH_ASSOC) as $rr) {
        if ($rr['cle'] === 'recaptcha_enabled')  $rcEnabledGlobal = ($rr['valeur'] === '1' || $rr['valeur'] === 'true');
        if ($rr['cle'] === 'recaptcha_type')     $rcTypeGlobal    = $rr['valeur'];
        if ($rr['cle'] === 'recaptcha_site_key') $rcSiteKeyGlobal = $rr['valeur'];
    }
} catch (Exception $e) { /* ignore */ }
$loadRcScript = $rcEnabledGlobal && $rcSiteKeyGlobal !== '';
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
    <?php if ($loadRcScript): ?>
    <?php if ($rcTypeGlobal === 'v3'): ?>
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo htmlspecialchars($rcSiteKeyGlobal); ?>"></script>
    <?php else: ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
    <?php endif; ?>
</head>
<body>
<?php renderFrontOfficeHeader($siteUrl, $companyName, null, $currentUri); ?>

<main>
    <div class="homepage-content">
        <?php if (isset($_GET['cf_success']) || isset($_GET['cf_error'])): ?>
        <div class="homepage-alerts">
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
<!-- Footer -->
<?php renderFrontOfficeFooter($companyName); ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
