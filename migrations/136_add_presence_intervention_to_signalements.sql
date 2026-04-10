-- Migration 136: Ajout du champ presence_intervention à la table signalements
-- Ce champ mémorise si le locataire accepte l'intervention en son absence ou souhaite être présent.

ALTER TABLE signalements
    ADD COLUMN IF NOT EXISTS presence_intervention ENUM('absence','present') NULL DEFAULT NULL
        COMMENT 'Choix du locataire : intervenir en son absence ou en sa présence';
