-- Migration 111: Add email_template column to contact_forms
-- Stores a custom HTML email template (TinyMCE) for each contact form.
-- When NULL or empty, the default template is used.
-- Template variables follow the {{variable}} syntax and include
-- form field names as well as site-wide variables ({{company}}, {{signature}}, etc.).

ALTER TABLE contact_forms
    ADD COLUMN email_template MEDIUMTEXT NULL DEFAULT NULL AFTER message_confirmation;
