<?php
/**
 * Génération du PDF pour Bilan du Logement
 * My Invest Immobilier
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

/**
 * Convert relative image paths to absolute URLs for TCPDF
 */
function convertBilanImagePathsToAbsolute($html, $config) {
    $baseUrl = rtrim($config['SITE_URL'], '/');
    
    $html = preg_replace_callback(
        '/<img([^>]*?)src=["\']([^"\']+)["\']([^>]*?)>/i',
        function($matches) use ($baseUrl) {
            $beforeSrc = $matches[1];
            $src = $matches[2];
            $afterSrc = $matches[3];
            
            // Skip data URIs
            if (strpos($src, 'data:') === 0) {
                return $matches[0];
            }
            
            // Skip already absolute URLs
            if (preg_match('#^https?://#i', $src)) {
                return $matches[0];
            }
            
            // Convert relative paths to absolute URLs
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

/**
 * Générer le PDF du bilan du logement
 * 
 * @param int $contratId ID du contrat
 * @return string|false Chemin du fichier PDF généré, ou false en cas d'erreur
 */
function generateBilanLogementPDF($contratId) {
    global $config, $pdo;

    error_log("=== generateBilanLogementPDF - START ===");
    error_log("Input - Contrat ID: $contratId");

    // Validation
    $contratId = (int)$contratId;
    if ($contratId <= 0) {
        error_log("Invalid contract ID: $contratId");
        return false;
    }

    try {
        // Get contract and logement details (including depot_garantie from logements table)
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   l.adresse as logement_adresse,
                   l.depot_garantie as depot_garantie,
                   c.reference_unique as contrat_ref
            FROM contrats c
            LEFT JOIN logements l ON c.logement_id = l.id
            WHERE c.id = ?
        ");
        $stmt->execute([$contratId]);
        $contrat = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contrat) {
            error_log("Contract not found: $contratId");
            return false;
        }
        
        // Extract depot_garantie for later use
        $depotGarantie = floatval($contrat['depot_garantie'] ?? 0);

        // Get locataires
        $stmt = $pdo->prepare("
            SELECT nom, prenom, email 
            FROM locataires 
            WHERE contrat_id = ? 
            ORDER BY ordre
        ");
        $stmt->execute([$contratId]);
        $locataires = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $locataireNom = '';
        if (!empty($locataires)) {
            $locataireNom = $locataires[0]['prenom'] . ' ' . $locataires[0]['nom'];
            if (count($locataires) > 1) {
                $locataireNom .= ' et ' . $locataires[1]['prenom'] . ' ' . $locataires[1]['nom'];
            }
        }

        // Get état des lieux de sortie with bilan data
        $stmt = $pdo->prepare("
            SELECT * FROM etats_lieux 
            WHERE contrat_id = ? AND type = 'sortie'
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$contratId]);
        $etatLieux = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$etatLieux || empty($etatLieux['bilan_logement_data'])) {
            error_log("No bilan data found for contract: $contratId");
            return false;
        }

        // Decode bilan data
        $bilanRows = json_decode($etatLieux['bilan_logement_data'], true) ?: [];
        
        // Get HTML template from parametres
        $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'bilan_logement_template_html'");
        $stmt->execute();
        $templateHtml = $stmt->fetchColumn();

        if (!$templateHtml) {
            error_log("Bilan logement template not found in parametres");
            return false;
        }

        // Get logo if exists
        $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'logo_societe'");
        $stmt->execute();
        $logoData = $stmt->fetchColumn();
        
        $logoHtml = '';
        if ($logoData) {
            if (strpos($logoData, 'data:') === 0 || strpos($logoData, 'uploads/') !== false) {
                $logoHtml = '<img src="' . htmlspecialchars($logoData) . '" alt="Logo" style="max-width: 150px; max-height: 80px;">';
            }
        }

        // Get signature if exists and build proper URL for TCPDF
        $signatureHtml = '';
        $signatureUrl = getCompanySignatureUrl($config, 'signature_societe_image', '');
        if (!empty($signatureUrl)) {
            $signatureHtml = '<div><strong>Signature du bailleur :</strong><br>';
            $signatureHtml .= '<img src="' . htmlspecialchars($signatureUrl) . '" alt="Signature" style="width: 80px; height: auto;">';
            $signatureHtml .= '</div>';
        }

        // Build bilan rows HTML with complete table structure (without thead/tbody tags)
        // Note: This variable includes the full <table> tag with all rows, headers, and totals
        // It will replace {{bilan_rows}} in the template as a complete table element
        $bilanRowsHtml = '<table border="1" cellspacing="0" cellpadding="3">';
        
        // Add header row
        $bilanRowsHtml .= '<tr style="color:#ffffff;background-color:#3498db;">';
        $bilanRowsHtml .= '<th style="width: 25%;text-align:center; vertical-align:middle;font-size:8.5pt;font-weight:bold;">Poste</th>';
        $bilanRowsHtml .= '<th style="width: 30%;text-align:center; vertical-align:middle;font-size:8.5pt;font-weight:bold;">Commentaires</th>';
        $bilanRowsHtml .= '<th style="width: 15%;text-align:center; vertical-align:middle;font-size:8.5pt;font-weight:bold;">Valeur (€)</th>';
        $bilanRowsHtml .= '<th style="width: 15%;text-align:center; vertical-align:middle;font-size:8.5pt;font-weight:bold;">Solde Débiteur (€)</th>';
        $bilanRowsHtml .= '<th style="width: 15%;text-align:center; vertical-align:middle;font-size:8.5pt;font-weight:bold;">Solde Créditeur (€)</th>';
        $bilanRowsHtml .= '</tr>';
        
        $totalValeur = 0;
        $totalSoldeDebiteur = 0;
        $totalSoldeCrediteur = 0;
        
        foreach ($bilanRows as $row) {
            $poste = htmlspecialchars($row['poste'] ?? '');
            $commentaires = htmlspecialchars($row['commentaires'] ?? '');
            $valeur = htmlspecialchars($row['valeur'] ?? '');
            
            // Handle backward compatibility: montant_du -> solde_debiteur
            $soldeDebiteur = $row['solde_debiteur'] ?? ($row['montant_du'] ?? '');
            $soldeCrediteur = $row['solde_crediteur'] ?? '';
            
            // Parse values to add to totals
            if (!empty($valeur) && is_numeric($valeur)) {
                $totalValeur += floatval($valeur);
            }
            if (!empty($soldeDebiteur) && is_numeric($soldeDebiteur)) {
                $totalSoldeDebiteur += floatval($soldeDebiteur);
            }
            if (!empty($soldeCrediteur) && is_numeric($soldeCrediteur)) {
                $totalSoldeCrediteur += floatval($soldeCrediteur);
            }
            
            // Format amounts for display
            $valeurDisplay = !empty($valeur) && is_numeric($valeur) ? number_format(floatval($valeur), 2, ',', '') : htmlspecialchars($valeur);
            $soldeDebiteurDisplay = !empty($soldeDebiteur) && is_numeric($soldeDebiteur) ? number_format(floatval($soldeDebiteur), 2, ',', '')  : htmlspecialchars($soldeDebiteur);
            $soldeCrediteurDisplay = !empty($soldeCrediteur) && is_numeric($soldeCrediteur) ? number_format(floatval($soldeCrediteur), 2, ',', '')  : htmlspecialchars($soldeCrediteur);
            
            $bilanRowsHtml .= '<tr>';
            $bilanRowsHtml .= '<td>' . $poste . '</td>';
            $bilanRowsHtml .= '<td>' . nl2br($commentaires) . '</td>';
            $bilanRowsHtml .= '<td style="text-align:center; vertical-align:middle;">' . $valeurDisplay . '</td>';
            $bilanRowsHtml .= '<td style="text-align:center; vertical-align:middle;">' . $soldeDebiteurDisplay . '</td>';
            $bilanRowsHtml .= '<td style="text-align:center; vertical-align:middle;">' . $soldeCrediteurDisplay . '</td>';
            $bilanRowsHtml .= '</tr>';
        }
        
        // Add totals row
        $bilanRowsHtml .= '<tr style="font-weight: bold; background-color: #f0f0f0;">';
        $bilanRowsHtml .= '<td colspan="2" style="text-align: right;">TOTAL</td>';
        $bilanRowsHtml .= '<td style="text-align:center; vertical-align:middle;">' . number_format($totalValeur, 2, ',', ' ') . ' €</td>';
        $bilanRowsHtml .= '<td style="text-align:center; vertical-align:middle;">' . number_format($totalSoldeDebiteur, 2, ',', ' ') . ' €</td>';
        $bilanRowsHtml .= '<td style="text-align:center; vertical-align:middle;">' . number_format($totalSoldeCrediteur, 2, ',', ' ') . ' €</td>';
        $bilanRowsHtml .= '</tr>';
        
        $bilanRowsHtml .= '</table>';

        // Build commentaire section
        $commentaireHtml = '';
        if (!empty($etatLieux['bilan_logement_commentaire'])) {
            $commentaire = htmlspecialchars($etatLieux['bilan_logement_commentaire']);
            $commentaireHtml = '<div><u><em><strong><span style="font-size: 12pt;">Observations générales</span></strong></em></u><br><br><span style="font-size: 10pt;">' . nl2br($commentaire) . '</span></div>';
        }

        // Calculate financial summary values
        // Valeur estimative = total valeur from bilan rows
        $valeurEstimative = $totalValeur;
        
        // Calculate: Montant à restituer = Dépôt de garantie + Solde Créditeur - Solde Débiteur (if > 0, else 0)
        $calculResultat = $depotGarantie + $totalSoldeCrediteur - $totalSoldeDebiteur;
        $montantARestituer = $calculResultat > 0 ? $calculResultat : 0;
        
        // Calculate: Reste dû = abs(Dépôt de garantie + Solde Créditeur - Solde Débiteur) (if < 0, else 0)
        $resteDu = $calculResultat < 0 ? abs($calculResultat) : 0;

        // Generate phrase_recap_financier based on conditions
        $phraseRecapFinancier = '';
        if ($resteDu > 0) {
            $phraseRecapFinancier = 'Vous devez nous régler la somme de ' . number_format($resteDu, 2, ',', ' ') . ' €.';
        } elseif ($montantARestituer > 0) {
            $phraseRecapFinancier = 'Vous recevrez prochainement la somme de ' . number_format($montantARestituer, 2, ',', ' ') . ' €.';
        }

        // Replace variables in template
        $variables = [
            '{{logo}}' => $logoHtml,
            '{{locataire_nom}}' => htmlspecialchars($locataireNom),
            '{{contrat_ref}}' => htmlspecialchars($contrat['contrat_ref']),
            '{{adresse}}' => htmlspecialchars($contrat['logement_adresse']),
            '{{date}}' => date('d/m/Y'),
            '{{bilan_rows}}' => $bilanRowsHtml,
            '{{commentaire_section}}' => $commentaireHtml,
            '{{total_valeur}}' => number_format($totalValeur, 2, ',', ' ') . ' €',
            '{{total_solde_debiteur}}' => number_format($totalSoldeDebiteur, 2, ',', ' ') . ' €',
            '{{total_solde_crediteur}}' => number_format($totalSoldeCrediteur, 2, ',', ' ') . ' €',
            '{{total_montant}}' => number_format($totalSoldeDebiteur, 2, ',', ' ') . ' €', // Backward compatibility
            '{{signature_agence}}' => $signatureHtml,
            // New financial summary variables
            '{{depot_garantie}}' => number_format($depotGarantie, 2, ',', ' ') . ' €',
            '{{valeur_estimative}}' => number_format($valeurEstimative, 2, ',', ' ') . ' €',
            '{{montant_a_restituer}}' => number_format($montantARestituer, 2, ',', ' ') . ' €',
            '{{reste_du}}' => number_format($resteDu, 2, ',', ' ') . ' €',
            '{{phrase_recap_financier}}' => $phraseRecapFinancier
        ];

        $html = str_replace(array_keys($variables), array_values($variables), $templateHtml);

        // Convert relative paths to absolute URLs
        $html = convertBilanImagePathsToAbsolute($html, $config);

        // Generate PDF using TCPDF
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('My Invest Immobilier');
        $pdf->SetAuthor('My Invest Immobilier');
        $pdf->SetTitle('Bilan du Logement - ' . $contrat['contrat_ref']);
        $pdf->SetSubject('Bilan du Logement');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(4, 10, 4);
        $pdf->SetAutoPageBreak(true, 5);

        //Set Fonts
        $pdf->SetFont('', '', 10, '', 'false');
        
        // Add page
        $pdf->AddPage();

        // Write HTML content
        $pdf->writeHTML($html, true, false, true, false, '');

        // Generate filename
        $filename = 'bilan_logement_' . $contrat['contrat_ref'] . '_' . date('Ymd') . '.pdf';
        $pdfDir = dirname(__DIR__) . '/pdf/bilans/';
        if (!is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }
        $filepath = $pdfDir . $filename;

        // Save PDF to permanent storage
        $pdf->Output($filepath, 'F');

        error_log("PDF generated successfully: $filepath");
        return $filepath;

    } catch (Exception $e) {
        error_log("Error generating bilan PDF: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}
