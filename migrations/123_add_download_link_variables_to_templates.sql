-- Migration 123: Add download link variables to email templates variables_disponibles
-- Adds lien_telechargement_dpe to contrat_signature template
-- Adds lien_telechargement to etat-lieux and inventaire templates
-- Adds lien_telechargement_quittance to quittance template
-- Adds lien_telechargement_bilan to bilan template

-- contrat_signature: add lien_telechargement_dpe
UPDATE email_templates
SET variables_disponibles = JSON_ARRAY(
    'nom', 'prenom', 'email', 'adresse',
    'lien_signature', 'date_expiration_lien_contrat',
    'duree_garantie', 'lien_telechargement_dpe'
)
WHERE identifiant = 'contrat_signature';

-- etat_lieux_entree_envoye: add lien_telechargement
UPDATE email_templates
SET variables_disponibles = JSON_ARRAY(
    'locataire_nom', 'adresse', 'date_etat', 'reference', 'type', 'lien_telechargement'
)
WHERE identifiant = 'etat_lieux_entree_envoye';

-- etat_lieux_sortie_envoye: add lien_telechargement
UPDATE email_templates
SET variables_disponibles = JSON_ARRAY(
    'locataire_nom', 'adresse', 'date_etat', 'reference', 'type', 'lien_telechargement'
)
WHERE identifiant = 'etat_lieux_sortie_envoye';

-- inventaire_entree_envoye: add lien_telechargement
UPDATE email_templates
SET variables_disponibles = JSON_ARRAY(
    'locataire_nom', 'adresse', 'date_inventaire', 'reference', 'type', 'lien_telechargement'
)
WHERE identifiant = 'inventaire_entree_envoye';

-- inventaire_sortie_envoye: add lien_telechargement
UPDATE email_templates
SET variables_disponibles = JSON_ARRAY(
    'locataire_nom', 'adresse', 'date_inventaire', 'reference', 'type', 'lien_telechargement'
)
WHERE identifiant = 'inventaire_sortie_envoye';

-- quittance_envoyee: add lien_telechargement_quittance
UPDATE email_templates
SET variables_disponibles = JSON_ARRAY(
    'locataire_nom', 'locataire_prenom', 'adresse', 'periode',
    'montant_loyer', 'montant_charges', 'montant_total', 'lien_telechargement_quittance'
)
WHERE identifiant = 'quittance_envoyee';

-- bilan_logement: add lien_telechargement_bilan
UPDATE email_templates
SET variables_disponibles = JSON_ARRAY(
    'locataire_nom', 'adresse', 'contrat_ref', 'date',
    'depot_garantie', 'valeur_estimative', 'total_solde_debiteur',
    'total_solde_crediteur', 'montant_a_restituer', 'reste_du',
    'commentaire', 'lien_telechargement_bilan'
)
WHERE identifiant = 'bilan_logement';
