<?php
/**
 * Génération du PDF pour État des lieux d'entrée/sortie
 * My Invest Immobilier
 * 
 * Génère un document PDF structuré pour l'état des lieux d'entrée ou de sortie
 * avec toutes les sections obligatoires et signatures.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail-templates.php';

// Import the default template function
require_once __DIR__ . '/../includes/etat-lieux-template.php';
require_once __DIR__ . '/pdf-pagination.php';

// Signature image display size constants (for PDF rendering)
define('ETAT_LIEUX_SIGNATURE_MAX_WIDTH', '20mm');
define('ETAT_LIEUX_SIGNATURE_MAX_HEIGHT', '10mm');

// Style CSS pour les images de signature (sans bordures)
// Simplified for TCPDF compatibility - removed unsupported properties
define('ETAT_LIEUX_SIGNATURE_IMG_STYLE', 'width: 100px; height: auto;');

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
 * Générer le PDF de l'état des lieux
 * 
 * @param int $contratId ID du contrat
 * @param string $type Type d'état des lieux: 'entree' ou 'sortie'
 * @return string|false Chemin du fichier PDF généré, ou false en cas d'erreur
 */
function generateEtatDesLieuxPDF($contratId, $type = 'entree') {
    global $config, $pdo;

    error_log("=== generateEtatDesLieuxPDF - START ===");
    error_log("Input - Contrat ID: $contratId, Type: $type");

    // Validation
    $contratId = (int)$contratId;
    if ($contratId <= 0) {
        error_log("ERROR: ID de contrat invalide: $contratId");
        return false;
    }

    if (!in_array($type, ['entree', 'sortie'])) {
        error_log("ERROR: Type invalide: $type (doit être 'entree' ou 'sortie')");
        return false;
    }

    try {
        // Récupérer les données du contrat
        error_log("Fetching contrat data from database...");
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   l.reference,
                   l.adresse,
                   
                   l.type as type_logement,
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
            error_log("ERROR: Contrat #$contratId non trouvé");
            return false;
        }
        
        error_log("Contrat found - Reference: " . ($contrat['reference'] ?? 'NULL'));
        error_log("Logement - Adresse: " . ($contrat['adresse'] ?? 'NULL'));

        // Récupérer les locataires
        error_log("Fetching locataires...");
        $stmt = $pdo->prepare("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC");
        $stmt->execute([$contratId]);
        $locataires = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($locataires)) {
            error_log("ERROR: Aucun locataire trouvé pour contrat #$contratId");
            return false;
        }
        
        error_log("Found " . count($locataires) . " locataire(s)");

        // Vérifier si un état des lieux existe déjà
        error_log("Checking for existing état des lieux...");
        $stmt = $pdo->prepare("SELECT * FROM etats_lieux WHERE contrat_id = ? AND type = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$contratId, $type]);
        $etatLieux = $stmt->fetch(PDO::FETCH_ASSOC);

        // Si pas d'état des lieux, créer un brouillon avec données par défaut
        if (!$etatLieux) {
            error_log("No existing état des lieux found, creating default...");
            $etatLieux = createDefaultEtatLieux($contratId, $type, $contrat, $locataires);
            if (!$etatLieux) {
                error_log("ERROR: Failed to create default état des lieux");
                return false;
            }
        } else {
            error_log("Existing état des lieux found - ID: " . $etatLieux['id']);
        }

        // Récupérer le template HTML depuis la base de données
        error_log("Fetching HTML template from database...");
        
        // Use different template for exit state if available
        if ($type === 'sortie') {
            $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'etat_lieux_sortie_template_html'");
            $stmt->execute();
            $templateHtml = $stmt->fetchColumn();
            
            // If no exit template, fall back to entry template
            if (empty($templateHtml)) {
                error_log("No exit template found, falling back to entry template");
                $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'etat_lieux_template_html'");
                $stmt->execute();
                $templateHtml = $stmt->fetchColumn();
            } else {
                error_log("Exit template loaded from database - Length: " . strlen($templateHtml) . " characters");
            }
        } else {
            $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'etat_lieux_template_html'");
            $stmt->execute();
            $templateHtml = $stmt->fetchColumn();
            
            if (!empty($templateHtml)) {
                error_log("Entry template loaded from database - Length: " . strlen($templateHtml) . " characters");
            }
        }
        
        // Si pas de template en base, utiliser le template par défaut
        if (empty($templateHtml)) {
            error_log("No template found in database, using default template");
            // Use sortie-specific template for exit inventory
            if ($type === 'sortie' && function_exists('getDefaultExitEtatLieuxTemplate')) {
                $templateHtml = getDefaultExitEtatLieuxTemplate();
                error_log("Using default exit template");
            } elseif (function_exists('getDefaultEtatLieuxTemplate')) {
                $templateHtml = getDefaultEtatLieuxTemplate();
                error_log("Using default entry template");
            } else {
                error_log("ERROR: Template functions not found");
                return false;
            }
        }
        
        // Générer le HTML en remplaçant les variables
        error_log("Replacing template variables...");
        $html = replaceEtatLieuxTemplateVariables($templateHtml, $contrat, $locataires, $etatLieux, $type);
        
        if (!$html) {
            error_log("ERROR: HTML generation failed");
            return false;
        }
        
        error_log("HTML generated - Length: " . strlen($html) . " characters");

        // Convert relative image paths to absolute URLs for TCPDF
        $html = convertRelativeImagePathsToAbsolute($html, $config);
        error_log("Image paths converted to absolute URLs");

        // Créer le PDF avec TCPDF
        error_log("Creating TCPDF instance...");
        $pdf = new MIIPdf('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('MY INVEST IMMOBILIER');
        
        $typeLabel = ($type === 'entree') ? 'Entrée' : 'Sortie';
        $pdf->SetTitle("État des lieux $typeLabel - " . $contrat['reference']);
        
        $pdf->SetMargins(15, 10, 15);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->AddPage();
        
        // Write HTML to PDF with error handling
        error_log("Writing HTML to PDF...");
        try {
            $pdf->writeHTML($html, true, false, true, false, '');
            error_log("HTML written to PDF successfully");
        } catch (Exception $htmlException) {
            error_log("TCPDF writeHTML ERROR: " . $htmlException->getMessage());
            error_log("HTML content length: " . strlen($html));
            error_log("Stack trace: " . $htmlException->getTraceAsString());
            throw new Exception("Erreur lors de la conversion HTML vers PDF: " . $htmlException->getMessage());
        }

        // Sauvegarder le PDF
        error_log("Saving PDF to file...");
        $pdfDir = dirname(__DIR__) . '/pdf/etat_des_lieux/';
        if (!is_dir($pdfDir)) {
            error_log("Creating directory: $pdfDir");
            mkdir($pdfDir, 0755, true);
        }

        $dateStr = date('Ymd');
        $filename = "etat_lieux_{$type}_{$contrat['reference']}_{$dateStr}.pdf";
        $filepath = $pdfDir . $filename;
        
        error_log("Saving to: $filepath");
        $pdf->Output($filepath, 'F');
        
        if (!file_exists($filepath)) {
            error_log("ERROR: PDF file not created at: $filepath");
            return false;
        }
        
        error_log("PDF file created successfully - Size: " . filesize($filepath) . " bytes");

        // Mettre à jour le statut de l'état des lieux
        if ($etatLieux && isset($etatLieux['id'])) {
            error_log("Updating etat_lieux status to 'finalise'...");
            $stmt = $pdo->prepare("UPDATE etats_lieux SET statut = 'finalise' WHERE id = ?");
            $stmt->execute([$etatLieux['id']]);
        }

        error_log("=== generateEtatDesLieuxPDF - SUCCESS ===");
        error_log("PDF Generated: $filepath");
        return $filepath;

    } catch (Exception $e) {
        error_log("=== generateEtatDesLieuxPDF - ERROR ===");
        error_log("Exception type: " . get_class($e));
        error_log("Error message: " . $e->getMessage());
        error_log("Error file: " . $e->getFile() . ":" . $e->getLine());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Créer un état des lieux par défaut avec données de base
 */
function createDefaultEtatLieux($contratId, $type, $contrat, $locataires) {
    global $pdo, $config;

    error_log("=== createDefaultEtatLieux - START ===");
    error_log("Creating default état des lieux for contrat #$contratId, type: $type");

    try {
        $referenceUnique = 'EDL-' . strtoupper($type) . '-' . $contrat['reference'] . '-' . date('YmdHis');
        error_log("Generated reference: $referenceUnique");
        
        // Get first locataire for email
        if (empty($locataires)) {
            error_log("ERROR: No locataires provided to createDefaultEtatLieux");
            throw new Exception("Aucun locataire fourni pour créer l'état des lieux");
        }
        
        $firstLocataire = $locataires[0];
        $locataireEmail = $firstLocataire['email'] ?? '';
        $locataireNomComplet = trim(($firstLocataire['prenom'] ?? '') . ' ' . ($firstLocataire['nom'] ?? ''));
        
        error_log("First locataire: $locataireNomComplet ($locataireEmail)");
        
        $stmt = $pdo->prepare("
            INSERT INTO etats_lieux (
                contrat_id, 
                type, 
                reference_unique,
                date_etat,
                adresse,
                bailleur_nom,
                bailleur_representant,
                locataire_email,
                locataire_nom_complet,
                compteur_electricite,
                compteur_eau_froide,
                cles_appartement,
                cles_boite_lettres,
                cles_autre,
                cles_total,
                piece_principale,
                coin_cuisine,
                salle_eau_wc,
                etat_general,
                lieu_signature,
                statut
            ) VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'brouillon')
        ");
        
        $defaultTexts = getDefaultPropertyDescriptions($type);
        
        $params = [
            $contratId,
            $type,
            $referenceUnique,
            $contrat['adresse'],
            $config['COMPANY_NAME'] ?? 'MY INVEST IMMOBILIER',
            $config['BAILLEUR_REPRESENTANT'] ?? '',
            $locataireEmail,
            $locataireNomComplet,
            '', // compteur_electricite - will be filled by user during état des lieux process
            '', // compteur_eau_froide - will be filled by user during état des lieux process
            0,  // cles_appartement - default 0
            0,  // cles_boite_lettres - default 0
            0,  // cles_autre - default 0
            0,  // cles_total - default 0
            $defaultTexts['piece_principale'],
            $defaultTexts['coin_cuisine'],
            $defaultTexts['salle_eau_wc'],
            $defaultTexts['etat_general'],
            '' // lieu_signature
        ];
        
        error_log("Executing INSERT with " . count($params) . " parameters");
        $stmt->execute($params);
        
        $etatLieuxId = $pdo->lastInsertId();
        error_log("État des lieux created with ID: $etatLieuxId");
        
        // Ajouter les locataires
        error_log("Adding " . count($locataires) . " locataire(s)...");
        foreach ($locataires as $i => $loc) {
            $stmt = $pdo->prepare("
                INSERT INTO etat_lieux_locataires (
                    etat_lieux_id,
                    locataire_id,
                    ordre,
                    nom,
                    prenom,
                    email,
                    signature_data,
                    signature_timestamp,
                    signature_ip
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $etatLieuxId,
                $loc['id'],
                $i + 1,
                $loc['nom'],
                $loc['prenom'],
                $loc['email'],
                $loc['signature_data'] ?? null,
                $loc['signature_timestamp'] ?? null,
                $loc['signature_ip'] ?? null
            ]);
            error_log("Added locataire " . ($i+1) . ": " . $loc['prenom'] . ' ' . $loc['nom']);
        }
    
        // Récupérer l'état des lieux créé
        error_log("Fetching created état des lieux...");
        $stmt = $pdo->prepare("SELECT * FROM etats_lieux WHERE id = ?");
        $stmt->execute([$etatLieuxId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("=== createDefaultEtatLieux - SUCCESS ===");
        return $result;
        
    } catch (Exception $e) {
        error_log("=== createDefaultEtatLieux - ERROR ===");
        error_log("Exception type: " . get_class($e));
        error_log("Error message: " . $e->getMessage());
        error_log("Error file: " . $e->getFile() . ":" . $e->getLine());
        error_log("Stack trace: " . $e->getTraceAsString());
        throw $e;
    }
}

/**
 * Remplacer les variables dans le template HTML de l'état des lieux
 * 
 * @param string $template Template HTML avec variables
 * @param array $contrat Données du contrat
 * @param array $locataires Liste des locataires
 * @param array $etatLieux Données de l'état des lieux
 * @param string $type Type: 'entree' ou 'sortie'
 * @return string HTML avec variables remplacées
 */
function replaceEtatLieuxTemplateVariables($template, $contrat, $locataires, $etatLieux, $type) {
    global $config;
    
    // Type label
    $typeLabel = ($type === 'entree') ? "D'ENTRÉE" : "DE SORTIE";
    
    // Dates
    $dateEtat = !empty($etatLieux['date_etat']) ? date('d/m/Y', strtotime($etatLieux['date_etat'])) : date('d/m/Y');
    $dateSignature = !empty($etatLieux['date_signature']) ? date('d/m/Y', strtotime($etatLieux['date_signature'])) : $dateEtat;
    
    // Reference
    $reference = htmlspecialchars($etatLieux['reference_unique'] ?? $contrat['reference'] ?? 'N/A');
    
    // Adresse
    $adresse = htmlspecialchars($etatLieux['adresse'] ?? $contrat['adresse'] ?? '');
    $typeLogement = htmlspecialchars($contrat['type_logement'] ?? $contrat['type'] ?? '');
    $surface = htmlspecialchars($contrat['surface'] ?? '');
    
    // Bailleur
    $bailleurNom = htmlspecialchars($etatLieux['bailleur_nom'] ?? $config['COMPANY_NAME'] ?? 'MY INVEST IMMOBILIER');
    $bailleurRepresentant = htmlspecialchars($etatLieux['bailleur_representant'] ?? $config['BAILLEUR_REPRESENTANT'] ?? '');
    
    // Locataires - build with br/strong structure instead of table rows
    $locatairesInfo = '';
    foreach ($locataires as $i => $loc) {
        $locatairesInfo .= '<br><strong>Locataire' . (count($locataires) > 1 ? ' ' . ($i + 1) : '') . ' : </strong>';
        $locatairesInfo .= htmlspecialchars($loc['prenom']) . ' ' . htmlspecialchars($loc['nom']);
        if (!empty($loc['email'])) {
            $locatairesInfo .= '<br><strong>Email : </strong>' . htmlspecialchars($loc['email']);
        }
    }
    
    // Description - use defaults if empty
    $defaultTexts = getDefaultPropertyDescriptions($type);
    $piecePrincipale = getValueOrDefault($etatLieux, 'piece_principale', $defaultTexts['piece_principale']);
    $coinCuisine = getValueOrDefault($etatLieux, 'coin_cuisine', $defaultTexts['coin_cuisine']);
    $salleEauWC = getValueOrDefault($etatLieux, 'salle_eau_wc', $defaultTexts['salle_eau_wc']);
    $etatGeneral = getValueOrDefault($etatLieux, 'etat_general', $defaultTexts['etat_general']);
    
    // Replace <br> tags with newlines before escaping HTML
    $piecePrincipale = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $piecePrincipale);
    $coinCuisine = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $coinCuisine);
    $salleEauWC = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $salleEauWC);
    $etatGeneral = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $etatGeneral);
    
    // Convert sentence-ending periods into line breaks
    // Pattern matches: period + space + uppercase letter
    // Excludes: digits (decimal numbers) and common French abbreviations via fixed-length lookbehinds
    // The lookbehind checks the characters immediately before the period (e.g., 'M', 'Mr', 'Mme', etc.)
    $sentencePattern = '/(?<!\d)(?<!M)(?<!Mr)(?<!Ms)(?<!Dr)(?<!St)(?<!Mme)(?<!Mrs)(?<!Ste)(?<!Mlle)\.\s+(?=[A-ZÀÂÄÇÉÈÊËÏÎÔÙÛÜ])/';
    $piecePrincipale = preg_replace($sentencePattern, ".\n", $piecePrincipale);
    $coinCuisine = preg_replace($sentencePattern, ".\n", $coinCuisine);
    $salleEauWC = preg_replace($sentencePattern, ".\n", $salleEauWC);
    $etatGeneral = preg_replace($sentencePattern, ".\n", $etatGeneral);
    
    // Escape HTML for descriptions (preserve newlines)
    // Replace \n with <br> and ensure proper line spacing for TCPDF
    $piecePrincipale = str_replace("\n", '<br>', htmlspecialchars($piecePrincipale));
    $coinCuisine = str_replace("\n", '<br>', htmlspecialchars($coinCuisine));
    $salleEauWC = str_replace("\n", '<br>', htmlspecialchars($salleEauWC));
    $etatGeneral = str_replace("\n", '<br>', htmlspecialchars($etatGeneral));
    
    // Observations - trim, replace <br> with newlines, escape and convert to <br> for HTML
    $observations = trim($etatLieux['observations'] ?? '');
    $observations = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $observations);
    $observationsEscaped = str_replace("\n", '<br>', htmlspecialchars($observations));
    
    // Compteurs (Meter readings)
    $compteurElec = htmlspecialchars($etatLieux['compteur_electricite'] ?? '');
    $compteurEau = htmlspecialchars($etatLieux['compteur_eau_froide'] ?? '');
    
    // Clés (Keys)
    $clesAppart = (int)($etatLieux['cles_appartement'] ?? 0);
    $clesBoite = (int)($etatLieux['cles_boite_lettres'] ?? 0);
    $clesAutre = (int)($etatLieux['cles_autre'] ?? 0);
    // Only auto-calculate total if not explicitly set in database
    if (!array_key_exists('cles_total', $etatLieux) || $etatLieux['cles_total'] === null || $etatLieux['cles_total'] === '') {
        $clesTotal = $clesAppart + $clesBoite + $clesAutre;
    } else {
        $clesTotal = (int)$etatLieux['cles_total'];
    }
    
    // Sortie-specific fields
    $clesConformite = '';
    $clesObservations = '';
    $etatGeneralConforme = '';
    $degradationsConstatees = '';
    $degradationsDetails = '';
    $depotGarantieSection = '';
    $bilanLogementSection = '';
    $signaturesSectionNumber = '7'; // Default for entry
    
    if ($type === 'sortie') {
        // Keys conformity
        $clesConformiteVal = $etatLieux['cles_conformite'] ?? '';
        if ($clesConformiteVal === 'conforme') {
            $clesConformite = '<span class="conformity-badge conformity-conforme">CONFORME</span>';
        } elseif ($clesConformiteVal === 'non_conforme') {
            $clesConformite = '<span class="conformity-badge conformity-non-conforme">NON CONFORME</span>';
        } elseif ($clesConformiteVal === 'non_applicable') {
            $clesConformite = 'Non applicable';
        }
        
        $clesObservations = trim($etatLieux['cles_observations'] ?? '');
        
        // General state conformity
        $etatGeneralConformeVal = $etatLieux['etat_general_conforme'] ?? '';
        if ($etatGeneralConformeVal === 'conforme') {
            $etatGeneralConforme = '<span class="conformity-badge conformity-conforme">CONFORME</span>';
        } elseif ($etatGeneralConformeVal === 'non_conforme') {
            $etatGeneralConforme = '<span class="conformity-badge conformity-non-conforme">NON CONFORME</span>';
        } elseif ($etatGeneralConformeVal === 'non_applicable') {
            $etatGeneralConforme = 'Non applicable';
        }
        
        // Degradations
        $degradationsConstateesVal = (bool)($etatLieux['degradations_constatees'] ?? false);
        $degradationsConstatees = $degradationsConstateesVal ? 'Oui' : 'Non';
        $degradationsDetails = convertAndEscapeText($etatLieux['degradations_details'] ?? '');
        
        // Bilan du logement section
        $bilanData = [];
        if (!empty($etatLieux['bilan_logement_data'])) {
            $bilanData = json_decode($etatLieux['bilan_logement_data'], true) ?: [];
        }
        
        // New section-based bilan data
        $bilanSectionsData = [];
        if (!empty($etatLieux['bilan_sections_data'])) {
            $bilanSectionsData = json_decode($etatLieux['bilan_sections_data'], true) ?: [];
        }
        
        $bilanCommentaire = convertAndEscapeText($etatLieux['bilan_logement_commentaire'] ?? '');
        
        // Display bilan section if there's any data
        if (!empty($bilanData) || !empty($bilanSectionsData) || !empty($bilanCommentaire)) {
            $sectionNum = '7';
            $bilanLogementSection = "<h2>$sectionNum. Bilan du logement</h2>";
            
            // Display section-based bilan data first (new format)
            if (!empty($bilanSectionsData)) {
                $sectionTitles = [
                    'compteurs' => 'Relevé des compteurs',
                    'cles' => 'Restitution des clés',
                    'piece_principale' => 'Pièce principale',
                    'cuisine' => 'Coin cuisine',
                    'salle_eau' => 'Salle d\'eau et WC'
                ];
                
                foreach ($bilanSectionsData as $section => $items) {
                    if (!empty($items) && is_array($items)) {
                        $sectionTitle = $sectionTitles[$section] ?? ucfirst(str_replace('_', ' ', $section));
                        $bilanLogementSection .= "<h3>$sectionTitle</h3>";
                        $bilanLogementSection .= '<table class="bilan-table" cellspacing="0" cellpadding="6">';
                        $bilanLogementSection .= '<thead><tr>';
                        $bilanLogementSection .= '<th width="35%">Équipement</th>';
                        $bilanLogementSection .= '<th width="65%">Commentaire</th>';
                        $bilanLogementSection .= '</tr></thead><tbody>';
                        
                        foreach ($items as $item) {
                            if (!empty($item['equipement']) || !empty($item['commentaire'])) {
                                $equipement = htmlspecialchars($item['equipement'] ?? '');
                                $commentaire = htmlspecialchars($item['commentaire'] ?? '');
                                
                                $bilanLogementSection .= '<tr>';
                                $bilanLogementSection .= '<td>' . $equipement . '</td>';
                                $bilanLogementSection .= '<td>' . $commentaire . '</td>';
                                $bilanLogementSection .= '</tr>';
                            }
                        }
                        
                        $bilanLogementSection .= '</tbody></table>';
                    }
                }
            }
            
            // Display old bilan data format if exists (backward compatibility)
            if (!empty($bilanData)) {
                // Filter out empty rows
                $bilanData = array_filter($bilanData, function($row) {
                    return !empty($row['poste']) || !empty($row['commentaires']) || 
                           (isset($row['valeur']) && $row['valeur'] !== '') || 
                           (isset($row['montant_du']) && $row['montant_du'] !== '');
                });
                
                if (!empty($bilanData)) {
                    if (!empty($bilanSectionsData)) {
                        $bilanLogementSection .= '<h3>Bilan détaillé des frais</h3>';
                    }
                    $bilanLogementSection .= '<table class="bilan-table" cellspacing="0" cellpadding="6">';
                    $bilanLogementSection .= '<thead><tr>';
                    $bilanLogementSection .= '<th width="25%">Poste / Équipement</th>';
                    $bilanLogementSection .= '<th width="35%">Commentaires</th>';
                    $bilanLogementSection .= '<th width="15%" class="text-right">Valeur (€)</th>';
                    $bilanLogementSection .= '<th width="15%" class="text-right">Montant dû (€)</th>';
                    $bilanLogementSection .= '</tr></thead><tbody>';
                    
                    $totalValeur = 0;
                    $totalMontantDu = 0;
                    
                    foreach ($bilanData as $row) {
                        $poste = htmlspecialchars($row['poste'] ?? '');
                        $commentaires = htmlspecialchars($row['commentaires'] ?? '');
                        $valeur = (float)($row['valeur'] ?? 0);
                        $montantDu = (float)($row['montant_du'] ?? 0);
                        
                        $totalValeur += $valeur;
                        $totalMontantDu += $montantDu;
                        
                        $bilanLogementSection .= '<tr>';
                        $bilanLogementSection .= '<td>' . $poste . '</td>';
                        $bilanLogementSection .= '<td>' . $commentaires . '</td>';
                        $bilanLogementSection .= '<td class="text-right">' . number_format($valeur, 2, ',', ' ') . '</td>';
                        $bilanLogementSection .= '<td class="text-right">' . number_format($montantDu, 2, ',', ' ') . '</td>';
                        $bilanLogementSection .= '</tr>';
                    }
                    
                    $bilanLogementSection .= '</tbody><tfoot><tr>';
                    $bilanLogementSection .= '<td colspan="2" class="text-right"><strong>Total des frais constatés :</strong></td>';
                    $bilanLogementSection .= '<td class="text-right"><strong>' . number_format($totalValeur, 2, ',', ' ') . ' €</strong></td>';
                    $bilanLogementSection .= '<td class="text-right"><strong>' . number_format($totalMontantDu, 2, ',', ' ') . ' €</strong></td>';
                    $bilanLogementSection .= '</tr></tfoot></table>';
                }
            }
            
            if (!empty($bilanCommentaire)) {
                $bilanLogementSection .= '<h3>Commentaires généraux</h3>';
                $bilanLogementSection .= '<p class="observations">' . $bilanCommentaire . '</p>';
            }
        }
        
        // Calculate final signatures section number based on all included sections
        if (!empty($bilanLogementSection)) {
            $signaturesSectionNumber = '8';
        } else {
            $signaturesSectionNumber = '7';
        }
    }
    
    // Lieu de signature
    $lieuSignature = htmlspecialchars(!empty($etatLieux['lieu_signature']) ? $etatLieux['lieu_signature'] : ($config['DEFAULT_SIGNATURE_LOCATION'] ?? 'Annemasse'));
    
    // Build signatures table
    $signaturesTable = buildSignaturesTableEtatLieux($contrat, $locataires, $etatLieux);
    
    // Company name for signature section
    $companyName = htmlspecialchars($config['COMPANY_NAME'] ?? 'MY INVEST IMMOBILIER');
    
    // Date prévue de fin du contrat
    $datefinPrevue = '';
    if (!empty($contrat['date_fin_prevue'])) {
        $datefinPrevue = date('d/m/Y', strtotime($contrat['date_fin_prevue']));
    }
    
    // Anomalie(s) constatée(s) - Description du logement (piece_principale bilan rows)
    $anomaliesDescriptionLogement = '';
    if (!empty($bilanSectionsData['piece_principale']) && is_array($bilanSectionsData['piece_principale'])) {
        $anomaliesDescriptionLogement = '<table class="bilan-table" cellspacing="0" cellpadding="6">';
        $anomaliesDescriptionLogement .= '<thead><tr><th width="35%">Élément</th><th width="65%">Anomalie constatée</th></tr></thead><tbody>';
        foreach ($bilanSectionsData['piece_principale'] as $item) {
            if (!empty($item['equipement']) || !empty($item['commentaire'])) {
                $anomaliesDescriptionLogement .= '<tr>';
                $anomaliesDescriptionLogement .= '<td>' . htmlspecialchars($item['equipement'] ?? '') . '</td>';
                $anomaliesDescriptionLogement .= '<td>' . htmlspecialchars($item['commentaire'] ?? '') . '</td>';
                $anomaliesDescriptionLogement .= '</tr>';
            }
        }
        $anomaliesDescriptionLogement .= '</tbody></table>';
    }
    
    // Prepare variable replacements
    $vars = [
        '{{reference}}' => $reference,
        '{{type}}' => strtolower($type),
        '{{type_label}}' => $typeLabel,
        '{{date_etat}}' => $dateEtat,
        '{{adresse}}' => $adresse,
        '{{type_logement}}' => $typeLogement,
        '{{surface}}' => $surface,
        '{{bailleur_nom}}' => $bailleurNom,
        '{{bailleur_representant}}' => $bailleurRepresentant,
        '{{locataires_info}}' => $locatairesInfo,
        '{{compteur_electricite}}' => $compteurElec,
        '{{compteur_eau_froide}}' => $compteurEau,
        '{{cles_appartement}}' => $clesAppart,
        '{{cles_boite_lettres}}' => $clesBoite,
        '{{cles_autre}}' => $clesAutre,
        '{{cles_total}}' => $clesTotal,
        '{{cles_observations}}' => htmlspecialchars($clesObservations),
        '{{piece_principale}}' => $piecePrincipale,
        '{{coin_cuisine}}' => $coinCuisine,
        '{{salle_eau_wc}}' => $salleEauWC,
        '{{etat_general}}' => $etatGeneral,
        '{{observations}}' => $observationsEscaped,
        '{{date_fin_prevue}}' => $datefinPrevue,
        '{{anomalies_description_logement}}' => $anomaliesDescriptionLogement,
        '{{lieu_signature}}' => $lieuSignature,
        '{{date_signature}}' => $dateSignature,
        '{{signatures_table}}' => $signaturesTable,
        '{{signature_agence}}' => $companyName,
        // Sortie-specific variables
        '{{cles_conformite}}' => $clesConformite,
        '{{etat_general_conforme}}' => $etatGeneralConforme,
        '{{degradations_constatees}}' => $degradationsConstatees,
        '{{degradations_details}}' => $degradationsDetails,
        '{{depot_garantie_section}}' => $depotGarantieSection,
        '{{bilan_logement_section}}' => $bilanLogementSection,
        '{{signatures_section_number}}' => $signaturesSectionNumber,
    ];
    
    // Handle conditional rows (use already-escaped variables)
    $vars['{{appartement_row}}'] = '';
    
    if (!empty($bailleurRepresentant)) {
        $vars['{{bailleur_representant_row}}'] = '<br><strong>Représenté par : </strong>' . $bailleurRepresentant;
    } else {
        $vars['{{bailleur_representant_row}}'] = '';
    }
    
    if (!empty($observations)) {
        $vars['{{observations_section}}'] = '<h3>Observations complémentaires</h3><p class="observations">' . $observationsEscaped . '</p>';
    } else {
        $vars['{{observations_section}}'] = '';
    }
    
    // Sortie-specific conditional sections
    if ($type === 'sortie') {
        // Keys observations section
        if (!empty($clesObservations)) {
            $clesObsEscaped = str_replace("\n", '<br>', htmlspecialchars($clesObservations));
            $vars['{{cles_observations_section}}'] = '<p><strong>Observations sur les clés :</strong><br>' . $clesObsEscaped . '</p>';
        } else {
            $vars['{{cles_observations_section}}'] = '';
        }
        
        // Degradations section
        if ($degradationsConstateesVal && !empty($degradationsDetails)) {
            $vars['{{degradations_section}}'] = '<h3>Dégradations constatées</h3><p class="observations">' . $degradationsDetails . '</p>';
        } else {
            $vars['{{degradations_section}}'] = '';
        }
    } else {
        $vars['{{cles_observations_section}}'] = '';
        $vars['{{degradations_section}}'] = '';
    }
    
    // Replace all variables
    $html = str_replace(array_keys($vars), array_values($vars), $template);
    
    return $html;
}

