<?php
/**
 * Centralized GrapesJS configuration with HTML editing support.
 * Include this file on every page that uses GrapesJS.
 *
 * Provides:
 *  - Drag & drop visual editor
 *  - Direct HTML editing via CodeMirror
 *  - Centralized config for emails, contrats, PDFs, pages publiques
 */
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/grapesjs@0.21.13/dist/css/grapes.min.css">
<script src="https://cdn.jsdelivr.net/npm/grapesjs@0.21.13/dist/grapes.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/grapesjs-blocks-basic@1.0.2/dist/index.js"></script>
<script src="https://unpkg.com/grapesjs-plugin-ckeditor@1.0.1/dist/index.js"></script>

<script>
window.gjsConfig = {
    storageManager: false,
    height: '500px',
    width: '100%',
    plugins: ['grapesjs-plugin-code-editor'],
    pluginsOpts: {
        'grapesjs-plugin-code-editor': {
            theme: 'monokai',   // thème du code
            editHtml: true      // autoriser l’édition
        }
    }
};

/**
 * Initialize a GrapesJS editor that mirrors a <textarea>.
 */
window.initGrapesTemplateEditor = function (containerId, textareaId, options) {
    var container = document.getElementById(containerId);
    var textarea  = document.getElementById(textareaId);
    if (!container || !textarea) return null;

    textarea.removeAttribute('required');
    textarea.style.display = 'none';

    var config = Object.assign({}, window.gjsConfig, {
        container: '#' + containerId,
        fromElement: false,
    }, options || {});

    var editor = grapesjs.init(config);

    // Charger contenu initial
    var initialHtml = textarea.value || '';
    if (initialHtml) {
        editor.setComponents(initialHtml);
    }

    // Bouton personnalisé pour basculer en mode HTML
    editor.Panels.addButton('options', [{
        id: 'edit-html',
        className: 'fa fa-code',
        command: 'open-code',
        attributes: { title: 'Basculer en HTML' }
    }]);

    editor.Commands.add('open-code', {
        run(ed) {
            ed.runCommand('open-code-editor'); // déclenche le plugin
        }
    });

    // Sync editor → textarea
    var form = textarea.closest('form') || container.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            var html = editor.getHtml() || '';
            var css  = editor.getCss() || '';
            textarea.value = (css && css.trim())
                ? '<style>' + css + '</style>' + html
                : html;
        }, true);
    }

    return editor;
};
</script>
