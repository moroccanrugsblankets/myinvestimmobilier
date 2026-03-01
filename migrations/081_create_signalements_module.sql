-- Migration 081 : Module de signalement d'anomalie
-- Date: 2026-03-01
-- Description: Crée les tables nécessaires au module de signalement d'anomalie :
--   - signalements        : tickets créés par les locataires
--   - signalements_photos : photos jointes aux signalements
--   - signalements_actions: timeline horodatée de chaque action (immuable)
-- Ajoute également un token d'accès sécurisé dans la table locataires.

-- 1. Ajouter le token de signalement aux locataires (lien sécurisé vers le formulaire)
ALTER TABLE locataires
    ADD COLUMN token_signalement VARCHAR(64) NULL UNIQUE COMMENT 'Token sécurisé pour accéder au formulaire de signalement',
    ADD INDEX idx_locataires_token_signalement (token_signalement);

-- 2. Table principale des signalements
CREATE TABLE IF NOT EXISTS signalements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(50) UNIQUE NOT NULL COMMENT 'Référence unique du ticket (ex: SIG-20260301-ABCD1234)',

    -- Relation avec le contrat et le logement
    contrat_id INT NOT NULL,
    logement_id INT NOT NULL,
    locataire_id INT NULL COMMENT 'Locataire principal qui a ouvert le ticket',

    -- Détails du ticket
    titre VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    priorite ENUM('urgent', 'normal') NOT NULL DEFAULT 'normal',

    -- Responsabilité
    responsabilite ENUM('locataire', 'proprietaire', 'non_determine') NOT NULL DEFAULT 'non_determine',
    checklist_confirmee BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Le locataire a confirmé avoir vérifié la checklist',
    responsabilite_confirmee_admin BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'L''admin a confirmé la responsabilité',

    -- Statut du ticket
    statut ENUM('nouveau', 'en_cours', 'en_attente', 'resolu', 'clos') NOT NULL DEFAULT 'nouveau',

    -- Timeline (horodatages de chaque étape)
    date_signalement TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_attribution TIMESTAMP NULL COMMENT 'Date d''attribution à un collaborateur',
    date_intervention TIMESTAMP NULL COMMENT 'Date de début d''intervention',
    date_resolution TIMESTAMP NULL COMMENT 'Date de résolution',
    date_cloture TIMESTAMP NULL COMMENT 'Date de clôture définitive',

    -- Attribution à un collaborateur
    collaborateur_nom VARCHAR(255) NULL,
    collaborateur_email VARCHAR(255) NULL,
    collaborateur_telephone VARCHAR(50) NULL,
    mode_notification_collab VARCHAR(20) NULL COMMENT 'email, whatsapp, les_deux',

    -- Complément post-clôture (seul ajout autorisé après clôture)
    complement TEXT NULL,

    -- Métadonnées
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_sig_contrat (contrat_id),
    INDEX idx_sig_logement (logement_id),
    INDEX idx_sig_locataire (locataire_id),
    INDEX idx_sig_statut (statut),
    INDEX idx_sig_priorite (priorite),
    INDEX idx_sig_date (date_signalement),

    FOREIGN KEY (contrat_id) REFERENCES contrats(id) ON DELETE CASCADE,
    FOREIGN KEY (logement_id) REFERENCES logements(id) ON DELETE CASCADE,
    FOREIGN KEY (locataire_id) REFERENCES locataires(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tickets de signalement d''anomalie ouverts par les locataires';

-- 3. Photos jointes aux signalements
CREATE TABLE IF NOT EXISTS signalements_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    signalement_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL COMMENT 'Nom du fichier stocké sur le serveur',
    original_name VARCHAR(255) NOT NULL COMMENT 'Nom original du fichier uploadé',
    mime_type VARCHAR(100) NOT NULL DEFAULT 'image/jpeg',
    taille INT NULL COMMENT 'Taille en octets',
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_photos_signalement (signalement_id),

    FOREIGN KEY (signalement_id) REFERENCES signalements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Photos jointes aux signalements d''anomalie';

-- 4. Timeline des actions (immuable : pas de UPDATE ni DELETE autorisés)
CREATE TABLE IF NOT EXISTS signalements_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    signalement_id INT NOT NULL,
    type_action VARCHAR(100) NOT NULL COMMENT 'creation, statut_change, attribution, complement, cloture, ...',
    description TEXT NOT NULL COMMENT 'Description lisible de l''action',
    acteur VARCHAR(255) NULL COMMENT 'Nom de l''auteur (admin, locataire, system)',
    ancienne_valeur TEXT NULL,
    nouvelle_valeur TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_actions_signalement (signalement_id),
    INDEX idx_actions_created_at (created_at),

    FOREIGN KEY (signalement_id) REFERENCES signalements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Timeline horodatée et immuable de toutes les actions sur les signalements';
