-- Migration 087: Ajouter {{reference}} dans le template confirmation_paiement_loyer
-- Date: 2026-03-03
-- Description: Ajoute la variable {{reference}} (référence du logement) dans le template
--              de confirmation de réception de paiement de loyer.

UPDATE email_templates
SET
    corps_html = '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmation de paiement</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="margin: 0; font-size: 26px;">✓ Paiement Reçu</h1>
        <p style="margin: 10px 0 0; font-size: 16px;">Confirmation de réception</p>
    </div>

    <div style="background-color: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; border: 1px solid #dee2e6; border-top: none;">
        <p style="font-size: 16px; margin-top: 0;">Bonjour <strong>{{locataire_prenom}} {{locataire_nom}}</strong>,</p>

        <p>Nous avons bien reçu votre règlement de loyer pour la période de <strong>{{periode}}</strong>. Nous vous en remercions.</p>

        <div style="background-color: white; border-left: 4px solid #27ae60; padding: 15px; margin: 20px 0; border-radius: 5px;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0;"><strong>Logement :</strong></td>
                    <td style="padding: 8px 0; text-align: right;">{{adresse}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0;"><strong>Référence :</strong></td>
                    <td style="padding: 8px 0; text-align: right; font-family: monospace;">{{reference}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0;"><strong>Période :</strong></td>
                    <td style="padding: 8px 0; text-align: right;">{{periode}}</td>
                </tr>
                <tr style="border-top: 1px solid #e9ecef;">
                    <td style="padding: 8px 0;"><strong>Loyer :</strong></td>
                    <td style="padding: 8px 0; text-align: right;">{{montant_loyer}} €</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0;"><strong>Charges :</strong></td>
                    <td style="padding: 8px 0; text-align: right;">{{montant_charges}} €</td>
                </tr>
                <tr style="border-top: 2px solid #27ae60; font-size: 18px; font-weight: bold;">
                    <td style="padding: 12px 0;">Total :</td>
                    <td style="padding: 12px 0; text-align: right; color: #27ae60;">{{montant_total}} €</td>
                </tr>
            </table>
        </div>

        <p>Vous recevrez votre quittance de loyer par email dans les instants qui suivent.</p>

        <p style="color: #6c757d; font-size: 14px; margin-top: 30px;">
            Pour toute question, n''hésitez pas à nous contacter.
        </p>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6;">
            {{signature}}
        </div>
    </div>
</body>
</html>',
    variables_disponibles = '["locataire_nom", "locataire_prenom", "adresse", "reference", "periode", "montant_loyer", "montant_charges", "montant_total", "signature"]',
    updated_at = NOW()
WHERE identifiant = 'confirmation_paiement_loyer';
