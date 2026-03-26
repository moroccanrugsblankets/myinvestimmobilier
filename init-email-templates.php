<?php
/**
 * Initialize Email Templates
 * This script creates default email templates if they don't exist
 * Run this script if email templates are missing from the database
 * 
 * Usage:
 *   php init-email-templates.php          - Create missing templates
 *   php init-email-templates.php --reset  - Reset all templates to defaults
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

// Check for reset flag
$reset = in_array('--reset', $argv ?? []);

echo "=== Initialisation des templates d'email ===\n";
if ($reset) {
    echo "MODE: Réinitialisation complète des templates\n";
} else {
    echo "MODE: Création des templates manquants uniquement\n";
}
echo "\n";

// Check if email_templates table exists
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'email_templates'");
    if (!$stmt->fetch()) {
        echo "❌ Table 'email_templates' n'existe pas.\n";
        echo "Veuillez exécuter les migrations d'abord: php run-migrations.php\n";
        exit(1);
    }
    echo "✓ Table 'email_templates' existe\n\n";
} catch (PDOException $e) {
    echo "❌ Erreur lors de la vérification de la table: " . $e->getMessage() . "\n";
    exit(1);
}

// Define default templates
$templates = [
    [
        'identifiant' => 'candidature_recue',
        'nom' => 'Accusé de réception de candidature',
        'sujet' => 'Votre candidature a bien été reçue - My Invest Immobilier',
        'corps_html' => '<!DOCTYPE html>
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
            
            <p>Nous vous confirmons la bonne réception de votre candidature pour le logement <strong>{{logement}}</strong>.</p>
            
            <p><strong>Référence de votre candidature :</strong> {{reference}}</p>
            
            <p>Votre dossier est en cours d\'étude. Nous reviendrons vers vous dans les meilleurs délais.</p>
            
            <p>Nous restons à votre disposition pour toute question.</p>
            
            {{signature}}
        </div>
        <div style="text-align: center; padding: 20px; font-size: 12px; color: #666;">
            <p>Date de soumission : {{date}}</p>
        </div>
    </div>
</body>
</html>',
        'variables_disponibles' => '["nom", "prenom", "email", "logement", "reference", "date"]',
        'description' => 'Email envoyé au candidat dès la soumission de sa candidature'
    ],
    [
        'identifiant' => 'candidature_acceptee',
        'nom' => 'Candidature acceptée',
        'sujet' => 'Suite à votre candidature',
        'corps_html' => '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0;">
    <div style="max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 28px;">My Invest Immobilier</h1>
        </div>
        <div style="padding: 30px;">
            <p style="margin: 15px 0;">Bonjour,</p>
            
            <p style="margin: 15px 0;">Nous vous remercions pour l\'intérêt que vous portez à notre logement et pour votre candidature.</p>
            
            <p style="margin: 15px 0;">Après une première analyse de votre dossier, nous avons le plaisir de vous informer qu\'il a été retenu pour la suite du processus.<br>
            Nous reviendrons vers vous prochainement afin de convenir ensemble d\'une date de visite.</p>
            
            <p style="margin: 15px 0;">Nous vous remercions encore pour votre démarche et restons à votre disposition pour toute information complémentaire.</p>
            
            {{signature}}
        </div>
        <div style="background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #e9ecef;">
            <p>My Invest Immobilier - Gestion locative professionnelle</p>
        </div>
    </div>
</body>
</html>',
        'variables_disponibles' => '["nom", "prenom", "email", "logement", "reference", "date", "lien_confirmation"]',
        'description' => 'Email envoyé au candidat si sa candidature est acceptée après le délai'
    ],
    [
        'identifiant' => 'candidature_refusee',
        'nom' => 'Candidature non retenue',
        'sujet' => 'Réponse à votre candidature',
        'corps_html' => '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0;">
    <div style="max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 28px;">My Invest Immobilier</h1>
        </div>
        <div style="padding: 30px;">
            <p style="margin: 15px 0;">Bonjour,</p>
            
            <p style="margin: 15px 0;">Nous vous remercions pour l\'intérêt que vous portez à notre logement et pour le temps consacré à votre candidature.</p>
            
            <p style="margin: 15px 0;">Après étude de l\'ensemble des dossiers reçus, nous vous informons que nous ne donnerons pas suite à votre demande pour ce logement.</p>
            
            <p style="margin: 15px 0;">Nous vous remercions pour votre démarche et vous souhaitons pleine réussite dans vos recherches.</p>
            
            {{signature}}
        </div>
        <div style="background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #e9ecef;">
            <p>My Invest Immobilier - Gestion locative professionnelle</p>
        </div>
    </div>
</body>
</html>',
        'variables_disponibles' => '["nom", "prenom", "email"]',
        'description' => 'Email envoyé au candidat si sa candidature est refusée automatiquement'
    ],
    [
        'identifiant' => 'visite_planifiee',
        'nom' => 'Refus après visite',
        'sujet' => 'Suite à votre visite - My Invest Immobilier',
        'corps_html' => '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0;">
    <div style="max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 28px;">My Invest Immobilier</h1>
        </div>
        <div style="padding: 30px;">
            <p style="margin: 15px 0;">Bonjour {{nom}},</p>
            
            <p style="margin: 15px 0;">📅 <strong>Votre visite du logement a été planifiée.</strong></p>
            
            <p style="margin: 15px 0;">Nous vous contacterons prochainement pour confirmer la date et l\'heure de la visite.</p>
            
            <p style="margin: 15px 0;">{{commentaire}}</p>
            
            <p style="margin: 15px 0;">Nous restons à votre disposition pour toute question.</p>
            
            {{signature}}
        </div>
        <div style="background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #e9ecef;">
            <p>My Invest Immobilier - Gestion locative professionnelle</p>
        </div>
    </div>
</body>
</html>',
        'variables_disponibles' => '["nom", "prenom", "email", "commentaire"]',
        'description' => 'Email envoyé au candidat quand une visite est planifiée'
    ],
    [
        'identifiant' => 'statut_refus_apres_visite',
        'nom' => 'Refus après visite',
        'sujet' => 'Suite à votre visite - My Invest Immobilier',
        'corps_html' => '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0;">
    <div style="max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 28px;">My Invest Immobilier</h1>
        </div>
        <div style="padding: 30px;">
            <p style="margin: 15px 0;">Bonjour {{nom}},</p>

            <p style="margin: 15px 0;">Nous vous remercions d\'avoir visité le logement <strong>{{logement}}</strong> et du temps que vous nous avez consacré.</p>

            <p style="margin: 15px 0;">Après examen de votre dossier, nous sommes au regret de vous informer que nous ne sommes pas en mesure de donner suite à votre candidature.</p>

            <p style="margin: 15px 0;">{{commentaire}}</p>

            <p style="margin: 15px 0;">Nous vous souhaitons bonne chance dans vos recherches.</p>

            {{signature}}
        </div>
        <div style="background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #e9ecef;">
            <p>My Invest Immobilier - Gestion locative professionnelle</p>
        </div>
    </div>
</body>
</html>',
        'variables_disponibles' => '["nom", "prenom", "logement", "commentaire"]',
        'description' => 'Email envoyé au candidat lorsque sa candidature est refusée après une visite'
    ],
    [
        'identifiant' => 'statut_contrat_envoye',
        'nom' => 'Contrat envoyé',
        'sujet' => 'Contrat de bail - My Invest Immobilier',
        'corps_html' => '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0;">
    <div style="max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 28px;">My Invest Immobilier</h1>
        </div>
        <div style="padding: 30px;">
            <p style="margin: 15px 0;">Bonjour {{nom}},</p>
            
            <p style="margin: 15px 0;">📄 <strong>Votre contrat de bail est prêt.</strong></p>
            
            <p style="margin: 15px 0;">Vous allez recevoir un lien pour le signer électroniquement.</p>
            
            <p style="margin: 15px 0;">{{commentaire}}</p>
            
            <p style="margin: 15px 0;">Nous restons à votre disposition pour toute question.</p>
            
            {{signature}}
        </div>
        <div style="background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #e9ecef;">
            <p>My Invest Immobilier - Gestion locative professionnelle</p>
        </div>
    </div>
</body>
</html>',
        'variables_disponibles' => '["nom", "prenom", "email", "commentaire"]',
        'description' => 'Email envoyé au candidat quand le contrat est envoyé'
    ],
    [
        'identifiant' => 'statut_contrat_signe',
        'nom' => 'Contrat signé',
        'sujet' => 'Contrat signé - My Invest Immobilier',
        'corps_html' => '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0;">
    <div style="max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 28px;">My Invest Immobilier</h1>
        </div>
        <div style="padding: 30px;">
            <p style="margin: 15px 0;">Bonjour {{nom}},</p>
            
            <p style="margin: 15px 0;">✓ <strong>Nous avons bien reçu votre contrat signé.</strong></p>
            
            <p style="margin: 15px 0;">Nous vous contacterons prochainement pour les modalités d\'entrée dans le logement.</p>
            
            <p style="margin: 15px 0;">{{commentaire}}</p>
            
            <p style="margin: 15px 0;">Nous restons à votre disposition pour toute question.</p>
            
            {{signature}}
        </div>
        <div style="background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #e9ecef;">
            <p>My Invest Immobilier - Gestion locative professionnelle</p>
        </div>
    </div>
</body>
</html>',
        'variables_disponibles' => '["nom", "prenom", "email", "commentaire"]',
        'description' => 'Email envoyé au candidat quand le contrat est signé'
    ],
    [
        'identifiant' => 'admin_nouvelle_candidature',
        'nom' => 'Notification admin - Nouvelle candidature',
        'sujet' => 'Nouvelle candidature reçue - {{reference}}',
        'corps_html' => '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="background: #2c3e50; color: white; padding: 20px; text-align: center;">
            <h1>Nouvelle candidature</h1>
        </div>
        <div style="background: #f8f9fa; padding: 30px;">
            <h2>Candidature reçue</h2>
            
            <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%;">Référence</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{reference}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%;">Candidat</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{prenom}} {{nom}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%;">Email</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{email}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%;">Téléphone</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{telephone}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%;">Logement</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{logement}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%;">Revenus mensuels</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{revenus}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%;">Statut professionnel</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{statut_pro}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%;">Date de soumission</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{date}}</td>
                </tr>
            </table>
            
            <p><a href="{{lien_admin}}">Voir la candidature dans l\'admin</a></p>
            
            {{signature}}
        </div>
        <div style="text-align: center; padding: 20px; font-size: 12px; color: #666;">
            <p>My Invest Immobilier - Système de gestion des candidatures</p>
        </div>
    </div>
</body>
</html>',
        'variables_disponibles' => '["nom", "prenom", "email", "telephone", "logement", "reference", "date", "revenus", "statut_pro", "lien_admin"]',
        'description' => 'Email envoyé aux administrateurs lors d\'une nouvelle candidature'
    ],
    [
        'identifiant' => 'contrat_signature',
        'nom' => 'Invitation à signer le contrat de bail',
        'sujet' => 'Contrat de bail à signer – Action immédiate requise',
        'corps_html' => '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0;">
    <div style="max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; padding: 30px 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 24px;">📝 Contrat de Bail à Signer</h1>
        </div>
        <div style="padding: 30px 20px;">
            <p>Bonjour,</p>
            
            <p>Merci de prendre connaissance de la procédure ci-dessous.</p>
            
            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <strong>⏰ Action immédiate requise</strong><br>
                Délai de 24 heures à compter de la réception de ce message
            </div>
            
            <h3>📋 Procédure de signature du bail</h3>
            <p>Merci de compléter l\'ensemble de la procédure dans un délai de 24 heures, incluant :</p>
            <ol>
                <li><strong>La signature du contrat de bail en ligne</strong></li>
                <li><strong>La transmission d\'une pièce d\'identité</strong> en cours de validité (CNI ou passeport)</li>
                <li><strong>Le règlement du dépôt de garantie</strong> (2 mois de loyer) par virement bancaire instantané</li>
            </ol>
            
            <div style="background: #f8f9fa; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <p style="margin: 0;"><strong>Important :</strong></p>
                <ul style="margin: 10px 0 0 0;">
                    <li>La prise d\'effet du bail et la remise des clés interviendront uniquement après réception complète de l\'ensemble des éléments</li>
                    <li>À défaut de réception complète du dossier dans le délai indiqué, la réservation du logement pourra être remise en disponibilité sans autre formalité</li>
                </ul>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="{{lien_signature}}" style="display: inline-block; padding: 15px 30px; background: #667eea; color: #ffffff; text-decoration: none; border-radius: 4px; margin: 20px 0; font-weight: bold;">🖊️ Accéder au Contrat de Bail</a>
            </div>
            
            <p>Nous restons à votre disposition en cas de question.</p>
            
            {{signature}}
        </div>
        <div style="background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666;">
            <p>My Invest Immobilier - Gestion locative professionnelle</p>
        </div>
    </div>
</body>
</html>',
        'variables_disponibles' => '["nom", "prenom", "email", "adresse", "lien_signature"]',
        'description' => 'Email envoyé au locataire pour l\'inviter à signer le contrat de bail en ligne'
    ],
    [
        'identifiant' => 'contrat_finalisation_client',
        'nom' => 'Contrat de bail - Finalisation Client',
        'sujet' => 'My Invest Immobilier: Contrat de bail – Finalisation',
        'corps_html' => '<!DOCTYPE html>
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
            
            <p>Veuillez trouver ci-joint une copie du <strong>contrat de bail dûment complété</strong>.</p>
            
            <div style="background: #e8f4f8; border-left: 4px solid #3498db; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <strong>📋 Référence du contrat :</strong> {{reference}}
            </div>
            
            <h3>Informations importantes</h3>
            
            <p>La prise d\'effet du bail intervient après le <span style="color: #e74c3c; font-weight: bold;">règlement immédiat du dépôt de garantie</span>, correspondant à deux mois de loyer (<strong>{{depot_garantie}}</strong>), par virement bancaire instantané sur le compte suivant :</p>
            
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
            
            <p>Dès réception du règlement, nous vous confirmerons la prise d\'effet du bail ainsi que les modalités de remise des clés.</p>
            
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
        'variables_disponibles' => '["nom", "prenom", "reference", "depot_garantie"]',
        'description' => 'Email HTML envoyé au client lors de la finalisation du contrat avec le PDF joint'
    ],
    [
        'identifiant' => 'contrat_finalisation_admin',
        'nom' => 'Notification Admin - Contrat Finalisé',
        'sujet' => 'Contrat signé - {{reference}} - Vérification requise',
        'corps_html' => '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="background: #2c3e50; color: white; padding: 30px 20px; text-align: center; border-radius: 10px 10px 0 0;">
            <h1 style="margin: 0;">📝 Contrat Signé - Notification Admin</h1>
        </div>
        <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;">
            <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <strong>✅ Nouveau contrat signé !</strong> Un contrat de bail a été finalisé et signé par le(s) locataire(s).
            </div>
            
            <h2>Détails du contrat</h2>
            
            <table style="width: 100%; border-collapse: collapse; margin: 20px 0; background: #fff;">
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%; background: #f8f9fa;">Référence</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd;"><strong>{{reference}}</strong></td>
                </tr>
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%; background: #f8f9fa;">Logement</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd;">{{logement}}</td>
                </tr>
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%; background: #f8f9fa;">Locataire(s)</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd;">{{locataires}}</td>
                </tr>
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%; background: #f8f9fa;">Dépôt de garantie</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd;">{{depot_garantie}}</td>
                </tr>
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%; background: #f8f9fa;">Date de signature</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd;">{{date_signature}}</td>
                </tr>
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%; background: #f8f9fa;">Date de finalisation</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd;">{{date_finalisation}}</td>
                </tr>
            </table>
            
            <h3>Actions à effectuer :</h3>
            <ol>
                <li>Vérifier la réception du dépôt de garantie</li>
                <li>Confirmer la prise d\'effet du bail</li>
                <li>Organiser la remise des clés</li>
                <li>Planifier l\'état des lieux d\'entrée</li>
            </ol>
            
            <p style="text-align: center;">
                <a href="{{lien_admin}}" style="display: inline-block; padding: 12px 30px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0;">Voir le Contrat dans l\'Admin</a>
            </p>
            
            <p><strong>Note :</strong> Le contrat PDF signé est joint à cet email.</p>
            
            {{signature}}
        </div>
        <div style="text-align: center; padding: 20px; font-size: 12px; color: #666; margin-top: 20px;">
            <p>My Invest Immobilier - Système de gestion des contrats<br>
            © 2026 My Invest Immobilier</p>
        </div>
    </div>
</body>
</html>',
        'variables_disponibles' => '["reference", "logement", "locataires", "depot_garantie", "date_signature", "date_finalisation", "lien_admin"]',
        'description' => 'Email HTML envoyé aux administrateurs quand un contrat est finalisé et signé'
    ],
    [
        'identifiant' => 'demande_justificatif_paiement',
        'nom' => 'Demande de justificatif de paiement',
        'sujet' => 'Justificatif de virement - Contrat {{reference}}',
        'corps_html' => '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center; border-radius: 10px 10px 0 0;">
            <h1 style="margin: 0;">📄 Justificatif de Paiement</h1>
        </div>
        <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;">
            <h2>Bonjour {{prenom}} {{nom}},</h2>
            
            <p>Nous vous confirmons que <strong>votre contrat de bail a été signé avec succès</strong>.</p>
            
            <div style="background: #e8f4f8; border-left: 4px solid #3498db; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <strong>📋 Référence du contrat :</strong> {{reference}}
            </div>
            
            <h3>Justificatif de virement requis</h3>
            
            <p>Afin de finaliser votre dossier, nous vous remercions de bien vouloir nous transmettre <strong>le justificatif de virement</strong> du dépôt de garantie d\'un montant de <strong>{{depot_garantie}}</strong>.</p>
            
            <div style="background: #fff; border: 2px solid #3498db; padding: 20px; margin: 20px 0; border-radius: 8px;">
                <h3 style="color: #2c3e50; margin-top: 0;">Rappel des Coordonnées Bancaires</h3>
                <div style="margin: 10px 0;">
                    <strong style="display: inline-block; min-width: 120px; color: #555;">Bénéficiaire :</strong> My Invest Immobilier
                </div>
                <div style="margin: 10px 0;">
                    <strong style="display: inline-block; min-width: 120px; color: #555;">IBAN :</strong> <span style="font-family: monospace; letter-spacing: 1px; white-space: nowrap;">FR76&nbsp;1027&nbsp;8021&nbsp;6000&nbsp;0206&nbsp;1834&nbsp;585</span>
                </div>
                <div style="margin: 10px 0;">
                    <strong style="display: inline-block; min-width: 120px; color: #555;">BIC :</strong> CMCIFRA
                </div>
                <div style="margin: 10px 0;">
                    <strong style="display: inline-block; min-width: 120px; color: #555;">Montant :</strong> {{depot_garantie}}
                </div>
            </div>
            
            <div style="background: #e8f5e9; border-left: 4px solid #4caf50; padding: 20px; margin: 20px 0; border-radius: 4px; text-align: center;">
                <h3 style="margin-top: 0; color: #2e7d32;">📤 Transmettre votre justificatif</h3>
                <p style="margin-bottom: 15px;">Une fois le virement effectué, cliquez sur le bouton ci-dessous pour envoyer votre justificatif :</p>
                <a href="{{lien_upload}}" style="display: inline-block; padding: 15px 40px; background: #4caf50; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;">
                    Envoyer mon justificatif
                </a>
                <p style="margin-top: 15px; font-size: 12px; color: #666;">Formats acceptés : JPG, PNG, PDF (max 5 Mo)</p>
            </div>
            
            <p><strong>Important :</strong> La prise d\'effet du bail et la remise des clés interviendront uniquement après réception et vérification du justificatif de paiement.</p>
            
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
        'variables_disponibles' => '["nom", "prenom", "reference", "depot_garantie", "lien_upload"]',
        'description' => 'Email automatique envoyé après signature du contrat pour demander le justificatif de paiement du dépôt de garantie'
    ],
    [
        'identifiant' => 'notification_justificatif_paiement_admin',
        'nom' => 'Notification Admin - Justificatif de paiement reçu',
        'sujet' => 'Justificatif reçu - Contrat {{reference}}',
        'corps_html' => '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <div style="background: #28a745; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
            <h1 style="margin: 0;">📄 Justificatif de paiement reçu</h1>
        </div>
        <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px;">
            <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <strong>✅ Nouveau justificatif :</strong> Un client a transmis son justificatif de virement du dépôt de garantie.
            </div>
            
            <h2>Informations du contrat</h2>
            
            <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%;">Référence</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{reference}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Logement</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{logement}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Locataires</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{locataires}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Date de réception</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{date_envoi}}</td>
                </tr>
            </table>
            
            <p><strong>Action requise :</strong></p>
            <ol>
                <li>Vérifier le justificatif de paiement dans le dossier du contrat</li>
                <li>Valider que le montant correspond au dépôt de garantie</li>
                <li>Confirmer la réception et organiser la remise des clés</li>
            </ol>
            
            <p style="text-align: center; margin: 30px 0;">
                <a href="{{lien_admin}}" style="display: inline-block; padding: 14px 35px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">👁 Voir le détail du contrat</a>
            </p>
        </div>
        <div style="text-align: center; padding: 20px; font-size: 12px; color: #666;">
            <p>My Invest Immobilier - Système de gestion des contrats</p>
        </div>
    </div>
</body>
</html>',
        'variables_disponibles' => '["reference", "logement", "locataires", "date_envoi", "lien_admin"]',
        'description' => 'Email envoyé aux administrateurs quand un client envoie son justificatif de paiement'
    ],
    [
        'identifiant' => 'demande_assurance_visale',
        'nom' => 'Demande d\'assurance habitation et Visale',
        'sujet' => 'My Invest Immobilier - Documents requis après signature de votre bail',
        'corps_html' => '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%); color: white; padding: 30px 20px; text-align: center; border-radius: 10px 10px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
        .docs-list { background: #fff; border: 1px solid #ddd; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .upload-btn { display: inline-block; padding: 15px 40px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="margin: 0;">Documents requis</h1>
            <p style="margin: 10px 0 0 0; opacity: 0.9;">My Invest Immobilier</p>
        </div>
        <div class="content">
            <p>Bonjour {{prenom}} {{nom}},</p>

            <p>Suite à la contre-signature de votre contrat de bail par My Invest Immobilier, nous vous remercions pour votre confiance.</p>

            <p>Afin de finaliser définitivement votre dossier, nous vous remercions de bien vouloir nous transmettre dans les meilleurs délais les éléments suivants :</p>

            <div class="docs-list">
                <ul style="margin: 0; padding-left: 20px;">
                    <li style="margin-bottom: 10px;">Votre <strong>attestation d\'assurance habitation</strong> en cours de validité couvrant le logement loué</li>
                    <li>Votre <strong>numéro de garantie Visale</strong> (ainsi que le visa certifié si disponible)</li>
                </ul>
            </div>

            <p>Ces documents sont obligatoires et doivent être en notre possession pour valider l\'entrée effective dans les lieux.</p>

            <div style="background: #e8f5e9; border-left: 4px solid #4caf50; padding: 20px; margin: 20px 0; border-radius: 4px; text-align: center;">
                <h3 style="margin-top: 0; color: #2e7d32;">📤 Transmettre vos documents</h3>
                <p style="margin-bottom: 15px;">Cliquez sur le bouton ci-dessous pour nous faire parvenir vos documents :</p>
                <a href="{{lien_upload}}" class="upload-btn">
                    Envoyer mes documents
                </a>
                <p style="margin-top: 15px; font-size: 12px; color: #666;">Formats acceptés : JPG, PNG, PDF (max 5 Mo par fichier)</p>
            </div>

            <p>Nous restons naturellement à votre disposition pour toute question.</p>

            <p>Cordialement,</p>

            {{signature}}
        </div>
        <div class="footer">
            <p>My Invest Immobilier - Gestion locative professionnelle<br>
            © 2026 My Invest Immobilier - Tous droits réservés</p>
        </div>
    </div>
</body>
</html>',
        'variables_disponibles' => '["nom", "prenom", "reference", "lien_upload"]',
        'description' => 'Email envoyé au locataire après validation du contrat pour demander l\'attestation d\'assurance habitation et le numéro Visale'
    ],
    [
        'identifiant' => 'notification_assurance_visale_admin',
        'nom' => 'Notification Admin - Documents assurance/Visale reçus',
        'sujet' => 'Documents assurance/Visale reçus - Contrat {{reference}}',
        'corps_html' => '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #28a745; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .success-box { background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .btn { display: inline-block; padding: 12px 30px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="margin: 0;">📄 Documents assurance/Visale reçus</h1>
        </div>
        <div class="content">
            <div class="success-box">
                <strong>✅ Nouveau dépôt :</strong> Un locataire a transmis ses documents d\'assurance habitation et/ou Visale.
            </div>

            <h2>Informations du contrat</h2>

            <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold; width: 40%;">Référence</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{reference}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Logement</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{logement}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Locataires</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{locataires}}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: bold;">Date de réception</td>
                    <td style="padding: 8px; border-bottom: 1px solid #ddd;">{{date_envoi}}</td>
                </tr>
            </table>

            <p><strong>Action requise :</strong></p>
            <ol>
                <li>Vérifier l\'attestation d\'assurance habitation dans le dossier du contrat</li>
                <li>Vérifier le numéro de garantie Visale et le visa certifié si fourni</li>
                <li>Confirmer la validité des documents et organiser l\'entrée dans les lieux</li>
            </ol>

            <p style="text-align: center;">
                <a href="{{lien_admin}}" class="btn">Voir le contrat</a>
            </p>
        </div>
    </div>
</body>
</html>',
        'variables_disponibles' => '["reference", "logement", "locataires", "date_envoi", "lien_admin"]',
        'description' => 'Notification envoyée aux administrateurs quand un locataire dépose ses documents assurance habitation et Visale'
    ],
    [
        'identifiant' => 'confirmation_assurance_visale_locataire',
        'nom' => 'Confirmation réception documents assurance/Visale - Locataire',
        'sujet' => 'My Invest Immobilier - Confirmation de réception de vos documents',
        'corps_html' => '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%); color: white; padding: 30px 20px; text-align: center; border-radius: 10px 10px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
        .success-box { background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="margin: 0;">Documents reçus</h1>
            <p style="margin: 10px 0 0 0; opacity: 0.9;">My Invest Immobilier</p>
        </div>
        <div class="content">
            <p>Bonjour {{prenom}} {{nom}},</p>

            <div class="success-box">
                <strong>✅ Confirmation :</strong> Nous avons bien reçu vos documents d\'assurance habitation et/ou Visale pour le contrat <strong>{{reference}}</strong>.
            </div>

            <p>Notre équipe va procéder à la vérification de vos documents dans les meilleurs délais. Vous serez contacté(e) prochainement pour organiser l\'entrée dans les lieux.</p>

            <p>Si vous avez des questions, n\'hésitez pas à nous contacter.</p>

            <p>Cordialement,</p>

            {{signature}}
        </div>
        <div class="footer">
            <p>My Invest Immobilier - Gestion locative professionnelle<br>
            © 2026 My Invest Immobilier - Tous droits réservés</p>
        </div>
    </div>
</body>
</html>',
        'variables_disponibles' => '["nom", "prenom", "reference"]',
        'description' => 'Email de confirmation envoyé au locataire après réception de ses documents assurance habitation et Visale'
    ]
];

$created = 0;
$updated = 0;
$skipped = 0;

foreach ($templates as $template) {
    try {
        // Check if template exists
        $stmt = $pdo->prepare("SELECT id FROM email_templates WHERE identifiant = ?");
        $stmt->execute([$template['identifiant']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            if ($reset) {
                // Update existing template
                $stmt = $pdo->prepare("
                    UPDATE email_templates 
                    SET nom = ?, sujet = ?, corps_html = ?, variables_disponibles = ?, 
                        description = ?, actif = 1, updated_at = NOW()
                    WHERE identifiant = ?
                ");
                $stmt->execute([
                    $template['nom'],
                    $template['sujet'],
                    $template['corps_html'],
                    $template['variables_disponibles'],
                    $template['description'],
                    $template['identifiant']
                ]);
                echo "↻ Template '{$template['identifiant']}' réinitialisé (ID: {$existing['id']})\n";
                $updated++;
            } else {
                echo "⊘ Template '{$template['identifiant']}' existe déjà (ID: {$existing['id']})\n";
                $skipped++;
            }
        } else {
            // Insert new template
            $stmt = $pdo->prepare("
                INSERT INTO email_templates 
                (identifiant, nom, sujet, corps_html, variables_disponibles, description, actif, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $stmt->execute([
                $template['identifiant'],
                $template['nom'],
                $template['sujet'],
                $template['corps_html'],
                $template['variables_disponibles'],
                $template['description']
            ]);
            echo "✓ Template '{$template['identifiant']}' créé avec succès\n";
            $created++;
        }
    } catch (PDOException $e) {
        echo "❌ Erreur lors du traitement du template '{$template['identifiant']}': " . $e->getMessage() . "\n";
    }
}

echo "\n=== Résumé ===\n";
echo "Templates créés: $created\n";
if ($reset) {
    echo "Templates réinitialisés: $updated\n";
}
echo "Templates existants (ignorés): $skipped\n";

if ($created > 0 || $updated > 0) {
    echo "\n✓ Templates d'email initialisés avec succès!\n";
    echo "Vous pouvez maintenant les voir et les modifier dans /admin-v2/email-templates.php\n";
} else {
    echo "\nℹ Tous les templates existent déjà.\n";
    if (!$reset) {
        echo "Pour réinitialiser les templates aux valeurs par défaut, utilisez:\n";
        echo "  php init-email-templates.php --reset\n";
    }
}

echo "\n=== Test des templates ===\n";
// Verify templates are accessible
$stmt = $pdo->query("SELECT identifiant, nom, actif FROM email_templates ORDER BY identifiant");
$allTemplates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($allTemplates)) {
    echo "❌ Aucun template trouvé dans la base de données!\n";
} else {
    echo "✓ Templates disponibles:\n";
    foreach ($allTemplates as $t) {
        $status = $t['actif'] ? '✓ Actif' : '✗ Inactif';
        echo "  - {$t['identifiant']}: {$t['nom']} ($status)\n";
    }
}

echo "\n=== Fin ===\n";
