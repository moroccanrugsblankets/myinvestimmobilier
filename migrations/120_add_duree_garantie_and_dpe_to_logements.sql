-- Migration 120: Add duree_garantie and dpe_file columns to logements table
-- duree_garantie: duration of guarantee in months (0, 1, 2, 3) — default 1
-- dpe_file: relative path to the DPE (Diagnostic de Performance Energetique) PDF file

ALTER TABLE logements
    ADD COLUMN duree_garantie TINYINT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'Durée de garantie en mois (0, 1, 2 ou 3 mois)',
    ADD COLUMN dpe_file VARCHAR(500) DEFAULT NULL
        COMMENT 'Chemin relatif vers le fichier PDF du DPE (Diagnostic de Performance Énergétique)';

-- Enforce allowed values for duree_garantie (MySQL 8.0.16+ enforces CHECK constraints)
ALTER TABLE logements
    ADD CONSTRAINT chk_duree_garantie CHECK (duree_garantie IN (0, 1, 2, 3));
