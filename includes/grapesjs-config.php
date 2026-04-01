<?php
/**
 * GrapesJS configuration avec bouton HTML fonctionnel.
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
                attributes: { title: 'Basculer en HTML' }
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

                    const saveBtn = document.createElement('button');
                    saveBtn.innerText = 'Appliquer les modifications';
                    saveBtn.style.marginTop = '10px';
                    saveBtn.onclick = () => {
                        const code = textarea.value;
                        const cssMatch = code.match(/<style>([\s\S]*?)<\/style>/);
                        let css = '';
                        let html = code;

                        if (cssMatch) {
                            css = cssMatch[1];
                            html = code.replace(cssMatch[0], '');
                        }

                        // Réinjecter proprement sans casser GrapesJS
                        ed.setHtml(html);
                        if (css) ed.setCss(css);

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
        editor.setHtml(initialHtml);
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
