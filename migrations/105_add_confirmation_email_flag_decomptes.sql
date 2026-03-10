-- Migration 105 : Ajout colonne confirmation_email_envoye dans signalements_decomptes
-- Date: 2026-03-10
-- Description:
--   Ajoute un indicateur pour éviter d'envoyer plusieurs fois l'email de
--   confirmation de paiement d'un décompte au locataire.

ALTER TABLE signalements_decomptes
    ADD COLUMN IF NOT EXISTS confirmation_email_envoye TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = email de confirmation de paiement déjà envoyé au locataire';