/**
 * Convertit les balises HTML br en sauts de ligne, échappe le HTML, puis reconvertit en br
 * Utilitaire pour préparer du texte pour l'affichage PDF
 * 
 * @param string $text Texte à convertir
 * @return string Texte converti et échappé
 */
function convertAndEscapeText($text) {
    $text = trim($text);
    $text = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $text);
    $text = htmlspecialchars($text);
    return str_replace("\n", '<br>', $text);
}

/**
 * Obtenir les descriptions par défaut du logement
 */
function getDefaultPropertyDescriptions($type) {
    if ($type === 'entree') {
        return [
            'piece_principale' => "État général : Bon état. Murs et plafonds propres. Revêtement de sol en bon état. Fenêtres et volets fonctionnels.",
            'coin_cuisine' => "État général : Bon état. Équipements (évier, plaques, réfrigérateur) fonctionnels et propres. Placards en bon état.",
            'salle_eau_wc' => "État général : Bon état. Sanitaires (lavabo, douche/baignoire, WC) propres et fonctionnels. Carrelage en bon état.",
            'etat_general' => "Le logement est remis en bon état général, propre et conforme à l'usage d'habitation."
        ];
    } else {
        return [
            'piece_principale' => "État constaté à la sortie : [À compléter]",
            'coin_cuisine' => "État constaté à la sortie : [À compléter]",
            'salle_eau_wc' => "État constaté à la sortie : [À compléter]",
            'etat_general' => "État général du logement à la sortie : [À compléter]"
        ];
    }
}

