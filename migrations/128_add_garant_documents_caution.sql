-- Migration 128: Ajout des documents obligatoires pour la caution solidaire
-- Ajoute les colonnes pour les bulletins de salaire, la fiche d'imposition
-- et le justificatif de domicile dans la table garants.

ALTER TABLE garants
    ADD COLUMN bulletin_salaire_1   VARCHAR(255) NULL AFTER piece_identite,
    ADD COLUMN bulletin_salaire_2   VARCHAR(255) NULL AFTER bulletin_salaire_1,
    ADD COLUMN bulletin_salaire_3   VARCHAR(255) NULL AFTER bulletin_salaire_2,
    ADD COLUMN fiche_imposition     VARCHAR(255) NULL AFTER bulletin_salaire_3,
    ADD COLUMN justificatif_domicile VARCHAR(255) NULL AFTER fiche_imposition;
