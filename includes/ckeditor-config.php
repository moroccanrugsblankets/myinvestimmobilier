<?php
/**
 * Centralized CKEditor configuration.
 * Include this file on every page that uses CKEditor instead of
 * repeating the <script> tag and inline configuration.
 *
 * After including this file the global JS object `ckConfig` is available.
 * Pages that need a different height or contentsCss can override individual
 * properties with Object.assign({}, ckConfig, { height: 600 }).
 */
if (!defined('CKEDITOR_CDN_URL')) {
    require_once __DIR__ . '/config.php';
}
?>
<style>
/* Hide CKEditor's "not secure / upgrade" notification banner */
.cke_notification { display: none !important; }
</style>
<script src="<?= htmlspecialchars(CKEDITOR_CDN_URL, ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
window.ckConfig = {
    height: 500,
    language: 'fr',
    allowedContent: true,
    // Autoriser explicitement div, span avec classes, styles et attributs + balise style
    extraAllowedContent: 'div(*){*}[*];span(*){*}[*];style;table(*){*}[*];tr(*){*}[*];td(*){*}[*]',
    entities: false,
    entities_latin: false,
    basicEntities: false,
    entities_processNumerical: false,
    fillEmptyBlocks: false,
    toolbar: [
        { name: 'document',    items: ['Source', '-', 'Undo', 'Redo'] },
        { name: 'styles',      items: ['Format'] },
        { name: 'basicstyles', items: ['Bold', 'Italic', 'Underline', 'Strikethrough', 'TextColor', 'BGColor', 'RemoveFormat'] },
        { name: 'paragraph',   items: ['JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyBlock', '-', 'BulletedList', 'NumberedList', '-', 'Outdent', 'Indent'] },
        { name: 'insert',      items: ['Link', 'Unlink', 'Image', 'Table', 'HorizontalRule', 'SpecialChar'] },
        { name: 'tools',       items: ['Maximize'] }
    ],
    // Charger un CSS externe pour interpréter les classes en mode édition
    contentsCss: [
        'body { font-family: Arial, sans-serif; font-size: 14px; }',
        '/chemin/vers/ton-fichier.css'
    ],
    // Protéger les balises <style> pour qu’elles restent visibles en mode source
    protectedSource: [/<style[\s\S]*?<\/style>/gi],
    removePlugins: 'notification'
};
</script>
