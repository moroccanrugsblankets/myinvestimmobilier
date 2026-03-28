-- Migration 125: Update contract email templates to use links instead of PDF attachments

-- contrat_finalisation_client:
--   - Replace "ci-joint" mention with a link button using {{lien_contrat_signe}}
--   - Add lien_contrat_signe to variables_disponibles
--   - Remove reference to PDF attachment in description
UPDATE email_templates
SET
    corps_html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center; border-radius: 10px 10px 0 0;">
            <h1 style="margin: 0;">✅ Contrat de Bail Finalisé</h1>
        </div>
        <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;">
            <h2>Bonjour {{prenom}} {{nom}},</h2>
            
            <p>Nous vous remercions pour votre confiance.</p>
            
            <p>Veuillez trouver ci-dessous le lien pour consulter votre <strong>contrat de bail dûment complété</strong> :</p>
            
            <p style="text-align: center;">
                <a href="{{lien_contrat_signe}}" style="display: inline-block; padding: 12px 30px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; margin: 10px 0;">📄 Voir le contrat signé</a>
            </p>
            
            <div style="background: #e8f4f8; border-left: 4px solid #3498db; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <strong>📋 Référence du contrat :</strong> {{reference}}
            </div>
            
            <h3>Informations importantes</h3>
            
            <p>La prise d\'\'effet du bail intervient après le <span style="color: #e74c3c; font-weight: bold;">règlement immédiat du dépôt de garantie</span>, correspondant à deux mois de loyer (<strong>{{depot_garantie}}</strong>), par virement bancaire instantané sur le compte suivant :</p>
            
            <div style="background: #fff; border: 2px solid #3498db; padding: 20px; margin: 20px 0; border-radius: 8px;">
                <h3 style="color: #2c3e50; margin-top: 0;">Coordonnées Bancaires</h3>
                <div style="margin: 10px 0;">
                    <strong style="display: inline-block; min-width: 120px; color: #555;">Bénéficiaire :</strong> My Invest Immobilier
                </div>
                <div style="margin: 10px 0; white-space: nowrap;">
                    <strong style="display: inline-block; min-width: 120px; color: #555;">IBAN :</strong> <span style="font-family: monospace; letter-spacing: 1px;">FR76&nbsp;1027&nbsp;8021&nbsp;6000&nbsp;0206&nbsp;1834&nbsp;585</span>
                </div>
                <div style="margin: 10px 0;">
                    <strong style="display: inline-block; min-width: 120px; color: #555;">BIC :</strong> CMCIFRA
                </div>
            </div>
            
            <p><strong>Prochaines étapes :</strong></p>
            <ol>
                <li>Effectuer le virement du dépôt de garantie ({{depot_garantie}})</li>
                <li>Attendre la confirmation de réception du règlement</li>
                <li>Recevoir les modalités de remise des clés</li>
            </ol>
            
            <p>Dès réception du règlement, nous vous confirmerons la prise d\'\'effet du bail ainsi que les modalités de remise des clés.</p>
            
            <p>Nous restons à votre disposition pour toute question.</p>
            
            {{signature}}
        </div>
        <div style="text-align: center; padding: 20px; font-size: 12px; color: #666; margin-top: 20px;">
            <p>My Invest Immobilier - Gestion locative professionnelle<br>
            © 2026 My Invest Immobilier - Tous droits réservés</p>
        </div>
    </div>
</body>
</html>',
    variables_disponibles = JSON_ARRAY('nom', 'prenom', 'reference', 'depot_garantie', 'lien_upload', 'lien_contrat_signe', 'lien_telechargement_dpe'),
    description = 'Email HTML envoyé au client lors de la finalisation du contrat avec lien vers le contrat (sans PJ)'
WHERE identifiant = 'contrat_finalisation_client';

-- contrat_valide_client:
--   - Update button label from "Télécharger" to "Voir" since the link now opens inline
--   - lien_telecharger now points to /pdf/download.php?contrat_id=XXX&view=1 (set in code)
UPDATE email_templates
SET
    corps_html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #28a745; color: white; padding: 20px; text-align: center; }
        .content { background: #f8f9fa; padding: 30px; }
        .success-box { background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; }
        .button { display: inline-block; padding: 12px 30px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✅ Contrat Validé</h1>
        </div>
        <div class="content">
            <h2>Bonjour {{prenom}} {{nom}},</h2>
            
            <div class="success-box">
                <strong>Félicitations !</strong> Votre contrat de bail a été validé par MY Invest Immobilier.
            </div>
            
            <p><strong>Référence du contrat :</strong> {{reference}}</p>
            <p><strong>Logement :</strong> {{logement}}</p>
            <p><strong>Date de prise d''''effet :</strong> {{date_prise_effet}}</p>
            
            <p>Le contrat final signé par toutes les parties est maintenant disponible en ligne.</p>
            
            <p style="text-align: center;">
                <a href="{{lien_telecharger}}" class="button">Voir le Contrat</a>
            </p>
            
            <p><strong>Prochaines étapes :</strong></p>
            <ol>
                <li>Versement du dépôt de garantie ({{depot_garantie}} €)</li>
                <li>Prise de possession du logement le {{date_prise_effet}}</li>
                <li>État des lieux d''''entrée</li>
            </ol>
            
            <p>Nous restons à votre disposition pour toute question.</p>
            
            <p>Cordialement,<br>
            <strong>MY Invest Immobilier</strong><br>
            contact@myinvest-immobilier.com</p>
        </div>
        <div class="footer">
            <p>MY Invest Immobilier - Gestion locative professionnelle</p>
        </div>
    </div>
</body>
</html>'
WHERE identifiant = 'contrat_valide_client';
