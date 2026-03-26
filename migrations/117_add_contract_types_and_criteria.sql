-- Migration 117 : Ajout du type de contrat et des critères d'annulation
-- Permet de choisir entre trois types de contrat (Meublé, Non meublé, Sur mesure)
-- et de configurer le préavis pour les annulations.

-- Ajout de la colonne type_contrat dans la table contrats
ALTER TABLE contrats
    ADD COLUMN  type_contrat ENUM('meuble','non_meuble','sur_mesure') NOT NULL DEFAULT 'meuble'
    AFTER nb_locataires;

-- Templates HTML par type de contrat (vides par défaut, seront remplis depuis le code PHP)
INSERT INTO parametres (cle, valeur, type, groupe, description)
VALUES
    ('contrat_template_html_meuble', '', 'text', 'contrats',
     'Template HTML du contrat de bail meublé avec variables dynamiques.')
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO parametres (cle, valeur, type, groupe, description)
VALUES
    ('contrat_template_html_non_meuble', '', 'text', 'contrats',
     'Template HTML du contrat de bail non meublé avec variables dynamiques.')
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO parametres (cle, valeur, type, groupe, description)
VALUES
    ('contrat_template_html_sur_mesure', '', 'text', 'contrats',
     'Template HTML du contrat sur mesure avec variables dynamiques.')
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Critère d'annulation : préavis en jours (0 = aucun préavis obligatoire)
INSERT INTO parametres (cle, valeur, type, groupe, description)
VALUES
    ('preavis_annulation_jours', '0', 'integer', 'contrats',
     'Nombre de jours de préavis requis pour l''annulation d''un contrat. 0 = suppression de la période de préavis.')
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Renommage du template d'email statut_visite_planifiee → visite_planifiee
-- (on ne modifie PAS les champs sujet et corps_html)
UPDATE email_templates
SET identifiant = 'visite_planifiee',
    updated_at  = NOW()
WHERE identifiant = 'statut_visite_planifiee';

-- Template pour refus après visite (si absent)
INSERT IGNORE INTO email_templates
    (identifiant, nom, sujet, corps_html, variables_disponibles, description, actif, created_at, updated_at)
VALUES (
    'statut_refus_apres_visite',
    'Refus après visite',
    'Suite à votre visite - My Invest Immobilier',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="background: #2c3e50; color: white; padding: 20px; text-align: center;">
            <h1>My Invest Immobilier</h1>
        </div>
        <div style="background: #f8f9fa; padding: 30px;">
            <h2>Bonjour {{prenom}} {{nom}},</h2>
            <p>Nous vous remercions d\'avoir visité le logement <strong>{{logement}}</strong> et du temps que vous nous avez consacré.</p>
            <p>Après examen de votre dossier, nous sommes au regret de vous informer que nous ne sommes pas en mesure de donner suite à votre candidature.</p>
            {{commentaire}}
            <p>Nous vous souhaitons bonne chance dans vos recherches.</p>
            <p>Cordialement,<br><strong>L\'équipe My Invest Immobilier</strong></p>
        </div>
    </div>
</body>
</html>',
    '["nom", "prenom", "logement", "commentaire"]',
    'Email envoyé au candidat lorsque sa candidature est refusée après une visite',
    1,
    NOW(),
    NOW()
);
