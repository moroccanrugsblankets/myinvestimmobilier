-- Migration 121: Add detailed DPE fields to logements table
-- dpe_classe: DPE energy class (A to G)
-- dpe_ges: GES (greenhouse gas) value
-- dpe_numero: DPE certificate number
-- dpe_valable_jusqu_a: DPE expiry date (text)
-- Note: dpe_file already exists from migration 120

ALTER TABLE logements
    ADD COLUMN dpe_classe VARCHAR(1) DEFAULT NULL
        COMMENT 'Classe DPE de A à G',
    ADD COLUMN dpe_ges VARCHAR(100) DEFAULT NULL
        COMMENT 'Valeur GES du logement',
    ADD COLUMN dpe_numero VARCHAR(255) DEFAULT NULL
        COMMENT 'Numéro du certificat DPE',
    ADD COLUMN dpe_valable_jusqu_a VARCHAR(100) DEFAULT NULL
        COMMENT 'Date de validité du DPE';
