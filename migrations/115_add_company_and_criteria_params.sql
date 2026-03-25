-- Migration 115 : Ajout paramètres nom de société et critère période d'essai CDI
-- Permet de configurer le nom de la société et d'activer/désactiver le critère
-- "période d'essai en cours" dans l'évaluation des candidatures.

-- Nom de la société (groupe général, visible dans Paramètres → Général)
INSERT INTO parametres (cle, valeur, type, groupe, description)
VALUES
    ('company_name', 'My Invest Immobilier', 'string', 'general',
     'Nom de la société affiché dans les emails, PDF et pages publiques.')
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Critère d'évaluation : refuser les candidats CDI en période d'essai
INSERT INTO parametres (cle, valeur, type, groupe, description)
VALUES
    ('cdi_periode_essai_bloque', '1', 'boolean', 'criteres',
     'Si activé, les candidats en CDI avec une période d''essai en cours seront automatiquement refusés.')
ON DUPLICATE KEY UPDATE updated_at = NOW();
