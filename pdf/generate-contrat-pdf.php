<?php
/**
 * Génération du PDF du contrat de bail
 * Version finale : Template HTML + Variables + Signatures + PDF
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/contract-templates.php';

// Style CSS pour les images de signature (sans bordures)
define('SIGNATURE_IMG_STYLE', 'width: 25mm; height: auto; display: block; margin-bottom: 15mm; border: none; outline: none; box-shadow: none; background: transparent;');

/**
 * Générer le PDF du contrat de bail
 */
function generateContratPDF($contratId) {
    global $config, $pdo;

    $contratId = (int)$contratId;
    if ($contratId <= 0) {
        error_log("Erreur: ID de contrat invalide");
        return false;
    }

    try {
        // Récupérer les données du contrat
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   l.reference,
                   l.adresse,
                   l.type,
                   l.surface,
                   l.loyer,
                   l.charges,
                   l.depot_garantie,
                   l.parking,
                   l.type_contrat as logement_type_contrat
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
            error_log("Erreur: Aucun locataire trouvé");
            return false;
        }

        // Récupérer la template HTML selon le type de contrat
        // Priorité : type du contrat, puis type du logement, puis 'meuble' par défaut
        $validTypes = ['meuble', 'non_meuble', 'sur_mesure'];
        $typeContrat = $contrat['type_contrat'] ?? '';
        if (!in_array($typeContrat, $validTypes)) {
            $typeContrat = $contrat['logement_type_contrat'] ?? '';
        }
        if (!in_array($typeContrat, $validTypes)) {
            $typeContrat = 'meuble';
        }
        $cleTemplate = 'contrat_template_html_' . $typeContrat;
        $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = ?");
        $stmt->execute([$cleTemplate]);
        $templateHtml = $stmt->fetchColumn();

        // Fallback: legacy key, then type-specific hardcoded default
        if (empty($templateHtml)) {
            $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'contrat_template_html'");
            $stmt->execute();
            $templateHtml = $stmt->fetchColumn();
        }

        if (empty($templateHtml)) {
            $templateHtml = getDefaultContractTemplateByType($typeContrat);
        }

        // Remplacer les variables
        $html = replaceContratTemplateVariables($templateHtml, $contrat, $locataires);

        // Injecter les signatures
        $html = injectSignatures($html, $contrat, $locataires);

        // Générer le PDF
        $typeContratLabel = getTypeContratLabel($typeContrat);
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('MY INVEST IMMOBILIER');
        $pdf->SetTitle('Contrat de Bail (' . $typeContratLabel . ') - ' . $contrat['reference_unique']);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        // Sauvegarder le PDF
        $filename = 'bail-' . $contrat['reference_unique'] . '.pdf';
        $pdfDir = dirname(__DIR__) . '/pdf/contrats/';
        if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);
        $filepath = $pdfDir . $filename;
        $pdf->Output($filepath, 'F');

        return $filepath;

    } catch (Exception $e) {
        error_log("Erreur génération PDF: " . $e->getMessage());
        return false;
    }
}

/**
 * Convert relative image paths to absolute URLs for TCPDF
 * TCPDF requires absolute URLs or file paths to display images correctly
 */
