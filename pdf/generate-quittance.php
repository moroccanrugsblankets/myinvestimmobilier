<?php
/**
 * Génération du PDF de quittance de loyer
 * My Invest Immobilier
 * 
 * Utilise TCPDF pour générer un PDF professionnel de quittance de loyer
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/pdf-pagination.php';

/**
 * Générer le PDF de quittance de loyer
 * @param int $contratId ID du contrat
 * @param int $mois Mois (1-12)
 * @param int $annee Année (ex: 2024)
 * @return array|false ['filepath' => string, 'reference' => string] ou false en cas d'erreur
 */
function generateQuittancePDF($contratId, $mois, $annee) {
    global $config, $pdo;

    $contratId = (int)$contratId;
    $mois = (int)$mois;
    $annee = (int)$annee;
    
    // Constants for validation
    if (!defined('MIN_VALID_YEAR')) {
        define('MIN_VALID_YEAR', 2000);
    }
    if (!defined('MAX_VALID_MONTH')) {
        define('MAX_VALID_MONTH', 12);
    }
    if (!defined('MIN_VALID_MONTH')) {
        define('MIN_VALID_MONTH', 1);
    }
    
    if ($contratId <= 0 || $mois < MIN_VALID_MONTH || $mois > MAX_VALID_MONTH || $annee < MIN_VALID_YEAR) {
        error_log("Erreur: Paramètres invalides - Contrat: $contratId, Mois: $mois, Année: $annee");
        return false;
    }

    try {
        // Récupérer les données du contrat et du logement
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   l.reference,
                   l.adresse,
                   l.type,
                   l.surface,
                   l.loyer,
                   l.charges,
                   l.depot_garantie,
                   l.parking
            FROM contrats c
            INNER JOIN logements l ON c.logement_id = l.id
            WHERE c.id = ?
        ");
        $stmt->execute([$contratId]);
        $contrat = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contrat) {
            error_log("Erreur: Contrat #$contratId non trouvé");
            return false;
        }

        // Récupérer les locataires
        $stmt = $pdo->prepare("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC");
        $stmt->execute([$contratId]);
        $locataires = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($locataires)) {
            error_log("Erreur: Aucun locataire trouvé pour le contrat #$contratId");
            return false;
        }

        // Calculer les dates de période
        $dateDebut = new DateTime("$annee-$mois-01");
        $dateFin = clone $dateDebut;
        $dateFin->modify('last day of this month');
        
        // Générer une référence unique pour la quittance
        $referenceQuittance = 'QUI-' . $contrat['reference_unique'] . '-' . sprintf('%04d%02d', $annee, $mois);
        
        // Vérifier si la quittance existe déjà en base
        $stmt = $pdo->prepare("SELECT id, fichier_pdf FROM quittances WHERE contrat_id = ? AND mois = ? AND annee = ?");
        $stmt->execute([$contratId, $mois, $annee]);
        $existingQuittance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $quittanceId = null;
        if ($existingQuittance) {
            $quittanceId = $existingQuittance['id'];
            error_log("Quittance existante trouvée: ID $quittanceId");
        } else {
            // Insérer la nouvelle quittance dans la base de données
            $montantLoyer = (float)$contrat['loyer'];
            $montantCharges = (float)$contrat['charges'];
            $montantTotal = $montantLoyer + $montantCharges;
            
            $stmt = $pdo->prepare("
                INSERT INTO quittances (
                    contrat_id, 
                    reference_unique, 
                    mois, 
                    annee, 
                    montant_loyer, 
                    montant_charges, 
                    montant_total,
                    date_debut_periode,
                    date_fin_periode,
                    genere_par
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $adminId = $_SESSION['admin_id'] ?? null;
            $stmt->execute([
                $contratId,
                $referenceQuittance,
                $mois,
                $annee,
                $montantLoyer,
                $montantCharges,
                $montantTotal,
                $dateDebut->format('Y-m-d'),
                $dateFin->format('Y-m-d'),
                $adminId
            ]);
            
            $quittanceId = $pdo->lastInsertId();
            error_log("Nouvelle quittance créée: ID $quittanceId");
        }

        // Récupérer le template HTML
        $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'quittance_template_html'");
        $stmt->execute();
        $templateHtml = $stmt->fetchColumn();

        if (empty($templateHtml)) {
            $templateHtml = getDefaultQuittanceTemplate();
        }

        // Remplacer les variables dans le template
        $html = replaceQuittanceTemplateVariables($templateHtml, $contrat, $locataires, $mois, $annee, $referenceQuittance, $dateDebut, $dateFin);

        // Générer le PDF avec TCPDF
        $pdf = new MIIPdf('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('MY INVEST IMMOBILIER');
        $pdf->SetTitle('Quittance de Loyer - ' . $referenceQuittance);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        // Sauvegarder le PDF
        $filename = 'quittance-' . $referenceQuittance . '.pdf';
        $pdfDir = dirname(__DIR__) . '/pdf/quittances/';
        if (!is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }
        $filepath = $pdfDir . $filename;
        $pdf->Output($filepath, 'F');
        
        // Mettre à jour le chemin du fichier dans la base de données
        $stmt = $pdo->prepare("UPDATE quittances SET fichier_pdf = ? WHERE id = ?");
        $stmt->execute([$filepath, $quittanceId]);

        error_log("PDF de quittance généré avec succès: $filepath");
        
        return [
            'filepath' => $filepath,
            'reference' => $referenceQuittance,
            'quittance_id' => $quittanceId
        ];

    } catch (Exception $e) {
        error_log("Erreur génération PDF quittance: " . $e->getMessage());
        return false;
    }
}

/**
 * Remplacer les variables dans le template de quittance
 */
function replaceQuittanceTemplateVariables($template, $contrat, $locataires, $mois, $annee, $reference, $dateDebut, $dateFin) {
    global $config, $pdo;

    // Nom des mois en français
    $nomsMois = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
    ];
    
    $periode = $nomsMois[$mois] . ' ' . $annee;
    
    // Construire la liste des locataires
    $locatairesNoms = [];
    foreach ($locataires as $loc) {
        $locatairesNoms[] = htmlspecialchars($loc['prenom']) . ' ' . htmlspecialchars($loc['nom']);
    }
    $locatairesText = implode(' et ', $locatairesNoms);
    
    // Premier locataire pour l'email
    $premierLocataire = $locataires[0];

    $montantLoyer = number_format((float)$contrat['loyer'], 2, ',', ' ');
    $montantCharges = number_format((float)$contrat['charges'], 2, ',', ' ');
    $montantTotal = number_format((float)$contrat['loyer'] + (float)$contrat['charges'], 2, ',', ' ');

    $dateGeneration = date('d/m/Y');
    $dateDebutStr = $dateDebut->format('d/m/Y');
    $dateFinStr = $dateFin->format('d/m/Y');

    // Récupérer les informations de la société
    $nomSociete = $config['COMPANY_NAME'] ?? 'MY INVEST IMMOBILIER';
    $adresseSociete = $config['COMPANY_ADDRESS'] ?? '';
    $telSociete = $config['COMPANY_PHONE'] ?? '';
    $emailSociete = $config['COMPANY_EMAIL'] ?? '';
    /*
    // Get signature if exists
    $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'signature_societe_image'");
    $stmt->execute();
    $signatureData = $stmt->fetchColumn();
    
    $signatureSociete = '';
    if ($signatureData) {
        $signatureSociete = $signatureData;
    }*/

    
        // Get signature if exists and build proper URL for TCPDF
        $signatureHtml = '';
        $signatureUrl = getCompanySignatureUrl($config, 'signature_societe_image', '');
        if (!empty($signatureUrl)) {
            $signatureHtml = '<div><strong>Signature du bailleur :</strong><br>';
            $signatureHtml .= '<img src="' . htmlspecialchars($signatureUrl) . '" alt="Signature" style="width: 80px; height: auto;">';
            $signatureHtml .= '</div>';
        }

    $vars = [
        '{{reference_quittance}}' => htmlspecialchars($reference),
        '{{locataires_noms}}' => $locatairesText,
        '{{locataire_nom}}' => htmlspecialchars($premierLocataire['nom']),
        '{{locataire_prenom}}' => htmlspecialchars($premierLocataire['prenom']),
        '{{adresse}}' => htmlspecialchars($contrat['adresse'] ?? ''),
        '{{logement_reference}}' => htmlspecialchars($contrat['reference'] ?? ''),
        '{{periode}}' => $periode,
        '{{mois}}' => $nomsMois[$mois],
        '{{annee}}' => $annee,
        '{{montant_loyer}}' => $montantLoyer,
        '{{montant_charges}}' => $montantCharges,
        '{{montant_total}}' => $montantTotal,
        '{{date_generation}}' => $dateGeneration,
        '{{date_debut_periode}}' => $dateDebutStr,
        '{{date_fin_periode}}' => $dateFinStr,
        '{{nom_societe}}' => htmlspecialchars($nomSociete),
        '{{adresse_societe}}' => htmlspecialchars($adresseSociete),
        '{{tel_societe}}' => htmlspecialchars($telSociete),
        '{{email_societe}}' => htmlspecialchars($emailSociete),
        '{{signature_societe}}' => $signatureHtml,
    ];

    $html = str_replace(array_keys($vars), array_values($vars), $template);
    
    // Convert relative paths to absolute for TCPDF
    $html = convertRelativeImagePathsToAbsolute($html, $config);
    
    return $html;
}