/**
 * Helper to get field value or default if empty
 * Trims whitespace and returns default if empty
 */
function getValueOrDefault($etatLieux, $field, $default) {
    $value = trim($etatLieux[$field] ?? '');
    return str_replace("\n", '<br>', htmlspecialchars(empty($value) ? $default : $value));
}


/**
 * Générer le HTML pour l'état des lieux d'entrée
 * @deprecated Use replaceEtatLieuxTemplateVariables() instead
 */
function generateEntreeHTML($contrat, $locataires, $etatLieux) {
    global $config;
    
    $dateEtat = !empty($etatLieux['date_etat']) ? date('d/m/Y', strtotime($etatLieux['date_etat'])) : date('d/m/Y');
    $adresse = htmlspecialchars($etatLieux['adresse'] ?? $contrat['adresse']);
    
    // Bailleur
    $bailleurNom = htmlspecialchars($etatLieux['bailleur_nom'] ?? $config['COMPANY_NAME']);
    $bailleurRepresentant = htmlspecialchars($etatLieux['bailleur_representant'] ?? $config['BAILLEUR_REPRESENTANT'] ?? '');
    
    // Locataires
    $locatairesHTML = '';
    foreach ($locataires as $i => $loc) {
        $locatairesHTML .= '<p>' . htmlspecialchars($loc['prenom']) . ' ' . htmlspecialchars($loc['nom']);
        if (!empty($loc['email'])) {
            $locatairesHTML .= '<br>Email : ' . htmlspecialchars($loc['email']);
        }
        $locatairesHTML .= '</p>';
    }
    
    // Compteurs
    $compteurElec = htmlspecialchars($etatLieux['compteur_electricite'] ?? '___________');
    $compteurEau = htmlspecialchars($etatLieux['compteur_eau_froide'] ?? '___________');
    
    // Clés
    $clesAppart = (int)($etatLieux['cles_appartement'] ?? 0);
    $clesBoite = (int)($etatLieux['cles_boite_lettres'] ?? 0);
    $clesAutre = (int)($etatLieux['cles_autre'] ?? 0);
    $clesTotal = (int)($etatLieux['cles_total'] ?? 0);
    if ($clesTotal === 0) $clesTotal = $clesAppart + $clesBoite + $clesAutre;
    
    // Description - use defaults if empty
    $defaultTexts = getDefaultPropertyDescriptions('entree');
    $piecePrincipale = getValueOrDefault($etatLieux, 'piece_principale', $defaultTexts['piece_principale']);
    $coinCuisine = getValueOrDefault($etatLieux, 'coin_cuisine', $defaultTexts['coin_cuisine']);
    $salleEauWC = getValueOrDefault($etatLieux, 'salle_eau_wc', $defaultTexts['salle_eau_wc']);
    $etatGeneral = getValueOrDefault($etatLieux, 'etat_general', $defaultTexts['etat_general']);
    
    // Step 1: Replace <br> tags with newlines
    $piecePrincipale = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $piecePrincipale);
    $coinCuisine = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $coinCuisine);
    $salleEauWC = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $salleEauWC);
    $etatGeneral = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $etatGeneral);
    
    // Step 2: Escape HTML and convert newlines to <br> tags for TCPDF rendering
    $piecePrincipale = str_replace("\n", '<br>', htmlspecialchars($piecePrincipale));
    $coinCuisine = str_replace("\n", '<br>', htmlspecialchars($coinCuisine));
    $salleEauWC = str_replace("\n", '<br>', htmlspecialchars($salleEauWC));
    $etatGeneral = str_replace("\n", '<br>', htmlspecialchars($etatGeneral));
    
    // Observations complémentaires - replace <br> with newlines
    $observations = $etatLieux['observations'] ?? '';
    $observations = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $observations);
    $observations = str_replace("\n", '<br>', htmlspecialchars($observations));
    
    // Signatures
    $signaturesHTML = buildSignaturesTableEtatLieux($contrat, $locataires, $etatLieux);
    
    $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>État des lieux d'entrée</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            font-size: 10pt; 
            line-height: 1.5; 
            color: #000; 
        }
        h1 { 
            text-align: center; 
            font-size: 16pt; 
            margin-bottom: 20px; 
            font-weight: bold; 
            text-decoration: underline;
        }
        h2 { 
            font-size: 12pt; 
            margin-top: 0; 
            margin-bottom: 10px; 
            font-weight: bold; 
            border-bottom: 2px solid #000;
            padding-bottom: 5px;
        }
        h3 { 
            font-size: 11pt; 
            margin-top: 15px; 
            margin-bottom: 8px; 
            font-weight: bold; 
        }
        p { 
            margin: 8px 0; 
            text-align: justify; 
        }
        p.description { 
            line-height: 1.5; 
        }
        .section { 
            margin-bottom: 20px; 
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 10px 0; 
        }
        table.data-table { 
            border: 1px solid #000; 
        }
        table.data-table th, 
        table.data-table td { 
            border: 1px solid #000; 
            padding: 8px; 
            text-align: left; 
        }
        table.data-table th { 
            background-color: #f0f0f0; 
            font-weight: bold; 
        }
        .signature-table { 
            margin-top: 30px; 
        }
        .signature-table td { 
            vertical-align: top; 
            text-align: center; 
            padding: 10px; 
        }
        .signature-box { 
            min-height: 80px; 
            margin-bottom: 5px; 
        }
        .text-field { 
            border-bottom: 1px dotted #333; 
            display: inline-block; 
            min-width: 200px; 
            padding: 2px 5px; 
        }
    </style>
