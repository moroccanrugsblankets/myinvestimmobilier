-- Migration 114: Add 'recaptcha' to contact_form_fields.type_champ ENUM
-- Date: 2026-03-17
-- Description:
--   The type_champ ENUM was created in migration 106 without the 'recaptcha' value.
--   This caused MySQL to silently discard 'recaptcha' type when saving a field,
--   reverting it to the default 'text'. This migration adds 'recaptcha' to the ENUM
--   so that reCAPTCHA fields are stored and retrieved correctly.

ALTER TABLE contact_form_fields
    MODIFY COLUMN type_champ
        ENUM('text','email','tel','textarea','select','checkbox','recaptcha')
        NOT NULL DEFAULT 'text';
