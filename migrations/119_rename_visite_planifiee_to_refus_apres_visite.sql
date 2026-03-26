-- Migration 119 : Remplacement du statut « Visite planifiée » par « Refus après visite »
-- Renomme la valeur d'enum `visite_planifiee` en `refus_apres_visite` dans la table candidatures
-- et migre toutes les lignes existantes vers la nouvelle valeur.

-- Étape 1 : modifier temporairement la colonne pour accepter les deux valeurs le temps de la migration
ALTER TABLE candidatures
    MODIFY COLUMN statut ENUM(
        'en_cours',
        'refuse',
        'accepte',
        'visite_planifiee',
        'refus_apres_visite',
        'contrat_envoye',
        'contrat_signe'
    ) DEFAULT 'en_cours';

-- Étape 2 : migrer les lignes existantes
UPDATE candidatures
    SET statut = 'refus_apres_visite'
    WHERE statut = 'visite_planifiee';

-- Étape 3 : retirer l'ancienne valeur de l'enum
ALTER TABLE candidatures
    MODIFY COLUMN statut ENUM(
        'en_cours',
        'refuse',
        'accepte',
        'refus_apres_visite',
        'contrat_envoye',
        'contrat_signe'
    ) DEFAULT 'en_cours';