</head>
<body>
    <h1>ÉTAT DES LIEUX D'ENTRÉE</h1>
    
    <div class="section">
        <h2>1. IDENTIFICATION</h2>
        <p><strong>Date de l'état des lieux :</strong> $dateEtat</p>
        <p><strong>Adresse du logement :</strong><br>$adresse
HTML;

    
    $html .= <<<HTML
</p>
        <p><strong>Bailleur :</strong><br>$bailleurNom
HTML;

    if ($bailleurRepresentant) {
        $html .= "<br>Représenté par : $bailleurRepresentant";
    }
    
    $html .= <<<HTML
</p>
        <p><strong>Locataire(s) :</strong><br>$locatairesHTML</p>
    </div>
    
    <div class="section" style="margin-top: 10px;">
        <h2>2. RELEVÉ DES COMPTEURS</h2>
        <p><strong>Électricité :</strong> $compteurElec</p>
        <p><strong>Eau froide :</strong> $compteurEau</p>
    </div>
    
    <div class="section">
        <h2>3. REMISE DES CLÉS</h2>
        <p><strong>Clés de l'appartement :</strong> $clesAppart</p>
        <p><strong>Clés de la boîte aux lettres :</strong> $clesBoite</p>
        <p><strong>Autre :</strong> $clesAutre</p>
        <p><strong>TOTAL :</strong> $clesTotal</p>
    </div>
    
    <div class="section">
        <h2>4. DESCRIPTION DU LOGEMENT</h2>
        
        <h3>4.1 Pièce principale</h3>
        <p class="description">$piecePrincipale</p>
        
        <h3>4.2 Coin cuisine</h3>
        <p class="description">$coinCuisine</p>
        
        <h3>4.3 Salle d'eau / WC</h3>
        <p class="description">$salleEauWC</p>
        
        <h3>4.4 État général</h3>
        <p>$etatGeneral</p>
    </div>
    
    <div class="section">
        <h2>5. SIGNATURES</h2>
        <p>Le présent état des lieux d'entrée a été établi contradictoirement entre les parties.</p>