function convertRelativeImagePathsToAbsolute($html, $config) {
    $baseUrl = rtrim($config['SITE_URL'], '/');
    
    // Process all img tags
    $html = preg_replace_callback(
        '/<img([^>]*?)src=["\']([^"\']+)["\']([^>]*?)>/i',
        function($matches) use ($baseUrl) {
            $beforeSrc = $matches[1];
            $src = $matches[2];
            $afterSrc = $matches[3];
            
            // Skip data URIs (base64 encoded images)
            if (strpos($src, 'data:') === 0) {
                return $matches[0];
            }
            
            // Skip already absolute URLs (http:// or https://)
            if (preg_match('#^https?://#i', $src)) {
                return $matches[0];
            }
            
            // Convert relative paths to absolute URLs
            $absoluteSrc = $src;
            
            // Handle paths starting with ../
            // Note: We strip all ../ because we're converting to web URLs from the site root
            // The template is stored in database and paths should be relative to web root
            if (strpos($src, '../') === 0) {
                // Remove leading ../
                $relativePath = preg_replace('#^(\.\./)+#', '', $src);
                $absoluteSrc = $baseUrl . '/' . $relativePath;
            }
            // Handle paths starting with ./
            elseif (strpos($src, './') === 0) {
                $relativePath = substr($src, 2);
                $absoluteSrc = $baseUrl . '/' . $relativePath;
            }
            // Handle paths starting with /
            elseif (strpos($src, '/') === 0) {
                $absoluteSrc = $baseUrl . $src;
            }
            // Handle simple relative paths (no leading slash)
            else {
                $absoluteSrc = $baseUrl . '/' . $src;
            }
            
            // Return the img tag with the absolute URL
            return '<img' . $beforeSrc . 'src="' . $absoluteSrc . '"' . $afterSrc . '>';
        },
        $html
    );
    
    return $html;
}

/**
 * Remplacer les variables dans la template
 */
function replaceContratTemplateVariables($template, $contrat, $locataires) {
    global $config;

    $locatairesInfo = [];
    foreach ($locataires as $loc) {
        $dateNaissance = 'N/A';
        if (!empty($loc['date_naissance'])) {
            $ts = strtotime($loc['date_naissance']);
            if ($ts !== false) $dateNaissance = date('d/m/Y', $ts);
        }
        $locatairesInfo[] = [
            'nom_complet' => htmlspecialchars($loc['prenom']) . ' ' . htmlspecialchars($loc['nom']),
            'date_naissance' => $dateNaissance,
            'email' => htmlspecialchars($loc['email'])
        ];
    }
    
    // Create a simple table for locataires_info without borders and colors
    // Optimized font size for readability while maintaining compact layout
    $locatairesInfoHtml = '<table style="width: 100%; border-collapse: collapse;">';
    $locatairesInfoHtml .= '<tr>';
    $locatairesInfoHtml .= '<th style="padding: 6px; text-align: left; width: 50%; font-size: 10pt;">Locataire 1:</th>';
    if (count($locatairesInfo) > 1) {
        $locatairesInfoHtml .= '<th style="padding: 6px; text-align: left; width: 50%; font-size: 10pt;">Locataire 2:</th>';
    }
    $locatairesInfoHtml .= '</tr>';
    $locatairesInfoHtml .= '<tr>';
    $locatairesInfoHtml .= '<td style="padding: 6px; vertical-align: top; font-size: 10pt;">';
    $locatairesInfoHtml .= '<strong>' . $locatairesInfo[0]['nom_complet'] . '</strong><br>';
    $locatairesInfoHtml .= 'Né(e) le ' . $locatairesInfo[0]['date_naissance'] . '<br>';
    $locatairesInfoHtml .= 'Email : ' . $locatairesInfo[0]['email'];
    $locatairesInfoHtml .= '</td>';
    if (count($locatairesInfo) > 1) {
        $locatairesInfoHtml .= '<td style="padding: 6px; vertical-align: top; font-size: 10pt;">';
        $locatairesInfoHtml .= '<strong>' . $locatairesInfo[1]['nom_complet'] . '</strong><br>';
        $locatairesInfoHtml .= 'Né(e) le ' . $locatairesInfo[1]['date_naissance'] . '<br>';
        $locatairesInfoHtml .= 'Email : ' . $locatairesInfo[1]['email'];
        $locatairesInfoHtml .= '</td>';
    }
    $locatairesInfoHtml .= '</tr>';
    $locatairesInfoHtml .= '</table>';
    
    // If there are more than 2 tenants, append additional tenants below the table
    if (count($locatairesInfo) > 2) {
        $locatairesInfoHtml .= '<div style="margin-top: 10px;">';
        for ($i = 2; $i < count($locatairesInfo); $i++) {
            $locatairesInfoHtml .= '<p style="margin: 5px 0;">';
            $locatairesInfoHtml .= '<strong>Locataire ' . ($i + 1) . ':</strong> ';
            $locatairesInfoHtml .= $locatairesInfo[$i]['nom_complet'] . ', ';
            $locatairesInfoHtml .= 'né(e) le ' . $locatairesInfo[$i]['date_naissance'] . ', ';
            $locatairesInfoHtml .= 'Email : ' . $locatairesInfo[$i]['email'];
            $locatairesInfoHtml .= '</p>';
        }
        $locatairesInfoHtml .= '</div>';
    }

    $datePriseEffet = !empty($contrat['date_prise_effet']) ? date('d/m/Y', strtotime($contrat['date_prise_effet'])) : 'N/A';
    $dateSignature = !empty($contrat['date_signature']) ? date('d/m/Y', strtotime($contrat['date_signature'])) : date('d/m/Y');

    $loyer = number_format((float)($contrat['loyer'] ?? 0), 2, ',', ' ');
    $charges = number_format((float)($contrat['charges'] ?? 0), 2, ',', ' ');
    $loyerTotal = number_format((float)($contrat['loyer'] ?? 0) + (float)($contrat['charges'] ?? 0), 2, ',', ' ');
    $depotGarantie = number_format((float)($contrat['depot_garantie'] ?? 0), 2, ',', ' ');

    $iban = $config['IBAN'] ?? '[IBAN non configuré]';
    $bic = $config['BIC'] ?? '[BIC non configuré]';

    $vars = [
        '{{reference_unique}}' => htmlspecialchars($contrat['reference_unique'] ?? ''),
        '{{locataires_info}}' => $locatairesInfoHtml,
        '{{adresse}}' => htmlspecialchars($contrat['adresse'] ?? ''),
        '{{type}}' => htmlspecialchars($contrat['type'] ?? ''),
        '{{surface}}' => htmlspecialchars($contrat['surface'] ?? ''),
        '{{parking}}' => htmlspecialchars($contrat['parking'] ?? ''),
        '{{date_prise_effet}}' => $datePriseEffet,
        '{{date_signature}}' => $dateSignature,
        '{{loyer}}' => $loyer,
        '{{charges}}' => $charges,
        '{{loyer_total}}' => $loyerTotal,
        '{{depot_garantie}}' => $depotGarantie,
        '{{iban}}' => htmlspecialchars($iban),
        '{{bic}}' => htmlspecialchars($bic),
        '{{type_contrat_label}}' => htmlspecialchars(getTypeContratLabel($contrat['type_contrat'] ?? $contrat['logement_type_contrat'] ?? 'meuble')),
    ];

    $html = str_replace(array_keys($vars), array_values($vars), $template);
    
    // Convert relative image paths to absolute URLs for TCPDF
    $html = convertRelativeImagePathsToAbsolute($html, $config);
    
    return $html;
}

