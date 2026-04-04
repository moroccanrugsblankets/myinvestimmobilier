-- Migration 133: Ajouter les colonnes piece_identite_recto et piece_identite_verso
-- aux tables etat_lieux_locataires et inventaire_locataires
-- La pièce d'identité recto est obligatoire, le verso est optionnel (passeport = recto uniquement)

ALTER TABLE etat_lieux_locataires
    ADD COLUMN piece_identite_recto VARCHAR(500) NULL AFTER certifie_exact,
    ADD COLUMN piece_identite_verso VARCHAR(500) NULL AFTER piece_identite_recto;

ALTER TABLE inventaire_locataires
    ADD COLUMN piece_identite_recto VARCHAR(500) NULL AFTER certifie_exact,
    ADD COLUMN piece_identite_verso VARCHAR(500) NULL AFTER piece_identite_recto;
