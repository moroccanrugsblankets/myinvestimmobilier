-- Migration 100 : Ajout champs médias et contenus enrichis pour les logements
-- Date: 2026-03-08
-- Description: Ajoute les colonnes commodites, conditions_visite, video_youtube à la table
--              logements, et crée la table logements_photos pour stocker les photos/vidéos.

-- Add new text content columns to logements
ALTER TABLE logements
    ADD COLUMN IF NOT EXISTS commodites TEXT NULL DEFAULT NULL COMMENT 'Commodités à proximité (HTML via TinyMCE)',
    ADD COLUMN IF NOT EXISTS conditions_visite TEXT NULL DEFAULT NULL COMMENT 'Conditions de visite et de candidature (HTML via TinyMCE)',
    ADD COLUMN IF NOT EXISTS video_youtube VARCHAR(2048) NULL DEFAULT NULL COMMENT 'URL de la vidéo YouTube associée au logement';

-- Create logements_photos table for photo/video uploads
CREATE TABLE IF NOT EXISTS logements_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    logement_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    taille INT NOT NULL DEFAULT 0,
    ordre SMALLINT NOT NULL DEFAULT 0 COMMENT 'Ordre d\'affichage dans le slider',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (logement_id) REFERENCES logements(id) ON DELETE CASCADE,
    INDEX idx_logement_photos (logement_id, ordre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
