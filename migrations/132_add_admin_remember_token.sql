-- Migration 132: Add remember_token column to administrateurs table
-- This column stores a secure token hash for the "Rester connecté" feature

ALTER TABLE administrateurs 
    ADD COLUMN remember_token VARCHAR(64) NULL DEFAULT NULL COMMENT 'Token for persistent login cookie';

CREATE INDEX  IF NOT EXISTS idx_admin_remember_token ON administrateurs(remember_token);
