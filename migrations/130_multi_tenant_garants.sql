-- Migration 130: Support multi-tenant garant management
--
-- Adds per-tenant tokens (token_garantie, token_assurance) to the locataires table
-- so that each tenant can have their own independent garant declaration and insurance
-- upload links. Also adds locataire_id to the garants table to link each garant
-- record to the specific tenant who declared it.
--
-- Backward compatible: existing columns on contrats remain untouched.
-- New columns on locataires are nullable; garants.locataire_id is nullable.

-- 1. Link each garant record to the specific tenant who declared it
ALTER TABLE garants
    ADD COLUMN locataire_id INT NULL AFTER contrat_id,
    ADD CONSTRAINT fk_garants_locataire
        FOREIGN KEY (locataire_id) REFERENCES locataires(id) ON DELETE SET NULL;

-- 2. Per-tenant token for the garant declaration form (/garant/index.php)
ALTER TABLE locataires
    ADD COLUMN token_garantie VARCHAR(100) NULL UNIQUE AFTER created_at;

-- 3. Per-tenant token for the insurance/visale upload form (/envoyer-assurance.php)
ALTER TABLE locataires
    ADD COLUMN token_assurance VARCHAR(100) NULL UNIQUE AFTER token_garantie;
