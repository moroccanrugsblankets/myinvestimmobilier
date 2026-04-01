<?php
/**
 * GrapesJS configuration avec bouton HTML stable.
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
    plugins: [
        function(editor) {
            const pn = editor.Panels;
            const cmd = editor.Commands;

            pn.addButton('options', {
                id: 'toggle-html',
                className: 'fa fa-code',
                command: 'toggle-html',
                attributes: { title: 'Voir/éditer le code source' }
            });

            cmd.add('toggle-html', {
                run(ed) {
                    const modal = ed.Modal;
                    const container = document.createElement('div');

                    const html = ed.getHtml();
                    const css  = ed.getCss();

                    const textarea = document.createElement('textarea');
                    textarea.style.width = '100%';
                    textarea.style.height = '300px';
                    textarea.value = (css && css.trim())
                        ? '<style>' + css + '</style>\n' + html
                        : html;

                    const info = document.createElement('p');
                    info.innerText = "⚠️ Les modifications ici ne sont pas réinjectées dans GrapesJS. Elles seront prises en compte uniquement lors du submit.";

                    container.appendChild(info);
                    container.appendChild(textarea);

                    modal.setTitle('Code source (lecture/édition)');
                    modal.setContent(container);
                    modal.open();
                },
                stop(ed) {
                    ed.Modal.close();
                }
            });
        }
    ],
    pluginsOpts: {},
};

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

    // Synchroniser contenu → textarea lors du submit
    var form = textarea.closest('form') || container.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            var html = editor.getHtml() || '';
            var css  = editor.getCss() || '';
            textarea.value = (css && css.trim())
                ? '<style>' + css + '</style>\n' + html
                : html;
        }, true);
    }

    return editor;
};
</script>
