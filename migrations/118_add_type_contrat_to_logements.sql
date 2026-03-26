-- Migration 118 : Ajout du champ type_contrat dans la table logements
-- Permet d'associer chaque logement à un type de contrat par défaut
-- afin que la génération du contrat utilise automatiquement la bonne template.

ALTER TABLE logements
    ADD COLUMN type_contrat ENUM('meuble', 'non_meuble', 'sur_mesure') NOT NULL DEFAULT 'meuble'
    AFTER lien_externe;
