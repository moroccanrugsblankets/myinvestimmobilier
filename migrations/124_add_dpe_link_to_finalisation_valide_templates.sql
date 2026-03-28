-- Migration 124: Add lien_telechargement_dpe to contrat_finalisation_client and contrat_valide_client templates

-- contrat_finalisation_client: add lien_telechargement_dpe and lien_upload
UPDATE email_templates
SET variables_disponibles = JSON_ARRAY(
    'nom', 'prenom', 'reference', 'depot_garantie', 'lien_upload', 'lien_telechargement_dpe'
)
WHERE identifiant = 'contrat_finalisation_client';

-- contrat_valide_client: add lien_telechargement_dpe
UPDATE email_templates
SET variables_disponibles = JSON_ARRAY(
    'nom', 'prenom', 'reference', 'logement', 'date_prise_effet',
    'depot_garantie', 'lien_telecharger', 'lien_procedure_depart', 'lien_telechargement_dpe'
)
WHERE identifiant = 'contrat_valide_client';
