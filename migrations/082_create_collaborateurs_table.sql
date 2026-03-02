-- Migration 082 : Module Collaborateurs
-- Date: 2026-03-01
-- Description: Crée la table des collaborateurs (intervenants) pour le module de signalement.
--   L'admin peut attribuer un ticket à un collaborateur enregistré et lui envoyer une mission.

CREATE TABLE IF NOT EXISTS collaborateurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(255) NOT NULL COMMENT 'Nom complet du collaborateur',
    metier VARCHAR(255) NULL COMMENT 'Métier / spécialité (électricien, maçon, plombier, etc.)',
    email VARCHAR(255) NULL,
    telephone VARCHAR(50) NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = actif, 0 = désactivé',
    notes TEXT NULL COMMENT 'Notes internes',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_collaborateurs_actif (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Collaborateurs (intervenants) pouvant être assignés aux tickets de signalement';

-- Lier optionnellement un collaborateur à un signalement (en plus des champs dénormalisés existants)
ALTER TABLE signalements
    ADD COLUMN collaborateur_id INT NULL COMMENT 'Référence vers la table collaborateurs' AFTER mode_notification_collab,
    ADD INDEX idx_sig_collaborateur (collaborateur_id),
    ADD FOREIGN KEY fk_sig_collaborateur (collaborateur_id) REFERENCES collaborateurs(id) ON DELETE SET NULL;
