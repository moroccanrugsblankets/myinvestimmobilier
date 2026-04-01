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
// Plugin GrapesJS - Code Editor Complet
export default (editor, opts = {}) => {
  const pn = editor.Panels;
  const cmd = editor.Commands;

  // Ajout du bouton dans la barre d’outils
  pn.addButton('options', {
    id: 'toggle-html',
    className: 'fa fa-code',
    command: 'toggle-html',
    attributes: { title: 'Basculer en HTML' }
  });

  // Définition de la commande toggle-html
  cmd.add('toggle-html', {
    run(editor) {
      const modal = editor.Modal;
      const container = document.createElement('div');
      const html = editor.getHtml();
      const css = editor.getCss();

      // Zone de texte pour édition
      const textarea = document.createElement('textarea');
      textarea.style.width = '100%';
      textarea.style.height = '300px';
      textarea.value = `${html}\n<style>${css}</style>`;

      // Bouton pour sauvegarder les modifications
      const saveBtn = document.createElement('button');
      saveBtn.innerText = 'Appliquer les modifications';
      saveBtn.style.marginTop = '10px';
      saveBtn.onclick = () => {
        const newHtml = textarea.value;
        editor.setComponents(newHtml); // Réinjecte le HTML modifié
        modal.close();
      };

      container.appendChild(textarea);
      container.appendChild(saveBtn);

      modal.setTitle('Édition du code source');
      modal.setContent(container);
      modal.open();
    },
    stop(editor) {
      editor.Modal.close();
    }
  });
};
</script>
