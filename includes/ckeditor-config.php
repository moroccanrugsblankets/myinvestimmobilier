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
    extraAllowedContent: '*(*)[*]{*}',
    entities: false,
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
    contentsCss: 'body { font-family: Arial, sans-serif; font-size: 14px; }',
    removePlugins: 'notification'
};
</script>
