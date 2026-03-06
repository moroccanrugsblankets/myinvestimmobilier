-- Migration 096 : Ajout de la référence logement dans tous les emails de signalement
-- Date: 2026-03-06
-- Description:
--   Ajoute la variable {{logement_reference}} dans tous les templates emails de signalement.
--   La référence du logement est plus importante que l'adresse car plusieurs logements
--   peuvent avoir la même adresse. Elle apparaît maintenant juste après l'adresse dans
--   chaque email.
--
--   Templates mis à jour :
--     - nouveau_signalement_locataire
--     - nouveau_signalement_admin
--     - nouveau_signalement_service_technique
--     - signalement_attribution
--     - signalement_pris_en_charge_admin
--     - signalement_sur_place_admin
--     - signalement_intervention_terminee_admin
--     - signalement_impossible_admin
--     - signalement_intervention_terminee_locataire
--     - signalement_decompte
--     - confirmation_responsabilite_proprietaire
--     - confirmation_responsabilite_locataire
--     - responsabilite_proprietaire_service_technique
--     - acceptation_intervention_locataire
--     - acceptation_intervention_service_technique

-- Ajouter {{logement_reference}} dans tous les templates signalement qui affichent {{adresse}}.
-- La ligne "Réf. logement" est insérée juste après la ligne "Logement : {{adresse}}".
-- Le filtre `AND corps_html NOT LIKE '%logement_reference%'` garantit l'idempotence.

UPDATE email_templates
SET
    corps_html = REPLACE(
        corps_html,
        '>{{adresse}}</p>',
        '>{{adresse}}</p>
            <p style="margin: 5px 0;"><strong>Réf. logement :</strong> <code>{{logement_reference}}</code></p>'
    ),
    variables_disponibles = CASE
        WHEN variables_disponibles IS NULL OR variables_disponibles = ''
            THEN 'logement_reference'
        ELSE CONCAT(variables_disponibles, ',logement_reference')
    END,
    updated_at = NOW()
WHERE identifiant IN (
    'nouveau_signalement_locataire',
    'nouveau_signalement_admin',
    'nouveau_signalement_service_technique',
    'signalement_attribution',
    'signalement_pris_en_charge_admin',
    'signalement_sur_place_admin',
    'signalement_intervention_terminee_admin',
    'signalement_impossible_admin',
    'signalement_intervention_terminee_locataire',
    'signalement_decompte',
    'confirmation_responsabilite_proprietaire',
    'confirmation_responsabilite_locataire',
    'responsabilite_proprietaire_service_technique',
    'acceptation_intervention_locataire',
    'acceptation_intervention_service_technique'
)
AND corps_html NOT LIKE '%logement_reference%';
