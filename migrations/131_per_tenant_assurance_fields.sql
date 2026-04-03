-- Migration 131: Per-tenant assurance and Visale document storage
--
-- Adds per-tenant fields to the locataires table so that each tenant
-- can upload their own assurance habitation attestation and Visale
-- documents independently.  Previously these were stored on the contrats
-- table (shared), which caused the second tenant's upload to overwrite
-- the first one's documents.
--
-- Backward compatible: existing columns on contrats remain untouched.
-- New columns on locataires are nullable.

ALTER TABLE locataires
    ADD COLUMN assurance_habitation   VARCHAR(255) NULL AFTER token_assurance,
    ADD COLUMN numero_visale          VARCHAR(100) NULL AFTER assurance_habitation,
    ADD COLUMN visa_certifie          VARCHAR(255) NULL AFTER numero_visale,
    ADD COLUMN date_envoi_assurance   DATETIME     NULL AFTER visa_certifie;
