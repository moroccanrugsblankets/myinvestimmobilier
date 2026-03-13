-- Migration 112 : Ajout des paramètres du pied de page (footer)
-- Permet de configurer le texte du footer depuis l'espace Admin → Paramètres.

INSERT INTO parametres (cle, valeur, type, groupe, label, description, ordre)
VALUES
    ('footer_texte', '© {company} — Tous droits réservés', 'text', 'general',
     'Texte du pied de page', 'Texte affiché dans le pied de page de toutes les pages publiques. Utilisez {company} pour insérer le nom de la société.', 90)
ON DUPLICATE KEY UPDATE updated_at = NOW();
