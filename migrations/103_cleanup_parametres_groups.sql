-- Migration 103 : Nettoyage des groupes de paramètres
-- Date: 2026-03-10
-- Description:
--   Déplace les paramètres vers leurs groupes dédiés afin qu'ils n'apparaissent
--   plus sur la page Paramètres généraux mais uniquement sur leurs pages de
--   configuration respectives.

-- 1. delai_expiration_lien_contrat → groupe 'contrats' (géré via contrat-configuration.php)
UPDATE parametres
SET groupe = 'contrats', updated_at = NOW()
WHERE cle = 'delai_expiration_lien_contrat';

-- 2. Paramètres états des lieux email → groupe 'etats_lieux' (déjà géré via email-templates.php)
UPDATE parametres
SET groupe = 'etats_lieux', updated_at = NOW()
WHERE cle IN ('etat_lieux_email_subject', 'etat_lieux_email_template');

-- 3. Paramètres templates inventaire → groupe 'inventaires' (géré via inventaire-configuration.php)
UPDATE parametres
SET groupe = 'inventaires', updated_at = NOW()
WHERE cle IN ('inventaire_template_html', 'inventaire_sortie_template_html', 'inventaire_items_template');

-- 4. Paramètres rappels loyers → groupe 'rappel_loyers' (géré via configuration-rappels-loyers.php)
UPDATE parametres
SET groupe = 'rappel_loyers', updated_at = NOW()
WHERE cle IN (
    'rappel_loyers_actif',
    'rappel_loyers_destinataires',
    'rappel_loyers_heure_execution',
    'rappel_loyers_inclure_bouton',
    'rappel_loyers_dates_envoi'
);

-- 5. Paramètres workflow candidatures → groupe 'workflow' (géré via candidatures-configuration.php)
UPDATE parametres
SET groupe = 'workflow', updated_at = NOW()
WHERE cle IN ('delai_reponse_valeur', 'delai_reponse_unite', 'jours_ouvres_debut', 'jours_ouvres_fin');
