-- Migration 099 : Ajout du support paiement Stripe pour les décomptes d'intervention
-- Date: 2026-03-07
-- Description: Ajoute les colonnes nécessaires pour générer et suivre les paiements Stripe
--              associés aux décomptes d'intervention (signalements_decomptes).

ALTER TABLE signalements_decomptes
    ADD COLUMN IF NOT EXISTS token_paiement VARCHAR(64) NULL UNIQUE
        COMMENT 'Token sécurisé pour le lien de paiement du décompte',
    ADD COLUMN IF NOT EXISTS token_paiement_expiration DATETIME NULL
        COMMENT 'Date d''expiration du lien de paiement',
    ADD COLUMN IF NOT EXISTS stripe_session_id VARCHAR(255) NULL
        COMMENT 'Identifiant Stripe Checkout Session pour le décompte',
    ADD COLUMN IF NOT EXISTS stripe_payment_intent_id VARCHAR(255) NULL
        COMMENT 'Identifiant Stripe PaymentIntent',
    ADD COLUMN IF NOT EXISTS statut_paiement ENUM('non_genere','en_attente','paye','annule') NOT NULL DEFAULT 'non_genere'
        COMMENT 'Statut du paiement Stripe : non_genere | en_attente | paye | annule',
    ADD COLUMN IF NOT EXISTS date_paiement TIMESTAMP NULL
        COMMENT 'Date de confirmation du paiement par Stripe',
    ADD INDEX IF NOT EXISTS idx_dec_token_paiement (token_paiement),
    ADD INDEX IF NOT EXISTS idx_dec_statut_paiement (statut_paiement);
