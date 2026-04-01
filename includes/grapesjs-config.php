<?php
/**
 * Centralized GrapesJS configuration.
 * Include this file on every page that uses GrapesJS instead of
 * repeating the <script> tags and inline configuration.
 *
 * After including this file the global helper function `initGrapesTemplateEditor`
 * is available for simple template editors (emails, contrats, PDFs…).
 * Pages that need the full visual builder (e.g. pages-frontoffice.php) can call
 * grapesjs.init() directly with their own custom options.
 *
 * NOTE: The grapesjs-blocks-basic plugin script is also loaded here so that
 *       pages-frontoffice.php can reference it without an extra CDN tag.
 */
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/grapesjs@0.21.13/dist/css/grapes.min.css">
<script src="https://cdn.jsdelivr.net/npm/grapesjs@0.21.13/dist/grapes.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/grapesjs-blocks-basic@1.0.2/dist/index.js"></script>
<script>
/**
 * Base GrapesJS options shared by all template editors.
 * Individual pages can override any property via the `options` argument of
 * initGrapesTemplateEditor().
 */
window.gjsConfig = {
    storageManager: false,
    height: '500px',
    width: '100%',
    plugins: [],
    pluginsOpts: {},
};

/**
 * Initialize a GrapesJS editor that mirrors a <textarea>.
 *
 * The textarea is hidden (but kept in the DOM with its original `name`
 * attribute so that form submission still works).  GrapesJS loads the
 * textarea's initial value and writes its HTML output back to the textarea
 * just before the enclosing <form> is submitted.
 *
 * The output is built as:
 *   <style>…editor CSS…</style>
 *   …editor HTML…
 * so that it can be rendered as-is in emails, PDFs and public pages without
 * any extra <html>/<body> wrapper that GrapesJS would otherwise inject.
 *
 * @param {string} containerId  ID of the <div> to use as the GrapesJS canvas
 * @param {string} textareaId   ID of the <textarea> to mirror
 * @param {object} [options]    Extra options merged into the grapesjs.init() call
 * @returns {object|null}       GrapesJS editor instance, or null if not found
 */
window.initGrapesTemplateEditor = function (containerId, textareaId, options) {
    var container = document.getElementById(containerId);
    var textarea  = document.getElementById(textareaId);
    if (!container || !textarea) return null;

    // Remove the `required` attribute before hiding so browser validation
    // does not block form submission on a hidden element.
    textarea.removeAttribute('required');
    textarea.style.display = 'none';

    var config = Object.assign({}, window.gjsConfig, {
        container: '#' + containerId,
        fromElement: false,
    }, options || {});

    var editor = grapesjs.init(config);

    // Populate editor with the textarea's current value.
    var initialHtml = textarea.value || '';
    if (initialHtml) {
        editor.setComponents(initialHtml);
    }

    // Sync editor content → textarea on form submit.
    var form = textarea.closest('form') || container.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            var html = editor.getHtml() || '';
            var css  = editor.getCss() || '';
            textarea.value = (css && css.trim())
                ? '<style>' + css + '</style>' + html
                : html;
        }, true); // capture phase so it runs before any other submit handler
    }

    return editor;
};
</script>
