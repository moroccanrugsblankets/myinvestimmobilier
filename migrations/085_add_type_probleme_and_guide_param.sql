-- Migration 085: Champ type_probleme pour signalements + paramètre guide réparations + {{reference}} dans rappel loyer
-- Date: 2026-03-02
-- Description:
--   1. Ajoute la colonne type_probleme à la table signalements (Plomberie, Électricité, etc.)
--   2. Ajoute le paramètre guide_reparations_lien (lien configurable vers le guide des réparations locatives)
--   3. Met à jour le template rappel_loyer_impaye_locataire pour inclure la variable {{reference}}

-- 1. Colonne type_probleme dans signalements (compatible MySQL < 8.0)
SET @dbname = DATABASE();
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'signalements' AND COLUMN_NAME = 'type_probleme');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE signalements ADD COLUMN type_probleme VARCHAR(50) NULL COMMENT ''Type de problème : Plomberie, Électricité, Serrurerie, Chauffage, Électroménager, Autre'' AFTER priorite', 'SELECT ''Column type_probleme already exists'' as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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
