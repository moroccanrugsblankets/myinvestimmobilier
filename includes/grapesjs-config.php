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

    // ── Boutons de bascule Visuel ↔ HTML brut (au-dessus de l'éditeur) ──────
    var btnGroup = document.createElement('div');
    btnGroup.className = 'btn-group btn-group-sm mb-2';
    btnGroup.setAttribute('role', 'group');
    btnGroup.setAttribute('aria-label', 'Mode éditeur');

    var btnVisual = document.createElement('button');
    btnVisual.type = 'button';
    btnVisual.className = 'btn btn-outline-primary active';
    btnVisual.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="me-1" viewBox="0 0 16 16"><path d="M0 5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm13.5 1a.5.5 0 0 0-1 0v3.793l-1.146-1.147a.5.5 0 0 0-.708.708l2 2a.5.5 0 0 0 .708 0l2-2a.5.5 0 0 0-.708-.708L13.5 9.793zm-7 1.5a.5.5 0 0 1 .5.5v3.793l1.146-1.147a.5.5 0 0 1 .708.708l-2 2a.5.5 0 0 1-.708 0l-2-2a.5.5 0 0 1 .708-.708L6 11.293V8a.5.5 0 0 1 .5-.5"/></svg>Éditeur visuel';

    var btnRaw = document.createElement('button');
    btnRaw.type = 'button';
    btnRaw.className = 'btn btn-outline-secondary';
    btnRaw.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" class="me-1" viewBox="0 0 16 16"><path d="M5.854 4.854a.5.5 0 1 0-.708-.708l-3.5 3.5a.5.5 0 0 0 0 .708l3.5 3.5a.5.5 0 0 0 .708-.708L2.707 8l3.147-3.146zm4.292 0a.5.5 0 0 1 .708-.708l3.5 3.5a.5.5 0 0 1 0 .708l-3.5 3.5a.5.5 0 0 1-.708-.708L13.293 8l-3.147-3.146z"/></svg>HTML brut';

    btnGroup.appendChild(btnVisual);
    btnGroup.appendChild(btnRaw);
    container.parentNode.insertBefore(btnGroup, container);
    // ── Fin boutons ──────────────────────────────────────────────────────────

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

    // Fonctions de bascule
    function switchToVisual() {
        var raw = rawTextarea.value;
        if (raw) {
            var parsed = window.gjsExtractStyles(raw);
            editor.setStyle(parsed.css || '');
            editor.setComponents(parsed.html);
        }
        container.style.display = '';
        rawWrapper.style.display = 'none';
        btnVisual.classList.add('active');
        btnRaw.classList.remove('active');
    }

    function switchToRaw() {
        rawTextarea.value = window.gjsBuildCombined(editor.getHtml() || '', editor.getCss() || '');
        container.style.display = 'none';
        rawWrapper.style.display = 'block';
        btnVisual.classList.remove('active');
        btnRaw.classList.add('active');
    }

    btnVisual.addEventListener('click', function (e) { e.preventDefault(); switchToVisual(); });
    btnRaw.addEventListener('click', function (e) { e.preventDefault(); switchToRaw(); });
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
