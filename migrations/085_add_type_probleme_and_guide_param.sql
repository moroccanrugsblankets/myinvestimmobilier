-- Migration 085: Champ type_probleme pour signalements + paramètre guide réparations + {{reference}} dans rappel loyer
-- Date: 2026-03-02
-- Description:
--   1. Ajoute la colonne type_probleme à la table signalements (Plomberie, Électricité, etc.)
--   2. Ajoute le paramètre guide_reparations_lien (lien configurable vers le guide des réparations locatives)
--   3. Met à jour le template rappel_loyer_impaye_locataire pour inclure la variable {{reference}}

-- 1. Colonne type_probleme dans signalements
ALTER TABLE signalements
    ADD COLUMN IF NOT EXISTS type_probleme VARCHAR(50) NULL COMMENT 'Type de problème : Plomberie, Électricité, Serrurerie, Chauffage, Électroménager, Autre'
    AFTER priorite;

-- 2. Paramètre configurable : lien vers le guide des réparations locatives
INSERT INTO parametres (cle, valeur, type, description, groupe) VALUES
('guide_reparations_lien', '', 'string', 'URL du guide des réparations locatives (affiché sur le formulaire de signalement)', 'signalement')
ON DUPLICATE KEY UPDATE cle = cle;

-- 3. Mise à jour du template rappel_loyer_impaye_locataire : ajout de {{reference}}
UPDATE email_templates
SET
    corps_html = REPLACE(
        corps_html,
        '<strong>Logement :</strong> {{adresse}}',
        '<strong>Référence logement :</strong> {{reference}}<br>
            <strong>Logement :</strong> {{adresse}}'
    ),
    variables_disponibles = '["locataire_nom", "locataire_prenom", "periode", "adresse", "reference", "montant_total", "signature"]',
    updated_at = NOW()
WHERE identifiant = 'rappel_loyer_impaye_locataire';
