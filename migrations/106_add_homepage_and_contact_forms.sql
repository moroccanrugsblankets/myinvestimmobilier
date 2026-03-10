-- Migration 106: Page d'accueil configurable + Formulaires de contact dynamiques
-- Date: 2026-03-10
-- Description:
--   1. Ajoute le champ `is_homepage` à `frontend_pages`
--   2. Crée la table `contact_forms` pour les formulaires configurables
--   3. Crée la table `contact_form_fields` pour les champs dynamiques
--   4. Crée la table `contact_form_submissions` pour stocker les soumissions

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Champ is_homepage dans frontend_pages
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE frontend_pages
    ADD COLUMN IF NOT EXISTS is_homepage TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = page d\'accueil par défaut (une seule à la fois)'
    AFTER actif;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Table des formulaires de contact
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS contact_forms (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nom         VARCHAR(150) NOT NULL          COMMENT 'Nom interne du formulaire',
    shortcode   VARCHAR(100) NOT NULL UNIQUE   COMMENT 'Shortcode d\'insertion, ex: [contact-form id=1]',
    email_dest  VARCHAR(255) DEFAULT ''        COMMENT 'Email de destination des soumissions',
    message_confirmation TEXT DEFAULT ''       COMMENT 'Message affiché après soumission',
    actif       TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_shortcode (shortcode),
    INDEX idx_actif     (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Table des champs de formulaires
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS contact_form_fields (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    form_id     INT NOT NULL,
    nom_champ   VARCHAR(100) NOT NULL          COMMENT 'Identifiant interne (slug)',
    label       VARCHAR(150) NOT NULL          COMMENT 'Libellé affiché',
    type_champ  ENUM('text','email','tel','textarea','select','checkbox') NOT NULL DEFAULT 'text',
    placeholder VARCHAR(255) DEFAULT '',
    options     TEXT DEFAULT ''               COMMENT 'Options séparées par | pour type select',
    requis      TINYINT(1) NOT NULL DEFAULT 0,
    ordre       INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (form_id) REFERENCES contact_forms(id) ON DELETE CASCADE,
    INDEX idx_form_id (form_id),
    INDEX idx_ordre   (ordre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. Table des soumissions de formulaires
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS contact_form_submissions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    form_id     INT NOT NULL,
    donnees     JSON                           COMMENT 'Données soumises en JSON',
    ip_address  VARCHAR(45) DEFAULT '',
    user_agent  TEXT DEFAULT '',
    lu          TINYINT(1) NOT NULL DEFAULT 0  COMMENT '0 = non lu',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (form_id) REFERENCES contact_forms(id) ON DELETE CASCADE,
    INDEX idx_form_id   (form_id),
    INDEX idx_lu        (lu),
    INDEX idx_created   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