HTML;

    if (!empty($observations)) {
        $html .= <<<HTML
        <p><strong>Observations complémentaires :</strong></p>
        <p>$observations</p>
HTML;
    }

    $html .= <<<HTML
        $signaturesHTML
    </div>
    
</body>
</html>
HTML;

    return $html;
}

/**
 * Générer le HTML pour l'état des lieux de sortie
 * @deprecated Use replaceEtatLieuxTemplateVariables() instead
 */
function generateSortieHTML($contrat, $locataires, $etatLieux) {
    global $config;
    
    $dateEtat = !empty($etatLieux['date_etat']) ? date('d/m/Y', strtotime($etatLieux['date_etat'])) : date('d/m/Y');
    $adresse = htmlspecialchars($etatLieux['adresse'] ?? $contrat['adresse']);
    
    // Bailleur
    $bailleurNom = htmlspecialchars($etatLieux['bailleur_nom'] ?? $config['COMPANY_NAME']);
    $bailleurRepresentant = htmlspecialchars($etatLieux['bailleur_representant'] ?? $config['BAILLEUR_REPRESENTANT'] ?? '');
    
    // Locataires
    $locatairesHTML = '';
    foreach ($locataires as $i => $loc) {
        $locatairesHTML .= '<p>' . htmlspecialchars($loc['prenom']) . ' ' . htmlspecialchars($loc['nom']);
        if (!empty($loc['email'])) {
            $locatairesHTML .= '<br>Email : ' . htmlspecialchars($loc['email']);
        }
        $locatairesHTML .= '</p>';
    }
    
    // Compteurs
    $compteurElec = htmlspecialchars($etatLieux['compteur_electricite'] ?? '___________');
    $compteurEau = htmlspecialchars($etatLieux['compteur_eau_froide'] ?? '___________');
    
    // Clés
    $clesAppart = (int)($etatLieux['cles_appartement'] ?? 0);
    $clesBoite = (int)($etatLieux['cles_boite_lettres'] ?? 0);
    $clesAutre = (int)($etatLieux['cles_autre'] ?? 0);
    $clesTotal = (int)($etatLieux['cles_total'] ?? 0);
    if ($clesTotal === 0) $clesTotal = $clesAppart + $clesBoite + $clesAutre;
    
    $clesConformite = $etatLieux['cles_conformite'] ?? 'non_applicable';
    $conformiteLabels = [
        'conforme' => 'Conforme',
        'non_conforme' => 'Non conforme',
        'non_applicable' => 'Non applicable'
    ];
    $clesConformiteLabel = $conformiteLabels[$clesConformite] ?? 'Non vérifié';
    // Add checkbox symbol in PDF
    if ($clesConformite === 'non_applicable') {
        $clesConformiteHTML = '☐ ' . $clesConformiteLabel;
    } else {
        $clesConformiteHTML = '☑ ' . $clesConformiteLabel;
    }
    $clesObservations = htmlspecialchars($etatLieux['cles_observations'] ?? '');
    
    // Description - use defaults if empty
    $defaultTexts = getDefaultPropertyDescriptions('sortie');
    $piecePrincipale = getValueOrDefault($etatLieux, 'piece_principale', $defaultTexts['piece_principale']);
    $coinCuisine = getValueOrDefault($etatLieux, 'coin_cuisine', $defaultTexts['coin_cuisine']);
    $salleEauWC = getValueOrDefault($etatLieux, 'salle_eau_wc', $defaultTexts['salle_eau_wc']);
    $etatGeneral = getValueOrDefault($etatLieux, 'etat_general', $defaultTexts['etat_general']);
    
    // Step 1: Replace <br> tags with newlines
    $piecePrincipale = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $piecePrincipale);
    $coinCuisine = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $coinCuisine);
    $salleEauWC = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $salleEauWC);
    $etatGeneral = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $etatGeneral);
    
    // Step 2: Escape HTML and convert newlines to <br> tags for TCPDF rendering
    $piecePrincipale = str_replace("\n", '<br>', htmlspecialchars($piecePrincipale));
    $coinCuisine = str_replace("\n", '<br>', htmlspecialchars($coinCuisine));
    $salleEauWC = str_replace("\n", '<br>', htmlspecialchars($salleEauWC));
    $etatGeneral = str_replace("\n", '<br>', htmlspecialchars($etatGeneral));
    
    // Observations complémentaires - replace <br> with newlines
    $observations = $etatLieux['observations'] ?? '';
    $observations = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $observations);
    $observations = str_replace("\n", '<br>', htmlspecialchars($observations));
    
    // Conclusion - replace <br> with newlines
    $comparaisonEntree = $etatLieux['comparaison_entree'] ?? 'Comparaison avec l\'état des lieux d\'entrée : [À compléter]';
    $comparaisonEntree = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $comparaisonEntree);
    $comparaisonEntree = str_replace("\n", '<br>', htmlspecialchars($comparaisonEntree));
    
    $depotStatus = $etatLieux['depot_garantie_status'] ?? 'non_applicable';
    $depotLabels = [
        'restitution_totale' => 'Aucune dégradation imputable au(x) locataire(s) - Restitution totale du dépôt de garantie',
        'restitution_partielle' => 'Dégradations mineures imputables au(x) locataire(s) - Restitution partielle du dépôt de garantie',
        'retenue_totale' => 'Dégradations importantes imputables au(x) locataire(s) - Retenue totale du dépôt de garantie',
        'non_applicable' => 'Non applicable'
    ];
    $depotHTML = '';
    foreach ($depotLabels as $key => $label) {
        if ($key === $depotStatus) {
            $depotHTML .= "<p>☑ $label</p>";
        } else {
            $depotHTML .= "<p>☐ $label</p>";
        }
    }
    
    if (!empty($etatLieux['depot_garantie_montant_retenu']) && $etatLieux['depot_garantie_montant_retenu'] > 0) {
        $montantRetenu = number_format((float)$etatLieux['depot_garantie_montant_retenu'], 2, ',', ' ');
        $depotHTML .= "<p><strong>Montant retenu :</strong> $montantRetenu €</p>";
    }
    
    if (!empty($etatLieux['depot_garantie_motif_retenue'])) {
        $motifRetenue = str_replace("\n", '<br>', htmlspecialchars($etatLieux['depot_garantie_motif_retenue']));
        $depotHTML .= "<p><strong>Justificatif / Motif de la retenue :</strong><br>$motifRetenue</p>";
    }
    
    // Signatures
    $signaturesHTML = buildSignaturesTableEtatLieux($contrat, $locataires, $etatLieux);
    
    $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>État des lieux de sortie</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            font-size: 10pt; 
            line-height: 1.5; 
            color: #000; 
        }
        h1 { 
            text-align: center; 
            font-size: 16pt; 
            margin-bottom: 20px; 
            font-weight: bold; 
            text-decoration: underline;
        }
        h2 { 
            font-size: 12pt; 
            margin-top: 0; 
            margin-bottom: 10px; 
            font-weight: bold; 
            border-bottom: 2px solid #000;
            padding-bottom: 5px;
        }
        h3 { 
            font-size: 11pt; 
            margin-top: 15px; 
            margin-bottom: 8px; 
            font-weight: bold; 
        }
        p { 
            margin: 8px 0; 
            text-align: justify; 
        }
        p.description { 
            line-height: 1.5; 
        }
        .section { 
            margin-bottom: 20px; 
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 10px 0; 
        }
        table.data-table { 
            border: 1px solid #000; 
        }
        table.data-table th, 
        table.data-table td { 
            border: 1px solid #000; 
            padding: 8px; 
            text-align: left; 
        }
        table.data-table th { 
            background-color: #f0f0f0; 
            font-weight: bold; 
        }
        .signature-table { 
            margin-top: 30px; 
        }
        .signature-table td { 
            vertical-align: top; 
            text-align: center; 
            padding: 10px; 
        }
        .signature-box { 
            min-height: 80px; 
            margin-bottom: 5px; 
        }
    </style>
