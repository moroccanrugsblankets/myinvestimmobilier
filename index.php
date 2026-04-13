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
 * Renders the HTML for a contact form.
 * Note: renderSearchLogementsHtml() and processShortcodes() are defined in includes/functions.php.
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
    $actionUrl = htmlspecialchars($siteUrl . '/contact-form-submit.php');

    $html  = '<form method="POST" action="' . $actionUrl . '" ';
    $html .= 'id="cf_form_' . $formId . '" class="contact-form-shortcode" data-form-id="' . $formId . '">';
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

    $html .= '<div id="cf-msg-' . $formId . '" style="display:none" class="mt-3" role="alert"></div>';
    $html .= '<button type="submit" class="btn btn-primary" id="cf-btn-' . $formId . '"><i class="bi bi-send me-1"></i>Envoyer</button>';
    $html .= '</form>';

    // ── AJAX submission handler ───────────────────────────────────────────────
    $rcTypeJs = $showRecaptcha ? json_encode($rcType, JSON_HEX_TAG) : '""';
    $rcKeyJs  = ($showRecaptcha && $rcType === 'v3')
        ? json_encode($rcSiteKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        : '""';
    $html .= '<script>(function(){';
    $html .= 'var fid=' . $formId . ';';
    $html .= 'var rcType=' . $rcTypeJs . ';';
    $html .= 'var rcKey=' . $rcKeyJs . ';';
    $html .= 'var form=document.getElementById("cf_form_"+fid);';
    $html .= 'var btn=document.getElementById("cf-btn-"+fid);';
    $html .= 'var msg=document.getElementById("cf-msg-"+fid);';
    $html .= 'if(!form)return;';
    $html .= 'function showMsg(cls,txt){';
    $html .= 'var icon=cls==="success"?"bi-check-circle":"bi-exclamation-triangle";';
    $html .= 'msg.className="alert alert-"+cls+" mt-3";';
    $html .= 'msg.innerHTML=\'<i class="bi \'+icon+\' me-2"></i>\'+txt;';
    $html .= 'msg.style.display="block";';
    $html .= 'msg.scrollIntoView({behavior:"smooth",block:"nearest"});';
    $html .= '}';
    $html .= 'function setLoading(on){';
    $html .= 'btn.disabled=on;';
    $html .= 'if(on){btn._orig=btn.innerHTML;btn.innerHTML=\'<span class="spinner-border spinner-border-sm me-1" role="status"></span>Envoi...\';}';
    $html .= 'else if(btn._orig){btn.innerHTML=btn._orig;}';
    $html .= '}';
    $html .= 'function doAjax(){';
    $html .= 'var fd=new FormData(form);';
    $html .= 'fetch(form.action,{method:"POST",headers:{"X-Requested-With":"XMLHttpRequest"},body:fd})';
    $html .= '.then(function(r){return r.json();})';
    $html .= '.then(function(d){';
    $html .= 'setLoading(false);';
    $html .= 'var csrfEl=form.querySelector("[name=\'csrf_token\']");';
    $html .= 'if(d.csrf_token&&csrfEl)csrfEl.value=d.csrf_token;';
    $html .= 'if(d.success){';
    $html .= 'showMsg("success",d.message||"Votre message a bien \u00e9t\u00e9 envoy\u00e9.");';
    $html .= 'form.reset();';
    $html .= 'if(typeof grecaptcha!=="undefined"){try{grecaptcha.reset();}catch(e){}}';
    $html .= 'var rcf=document.getElementById("cf_rc_token_"+fid);if(rcf)rcf.value="";';
    $html .= '}else{';
    $html .= 'var txt=d.error||"Une erreur est survenue.";';
    $html .= 'if(d.reload)txt+=\' <a href="">Recharger la page</a>.\';';
    $html .= 'showMsg("danger",txt);';
    $html .= '}})';
    $html .= '.catch(function(){setLoading(false);showMsg("danger","Erreur r\u00e9seau. Veuillez r\u00e9essayer.");});';
    $html .= '}';
    $html .= 'if(rcType==="v3"){';
    $html .= 'btn.type="button";';
    $html .= 'btn.addEventListener("click",function(){';
    $html .= 'msg.style.display="none";setLoading(true);';
    $html .= 'grecaptcha.ready(function(){';
    $html .= 'grecaptcha.execute(rcKey,{action:"contact_form_"+fid}).then(function(t){';
    $html .= 'var rcf=document.getElementById("cf_rc_token_"+fid);if(rcf)rcf.value=t;';
    $html .= 'doAjax();';
    $html .= '}).catch(function(){setLoading(false);showMsg("danger","Erreur reCAPTCHA. Veuillez r\u00e9essayer.");});';
    $html .= '});});';
    $html .= '}else{';
    $html .= 'form.addEventListener("submit",function(e){';
    $html .= 'e.preventDefault();msg.style.display="none";setLoading(true);doAjax();';
    $html .= '});';
    $html .= '}';
    $html .= '}());</script>';

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
