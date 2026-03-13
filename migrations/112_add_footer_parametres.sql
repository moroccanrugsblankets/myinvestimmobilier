-- Migration 112 : Ajout des paramètres du pied de page (footer)
-- Permet de configurer le texte du footer depuis l'espace Admin → Paramètres.

INSERT INTO parametres (cle, valeur, type, groupe, description)
VALUES
    ('footer_texte', '© {company} — Tous droits réservés', 'text', 'general',
     'Texte affiché dans le pied de page de toutes les pages publiques. Utilisez {company} pour insérer le nom de la société.')
ON DUPLICATE KEY UPDATE updated_at = NOW();