</head>
<body>
    <h1>ÉTAT DES LIEUX DE SORTIE</h1>
    
    <div class="section">
        <h2>1. IDENTIFICATION</h2>
        <p><strong>Date de l'état des lieux :</strong> $dateEtat</p>
        <p><strong>Adresse du logement :</strong><br>$adresse
HTML;

    
    $html .= <<<HTML
</p>
        <p><strong>Bailleur :</strong><br>$bailleurNom
HTML;

    if ($bailleurRepresentant) {
        $html .= "<br>Représenté par : $bailleurRepresentant";
    }
    
    $html .= <<<HTML
</p>
        <p><strong>Locataire(s) sortant(s) :</strong><br>$locatairesHTML</p>
    </div>
    
    <div class="section" style="margin-top: 10px;">
        <h2>2. RELEVÉ DES COMPTEURS À LA SORTIE</h2>
        <p><strong>Électricité :</strong> $compteurElec</p>
        <p><strong>Eau froide :</strong> $compteurEau</p>
    </div>
    
    <div class="section">
        <h2>3. RESTITUTION DES CLÉS</h2>
        <p><strong>Clés de l'appartement :</strong> $clesAppart</p>
        <p><strong>Clés de la boîte aux lettres :</strong> $clesBoite</p>
        <p><strong>Autre :</strong> $clesAutre</p>
        <p><strong>TOTAL :</strong> $clesTotal</p>
        <p><strong>Conformité :</strong> $clesConformiteHTML</p>
