<?php
/**
 * Génération du PDF pour Inventaire d'entrée/sortie
 * My Invest Immobilier
 * 
 * Génère un document PDF structuré pour l'inventaire des équipements d'entrée ou de sortie
 * avec toutes les sections obligatoires et signatures.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail-templates.php';
require_once __DIR__ . '/../includes/inventaire-template.php';

// Signature image display size constants (for PDF rendering)
define('INVENTAIRE_SIGNATURE_MAX_WIDTH', '20mm');
define('INVENTAIRE_SIGNATURE_MAX_HEIGHT', '10mm');

// Style CSS pour les images de signature (sans bordures)
// Simplified for TCPDF compatibility - removed unsupported inline CSS
// TCPDF works best with HTML attributes instead of CSS
define('INVENTAIRE_SIGNATURE_IMG_STYLE', 'width: 120px; height: auto;');

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
 * Générer le PDF de l'inventaire
 * 
 * @param int $inventaireId ID de l'inventaire
 * @return string|false Chemin du fichier PDF généré, ou false en cas d'erreur
 */
function generateInventairePDF($inventaireId) {
    global $config, $pdo;

    error_log("=== generateInventairePDF - START ===");
    error_log("Input - Inventaire ID: $inventaireId");

    // Validation
    $inventaireId = (int)$inventaireId;
    if ($inventaireId <= 0) {
        error_log("ERROR: ID d'inventaire invalide: $inventaireId");
        return false;
    }

    try {
        // Récupérer les données de l'inventaire
        error_log("Fetching inventaire data from database...");
        $stmt = $pdo->prepare("
            SELECT inv.*, 
                   l.reference,
                   l.adresse as logement_adresse,
                   
                   l.type as type_logement,
                   c.reference_unique as contrat_ref
            FROM inventaires inv
            INNER JOIN logements l ON inv.logement_id = l.id
            LEFT JOIN contrats c ON inv.contrat_id = c.id
            WHERE inv.id = ?
        ");
        $stmt->execute([$inventaireId]);
        $inventaire = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inventaire) {
            error_log("ERROR: Inventaire #$inventaireId non trouvé");
            return false;
        }
        
        error_log("Inventaire found - Reference: " . ($inventaire['reference_unique'] ?? 'NULL'));
        error_log("Logement - Adresse: " . ($inventaire['adresse'] ?? 'NULL'));
        error_log("Type: " . ($inventaire['type'] ?? 'NULL'));

        // Récupérer les locataires associés à cet inventaire
        error_log("Fetching locataires...");
        $stmt = $pdo->prepare("SELECT * FROM inventaire_locataires WHERE inventaire_id = ? ORDER BY id ASC");
        $stmt->execute([$inventaireId]);
        $locataires = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($locataires)) {
            error_log("WARNING: Aucun locataire trouvé pour inventaire #$inventaireId, using fallback name");
            // Use locataire_nom_complet from inventaire as fallback
            if (!empty($inventaire['locataire_nom_complet'])) {
                $locataires = [[
                    'nom' => $inventaire['locataire_nom_complet'],
                    'prenom' => '',
                    'email' => $inventaire['locataire_email'] ?? '',
                    'signature' => null,
                    'certifie_exact' => false
                ]];
            }
        }
        
        error_log("Found " . count($locataires) . " locataire(s)");

        // Récupérer le template HTML depuis la base de données
        error_log("Fetching HTML template from database...");
        
        $type = $inventaire['type'] ?? 'entree';
        
        // Use different template for exit inventory if available
        if ($type === 'sortie') {
            $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'inventaire_sortie_template_html'");
            $stmt->execute();
            $templateHtml = $stmt->fetchColumn();
            
            // If no exit template, fall back to entry template
            if (empty($templateHtml)) {
                error_log("No exit template found, falling back to entry template");
                $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'inventaire_template_html'");
                $stmt->execute();
                $templateHtml = $stmt->fetchColumn();
            } else {
                error_log("Exit template loaded from database - Length: " . strlen($templateHtml) . " characters");
            }
        } else {
            $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'inventaire_template_html'");
            $stmt->execute();
            $templateHtml = $stmt->fetchColumn();
            
            if (!empty($templateHtml)) {
                error_log("Entry template loaded from database - Length: " . strlen($templateHtml) . " characters");
            }
        }
        
        // Si pas de template en base, utiliser le template par défaut
        if (empty($templateHtml)) {
            error_log("No template found in database, using default template");
            if ($type === 'sortie' && function_exists('getDefaultInventaireSortieTemplate')) {
                $templateHtml = getDefaultInventaireSortieTemplate();
                error_log("Using default exit template");
            } elseif (function_exists('getDefaultInventaireTemplate')) {
                $templateHtml = getDefaultInventaireTemplate();
                error_log("Using default entry template");
            } else {
                error_log("ERROR: Template functions not found");
                return false;
            }
        }
        
        // Générer le HTML en remplaçant les variables
        error_log("Replacing template variables...");
        $html = replaceInventaireTemplateVariables($templateHtml, $inventaire, $locataires);
        
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
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('MY INVEST IMMOBILIER');
        
        $pdf->SetTitle("Inventaire - " . ($inventaire['reference_unique'] ?? ''));
        
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
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
        $pdfDir = dirname(__DIR__) . '/pdf/inventaires/';
        if (!is_dir($pdfDir)) {
            error_log("Creating directory: $pdfDir");
            mkdir($pdfDir, 0755, true);
        }

        $dateStr = date('Ymd');
        // Sanitize reference for filename
        $safeReference = preg_replace('/[^a-zA-Z0-9_-]/', '_', $inventaire['reference_unique']);
        $filename = "inventaire_{$safeReference}_{$dateStr}.pdf";
        $filepath = $pdfDir . $filename;
        
        error_log("Saving to: $filepath");
        $pdf->Output($filepath, 'F');
        
        if (!file_exists($filepath)) {
            error_log("ERROR: PDF file not created at: $filepath");
            return false;
        }
        
        error_log("PDF file created successfully - Size: " . filesize($filepath) . " bytes");

        // Mettre à jour le statut de l'inventaire
        if ($inventaire['statut'] === 'brouillon') {
            error_log("Updating inventaire status to 'finalise'...");
            $stmt = $pdo->prepare("UPDATE inventaires SET statut = 'finalise' WHERE id = ?");
            $stmt->execute([$inventaireId]);
        }

        error_log("=== generateInventairePDF - SUCCESS ===");
        error_log("PDF Generated: $filepath");
        return $filepath;

    } catch (Exception $e) {
        error_log("=== generateInventairePDF - ERROR ===");
        error_log("Exception type: " . get_class($e));
        error_log("Error message: " . $e->getMessage());
        error_log("Error file: " . $e->getFile() . ":" . $e->getLine());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Remplacer les variables dans le template HTML de l'inventaire
 * 
 * @param string $template Template HTML avec variables
 * @param array $inventaire Données de l'inventaire
 * @param array $locataires Liste des locataires
 * @return string HTML avec variables remplacées
 */
function replaceInventaireTemplateVariables($template, $inventaire, $locataires) {
    global $config, $pdo;
    
    // Dates
    $dateInventaire = !empty($inventaire['date_inventaire']) ? date('d/m/Y', strtotime($inventaire['date_inventaire'])) : date('d/m/Y');
    $dateSignature = !empty($inventaire['date_signature']) ? date('d/m/Y', strtotime($inventaire['date_signature'])) : $dateInventaire;
    
    // Reference
    $reference = htmlspecialchars($inventaire['reference_unique'] ?? 'N/A');
    
    // Adresse
    $adresse = htmlspecialchars($inventaire['adresse'] ?? $inventaire['logement_adresse'] ?? '');
    
    // Bailleur
    $bailleurNom = htmlspecialchars($inventaire['bailleur_nom'] ?? $config['COMPANY_NAME'] ?? 'MY INVEST IMMOBILIER');
    $bailleurRepresentant = htmlspecialchars($inventaire['bailleur_representant'] ?? '');
    
    // Locataire info
    $locataireNom = htmlspecialchars($inventaire['locataire_nom_complet'] ?? '');
    if (empty($locataireNom) && !empty($locataires)) {
        $firstLocataire = $locataires[0];
        $locataireNom = htmlspecialchars(trim(($firstLocataire['prenom'] ?? '') . ' ' . ($firstLocataire['nom'] ?? '')));
    }
    
    // Fetch entry inventory data for exit inventories
    $entree_inventory_data = [];
    if (($inventaire['type'] ?? 'entree') === 'sortie' && !empty($inventaire['contrat_id'])) {
        try {
            $stmt = $pdo->prepare("
                SELECT equipements_data 
                FROM inventaires 
                WHERE contrat_id = ? AND type = 'entree' 
                ORDER BY date_inventaire DESC 
                LIMIT 1
            ");
            $stmt->execute([$inventaire['contrat_id']]);
            $entree_inventory = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($entree_inventory && !empty($entree_inventory['equipements_data'])) {
                $entree_items = json_decode($entree_inventory['equipements_data'], true);
                if (is_array($entree_items)) {
                    // Index by item ID for easy lookup
                    foreach ($entree_items as $item) {
                        if (isset($item['id'])) {
                            $entree_inventory_data[$item['id']] = $item;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Failed to fetch entry inventory for exit PDF: " . $e->getMessage());
        }
    }
    
    // Equipements list - Build HTML table/list with entry data if available
    $equipementsHtml = buildEquipementsHtml($inventaire, null, $entree_inventory_data);
    
    // Observations générales
    $observations = trim($inventaire['observations_generales'] ?? '');
    $observations = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $observations);
    $observationsEscaped = str_replace("\n", '<br>', htmlspecialchars($observations));
    
    if (empty($observations)) {
        $observationsEscaped = 'Aucune observation particulière.';
    }
    
    // Lieu de signature
    $lieuSignature = htmlspecialchars(!empty($inventaire['lieu_signature']) ? $inventaire['lieu_signature'] : ($config['DEFAULT_SIGNATURE_LOCATION'] ?? 'Annemasse'));
    
    // Build signatures table
    $signaturesTable = buildSignaturesTableInventaire($inventaire, $locataires);
    
    // Prepare variable replacements
    $vars = [
        '{{reference}}' => $reference,
        '{{type}}' => 'inventaire', // Generic type
        '{{type_label}}' => '', // No longer needed
        '{{date}}' => $dateInventaire,
        '{{date_inventaire}}' => $dateInventaire,
        '{{adresse}}' => $adresse,
        '{{bailleur_nom}}' => $bailleurNom,
        '{{bailleur_representant}}' => $bailleurRepresentant,
        '{{locataire_nom}}' => $locataireNom,
        '{{equipements}}' => $equipementsHtml,
        '{{observations}}' => $observationsEscaped,
        '{{lieu_signature}}' => $lieuSignature,
        '{{date_signature}}' => $dateSignature,
        '{{signatures_table}}' => $signaturesTable,
        // Remove sortie-specific variables (no longer used)
        '{{comparaison}}' => '',
        '{{comparaison_section}}' => '',
        '{{equipements_manquants_section}}' => '',
        '{{depot_garantie_section}}' => '',
    ];
    
    // Handle conditional rows
    $vars['{{appartement_row}}'] = '';
    
    if (!empty($bailleurRepresentant)) {
        $vars['{{bailleur_representant_row}}'] = '<div class="info-row"><span class="info-label">Représenté par :</span><span class="info-value">' . $bailleurRepresentant . '</span></div>';
    } else {
        $vars['{{bailleur_representant_row}}'] = '';
    }
    
    // Replace all variables
    $html = str_replace(array_keys($vars), array_values($vars), $template);
    
    return $html;
}

/**
 * Get checkbox symbol for PDF display
 * 
 * @param bool $checked Whether the checkbox is checked
 * @return string Checkbox symbol (☑ or ☐)
 */
function getCheckboxSymbol($checked) {
    // Use HTML entities for better compatibility across different systems
    return !empty($checked) ? '&#9745;' : '&#9744;';
}

/**
 * Get quantity value for PDF display
 * Ensures empty values stay empty instead of showing 0
 * 
 * @param mixed $value The quantity value from data
 * @return string|int Empty string if not set, integer if set
 */
function getQuantityValue($value) {
    return isset($value) && $value !== '' ? (int)$value : '';
}

/**
 * Get equipment quantity with backward compatibility
 * Supports new simplified structure and old entry/exit structure
 * 
 * @param array $eq Equipment item data
 * @return mixed Quantity value (may be empty string or integer)
 */
function getEquipmentQuantity($eq) {
    if (isset($eq['nombre'])) {
        // New simplified structure
        return $eq['nombre'];
    } elseif (isset($eq['entree']['nombre'])) {
        // Old structure with entree/sortie
        return $eq['entree']['nombre'];
    } elseif (isset($eq['quantite_presente'])) {
        // Legacy format
        return $eq['quantite_presente'];
    } elseif (isset($eq['quantite_attendue'])) {
        // Legacy format fallback
        return $eq['quantite_attendue'];
    }
    return '';
}

/**
 * Construire le HTML pour la liste des équipements (structure simplifiée)
 * 
 * @param array $inventaire Données de l'inventaire
 * @param string $type Type d'inventaire (deprecated, kept for compatibility)
 * @param array $entree_data Optional entry inventory data for exit inventories
 * @return string HTML pour les équipements
 */
function buildEquipementsHtml($inventaire, $type = null, $entree_data = []) {
    global $pdo;
    
    $equipements_data = json_decode($inventaire['equipements_data'] ?? '[]', true);
    
    if (!is_array($equipements_data) || empty($equipements_data)) {
        return '<p><em>Aucun équipement enregistré.</em></p>';
    }
    
    // Determine if this is an exit inventory
    $isExitInventory = ($inventaire['type'] ?? 'entree') === 'sortie';
    
    // Fetch category order from database for proper sorting
    // Note: This query is executed per PDF generation. Category order is relatively static
    // and could be cached if performance becomes an issue in the future.
    $category_order = [];
    try {
        $stmt = $pdo->query("SELECT nom, ordre FROM inventaire_categories ORDER BY ordre ASC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $category_order[$row['nom']] = (int)$row['ordre'];
        }
    } catch (Exception $e) {
        error_log("Failed to fetch category order: " . $e->getMessage());
    }
    
    // Group by category and subcategory
    $equipements_by_category = [];
    foreach ($equipements_data as $eq) {
        $cat = $eq['categorie'] ?? 'Autre';
        $subcat = $eq['sous_categorie'] ?? null;
        
        if (!isset($equipements_by_category[$cat])) {
            $equipements_by_category[$cat] = [
                '_items' => [],
                '_subcategories' => []
            ];
        }
        
        if ($subcat) {
            // Has subcategory (like État des pièces)
            if (!isset($equipements_by_category[$cat]['_subcategories'][$subcat])) {
                $equipements_by_category[$cat]['_subcategories'][$subcat] = [];
            }
            $equipements_by_category[$cat]['_subcategories'][$subcat][] = $eq;
        } else {
            // Direct category items
            $equipements_by_category[$cat]['_items'][] = $eq;
        }
    }
    
    // Sort categories by their ordre field from database
    uksort($equipements_by_category, function($a, $b) use ($category_order) {
        $orderA = $category_order[$a] ?? 999;
        $orderB = $category_order[$b] ?? 999;
        if ($orderA === $orderB) {
            return strcmp($a, $b); // Alphabetical if same order
        }
        return $orderA - $orderB;
    });
    
    $html = '';
    
    foreach ($equipements_by_category as $categorie => $categoryData) {
        $html .= '<h4 style="margin-top: 10px; margin-bottom: 8px; color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 5px;">' . htmlspecialchars($categorie) . '</h4>';
        
        // Render subcategories first (if any)
        if (!empty($categoryData['_subcategories'])) {
            foreach ($categoryData['_subcategories'] as $subcategorie => $equipements) {
                $html .= '<h5 style="margin-top: 10px; margin-bottom: 6px; color: #34495e; font-size: 12px; font-weight: 600;">' . htmlspecialchars($subcategorie) . '</h5>';
                $html .= renderEquipementsTable($equipements, $type, $isExitInventory, $entree_data);
            }
        }
        
        // Render direct items (if any)
        if (!empty($categoryData['_items'])) {
            $html .= renderEquipementsTable($categoryData['_items'], $type, $isExitInventory, $entree_data);
        }
    }
    
    return $html;
}

/**
 * Generate table header HTML for inventory equipment table
 * Simplified to 3 columns: Élément, Nombre, Commentaire
 * For exit inventories: 4 columns: Élément, Qté Entrée, Nombre, Commentaire
 * @param bool $isExitInventory Whether this is an exit inventory
 * @return string HTML for table header
 */
function getInventoryTableHeader($isExitInventory = false) {
    $html = '<tr style="background-color:#3498db; color:#FFFFFF;">';
    
    if ($isExitInventory) {
        // Exit inventory: 4 columns with entry reference
        // Élément (65mm) + Qté Entrée (30mm) + Nombre (30mm) + Commentaires (65mm) ≈ 190mm
        $html .= '<td style="border:1px solid #ddd; padding:6px; width:65mm; font-size:10px; background-color:#3498db; color:#FFFFFF; text-align:left; vertical-align:middle; font-weight:bold;">Élément</td>';
        $html .= '<td style="border:1px solid #ddd; padding:6px; width:30mm; font-size:10px; background-color:#95a5a6; color:#FFFFFF; text-align:center; vertical-align:middle; font-weight:bold;">Qté Entrée</td>';
        $html .= '<td style="border:1px solid #ddd; padding:6px; width:30mm; font-size:10px; background-color:#3498db; color:#FFFFFF; text-align:center; vertical-align:middle; font-weight:bold;">Qté Sortie</td>';
        $html .= '<td style="border:1px solid #ddd; padding:6px; width:65mm; font-size:10px; background-color:#3498db; color:#FFFFFF; text-align:left; vertical-align:middle; font-weight:bold;">Commentaire</td>';
    } else {
        // Entry inventory: 3 columns
        // Élément (80mm) + Nombre (30mm) + Commentaires (80mm) ≈ 190mm
        $html .= '<td style="border:1px solid #ddd; padding:6px; width:80mm; font-size:10px; background-color:#3498db; color:#FFFFFF; text-align:left; vertical-align:middle; font-weight:bold;">Élément</td>';
        $html .= '<td style="border:1px solid #ddd; padding:6px; width:30mm; font-size:10px; background-color:#3498db; color:#FFFFFF; text-align:center; vertical-align:middle; font-weight:bold;">Nombre</td>';
        $html .= '<td style="border:1px solid #ddd; padding:6px; width:80mm; font-size:10px; background-color:#3498db; color:#FFFFFF; text-align:left; vertical-align:middle; font-weight:bold;">Commentaire</td>';
    }
    
    $html .= '</tr>';
    return $html;
}

/**
 * Render equipment table for PDF - simplified to 3 columns (or 4 for exit)
 */
function renderEquipementsTable($equipements, $type, $isExitInventory = false, $entree_data = []) {
    $html = '<table cellspacing="0" cellpadding="2" border="0" style="width: 100%; margin-bottom: 5px; font-size: 9px;">';
    $html .= getInventoryTableHeader($isExitInventory);
    $html .= '<tbody>';
    
    foreach ($equipements as $eq) {
        $nom = htmlspecialchars($eq['nom'] ?? '');
        $itemId = $eq['id'] ?? null;
        
        // Get quantity - use helper function for backward compatibility
        $nombre = getQuantityValue(getEquipmentQuantity($eq));
        
        // Get entry quantity for exit inventories
        $entreeQty = '';
        if ($isExitInventory && $itemId && isset($entree_data[$itemId])) {
            $entreeQty = getQuantityValue(getEquipmentQuantity($entree_data[$itemId]));
        }
        
        // Comments
        $commentaires = htmlspecialchars($eq['commentaires'] ?? $eq['observations'] ?? '');
        
        $html .= '<tr style="background-color: #ffffff;">';
        
        if ($isExitInventory) {
            // Exit inventory: 4 columns
            $html .= '<td style="border: 1px solid #ddd; padding: 2px 6px; font-size: 10px; text-align: left; vertical-align: top;">' . $nom . '</td>';
            $html .= '<td style="border: 1px solid #ddd; padding: 2px 6px; text-align: center; font-size: 10px; vertical-align: middle; background-color: #ecf0f1; color: #7f8c8d;">' . ($entreeQty !== '' ? $entreeQty : '—') . '</td>';
            $html .= '<td style="border: 1px solid #ddd; padding: 2px 6px; text-align: center; font-size: 10px; vertical-align: middle;">' . $nombre . '</td>';
            $html .= '<td style="border: 1px solid #ddd; padding: 2px 6px; font-size: 10px; text-align: left; vertical-align: top;">' . $commentaires . '</td>';
        } else {
            // Entry inventory: 3 columns
            $html .= '<td style="border: 1px solid #ddd; padding: 2px 6px; font-size: 10px; text-align: left; vertical-align: top;">' . $nom . '</td>';
            $html .= '<td style="border: 1px solid #ddd; padding: 2px 6px; text-align: center; font-size: 10px; vertical-align: middle;">' . $nombre . '</td>';
            $html .= '<td style="border: 1px solid #ddd; padding: 2px 6px; font-size: 10px; text-align: left; vertical-align: top;">' . $commentaires . '</td>';
        }
        
        $html .= '</tr>';
    }
    
    $html .= '</tbody>';
    $html .= '</table>';
    
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
 * Convert base64 signature to physical file for inventaire
 * Returns file path or original data if conversion fails
 */
function convertInventaireSignatureToPhysicalFile($signatureData, $prefix, $inventaireId, $locataireId = null) {
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
    $suffix = $locataireId ? "_locataire_{$locataireId}" : "";
    $filename = "{$prefix}_inventaire_{$inventaireId}{$suffix}_{$timestamp}.jpg";
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
 * Construire le tableau de signatures pour l'inventaire
 * 
 * @param array $inventaire Données de l'inventaire
 * @param array $locataires Liste des locataires
 * @return string HTML du tableau de signatures
 */
function buildSignaturesTableInventaire($inventaire, $locataires) {
    global $pdo, $config;
    
    $nbCols = count($locataires) + 1; // +1 for landlord
    
    // Guard against edge case of no locataires
    if ($nbCols <= 0) {
        error_log("WARNING: buildSignaturesTableInventaire called with no locataires");
        $nbCols = 1; // Minimum 1 column for landlord
    }
    
    // Calculate column width percentage for consistent sizing
    // Use exact division for TCPDF - ensures columns sum to 100%
    $colWidthPercent = number_format(100 / $nbCols, 2, '.', '');

    // Build signature table with proper TCPDF-compatible structure
    // Using HTML attributes instead of inline CSS for better TCPDF compatibility
    $tableStyle = 'width: 100%; margin-top: 20px; text-align: center;';
    $cellStyle = 'width: ' . $colWidthPercent . '%; vertical-align: top; text-align: center; padding: 12px 8px;';
    
    $html = '<table cellspacing="0" cellpadding="4" border="0" style="' . $tableStyle . '">'
        . '<tbody><tr>';

    // Landlord column
    $html .= '<td style="' . $cellStyle . '">';
    $html .= '<p style="margin: 5px 0; font-weight: bold;"><strong>Le bailleur :</strong></p>';
    
    // Get landlord signature from parametres - fetch both in one query using COALESCE
    $stmt = $pdo->prepare("
        SELECT COALESCE(
            (SELECT valeur FROM parametres WHERE cle = 'signature_societe_inventaire_image' LIMIT 1),
            (SELECT valeur FROM parametres WHERE cle = 'signature_societe_image' LIMIT 1)
        ) AS signature_path
    ");
    $stmt->execute();
    $landlordSigPath = $stmt->fetchColumn();

    if (!empty($landlordSigPath)) {
        $inventaireId = $inventaire['id'] ?? 0;
        $originalLandlordSig = $landlordSigPath; // Store original for comparison
        
        // Convert base64 to physical file if needed
        $landlordSigPath = convertInventaireSignatureToPhysicalFile($landlordSigPath, 'landlord', $inventaireId);

        // Normalize path: company signatures are stored as plain filenames in uploads/
        $landlordSigPath = normalizeCompanySignaturePath($landlordSigPath);
        $isFilePath = strpos($landlordSigPath, 'uploads/') === 0;
        
        // Update database with physical path if it was converted (check by comparing with original)
        if ($isFilePath && !empty($inventaireId) && $landlordSigPath !== $originalLandlordSig &&
            preg_match('/^data:image/', $originalLandlordSig)) {
            // Only update if signature was actually converted
            $paramKey = 'signature_societe_inventaire_image';
            $updateStmt = $pdo->prepare("UPDATE parametres SET valeur = ? WHERE cle = ?");
            $updateStmt->execute([$landlordSigPath, $paramKey]);
            error_log("✓ Updated landlord signature in database to physical file");
        }
        
        if ($isFilePath) {
            // Verify file exists before adding to PDF
            $fullPath = dirname(__DIR__) . '/' . $landlordSigPath;
            if (file_exists($fullPath)) {
                // Use public URL for signature image with no-border styling
                $publicUrl = rtrim($config['SITE_URL'], '/') . '/' . ltrim($landlordSigPath, '/');
                $html .= '<img src="' . htmlspecialchars($publicUrl) . '" alt="Signature Bailleur" border="0" width="80">';
            } else {
                error_log("Landlord signature file not found: $fullPath");
            }
        } else {
            // Still base64 after conversion attempt - use as fallback but log warning
            error_log("WARNING: Using base64 signature for landlord (conversion may have failed)");
            $html .= '<img src="' . htmlspecialchars($landlordSigPath) . '" alt="Signature Bailleur" border="0" width="80">';
        }
    }
    
    $placeSignature = !empty($inventaire['lieu_signature']) ? htmlspecialchars($inventaire['lieu_signature']) : htmlspecialchars($config['DEFAULT_SIGNATURE_LOCATION'] ?? 'Annemasse');
    $html .= '<p style="font-size: 8pt; margin: 5px 0 3px 0;"><br>&nbsp;<br>&nbsp;<br>Fait à ' . $placeSignature . '</p>';
    
    if (!empty($inventaire['date_inventaire'])) {
        $signDate = date('d/m/Y', strtotime($inventaire['date_inventaire']));
        $html .= '<p style="font-size: 8pt; margin: 3px 0;">Le ' . $signDate . '</p>';
    }
    
    $html .= '<p style="font-size: 9pt; margin: 5px 0; font-weight: bold;">' . htmlspecialchars($inventaire['bailleur_nom'] ?? $config['COMPANY_NAME']) . '</p>';
    $html .= '</td>';

    // Tenant columns - iterate through each tenant with unique signature
    foreach ($locataires as $idx => $tenantInfo) {
        // Log tenant being processed for debugging signature issues
        $tenantDbId = $tenantInfo['id'] ?? 'NULL';
        error_log("PDF: Processing tenant $idx - DB_ID: $tenantDbId, Name: " . ($tenantInfo['prenom'] ?? '') . ' ' . ($tenantInfo['nom'] ?? ''));
        
        $html .= '<td style="' . $cellStyle . '">';

        $tenantLabel = ($nbCols === 2) ? 'Locataire :' : 'Locataire ' . ($idx + 1) . ' :';
        $html .= '<p style="margin: 5px 0; font-weight: bold;"><strong>' . $tenantLabel . '</strong></p>';

        // Display tenant signature if available
        if (!empty($tenantInfo['signature'])) {
            $originalSignature = $tenantInfo['signature'];
            $signatureData = $originalSignature;
            $tenantDbId = $tenantInfo['id'] ?? null;
            $inventaireId = $inventaire['id'] ?? 0;
            
            // Log signature processing for this specific tenant
            error_log("PDF: Tenant $idx (DB ID: $tenantDbId) has signature: " . substr($originalSignature, 0, 50) . "...");
            
            // Convert base64 to physical file if needed
            $signatureData = convertInventaireSignatureToPhysicalFile($signatureData, 'tenant', $inventaireId, $tenantDbId);
            
            // Update database if signature was actually converted (avoid race conditions by checking conversion occurred)
            if ($tenantDbId && $signatureData !== $originalSignature && 
                preg_match('/^data:image/', $originalSignature) && 
                preg_match('/^uploads\/signatures\//', $signatureData)) {
                $updateStmt = $pdo->prepare("UPDATE inventaire_locataires SET signature = ? WHERE id = ? AND signature = ?");
                $updateStmt->execute([$signatureData, $tenantDbId, $originalSignature]);
                if ($updateStmt->rowCount() > 0) {
                    error_log("✓ PDF: Updated tenant $idx (DB ID: $tenantDbId) signature in database to physical file");
                }
            }
            
            if (preg_match('/^uploads\/signatures\//', $signatureData)) {
                // File path format - verify file exists before using public URL
                $fullPath = dirname(__DIR__) . '/' . $signatureData;
                if (file_exists($fullPath)) {
                    // Use public URL with no-border styling
                    $publicUrl = rtrim($config['SITE_URL'], '/') . '/' . ltrim($signatureData, '/');
                    $html .= '<img src="' . htmlspecialchars($publicUrl) . '" alt="Signature Locataire ' . ($idx + 1) . '" border="0" style="' 
                        . INVENTAIRE_SIGNATURE_IMG_STYLE . '">';
                    error_log("PDF: Displaying signature image for tenant $idx from: $signatureData");
                } else {
                    error_log("PDF: Tenant $idx signature file not found: $fullPath");
                }
            } else {
                // Still base64 after conversion attempt - use as fallback but log warning
                error_log("WARNING: PDF: Using base64 signature for tenant $idx (conversion may have failed)");
                $html .= '<img src="' . htmlspecialchars($signatureData) . '" alt="Signature Locataire ' . ($idx + 1) . '" border="0" style="' 
                    . INVENTAIRE_SIGNATURE_IMG_STYLE . '">';
            }
            
            if (!empty($tenantInfo['date_signature'])) {
                $signDate = date('d/m/Y à H:i', strtotime($tenantInfo['date_signature']));
                $html .= '<p style="font-size: 8pt; margin: 5px 0 3px 0;"><br>&nbsp;<br>&nbsp;<br>Signé le ' . $signDate . '</p>';
            }
            
            // Display "Certifié exact" checkbox status
            if (!empty($tenantInfo['certifie_exact'])) {
                $html .= '<p style="font-size: 8pt; margin: 3px 0;">Certifié exact</p>';
            }
        } else {
            // No signature for this tenant
            error_log("PDF: Tenant $idx (DB_ID: $tenantDbId) has NO signature");
        }

        $tenantName = htmlspecialchars(trim(($tenantInfo['prenom'] ?? '') . ' ' . ($tenantInfo['nom'] ?? '')));
        $html .= '<p style="font-size: 9pt; margin: 5px 0; font-weight: bold;">' . $tenantName . '</p>';
        $html .= '</td>';
    }

    $html .= '</tr></tbody></table>';
    
    return $html;
}
