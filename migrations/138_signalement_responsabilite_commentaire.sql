-- Migration 138 : Commentaire de responsabilité avec textes par défaut configurables
-- Date: 2026-04-16
-- Description:
--   1. Ajoute la colonne commentaire_responsabilite à la table signalements
--   2. Ajoute les paramètres de texte par défaut pour locataire et propriétaire
--   3. Met à jour les templates email de responsabilité pour intégrer {{commentaire_responsabilite}}

-- 1. Colonne commentaire sur la table signalements
ALTER TABLE signalements
    ADD COLUMN  commentaire_responsabilite TEXT NULL
        COMMENT 'Commentaire/remarque saisi par l''admin lors de la confirmation de responsabilité';

-- 2. Paramètres : textes par défaut pour le commentaire de responsabilité
INSERT INTO parametres (cle, valeur, type, description, groupe)
VALUES
    ('texte_defaut_responsabilite_locataire',
     'Suite à l''analyse de votre signalement, nous vous informons que la responsabilité de ce problème a été déterminée à votre charge en tant que locataire. Notre équipe reste disponible pour toute question.',
     'string',
     'Texte par défaut du commentaire affiché dans la modal lorsque la responsabilité est attribuée au locataire',
     'signalement'),
    ('texte_defaut_responsabilite_proprietaire',
     'Suite à l''analyse de votre signalement, nous vous informons que ce problème relève de la responsabilité du propriétaire et sera traité dans les meilleurs délais. Notre équipe vous contactera prochainement.',
     'string',
     'Texte par défaut du commentaire affiché dans la modal lorsque la responsabilité est attribuée au propriétaire',
     'signalement')
ON DUPLICATE KEY UPDATE cle = cle;

-- 3a. Mise à jour du template confirmation_responsabilite_locataire
UPDATE email_templates
SET
    corps_html = REPLACE(
        corps_html,
        '<p style="color: #666; font-size: 13px;">Si vous avez des questions ou souhaitez discuter de cette situation, n''hésitez pas à contacter votre gestionnaire.</p>',
        '<div style="background: #fff8ec; border-left: 4px solid #e67e22; padding: 15px; margin: 20px 0; border-radius: 0 5px 5px 0;">
            <p style="margin: 0; font-size: 14px;">{{commentaire_responsabilite}}</p>
        </div>
        <p style="color: #666; font-size: 13px;">Si vous avez des questions ou souhaitez discuter de cette situation, n''hésitez pas à contacter votre gestionnaire.</p>'
    ),
    variables_disponibles = CASE
        WHEN variables_disponibles NOT LIKE '%commentaire_responsabilite%'
        THEN CONCAT(variables_disponibles, ',commentaire_responsabilite')
        ELSE variables_disponibles
    END,
    updated_at = NOW()
WHERE identifiant = 'confirmation_responsabilite_locataire';

-- 3b. Mise à jour du template confirmation_responsabilite_proprietaire
UPDATE email_templates
SET
    corps_html = REPLACE(
        corps_html,
        '<p>Notre équipe vous contactera prochainement pour convenir des modalités d''intervention. Pour toute question, n''hésitez pas à contacter votre gestionnaire.</p>',
        '<div style="background: #eaf7ee; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 0 5px 5px 0;">
            <p style="margin: 0; font-size: 14px;">{{commentaire_responsabilite}}</p>
        </div>
        <p>Notre équipe vous contactera prochainement pour convenir des modalités d''intervention. Pour toute question, n''hésitez pas à contacter votre gestionnaire.</p>'
    ),
    variables_disponibles = CASE
        WHEN variables_disponibles NOT LIKE '%commentaire_responsabilite%'
        THEN CONCAT(variables_disponibles, ',commentaire_responsabilite')
        ELSE variables_disponibles
    END,
    updated_at = NOW()
WHERE identifiant = 'confirmation_responsabilite_proprietaire';