HTML;

    if ($clesObservations) {
        $html .= "<p><strong>Observations :</strong> $clesObservations</p>";
    }
    
    $html .= <<<HTML
    </div>
    
    <div class="section">
        <h2>4. DESCRIPTION DU LOGEMENT</h2>
        
        <h3>4.1 Pièce principale</h3>
        <p class="description">$piecePrincipale</p>
        
        <h3>4.2 Coin cuisine</h3>
        <p class="description">$coinCuisine</p>
        
        <h3>4.3 Salle d'eau / WC</h3>
        <p class="description">$salleEauWC</p>
        
        <h3>4.4 État général</h3>
        <p>$etatGeneral</p>
    </div>
    
    <div class="section">
        <h2>5. CONCLUSION</h2>
        
        <h3>5.1 Comparaison avec l'état des lieux d'entrée</h3>
        <p>$comparaisonEntree</p>
        
        <h3>5.2 Dépôt de garantie</h3>
        $depotHTML
    </div>
    
    <div class="section">
        <h2>6. SIGNATURES</h2>
        <p>Le présent état des lieux de sortie a été établi contradictoirement entre les parties.</p>
HTML;

    if (!empty($observations)) {
        $html .= <<<HTML
        <p><strong>Observations complémentaires :</strong></p>
        <p>$observations</p>
HTML;
    }

    $html .= <<<HTML
        $signaturesHTML
    </div>
    
</body>
</html>
HTML;

    return $html;
}

/**
 * Convert base64 signature to physical file
 * Returns file path or original data if conversion fails
 */
function convertSignatureToPhysicalFile($signatureData, $prefix, $etatLieuxId, $tenantId = null) {
    // If already a file path, return it
    if (!preg_match('/^data:image\/(jpeg|jpg|png);base64,/', $signatureData)) {
        return $signatureData;
    }
    
    error_log("Converting base64 signature to physical file for {$prefix}");
    
    // Extract base64 data
    if (!preg_match('/^data:image\/(png|jpeg|jpg);base64,(.+)$/', $signatureData, $matches)) {
        error_log("Invalid data URI format for signature");
        return $signatureData; // Return original if invalid
    }
    
    $imageFormat = $matches[1];
    $base64Data = $matches[2];
    
    // Decode base64
    $imageData = base64_decode($base64Data, true);
    if ($imageData === false) {
        error_log("Failed to decode base64 signature");
        return $signatureData;
    }
    
    // Create uploads/signatures directory if it doesn't exist
    $uploadsDir = dirname(__DIR__) . '/uploads/signatures';
    if (!is_dir($uploadsDir)) {
        if (!mkdir($uploadsDir, 0755, true)) {
            error_log("Failed to create signatures directory");
            return $signatureData;
        }
    }
    
    // Generate unique filename
    $timestamp = time();
    $suffix = $tenantId ? "_tenant_{$tenantId}" : "";
    $filename = "{$prefix}_etat_lieux_{$etatLieuxId}{$suffix}_{$timestamp}.jpg";
    $filepath = $uploadsDir . '/' . $filename;
    
    // Save physical file
    if (file_put_contents($filepath, $imageData) === false) {
        error_log("Failed to save signature file: $filepath");
        return $signatureData;
    }
    
    // Return relative path
    $relativePath = 'uploads/signatures/' . $filename;
    error_log("✓ Signature converted to physical file: $relativePath");
    
    return $relativePath;
}

/**
 * Construire le tableau de signatures pour l'état des lieux
 */
