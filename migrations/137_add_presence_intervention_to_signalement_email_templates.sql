-- Migration 137 : Ajout de {{presenceIntervention}} dans les templates emails de nouveau signalement
-- Date: 2026-04-11
-- Description:
--   Ajoute la variable {{presenceIntervention}} (Présence lors de l'intervention) dans les trois
--   templates emails envoyés lors d'un nouveau signalement :
--     - nouveau_signalement_locataire
--     - nouveau_signalement_admin
--     - nouveau_signalement_service_technique
--
--   La ligne apparaît juste après {{disponibilites_html}} dans le corps HTML.
--   Le filtre `AND corps_html NOT LIKE '%presenceIntervention%'` garantit l'idempotence.

UPDATE email_templates
SET
    corps_html = REPLACE(
        corps_html,
        '{{disponibilites_html}}',
        '{{disponibilites_html}}
        <p style="margin: 5px 0;"><strong>Présence lors de l\'intervention :</strong> {{presenceIntervention}}</p>'
    ),
    variables_disponibles = CASE
        WHEN variables_disponibles IS NULL OR variables_disponibles = ''
            THEN 'presenceIntervention'
        ELSE CONCAT(variables_disponibles, ',presenceIntervention')
    END,
    updated_at = NOW()
WHERE identifiant IN (
    'nouveau_signalement_locataire',
    'nouveau_signalement_admin',
    'nouveau_signalement_service_technique'
)
AND corps_html NOT LIKE '%presenceIntervention%';