/**
 * Obtenir le template par défaut de quittance
 */
function getDefaultQuittanceTemplate() {
    return '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Quittance de Loyer</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #000;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 15px;
        }
        .header h1 {
            font-size: 20pt;
            color: #667eea;
            margin: 10px 0;
        }
        .info-section {
            margin: 20px 0;
        }
        .info-row {
            margin: 8px 0;
        }
        .info-label {
            font-weight: bold;
            display: inline-block;
            width: 150px;
        }
        .montants-table {
            width: 100%;
            border-collapse: collapse;
            margin: 30px 0;
        }
        .montants-table th,
        .montants-table td {
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }
        .montants-table th {
            background-color: #667eea;
            color: white;
        }
        .montants-table .total-row {
            background-color: #f0f0f0;
            font-weight: bold;
            font-size: 13pt;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        .signature-section {
            margin-top: 50px;
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>QUITTANCE DE LOYER</h1>
        <p><strong>{{nom_societe}}</strong></p>
        <p>{{adresse_societe}}</p>
        <p>Tél: {{tel_societe}} | Email: {{email_societe}}</p>
    </div>

    <div class="info-section">
        <p><strong>Référence:</strong> {{reference_quittance}}</p>
        <p><strong>Date d\'émission:</strong> {{date_generation}}</p>
    </div>

    <div class="info-section">
        <h3>Locataire(s)</h3>
        <p>{{locataires_noms}}</p>
        <p>{{adresse}}</p>
    </div>

    <div class="info-section">
        <h3>Période concernée</h3>
        <p><strong>{{periode}}</strong> (du {{date_debut_periode}} au {{date_fin_periode}})</p>
    </div>

    <table class="montants-table">
        <thead>
            <tr>
                <th>Description</th>
                <th style="text-align: right;">Montant</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Loyer mensuel</td>
                <td style="text-align: right;">{{montant_loyer}} €</td>
            </tr>
            <tr>
                <td>Provisions sur charges</td>
                <td style="text-align: right;">{{montant_charges}} €</td>
            </tr>
            <tr class="total-row">
                <td>TOTAL PAYÉ</td>
                <td style="text-align: right;">{{montant_total}} €</td>
            </tr>
        </tbody>
    </table>

    <div class="info-section">
        <p style="font-style: italic;">
            Je soussigné(e) {{nom_societe}}, propriétaire du logement situé au {{adresse}}, 
            certifie avoir reçu de {{locataires_noms}} la somme de {{montant_total}} € 
            au titre du loyer et des charges pour la période du {{date_debut_periode}} au {{date_fin_periode}}.
        </p>
        <p style="font-style: italic;">
            Cette quittance annule tous les reçus qui auraient pu être établis précédemment en cas d\'acomptes versés 
            sur la période. Elle est remise en un seul exemplaire à l\'intéressé pour servir et valoir ce que de droit.
        </p>
    </div>

    <div class="signature-section">
        <p>Fait à {{adresse_societe}}, le {{date_generation}}</p>
        <p style="margin-top: 30px;">
            <strong>{{nom_societe}}</strong><br>
            Le Bailleur
        </p>
        <div style="margin-top: 20px;">
            <img src="{{signature_societe}}" style="width: 150px; height: auto;" alt="Signature" />
        </div>
    </div>
</body>
</html>';
}

/**
 * Convert relative image paths to absolute URLs for TCPDF
 */
function convertRelativeImagePathsToAbsolute($html, $config) {
    $baseUrl = rtrim($config['SITE_URL'], '/');
    
    $html = preg_replace_callback(
        '/<img([^>]*?)src=["\']([^"\']+)["\']([^>]*?)>/i',
        function($matches) use ($baseUrl) {
            $beforeSrc = $matches[1];
            $src = $matches[2];
            $afterSrc = $matches[3];
            
            if (strpos($src, 'data:') === 0 || preg_match('#^https?://#i', $src)) {
                return $matches[0];
            }
            
            $absoluteSrc = $src;
            if (strpos($src, '../') === 0) {
                $relativePath = preg_replace('#^(\.\./)+#', '', $src);
                $absoluteSrc = $baseUrl . '/' . $relativePath;
            } elseif (strpos($src, './') === 0) {
                $relativePath = substr($src, 2);
                $absoluteSrc = $baseUrl . '/' . $relativePath;
            } elseif (strpos($src, '/') === 0) {
                $absoluteSrc = $baseUrl . $src;
            } else {
                $absoluteSrc = $baseUrl . '/' . $src;
            }
            
            return '<img' . $beforeSrc . 'src="' . $absoluteSrc . '"' . $afterSrc . '>';
        },
        $html
    );
    
    return $html;
}