/**
 * Injecter les signatures
 */
function injectSignatures($html, $contrat, $locataires) {
    $signaturesTable = buildSignaturesTable($contrat, $locataires);
    return str_replace('{{signatures_table}}', $signaturesTable, $html);
}

/**
 * Construire le tableau de signatures
 */
function buildSignaturesTable($contrat, $locataires) {
    global $pdo, $config;

    $nbCols = count($locataires) + 1; // +1 pour le bailleur
    $colWidth = round(100 / $nbCols, 2); // Use rounded percentage for consistent widths

    // TCPDF-optimized table structure with proper cell styling
    // Use border="1" and cellspacing="0" for consistent cell rendering
    // Explicit width and height for consistent layout
    // Reduced padding for more compact signature table
    $html = '<table cellspacing="0" cellpadding="10" border="1" style="width: 100%; border-collapse: collapse; margin-top: 20px; background: transparent;">';
    $html .= '<tbody><tr>';

    // Bailleur column
    $html .= '<td style="width: ' . $colWidth . '%; vertical-align: top; text-align: center; padding: 10px; border: 1px solid #333; background: transparent; font-size: 10pt;">';
    $html .= '<p style="margin: 0 0 8px 0; font-weight: bold;">Le bailleur :</p>';
    
    if ($contrat['statut'] === 'valide') {
        // Check if signature feature is enabled using getParameter
        $signatureEnabled = getParameter('signature_societe_enabled', false);
        $isSignatureEnabled = toBooleanParam($signatureEnabled);
        
        if ($isSignatureEnabled) {
            $signatureUrl = getCompanySignatureUrl($config, 'signature_societe_image', '');

            if (!empty($signatureUrl)) {
                // Consistent image sizing - remove borders and backgrounds
                $html .= '<div style="margin: 10px 0; min-height: 60px;">';
                $html .= '<img src="' . htmlspecialchars($signatureUrl) . '" alt="Signature Société" style="width: 120px; height: auto; border: none; background: transparent;">';
                $html .= '</div>';
            }
        }

        if (!empty($contrat['date_validation'])) {
            $ts = strtotime($contrat['date_validation']);
            if ($ts !== false) {
                $html .= '<p style="margin: 10px 0 5px 0; font-size: 8pt; color: #666;">Validé le : ' . date('d/m/Y H:i:s', $ts) . '</p>';
            }
        }
        $html .= '<p style="margin: 5px 0 0 0; font-size: 8pt; color: #666;">MY INVEST IMMOBILIER</p>';
    }
    $html .= '</td>';

    // Locataires columns
    foreach ($locataires as $i => $loc) {
        $html .= '<td style="width: ' . $colWidth . '%; vertical-align: top; text-align: center; padding: 10px; border: 1px solid #333; background: transparent; font-size: 10pt;">';

        // Tenant label
        if ($nbCols === 2) {
            $html .= '<p style="margin: 0 0 4px 0; font-weight: bold;">Locataire :</p>';
        } else {
            $html .= '<p style="margin: 0 0 4px 0; font-weight: bold;">Locataire ' . ($i + 1) . ' :</p>';
        }

        // Tenant name
        $html .= '<p style="margin: 0 0 8px 0;">' . htmlspecialchars($loc['prenom']) . ' ' . htmlspecialchars($loc['nom']) . '</p>';

        // Signature image - consistent sizing and no backgrounds
        if (!empty($loc['signature_data']) && preg_match('/^uploads\/signatures\//', $loc['signature_data'])) {
            $publicUrl = rtrim($config['SITE_URL'], '/') . '/' . ltrim($loc['signature_data'], '/');
            $html .= '<div style="margin: 10px 0; min-height: 60px;">';
            $html .= '<img src="' . htmlspecialchars($publicUrl) . '" alt="Signature Locataire" style="width: 150px; height: auto; border: none; background: transparent;">';
            $html .= '</div>';
        } else {
            // Placeholder for unsigned tenant to maintain consistent cell height
            $html .= '<div style="margin: 10px 0; min-height: 60px;">&nbsp;</div>';
        }
        
        // "Certifié exact" indicator - using standard text for PDF compatibility
        $html .= '<p style="margin: 10px 0 5px 0; font-size: 9pt;">Certifié exact</p>';

        // Signature metadata
        if (!empty($loc['signature_timestamp']) || !empty($loc['signature_ip'])) {
            $html .= '<div style="margin: 10px 0 0 0; font-size: 8pt; color: #666;">';
            if (!empty($loc['signature_timestamp'])) {
                $ts = strtotime($loc['signature_timestamp']);
                if ($ts !== false) {
                    $html .= '<p style="margin: 2px 0;">Signé le ' . date('d/m/Y à H:i', $ts) . '</p>';
                }
            }
            if (!empty($loc['signature_ip'])) {
                $html .= '<p style="margin: 2px 0;">IP : ' . htmlspecialchars($loc['signature_ip']) . '</p>';
            }
            $html .= '</div>';
        }

        $html .= '</td>';
    }

    $html .= '</tr></tbody></table>';
    return $html;
}


