<?php
/**
 * GrapesJS configuration stable.
 */
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/grapesjs@0.21.13/dist/css/grapes.min.css">
<script src="https://cdn.jsdelivr.net/npm/grapesjs@0.21.13/dist/grapes.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/grapesjs-blocks-basic@1.0.2/dist/index.js"></script>

<script>
window.gjsConfig = {
    storageManager: false,
    height: '500px',
    width: '100%',
    plugins: ['gjs-blocks-basic'], // activer les blocs de base
    pluginsOpts: {},
};

// Extrait les blocs <style> d'une chaîne HTML et retourne { html, css }.
window.gjsExtractStyles = function (raw) {
    var css = '';
    var html = raw.replace(/<style[^>]*>([\s\S]*?)<\/style>/gi, function (match, content) {
        css += content + '\n';
        return '';
    });
    return { html: html.trim(), css: css.trim() };
};

// Reconstruit une chaîne combinée <style>…</style>\n<html…>.
window.gjsBuildCombined = function (html, css) {
    if (!css || !css.trim()) return html;
    return '<style>\n' + css + '\n</style>\n' + html;
};

window.initGrapesTemplateEditor = function (containerId, textareaId, options) {
    var container = document.getElementById(containerId);
    var textarea  = document.getElementById(textareaId);
    if (!container || !textarea) return null;

    textarea.removeAttribute('required');
    textarea.style.display = 'none';

    var config = Object.assign({}, window.gjsConfig, {
        container: '#' + containerId,
        fromElement: true, // laisser GrapesJS parser le contenu
    }, options || {});

    var editor = grapesjs.init(config);

    // Charger contenu initial
    var initialHtml = textarea.value || '';
    if (initialHtml) {
        var parsed0 = window.gjsExtractStyles(initialHtml);
        editor.setComponents(parsed0.html);
        if (parsed0.css) editor.setStyle(parsed0.css);
    }

    // ── Mode HTML brut ──────────────────────────────────────────────────────
    // Zone de saisie brute insérée juste après le conteneur GrapesJS.
    var rawWrapper = document.createElement('div');
    rawWrapper.style.display = 'none';
    rawWrapper.style.marginTop = '2px';

    var rawTextarea = document.createElement('textarea');
    rawTextarea.className = 'form-control font-monospace';
    rawTextarea.rows = 20;
    rawTextarea.style.cssText = 'font-size:.82rem;width:100%;resize:vertical;';
    rawWrapper.appendChild(rawTextarea);

    var rawNote = document.createElement('div');
    rawNote.className = 'form-text';
    rawNote.textContent = 'HTML complet du corps de page (sections, balises, styles inline…).';
    rawWrapper.appendChild(rawNote);

    container.parentNode.insertBefore(rawWrapper, container.nextSibling);

    // Commande de bascule visuel ↔ HTML brut (unique par instance).
    var cmdId = 'gjs-edit-html-' + containerId;
    editor.Commands.add(cmdId, {
        run: function (ed) {
            rawTextarea.value = window.gjsBuildCombined(ed.getHtml() || '', ed.getCss() || '');
            container.style.display = 'none';
            rawWrapper.style.display = 'block';
        },
        stop: function (ed) {
            var raw = rawTextarea.value;
            if (raw) {
                var parsed = window.gjsExtractStyles(raw);
                ed.setStyle(parsed.css || '');
                ed.setComponents(parsed.html);
            }
            rawWrapper.style.display = 'none';
            container.style.display = '';
        },
    });

    // Bouton dans la barre d'options de GrapesJS.
    editor.Panels.addButton('options', {
        id: cmdId,
        className: 'gjs-pn-btn',
        label: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M5.854 4.854a.5.5 0 1 0-.708-.708l-3.5 3.5a.5.5 0 0 0 0 .708l3.5 3.5a.5.5 0 0 0 .708-.708L2.707 8l3.147-3.146zm4.292 0a.5.5 0 0 1 .708-.708l3.5 3.5a.5.5 0 0 1 0 .708l-3.5 3.5a.5.5 0 0 1-.708-.708L13.293 8l-3.147-3.146z"/></svg>',
        command: cmdId,
        attributes: { title: 'Éditer HTML brut' },
        active: false,
    });
    // ── Fin Mode HTML brut ──────────────────────────────────────────────────

    // Synchroniser contenu → textarea lors du submit
    var form = textarea.closest('form') || container.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            var isRaw = rawWrapper.style.display !== 'none';
            if (isRaw) {
                textarea.value = rawTextarea.value;
            } else {
                textarea.value = window.gjsBuildCombined(editor.getHtml() || '', editor.getCss() || '');
            }
        }, true);
    }

    return editor;
};
</script>