function buildSignaturesTableEtatLieux($contrat, $locataires, $etatLieux) {
    global $pdo, $config;

    // Get tenants from etat_lieux_locataires table for this specific état des lieux
    $etatLieuxTenants = [];
    if ($etatLieux && isset($etatLieux['id'])) {
        $stmt = $pdo->prepare("
            SELECT * FROM etat_lieux_locataires 
            WHERE etat_lieux_id = ? 
            ORDER BY ordre ASC
        ");
        $stmt->execute([$etatLieux['id']]);
        $etatLieuxTenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Use etat_lieux_locataires if available, otherwise fall back to locataires
    $tenantsToDisplay = !empty($etatLieuxTenants) ? $etatLieuxTenants : $locataires;
    
    $nbCols = count($tenantsToDisplay) + 1; // +1 for landlord
    $colWidth = 100 / $nbCols;

    $html = '<table border="0" cellspacing="0" cellpadding="0" style="max-width: 600px; width: 100%; border: none; margin-top: 20px; text-align:center;"><tr>';

    // Landlord column - Use signature_societe_etat_lieux_image from parametres
    $html .= '<td style="width:' . $colWidth . '%; vertical-align: top; text-align:center; padding:0px; border: none;">';
    $html .= '<p><strong>Le bailleur :</strong></p>';
    
    // Get landlord signature from parametres - use etat_lieux specific signature
    $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'signature_societe_etat_lieux_image'");
    $stmt->execute();
    $landlordSigPath = $stmt->fetchColumn();
    
    // Fallback to general signature if etat_lieux specific one not found
    if (empty($landlordSigPath)) {
        $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'signature_societe_image'");
        $stmt->execute();
        $landlordSigPath = $stmt->fetchColumn();
    }

    if (!empty($landlordSigPath)) {
        // Convert base64 to physical file if needed
        $etatLieuxId = $etatLieux['id'] ?? 0;
        $landlordSigPath = convertSignatureToPhysicalFile($landlordSigPath, 'landlord', $etatLieuxId);

        // Normalize path: company signatures are stored as plain filenames in uploads/
        $landlordSigPath = normalizeCompanySignaturePath($landlordSigPath);
        $isFilePath = strpos($landlordSigPath, 'uploads/') === 0;
        
        // Update database with physical path if it was converted
        if ($isFilePath && !empty($etatLieuxId) && !preg_match('/^data:image/', $landlordSigPath)) {
            // Update the parameter with the new physical path
            $paramKey = 'signature_societe_etat_lieux_image';
            $updateStmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = ?");
            $updateStmt->execute([$paramKey]);
            $currentValue = $updateStmt->fetchColumn();
            
            // Only update if current value is base64 (to avoid overwriting if already updated)
            if ($currentValue && preg_match('/^data:image/', $currentValue)) {
                $updateStmt = $pdo->prepare("UPDATE parametres SET valeur = ? WHERE cle = ?");
                $updateStmt->execute([$landlordSigPath, $paramKey]);
                error_log("✓ Updated landlord signature in database to physical file");
            }
        }
        
        if ($isFilePath) {
            // Verify file exists before adding to PDF
            $fullPath = dirname(__DIR__) . '/' . $landlordSigPath;
            if (file_exists($fullPath)) {
                // Use public URL for signature image
                // Remove span wrapper - TCPDF doesn't handle it well
                // Add explicit inline styles for TCPDF compatibility
                $publicUrl = rtrim($config['SITE_URL'], '/') . '/' . ltrim($landlordSigPath, '/');
                $html .= '<img src="' . htmlspecialchars($publicUrl) . '" alt="Signature Bailleur" style="width: 80px; height: auto;">';
            } else {
                error_log("Landlord signature file not found: $fullPath");
            }
        } else {
            // Still base64 after conversion attempt - use as fallback but log warning
            error_log("WARNING: Using base64 signature for landlord (conversion may have failed)");
            $html .= '<img src="' . htmlspecialchars($landlordSigPath) . '" alt="Signature Bailleur" style="width: 80px; height: auto;">';
        }
    }
    
    $placeSignature = !empty($etatLieux['lieu_signature']) ? htmlspecialchars($etatLieux['lieu_signature']) : htmlspecialchars($config['DEFAULT_SIGNATURE_LOCATION'] ?? 'Annemasse');
    $html .= '<p style="font-size:8pt;"><br>&nbsp;<br>&nbsp;<br>Fait à ' . $placeSignature . '</p>';
    
    if (!empty($etatLieux['date_etat'])) {
        $signDate = date('d/m/Y', strtotime($etatLieux['date_etat']));
        $html .= '<p style="font-size:8pt;">Le ' . $signDate . '</p>';
    }
    
    $html .= '<p style="font-size:9pt;">' . htmlspecialchars($etatLieux['bailleur_nom'] ?? $config['COMPANY_NAME']) . '</p>';
    $html .= '</td>';

    // Tenant columns
    foreach ($tenantsToDisplay as $idx => $tenantInfo) {
        $html .= '<td style="width:' . $colWidth . '%; vertical-align: top; text-align:center; padding:10px; border: none;">';

        $tenantLabel = ($nbCols === 2) ? 'Locataire :' : 'Locataire ' . ($idx + 1) . ' :';
        $html .= '<p><strong>' . $tenantLabel . '</strong></p>';

        // Display tenant signature if available
        if (!empty($tenantInfo['signature_data'])) {
            $signatureData = $tenantInfo['signature_data'];
            $tenantDbId = $tenantInfo['id'] ?? null;
            $etatLieuxId = $etatLieux['id'] ?? 0;
            
            // Convert base64 to physical file if needed
            $signatureData = convertSignatureToPhysicalFile($signatureData, 'tenant', $etatLieuxId, $tenantDbId);
            
            // Update database if signature was converted from base64
            if ($tenantDbId && !preg_match('/^data:image/', $signatureData) && preg_match('/^uploads\/signatures\//', $signatureData)) {
                // Check if this is the original base64
                if (preg_match('/^data:image/', $tenantInfo['signature_data'])) {
                    $updateStmt = $pdo->prepare("UPDATE etat_lieux_locataires SET signature_data = ? WHERE id = ?");
                    $updateStmt->execute([$signatureData, $tenantDbId]);
                    error_log("✓ Updated tenant signature in database to physical file");
                }
            }
            
            if (preg_match('/^uploads\/signatures\//', $signatureData)) {
                // File path format - verify file exists before using public URL
                $fullPath = dirname(__DIR__) . '/' . $signatureData;
                if (file_exists($fullPath)) {
                    // Use public URL with explicit inline styles for TCPDF compatibility
                    $publicUrl = rtrim($config['SITE_URL'], '/') . '/' . ltrim($signatureData, '/');
                    $html .= '<img src="' . htmlspecialchars($publicUrl) . '" alt="Signature Locataire" style="width: 150px; height: auto;">';
                } else {
                    error_log("Tenant signature file not found: $fullPath");
                }
            } else {
                // Still base64 after conversion attempt - use as fallback but log warning
                error_log("WARNING: Using base64 signature for tenant (conversion may have failed)");
                $html .= '<img src="' . htmlspecialchars($signatureData) . '" alt="Signature Locataire" style="width: 150px; height: auto;">';
            }
            
            if (!empty($tenantInfo['signature_timestamp'])) {
                $signDate = date('d/m/Y à H:i', strtotime($tenantInfo['signature_timestamp']));
                $html .= '<p style="font-size:8pt;"><br>&nbsp;<br>&nbsp;<br>Signé le ' . $signDate . '</p>';
            }
            
            // Display "Certifié exact" checkbox status
            if (!empty($tenantInfo['certifie_exact'])) {
                $html .= '<p style="font-size:8pt; margin-top: 5px;">Certifié exact</p>';
            }
        }

        $tenantName = htmlspecialchars(($tenantInfo['prenom'] ?? '') . ' ' . ($tenantInfo['nom'] ?? ''));
        $html .= '<p style="font-size:9pt;">' . $tenantName . '</p>';
        $html .= '</td>';
    }

    $html .= '</tr></table>';
	// echo $html;exit;
    return $html;
}

/**
 * Envoyer l'état des lieux par email au locataire et à l'email admin configuré
 * 
 * @param int $contratId ID du contrat
 * @param string $type Type d'état des lieux: 'entree' ou 'sortie'
 * @param string $pdfPath Chemin du fichier PDF
 * @return bool True si l'email a été envoyé avec succès
 */
function sendEtatDesLieuxEmail($contratId, $type, $pdfPath) {
    global $pdo, $config;
    
    try {
        // Récupérer le contrat et locataires
        $stmt = $pdo->prepare("
            SELECT c.*, l.adresse,  l.reference
            FROM contrats c
            INNER JOIN logements l ON c.logement_id = l.id
            WHERE c.id = ?
        ");
        $stmt->execute([$contratId]);
        $contrat = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$contrat) {
            error_log("Contrat #$contratId non trouvé");
            return false;
        }
        
        // Récupérer les locataires
        $stmt = $pdo->prepare("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC");
        $stmt->execute([$contratId]);
        $locataires = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($locataires)) {
            error_log("Aucun locataire trouvé pour contrat #$contratId");
            return false;
        }
        
        // Récupérer l'état des lieux
        $stmt = $pdo->prepare("SELECT * FROM etats_lieux WHERE contrat_id = ? AND type = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$contratId, $type]);
        $etatLieux = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Préparer le sujet et le corps de l'email
        $typeLabel = ($type === 'entree') ? "d'entrée" : "de sortie";
        $subject = "État des lieux $typeLabel - " . ($contrat['adresse'] ?? '');
        
        $dateEtat = date('d/m/Y');
        if ($etatLieux && !empty($etatLieux['date_etat'])) {
            $dateEtat = date('d/m/Y', strtotime($etatLieux['date_etat']));
        }
        
        $adresse = $contrat['adresse'];
        
        $body = "Bonjour,\n\n";
        $body .= "Veuillez trouver ci-joint l'état des lieux $typeLabel pour le logement situé au :\n";
        $body .= "$adresse\n\n";
        $body .= "Date de l'état des lieux : $dateEtat\n\n";
        $body .= "Ce document est à conserver précieusement.\n\n";
        $body .= "Cordialement,\n";
        $body .= "MY INVEST IMMOBILIER";
        
        // Envoyer à chaque locataire
        $success = true;
        foreach ($locataires as $locataire) {
            $emailSent = sendEmail(
                $locataire['email'],
                $subject,
                $body,
                $pdfPath,
                false // texte brut
            );
            
            if (!$emailSent) {
                error_log("Erreur envoi email état des lieux à " . $locataire['email']);
                $success = false;
            } else {
                error_log("Email état des lieux envoyé à " . $locataire['email']);
            }
        }
        
        // Envoyer une copie à l'email admin configuré
        $gestionEmail = getAdminEmail();
        $emailSent = sendEmail(
            $gestionEmail,
            "[COPIE] $subject",
            $body,
            $pdfPath,
            false
        );
        
        if (!$emailSent) {
            error_log("Erreur envoi copie email état des lieux à $gestionEmail");
            $success = false;
        } else {
            error_log("Copie email état des lieux envoyée à $gestionEmail");
        }
        
        // Mettre à jour le statut de l'email dans la base de données
        if ($etatLieux && $success) {
            $stmt = $pdo->prepare("
                UPDATE etats_lieux 
                SET email_envoye = TRUE, date_envoi_email = NOW(), statut = 'envoye'
                WHERE id = ?
            ");
            $stmt->execute([$etatLieux['id']]);
        }
        
        return $success;
        
    } catch (Exception $e) {
        error_log("Erreur envoi email état des lieux: " . $e->getMessage());
        return false;
    }
}
