-- Migration 125: Update contract email templates to use links instead of PDF attachments

-- contrat_finalisation_client:
--   - Replace "ci-joint" mention with a link button using {{lien_contrat_signe}}
--   - Add lien_contrat_signe to variables_disponibles
--   - Remove reference to PDF attachment in description
UPDATE email_templates
SET  
    variables_disponibles = JSON_ARRAY('nom', 'prenom', 'reference', 'depot_garantie', 'lien_upload', 'lien_contrat_signe', 'lien_telechargement_dpe'),
    description = 'Email HTML envoyé au client lors de la finalisation du contrat avec lien vers le contrat (sans PJ)'
WHERE identifiant = 'contrat_finalisation_client';


    
