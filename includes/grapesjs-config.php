<?php
/**
 * Centralized GrapesJS configuration avec bouton HTML.
 * 
 * On garde la configuration d’origine et on ajoute un plugin
 * qui enregistre la commande `toggle-html` et le bouton associé.
 */
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/grapesjs@0.21.13/dist/css/grapes.min.css">
<script src="https://cdn.jsdelivr.net/npm/grapesjs@0.21.13/dist/grapes.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/grapesjs-blocks-basic@1.0.2/dist/index.js"></script>

<script>
/**
 * Base GrapesJS options partagées par tous les éditeurs.
 */
window.gjsConfig = {
    storageManager: false,
    height: '500px',
    width: '100%',
    plugins: [
        // Ajout du plugin custom pour basculer en HTML
        function(editor) {
            const pn = editor.Panels;
            const cmd = editor.Commands;

            // Bouton dans la barre d’options
            pn.addButton('options', {
                id: 'toggle-html',
                className: 'fa fa-file-html',
                command: 'toggle-html',
                attributes: { title: 'Basculer en HTML' }
            });

            // Commande toggle-html
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
                        ? '<style>' + css + '</style>' + html
                        : html;

                    const saveBtn = document.createElement('button');
                    saveBtn.innerText = 'Appliquer les modifications';
                    saveBtn.style.marginTop = '10px';
                    saveBtn.onclick = () => {
                        ed.setComponents(textarea.value);
                        modal.close();
                    };

                    container.appendChild(textarea);
                    container.appendChild(saveBtn);

                    modal.setTitle('Édition du code source');
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

/**
 * Fonction d’initialisation GrapesJS qui synchronise avec un <textarea>.
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

    // Synchroniser contenu → textarea lors du submit
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
