-- Migration 116 : Corrections templates emails et ajout template client décompte validé
-- 1. Ajoute logement_reference aux variables du template decompte_valide_collab
-- 2. Ajoute la variable 'company' aux variables_disponibles de tous les templates
-- 3. Crée le template decompte_valide_client pour notifier le locataire

-- 1. Mettre à jour decompte_valide_collab : ajouter logement_reference
UPDATE email_templates
SET variables_disponibles = 'reference_decompte,reference_signalement,titre,adresse,logement_reference,montant_total,lignes_html,company',
    updated_at = NOW()
WHERE identifiant = 'decompte_valide_collab';

-- 2. Ajouter 'company' aux variables_disponibles de tous les templates qui ne l'ont pas encore
UPDATE email_templates
SET variables_disponibles = CASE
    WHEN variables_disponibles IS NULL OR variables_disponibles = '' THEN 'company'
    WHEN variables_disponibles NOT LIKE '%company%' THEN CONCAT(variables_disponibles, ',company')
    ELSE variables_disponibles
END,
updated_at = NOW()
WHERE variables_disponibles NOT LIKE '%company%' OR variables_disponibles IS NULL;

-- 3. Créer le template decompte_valide_client pour notifier le locataire lors de la validation du décompte
INSERT INTO email_templates (identifiant, nom, sujet, corps_html, variables_disponibles, description, actif, ordre, created_at)
VALUES (
    'decompte_valide_client',
    'Décompte d''intervention validé (client)',
    'Décompte validé — Réf. {{reference_decompte}} — {{company}}',
    '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Décompte validé</title></head>
<body style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;margin:0 auto;padding:20px;">
    <div style="background:linear-gradient(135deg,#2c3e50 0%,#3498db 100%);color:white;padding:30px;text-align:center;border-radius:10px 10px 0 0;">
        <h1 style="margin:0;font-size:24px;">📋 Décompte d''Intervention Validé</h1>
        <p style="margin:10px 0 0;font-size:15px;">{{company}}</p>
    </div>
    <div style="background:#fff;padding:30px;border:1px solid #e0e0e0;border-top:none;">
        <p>Bonjour {{prenom}} {{nom}},</p>
        <p>Le décompte d''intervention concernant votre logement a été validé :</p>
        <div style="background:#e8f4fd;border-left:4px solid #3498db;padding:15px;margin:20px 0;border-radius:0 5px 5px 0;">
            <p style="margin:5px 0;"><strong>Décompte :</strong> <code>{{reference_decompte}}</code></p>
            <p style="margin:5px 0;"><strong>Signalement :</strong> {{reference_signalement}}</p>
            <p style="margin:5px 0;"><strong>Titre :</strong> {{titre}}</p>
            <p style="margin:5px 0;"><strong>Logement :</strong> {{adresse}}</p>
            <p style="margin:5px 0;"><strong>Référence logement :</strong> {{logement_reference}}</p>
            <p style="margin:5px 0;"><strong>Montant total :</strong> <strong>{{montant_total}} €</strong></p>
        </div>
        {{lignes_html}}
        <p>Si vous avez des questions concernant ce décompte, n''hésitez pas à nous contacter.</p>
    </div>
    <div style="background:#f8f9fa;padding:15px;text-align:center;border-radius:0 0 10px 10px;border:1px solid #e0e0e0;border-top:none;">
        <p style="margin:0;color:#666;font-size:12px;">{{company}}</p>
    </div>
    {{signature}}
</body>
</html>',
    'prenom,nom,reference_decompte,reference_signalement,titre,adresse,logement_reference,montant_total,lignes_html,company',
    'Email envoyé au locataire (client) quand un décompte est validé par l''administration',
    1, 100, NOW()
)
ON DUPLICATE KEY UPDATE
    nom = VALUES(nom),
    sujet = VALUES(sujet),
    corps_html = VALUES(corps_html),
    variables_disponibles = VALUES(variables_disponibles),
    description = VALUES(description),
    updated_at = NOW();
