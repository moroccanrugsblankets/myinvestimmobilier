-- Migration 129: Ajouter les colonnes piece_identite_recto et piece_identite_verso à la table garants
-- La pièce d'identité recto est obligatoire, le verso est optionnel (passeport = recto uniquement)

ALTER TABLE garants
    ADD COLUMN piece_identite_recto VARCHAR(255) NULL AFTER piece_identite,
    ADD COLUMN piece_identite_verso VARCHAR(255) NULL AFTER piece_identite_recto;
