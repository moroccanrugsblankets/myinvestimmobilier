<?php
/**
 * Edit Inventaire - Dynamic equipment loading from database
 * Equipment is loaded from inventaire_equipements table based on logement_id
 * Falls back to standard items if no equipment is defined for the logement
 * Enhanced interface with Entry/Exit grid and subcategory organization
 */
require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/inventaire-standard-items.php';

$inventaire_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$inventaire_id) {
    $_SESSION['error'] = "Inventaire non spécifié";
    header('Location: inventaires.php');
    exit;
}

// Get inventaire data
$stmt = $pdo->prepare("
    SELECT inv.*, 
           l.reference as logement_reference,
           l.type as logement_type
    FROM inventaires inv
    INNER JOIN logements l ON inv.logement_id = l.id
    WHERE inv.id = ?
");
$stmt->execute([$inventaire_id]);
$inventaire = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$inventaire) {
    $_SESSION['error'] = "Inventaire introuvable";
    header('Location: inventaires.php');
    exit;
}

// If this is an exit inventory, fetch the related entry inventory for reference
$entree_inventory_data = [];
if ($inventaire['type'] === 'sortie' && !empty($inventaire['contrat_id'])) {
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
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Process standardized inventory items - simplified structure
        $equipements_data = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $itemData) {
                $equipements_data[] = [
                    'id' => (int)$itemData['id'],
                    'categorie' => $itemData['categorie'] ?? '',
                    'sous_categorie' => $itemData['sous_categorie'] ?? null,
                    'nom' => $itemData['nom'] ?? '',
                    'type' => $itemData['type'] ?? 'item',
                    'nombre' => isset($itemData['nombre']) && $itemData['nombre'] !== '' ? (int)$itemData['nombre'] : null,
                    'commentaires' => $itemData['commentaires'] ?? ''
                ];
            }
        }
        
        $stmt = $pdo->prepare("
            UPDATE inventaires SET
                equipements_data = ?,
                observations_generales = ?,
                lieu_signature = ?,
                date_inventaire = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $date_inventaire_input = $_POST['date_inventaire'] ?? $inventaire['date_inventaire'];
        $d = DateTime::createFromFormat('Y-m-d', $date_inventaire_input);
        $date_inventaire_val = ($d && $d->format('Y-m-d') === $date_inventaire_input) ? $date_inventaire_input : $inventaire['date_inventaire'];

        $stmt->execute([
            json_encode($equipements_data, JSON_UNESCAPED_UNICODE),
            $_POST['observations_generales'] ?? null,
            $_POST['lieu_signature'] ?? null,
            $date_inventaire_val,
            $inventaire_id
        ]);
        
        // Update tenant signatures - array key is now the loop index, extract DB ID from hidden field
        if (isset($_POST['tenants']) && is_array($_POST['tenants'])) {
            // First, validate that all tenants have db_id field
            $missingDbIds = [];
            foreach ($_POST['tenants'] as $tenantIndex => $tenantInfo) {
                if (!isset($tenantInfo['db_id']) || $tenantInfo['db_id'] === '') {
                    $missingDbIds[] = $tenantIndex;
                    error_log("WARNING: Tenant at index $tenantIndex is missing db_id field");
                }
            }
            
            if (!empty($missingDbIds)) {
                throw new Exception("Données de locataire incomplètes (indices: " . implode(', ', $missingDbIds) . "). Veuillez réessayer.");
            }
            
            foreach ($_POST['tenants'] as $tenantIndex => $tenantInfo) {
                // Extract the database ID from the hidden field (not from array key)
                $tenantId = (int)$tenantInfo['db_id'];
                
                // Log for debugging
                error_log("Processing tenant at index $tenantIndex with DB ID $tenantId");
                
                // Only update certifie_exact if finalizing (not for draft saves)
                // For draft saves, we preserve the existing value unless explicitly checked
                // For finalize, we always update it (validation ensures it's checked)
                $isFinalizing = isset($_POST['finalize']) && $_POST['finalize'] === '1';
                
                if ($isFinalizing) {
                    // Update certifie_exact status (only for finalize)
                    $certifieExact = isset($tenantInfo['certifie_exact']) ? 1 : 0;
                    
                    $stmt = $pdo->prepare("
                        UPDATE inventaire_locataires 
                        SET certifie_exact = ?
                        WHERE id = ? AND inventaire_id = ?
                    ");
                    $stmt->execute([$certifieExact, $tenantId, $inventaire_id]);
                } else {
                    // For draft saves, only update if explicitly checked (preserve existing value otherwise)
                    if (isset($tenantInfo['certifie_exact'])) {
                        $stmt = $pdo->prepare("
                            UPDATE inventaire_locataires 
                            SET certifie_exact = 1
                            WHERE id = ? AND inventaire_id = ?
                        ");
                        $stmt->execute([$tenantId, $inventaire_id]);
                    }
                }
                
                // Update signature if provided
                if (!empty($tenantInfo['signature'])) {
                    // Validate signature format (expected: data:image/(jpeg|jpg|png);base64,<base64_data>)
                    if (!preg_match('/^data:image\/(jpeg|jpg|png);base64,[A-Za-z0-9+\/=]+$/', $tenantInfo['signature'])) {
                        error_log("Invalid signature format for tenant index $tenantIndex (DB ID: $tenantId) - Expected: data:image/jpeg;base64,...");
                        continue;
                    }
                    
                    // Log signature save attempt for debugging
                    error_log("SAVE: Attempting to save signature for tenant index $tenantIndex (DB ID: $tenantId), inventaire: $inventaire_id");
                    error_log("SAVE: Signature data length: " . strlen($tenantInfo['signature']) . " bytes");
                    
                    // Use the helper function from functions.php
                    $result = updateInventaireTenantSignature($tenantId, $tenantInfo['signature'], $inventaire_id);
                    
                    if (!$result) {
                        error_log("SAVE: ❌ Failed to save signature for tenant index $tenantIndex (DB ID: $tenantId)");
                    } else {
                        error_log("SAVE: ✓ Successfully saved signature for tenant index $tenantIndex (DB ID: $tenantId)");
                    }
                }

                // Handle pièce d'identité uploads (recto/verso)
                foreach (['recto', 'verso'] as $side) {
                    $fileKey = "tenant_piece_identite_{$side}_{$tenantId}";
                    if (!empty($_FILES[$fileKey]['name']) && $_FILES[$fileKey]['error'] !== UPLOAD_ERR_NO_FILE) {
                        $validation = validateUploadedFile($_FILES[$fileKey]);
                        if ($validation['success']) {
                            $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
                            $filename = 'inventaire_' . $inventaire_id . '_locataire_' . $tenantId . '_' . $side . '_' . time() . '.' . $ext;
                            if (saveUploadedFile($_FILES[$fileKey], $filename)) {
                                // Whitelist column name to prevent SQL injection
                                $col = ($side === 'recto') ? 'piece_identite_recto' : 'piece_identite_verso';
                                $stmt = $pdo->prepare("UPDATE inventaire_locataires SET {$col} = ? WHERE id = ? AND inventaire_id = ?");
                                $stmt->execute([$filename, $tenantId, $inventaire_id]);
                            } else {
                                error_log("Failed to save piece_identite_{$side} for inventaire_locataire ID: $tenantId");
                            }
                        } else {
                            error_log("Invalid piece_identite_{$side} upload for tenant index $tenantIndex (DB ID: $tenantId): " . $validation['error']);
                        }
                    }
                }
            }
        }
        
        $pdo->commit();
        $_SESSION['success'] = "Inventaire mis à jour avec succès";
        
        // If finalizing, validate before redirecting
        if (isset($_POST['finalize']) && $_POST['finalize'] === '1') {
            // Re-fetch tenants from DB to get current piece_identite_recto status
            $stmtCheck = $pdo->prepare("SELECT id, nom, prenom, signature, piece_identite_recto, certifie_exact FROM inventaire_locataires WHERE inventaire_id = ?");
            $stmtCheck->execute([$inventaire_id]);
            $tenantsForCheck = $stmtCheck->fetchAll(PDO::FETCH_ASSOC);
            $finalizeErrors = [];
            foreach ($tenantsForCheck as $t) {
                $tenantName = htmlspecialchars(trim($t['prenom'] . ' ' . $t['nom']), ENT_QUOTES, 'UTF-8');
                if (empty($t['signature'])) {
                    $finalizeErrors[] = "Signature manquante pour " . $tenantName;
                }
                if (empty($t['piece_identite_recto'])) {
                    $finalizeErrors[] = "Pièce d'identité (recto) manquante pour " . $tenantName;
                }
                if (empty($t['certifie_exact'])) {
                    $finalizeErrors[] = "La case \"Certifié exact\" doit être cochée pour " . $tenantName;
                }
            }
            if (!empty($finalizeErrors)) {
                $_SESSION['error'] = "Impossible de finaliser l'inventaire :<br>" . implode("<br>", $finalizeErrors);
                header("Location: edit-inventaire.php?id=" . urlencode((string)$inventaire_id));
                exit;
            }
            header("Location: finalize-inventaire.php?id=$inventaire_id");
            exit;
        }
        
        header("Location: edit-inventaire.php?id=$inventaire_id");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erreur lors de la mise à jour: " . $e->getMessage();
        error_log("Erreur update inventaire: " . $e->getMessage());
    }
}

// Load equipment from database for this logement
// First, try to get equipment defined specifically for this logement
$stmt = $pdo->prepare("
    SELECT e.*, 
           c.nom as categorie_nom, 
           c.icone as categorie_icone,
           sc.nom as sous_categorie_nom
    FROM inventaire_equipements e
    LEFT JOIN inventaire_categories c ON e.categorie_id = c.id
    LEFT JOIN inventaire_sous_categories sc ON e.sous_categorie_id = sc.id
    WHERE e.logement_id = ? 
    ORDER BY COALESCE(c.ordre, 999), e.ordre, e.nom
");
$stmt->execute([$inventaire['logement_id']]);
$logement_equipements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Transform database equipment into the structure expected by the view
// The view expects: $standardItems[$categoryName][$subcategoryName][] = ['nom' => '...', 'type' => '...']
// or $standardItems[$categoryName][] = ['nom' => '...', 'type' => '...'] for categories without subcategories
$standardItems = [];

if (!empty($logement_equipements)) {
    // Use equipment from database
    foreach ($logement_equipements as $eq) {
        $categoryName = $eq['categorie_nom'] ?: $eq['categorie']; // Use category name from join or fallback to text field
        $subcategoryName = $eq['sous_categorie_nom'];
        
        $item = [
            'nom' => $eq['nom'],
            // Note: Database equipment defaults to 'countable' type.
            // The type field is used by the view to determine rendering behavior.
            // All equipment from the database is treated as countable.
            'type' => 'countable'
        ];
        
        if ($subcategoryName) {
            // Equipment has a subcategory
            if (!isset($standardItems[$categoryName])) {
                $standardItems[$categoryName] = [];
            }
            if (!isset($standardItems[$categoryName][$subcategoryName])) {
                $standardItems[$categoryName][$subcategoryName] = [];
            }
            $standardItems[$categoryName][$subcategoryName][] = $item;
        } else {
            // Equipment without subcategory
            if (!isset($standardItems[$categoryName])) {
                $standardItems[$categoryName] = [];
            }
            $standardItems[$categoryName][] = $item;
        }
    }
} else {
    // Fallback to standard items if no equipment defined for this logement
    $standardItems = getStandardInventaireItems($inventaire['logement_reference']);
}

// Generate initial inventory data structure from equipment
// This will be used to initialize the form if no saved data exists
function generateInventoryDataFromEquipment($standardItems) {
    $data = [];
    $itemIndex = 0;
    
    // Simplified structure - only element, nombre, commentaire
    foreach ($standardItems as $categoryName => $categoryItems) {
        foreach ($categoryItems as $item) {
            $data[] = [
                'id' => ++$itemIndex,
                'categorie' => $categoryName,
                'sous_categorie' => null,
                'nom' => $item['nom'],
                'type' => $item['type'],
                'nombre' => $item['quantite'] ?? 0,
                'commentaires' => ''
            ];
        }
    }
    
    return $data;
}

// Decode equipment data
$equipements_data = json_decode($inventaire['equipements_data'], true);
if (!is_array($equipements_data)) {
    $equipements_data = [];
}

// If no data exists, generate from equipment (database or standard items)
if (empty($equipements_data)) {
    $equipements_data = generateInventoryDataFromEquipment($standardItems);
}

// Index existing data by ID for quick lookup
$existing_data_by_id = [];
foreach ($equipements_data as $item) {
    if (isset($item['id'])) {
        $existing_data_by_id[$item['id']] = $item;
    }
}

// Get existing tenants for this inventaire - JOIN with locataires to always show up-to-date names
$stmt = $pdo->prepare("
    SELECT il.*,
           COALESCE(l.nom, il.nom) as nom,
           COALESCE(l.prenom, il.prenom) as prenom,
           COALESCE(l.email, il.email) as email
    FROM inventaire_locataires il
    LEFT JOIN locataires l ON il.locataire_id = l.id
    WHERE il.inventaire_id = ?
    ORDER BY il.id ASC
");
$stmt->execute([$inventaire_id]);
$existing_tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// DEFENSIVE: Check for and remove duplicate tenant records (same inventaire_id + locataire_id)
// This handles data corruption or race conditions
// NOTE: Tenants are fetched with ORDER BY id ASC, so we process them from oldest to newest
// When a duplicate is found, we keep the first occurrence (oldest) and mark later ones for deletion
$seen_locataire_ids = [];
$duplicate_ids_to_remove = [];

foreach ($existing_tenants as $tenant) {
    $locataire_id = $tenant['locataire_id'];
    
    // If we've already seen this locataire_id for this inventory, mark it as a duplicate
    // The first occurrence (smallest ID) was already added to $seen_locataire_ids, so this is a duplicate
    if ($locataire_id && isset($seen_locataire_ids[$locataire_id])) {
        $duplicate_ids_to_remove[] = $tenant['id'];
        error_log("DUPLICATE TENANT DETECTED: inventaire_locataires id={$tenant['id']}, locataire_id=$locataire_id, inventaire_id=$inventaire_id (will be removed, keeping oldest record)");
    } else if ($locataire_id) {
        $seen_locataire_ids[$locataire_id] = $tenant['id']; // Track the ID we're keeping
    }
}

// Remove duplicates if any found
if (!empty($duplicate_ids_to_remove)) {
    error_log("Soft deleting " . count($duplicate_ids_to_remove) . " duplicate tenant records for inventaire_id=$inventaire_id");
    $placeholders = implode(',', array_fill(0, count($duplicate_ids_to_remove), '?'));
    // Soft delete duplicates (set deleted_at timestamp instead of DELETE)
    $deleteStmt = $pdo->prepare("UPDATE inventaire_locataires SET deleted_at = NOW() WHERE id IN ($placeholders) AND inventaire_id = ? AND deleted_at IS NULL");
    $params = array_merge($duplicate_ids_to_remove, [$inventaire_id]);
    $deleteStmt->execute($params);
    
    // Reload tenants after cleanup (exclude soft-deleted) - JOIN with locataires for fresh names
    $stmt = $pdo->prepare("
        SELECT il.*,
               COALESCE(l.nom, il.nom) as nom,
               COALESCE(l.prenom, il.prenom) as prenom,
               COALESCE(l.email, il.email) as email
        FROM inventaire_locataires il
        LEFT JOIN locataires l ON il.locataire_id = l.id
        WHERE il.inventaire_id = ? AND il.deleted_at IS NULL
        ORDER BY il.id ASC
    ");
    $stmt->execute([$inventaire_id]);
    $existing_tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("After cleanup: " . count($existing_tenants) . " unique tenant(s) remain for inventaire_id=$inventaire_id");
}

// If no tenants linked yet, auto-populate from contract (if inventaire is linked to a contract)
if (empty($existing_tenants) && !empty($inventaire['contrat_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC");
    $stmt->execute([$inventaire['contrat_id']]);
    $contract_tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get existing tenant-inventaire relationships to avoid duplicate inserts
    $existingLinkStmt = $pdo->prepare("
        SELECT locataire_id FROM inventaire_locataires 
        WHERE inventaire_id = ?
    ");
    $existingLinkStmt->execute([$inventaire_id]);
    $existing_tenant_ids = $existingLinkStmt->fetchAll(PDO::FETCH_COLUMN);
    $existing_tenant_lookup = array_flip($existing_tenant_ids); // Convert to associative array for O(1) lookup
    
    // Insert tenants into inventaire_locataires with duplicate check
    $insertStmt = $pdo->prepare("
        INSERT INTO inventaire_locataires (inventaire_id, locataire_id, nom, prenom, email)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    foreach ($contract_tenants as $tenant) {
        // Check if this tenant is already linked to this inventaire using in-memory map
        if (!isset($existing_tenant_lookup[$tenant['id']])) {
            try {
                error_log("Inserting tenant into inventaire_locataires: inventaire_id=$inventaire_id, locataire_id={$tenant['id']}, name={$tenant['prenom']} {$tenant['nom']}");
                $insertStmt->execute([
                    $inventaire_id,
                    $tenant['id'],
                    $tenant['nom'],
                    $tenant['prenom'],
                    $tenant['email']
                ]);
                $insertedId = $pdo->lastInsertId();
                error_log("Successfully inserted inventaire_locataires record with id=$insertedId");
            } catch (PDOException $e) {
                // Handle duplicate key error gracefully (error code 23000)
                if ($e->getCode() == 23000) {
                    error_log("Duplicate key prevented insertion: locataire_id={$tenant['id']} already exists for inventaire_id=$inventaire_id");
                } else {
                    // Re-throw other errors
                    throw $e;
                }
            }
        } else {
            error_log("Skipping duplicate tenant insertion: locataire_id={$tenant['id']} already linked to inventaire_id=$inventaire_id");
        }
    }
    
    // Reload tenants - JOIN with locataires for fresh names
    $stmt = $pdo->prepare("
        SELECT il.*,
               COALESCE(l.nom, il.nom) as nom,
               COALESCE(l.prenom, il.prenom) as prenom,
               COALESCE(l.email, il.email) as email
        FROM inventaire_locataires il
        LEFT JOIN locataires l ON il.locataire_id = l.id
        WHERE il.inventaire_id = ?
        ORDER BY il.id ASC
    ");
    $stmt->execute([$inventaire_id]);
    $existing_tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Transform tenant signatures for display
foreach ($existing_tenants as &$tenant) {
    $tenant['signature_data'] = $tenant['signature'] ?? '';
    $tenant['signature_timestamp'] = $tenant['date_signature'] ?? '';
}
unset($tenant); // Clean up reference to prevent accidental modifications

// CRITICAL: Final validation to ensure no duplicate tenant IDs
// This prevents the canvas ID collision bug
$tenant_ids = array_column($existing_tenants, 'id');
$unique_tenant_ids = array_unique($tenant_ids);
if (count($tenant_ids) !== count($unique_tenant_ids)) {
    error_log("⚠️  CRITICAL: Duplicate tenant IDs detected in inventaire_locataires for inventaire_id=$inventaire_id");
    error_log("Tenant IDs: " . implode(', ', $tenant_ids));
    error_log("Unique IDs: " . implode(', ', $unique_tenant_ids));
    $_SESSION['error'] = "Erreur de données: Plusieurs locataires ont le même identifiant. Veuillez contacter l'administrateur.";
}

// Log tenant IDs for debugging
error_log("INVENTAIRE $inventaire_id: Rendering " . count($existing_tenants) . " tenant(s) with IDs: " . implode(', ', $tenant_ids));

$isEntreeInventory = ($inventaire['type'] === 'entree');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier l'inventaire - <?php echo htmlspecialchars($inventaire['reference_unique']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .form-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .category-section {
            margin-bottom: 30px;
        }
        .category-header {
            font-size: 1.3rem;
            font-weight: 600;
            color: #212529;
            margin-bottom: 1.5rem;
            padding: 12px 15px;
            background: linear-gradient(135deg, #0d6efd 0%, #0056b3 100%);
            color: white;
            border-radius: 6px;
            border-left: 5px solid #004085;
        }
        .subcategory-header {
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
            margin-top: 1.5rem;
            margin-bottom: 1rem;
            padding: 8px 12px;
            background: #f8f9fa;
            border-left: 4px solid #6c757d;
            border-radius: 4px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .inventory-table {
            font-size: 0.9rem;
        }
        .inventory-table thead th {
            font-weight: 600;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
        }
        .inventory-table td {
            vertical-align: middle;
        }
        .inventory-table input[type="number"] {
            min-width: 60px;
        }
        .inventory-table input[type="text"] {
            min-width: 150px;
        }
        .readonly-column {
            background-color: #f8f9fa !important;
        }
        .signature-container {
            border: 2px solid #dee2e6;
            border-radius: 5px;
            background-color: #ffffff;
            display: inline-block;
            cursor: crosshair;
            margin-bottom: 10px;
        }
        .signature-container canvas {
            display: block;
        }
        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #212529;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e9ecef;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4>Modifier l'inventaire</h4>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($inventaire['reference_unique']); ?> - 
                        <?php echo htmlspecialchars($inventaire['logement_reference']); ?>
                    </p>
                </div>
                <div>
                    <a href="download-inventaire.php?id=<?php echo $inventaire_id; ?>" class="btn btn-info" target="_blank">
                        <i class="bi bi-file-pdf"></i> Voir le PDF
                    </a>
                    <a href="inventaires.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Retour
                    </a>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST" id="inventaireForm" enctype="multipart/form-data">
            <?php 
            $itemIndex = 0;
            foreach ($standardItems as $categoryName => $categoryContent): 
            ?>
                <div class="form-card">
                    <div class="category-section">
                        <div class="category-header">
                            <i class="bi bi-box-seam"></i> <?php echo htmlspecialchars($categoryName); ?>
                        </div>
                        
                        <?php if ($categoryName === 'État des pièces'): ?>
                            <!-- État des pièces has subcategories -->
                            <?php foreach ($categoryContent as $subcategoryName => $subcategoryItems): ?>
                                <div class="subcategory-header">
                                    <?php echo htmlspecialchars($subcategoryName); ?>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered inventory-table">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: <?php echo $inventaire['type'] === 'sortie' ? '30%' : '40%'; ?>;">Élément</th>
                                                <?php if ($inventaire['type'] === 'sortie'): ?>
                                                    <th class="text-center readonly-column" style="width: 15%;" title="Quantité à l'entrée">Qté Entrée</th>
                                                <?php endif; ?>
                                                <th class="text-center" style="width: 15%;">Nombre</th>
                                                <th style="width: <?php echo $inventaire['type'] === 'sortie' ? '40%' : '45%'; ?>;">Commentaire</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($subcategoryItems as $item): 
                                                $itemIndex++;
                                                $existingData = $existing_data_by_id[$itemIndex] ?? null;
                                                $entreeRef = $entree_inventory_data[$itemIndex] ?? null;
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['nom']); ?></strong>
                                                    <input type="hidden" name="items[<?php echo $itemIndex; ?>][id]" value="<?php echo $itemIndex; ?>">
                                                    <input type="hidden" name="items[<?php echo $itemIndex; ?>][categorie]" value="<?php echo htmlspecialchars($categoryName); ?>">
                                                    <input type="hidden" name="items[<?php echo $itemIndex; ?>][sous_categorie]" value="<?php echo htmlspecialchars($subcategoryName); ?>">
                                                    <input type="hidden" name="items[<?php echo $itemIndex; ?>][nom]" value="<?php echo htmlspecialchars($item['nom']); ?>">
                                                    <input type="hidden" name="items[<?php echo $itemIndex; ?>][type]" value="<?php echo htmlspecialchars($item['type']); ?>">
                                                </td>
                                                
                                                <?php if ($inventaire['type'] === 'sortie'): ?>
                                                <!-- Entry reference column (read-only) -->
                                                <td class="text-center readonly-column">
                                                    <span class="text-muted">
                                                        <?php 
                                                        $entreeQty = $entreeRef['nombre'] ?? '';
                                                        echo $entreeQty !== '' ? htmlspecialchars($entreeQty) : '—';
                                                        ?>
                                                    </span>
                                                </td>
                                                <?php endif; ?>
                                                
                                                <!-- Nombre column -->
                                                <td class="text-center">
                                                    <input type="number" 
                                                           name="items[<?php echo $itemIndex; ?>][nombre]" 
                                                           class="form-control form-control-sm text-center" 
                                                           value="<?php echo htmlspecialchars(getInventaireEquipmentQuantity($existingData)); ?>" 
                                                           min="0">
                                                </td>
                                                
                                                <!-- Commentaire column -->
                                                <td>
                                                    <input type="text" 
                                                           name="items[<?php echo $itemIndex; ?>][commentaires]" 
                                                           class="form-control form-control-sm" 
                                                           value="<?php echo htmlspecialchars($existingData['commentaires'] ?? ''); ?>" 
                                                           placeholder="Commentaires...">
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- Simple category (no subcategories) -->
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered inventory-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: <?php echo $inventaire['type'] === 'sortie' ? '30%' : '40%'; ?>;">Élément</th>
                                            <?php if ($inventaire['type'] === 'sortie'): ?>
                                                <th class="text-center readonly-column" style="width: 15%;" title="Quantité à l'entrée">Qté Entrée</th>
                                            <?php endif; ?>
                                            <th class="text-center" style="width: 15%;">Nombre</th>
                                            <th style="width: <?php echo $inventaire['type'] === 'sortie' ? '40%' : '45%'; ?>;">Commentaire</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($categoryContent as $item): 
                                            $itemIndex++;
                                            $existingData = $existing_data_by_id[$itemIndex] ?? null;
                                            $entreeRef = $entree_inventory_data[$itemIndex] ?? null;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['nom']); ?></strong>
                                                <input type="hidden" name="items[<?php echo $itemIndex; ?>][id]" value="<?php echo $itemIndex; ?>">
                                                <input type="hidden" name="items[<?php echo $itemIndex; ?>][categorie]" value="<?php echo htmlspecialchars($categoryName); ?>">
                                                <input type="hidden" name="items[<?php echo $itemIndex; ?>][sous_categorie]" value="">
                                                <input type="hidden" name="items[<?php echo $itemIndex; ?>][nom]" value="<?php echo htmlspecialchars($item['nom']); ?>">
                                                <input type="hidden" name="items[<?php echo $itemIndex; ?>][type]" value="<?php echo htmlspecialchars($item['type']); ?>">
                                            </td>
                                            
                                            <?php if ($inventaire['type'] === 'sortie'): ?>
                                            <!-- Entry reference column (read-only) -->
                                            <td class="text-center readonly-column">
                                                <span class="text-muted">
                                                    <?php 
                                                    $entreeQty = $entreeRef['nombre'] ?? '';
                                                    echo $entreeQty !== '' ? htmlspecialchars($entreeQty) : '—';
                                                    ?>
                                                </span>
                                            </td>
                                            <?php endif; ?>
                                            
                                            <!-- Nombre column -->
                                            <td class="text-center">
                                                <input type="number" 
                                                       name="items[<?php echo $itemIndex; ?>][nombre]" 
                                                       class="form-control form-control-sm text-center" 
                                                       value="<?php echo htmlspecialchars(getInventaireEquipmentQuantity($existingData)); ?>" 
                                                       min="0">
                                            </td>
                                            
                                            <!-- Commentaire column -->
                                            <td>
                                                <input type="text" 
                                                       name="items[<?php echo $itemIndex; ?>][commentaires]" 
                                                       class="form-control form-control-sm" 
                                                       value="<?php echo htmlspecialchars($existingData['commentaires'] ?? ''); ?>" 
                                                       placeholder="Commentaires...">
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="form-card">
                <h5>Observations générales</h5>
                <textarea name="observations_generales" class="form-control" rows="4" 
                          placeholder="Observations générales sur l'inventaire..."><?php echo htmlspecialchars($inventaire['observations_generales'] ?? ''); ?></textarea>
            </div>

            <!-- Signatures Section -->
            <?php if (!empty($existing_tenants)): ?>
            <div class="form-card">
                <div class="section-title">
                    <i class="bi bi-pen"></i> Signatures des locataires
                </div>
                
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Signatures</strong> : 
                    Les locataires peuvent signer ci-dessous pour confirmer l'inventaire.
                </div>
                
                <!-- Lieu de signature (common for all) -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <label for="lieu_signature" class="form-label">Lieu de signature</label>
                        <input type="text" name="lieu_signature" id="lieu_signature" class="form-control" 
                               value="<?php echo htmlspecialchars($inventaire['lieu_signature'] ?? ''); ?>" 
                               placeholder="Ex: Paris">
                    </div>
                    <div class="col-md-6">
                        <label for="date_inventaire" class="form-label">Date de l'inventaire</label>
                        <input type="date" name="date_inventaire" id="date_inventaire" class="form-control"
                               value="<?php echo htmlspecialchars($inventaire['date_inventaire'] ?? date('Y-m-d')); ?>">
                    </div>
                </div>
                
                <!-- Tenant Signatures -->
                <?php foreach ($existing_tenants as $index => $tenant): ?>
                <!-- 
                    IMPORTANT: Each tenant has unique identifiers to prevent signature duplication:
                    - Canvas ID: tenantCanvas_<?php echo $index; ?> (unique per loop index)
                    - Hidden field ID: tenantSignature_<?php echo $index; ?> (unique per loop index)
                    - Array key uses loop index: tenants[<?php echo $index; ?>] for guaranteed uniqueness
                    - Database ID is preserved in hidden field: tenants[<?php echo $index; ?>][db_id]
                    This ensures each tenant's signature works independently, regardless of database issues
                -->
                <div class="mb-4 pb-4 border-bottom">
                    <h6 class="mb-3">
                        Signature locataire <?php echo $index + 1; ?> - <?php echo htmlspecialchars($tenant['prenom'] . ' ' . $tenant['nom']); ?>
                    </h6>
                    <div class="row">
                        <div class="col-md-12">
                            <?php if (!empty($tenant['signature_data'])): ?>
                                <div class="alert alert-success mb-2">
                                    <i class="bi bi-check-circle"></i> 
                                    Signé le <?php echo !empty($tenant['signature_timestamp']) ? date('d/m/Y à H:i', strtotime($tenant['signature_timestamp'])) : 'Date inconnue'; ?>
                                </div>
                                <div class="mb-2">
                                    <?php
                                    // Handle signature path - prepend ../ for relative paths since we're in admin-v2 directory
                                    $signatureSrc = $tenant['signature_data'];
                                    
                                    // Validate data URL format with length check (max 2MB)
                                    if (preg_match('/^data:image\/(jpeg|jpg|png);base64,(?:[A-Za-z0-9+\/=]+)$/', $signatureSrc)) {
                                        // Data URL - validate size
                                        if (strlen($signatureSrc) <= 2 * 1024 * 1024) {
                                            $displaySrc = $signatureSrc;
                                        } else {
                                            error_log("Oversized signature data URL for tenant index $index (DB ID: " . (int)$tenant['id'] . ")");
                                            $displaySrc = '';
                                        }
                                    } elseif (preg_match('/^uploads\/signatures\/[a-zA-Z0-9_\-]+\.(jpg|jpeg|png)$/', $signatureSrc)) {
                                        // Relative path - validate it's within expected directory and prepend ../
                                        $displaySrc = '../' . $signatureSrc;
                                    } else {
                                        // Invalid or unexpected format - don't display to prevent security issues
                                        error_log("Invalid signature path format detected for tenant index $index (DB ID: " . (int)$tenant['id'] . ")");
                                        $displaySrc = '';
                                    }
                                    ?>
                                    <?php if (!empty($displaySrc)): ?>
                                    <img src="<?php echo htmlspecialchars($displaySrc); ?>" 
                                         alt="Signature" style="max-width: 200px; max-height: 80px; border: 1px solid #dee2e6; padding: 5px;">
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <label class="form-label">Veuillez signer dans le cadre ci-dessous :</label>
                            <div class="signature-container" style="max-width: 300px;">
                                <canvas id="tenantCanvas_<?php echo $index; ?>" width="300" height="150" style="background: transparent; border: none; outline: none; padding: 0;"></canvas>
                            </div>
                            <input type="hidden" name="tenants[<?php echo $index; ?>][signature]" 
                                   id="tenantSignature_<?php echo $index; ?>" 
                                   value="<?php echo htmlspecialchars($tenant['signature_data'] ?? ''); ?>">
                            <input type="hidden" name="tenants[<?php echo $index; ?>][db_id]" 
                                   value="<?php echo $tenant['id']; ?>">
                            <input type="hidden" name="tenants[<?php echo $index; ?>][locataire_id]" 
                                   value="<?php echo $tenant['locataire_id'] ?? ''; ?>">
                            <input type="hidden" name="tenants[<?php echo $index; ?>][nom]" 
                                   value="<?php echo htmlspecialchars($tenant['nom']); ?>">
                            <input type="hidden" name="tenants[<?php echo $index; ?>][prenom]" 
                                   value="<?php echo htmlspecialchars($tenant['prenom']); ?>">
                            <input type="hidden" name="tenants[<?php echo $index; ?>][email]" 
                                   value="<?php echo htmlspecialchars($tenant['email'] ?? ''); ?>">
                            <div class="mt-2">
                                <button type="button" class="btn btn-warning btn-sm" onclick="clearTenantSignature(<?php echo $index; ?>)">
                                    <i class="bi bi-eraser"></i> Effacer
                                </button>
                            </div>
                            <div class="mt-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" 
                                           name="tenants[<?php echo $index; ?>][certifie_exact]" 
                                           id="certifie_exact_<?php echo $index; ?>" 
                                           value="1"
                                           <?php echo !empty($tenant['certifie_exact']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="certifie_exact_<?php echo $index; ?>">
                                        <strong>Certifié exact</strong>
                                    </label>
                                </div>
                            </div>

                            <!-- Pièces d'identité Signataire -->
                            <div class="mt-3 pt-3 border-top">
                                <label class="form-label fw-semibold">
                                    <i class="bi bi-person-vcard"></i> Pièces d'identité Signataire
                                </label>
                                <?php
                                $rectoPath = $tenant['piece_identite_recto'] ?? '';
                                $versoPath = $tenant['piece_identite_verso'] ?? '';
                                ?>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small">Recto <span class="text-danger">*</span></label>
                                        <?php if (!empty($rectoPath)): ?>
                                            <div class="mb-1">
                                                <?php
                                                $rectoExt = strtolower(pathinfo($rectoPath, PATHINFO_EXTENSION));
                                                if (in_array($rectoExt, ['jpg','jpeg','png'])): ?>
                                                    <img src="../uploads/<?php echo htmlspecialchars($rectoPath); ?>" alt="Recto actuel" style="max-width:160px;max-height:100px;border:1px solid #dee2e6;padding:3px;">
                                                <?php else: ?>
                                                    <a href="../uploads/<?php echo htmlspecialchars($rectoPath); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                        <i class="bi bi-file-earmark"></i> Voir le recto
                                                    </a>
                                                <?php endif; ?>
                                                <p class="text-muted small mb-0">Recto enregistré – déposer un nouveau fichier pour remplacer</p>
                                            </div>
                                        <?php endif; ?>
                                        <input type="file" class="form-control form-control-sm"
                                               id="tenant_piece_identite_recto_<?php echo $tenant['id']; ?>"
                                               name="tenant_piece_identite_recto_<?php echo $tenant['id']; ?>"
                                               accept=".jpg,.jpeg,.png,.pdf"
                                               data-tenant-id="<?php echo $tenant['id']; ?>"
                                               data-tenant-index="<?php echo $index; ?>"
                                               data-side="recto"
                                               <?php echo empty($rectoPath) ? 'required' : ''; ?>>
                                        <div class="form-text">JPG, PNG ou PDF – max 5 Mo</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Verso <span class="text-muted">(optionnel)</span></label>
                                        <?php if (!empty($versoPath)): ?>
                                            <div class="mb-1">
                                                <?php
                                                $versoExt = strtolower(pathinfo($versoPath, PATHINFO_EXTENSION));
                                                if (in_array($versoExt, ['jpg','jpeg','png'])): ?>
                                                    <img src="../uploads/<?php echo htmlspecialchars($versoPath); ?>" alt="Verso actuel" style="max-width:160px;max-height:100px;border:1px solid #dee2e6;padding:3px;">
                                                <?php else: ?>
                                                    <a href="../uploads/<?php echo htmlspecialchars($versoPath); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                        <i class="bi bi-file-earmark"></i> Voir le verso
                                                    </a>
                                                <?php endif; ?>
                                                <p class="text-muted small mb-0">Verso enregistré – déposer un nouveau fichier pour remplacer</p>
                                            </div>
                                        <?php endif; ?>
                                        <input type="file" class="form-control form-control-sm"
                                               id="tenant_piece_identite_verso_<?php echo $tenant['id']; ?>"
                                               name="tenant_piece_identite_verso_<?php echo $tenant['id']; ?>"
                                               accept=".jpg,.jpeg,.png,.pdf"
                                               data-tenant-id="<?php echo $tenant['id']; ?>"
                                               data-tenant-index="<?php echo $index; ?>"
                                               data-side="verso">
                                        <div class="form-text">JPG, PNG ou PDF – max 5 Mo</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between">
                <a href="inventaires.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Annuler
                </a>
                <div>
                    <button type="submit" class="btn btn-secondary" aria-label="Enregistrer l'inventaire comme brouillon sans envoyer d'email">
                        <i class="bi bi-save"></i> Enregistrer le brouillon
                    </button>
                    <button type="submit" name="finalize" value="1" class="btn btn-primary" aria-label="Finaliser et envoyer l'inventaire par email au locataire">
                        <i class="bi bi-check-circle"></i> Finaliser et envoyer
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Configuration
        const SIGNATURE_JPEG_QUALITY = 0.95;
        
        // Track initialized canvas IDs to detect duplicates
        const initializedCanvasIds = new Set();
        
        // Initialize tenant signature canvases on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log('=== INVENTAIRE TENANT SIGNATURE INITIALIZATION ===');
            console.log('Total tenants to initialize: <?php echo count($existing_tenants); ?>');
            
            // Initialize tenant signatures using array index for uniqueness
            <?php if (!empty($existing_tenants)): ?>
                <?php foreach ($existing_tenants as $index => $tenant): ?>
                    {
                        const tenantIndex = <?php echo $index; ?>;
                        const tenantDbId = <?php echo $tenant['id']; ?>;
                        const tenantName = <?php echo json_encode($tenant['prenom'] . ' ' . $tenant['nom']); ?>;
                        console.log('Initializing Tenant <?php echo $index + 1; ?>: Index=' + tenantIndex + ', DB_ID=' + tenantDbId + ', Name=' + tenantName + ', Canvas=tenantCanvas_' + tenantIndex);
                        
                        // Check for duplicate canvas ID (should never happen with index-based IDs)
                        if (initializedCanvasIds.has(tenantIndex)) {
                            console.error('⚠️  CRITICAL: Duplicate canvas index detected! Index ' + tenantIndex + ' already initialized.');
                            console.error('This should never happen with index-based identifiers. Please report this bug.');
                            
                            // Show accessible error message in the DOM
                            const errorDiv = document.createElement('div');
                            errorDiv.className = 'alert alert-danger alert-dismissible fade show';
                            errorDiv.setAttribute('role', 'alert');
                            errorDiv.setAttribute('aria-live', 'assertive');
                            errorDiv.innerHTML = `
                                <strong><i class="bi bi-exclamation-triangle"></i> Erreur Critique:</strong>
                                ID de canvas en double détecté (Index: ${tenantIndex}). 
                                Les signatures pourraient ne pas fonctionner correctement. 
                                Veuillez contacter l'administrateur.
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
                            `;
                            document.querySelector('.main-content').insertBefore(
                                errorDiv, 
                                document.querySelector('.header')
                            );
                        } else {
                            initializedCanvasIds.add(tenantIndex);
                        }
                        
                        initTenantSignature(tenantIndex);
                    }
                <?php endforeach; ?>
            <?php endif; ?>
            
            console.log('Initialized canvas indices:', Array.from(initializedCanvasIds));
            console.log('=== INITIALIZATION COMPLETE ===');
        });
        
        // Function to duplicate Entry data to Exit
        
        function initTenantSignature(id) {
            const canvas = document.getElementById(`tenantCanvas_${id}`);
            if (!canvas) return;
            
            // Prevent duplicate initialization
            if (canvas.dataset.initialized === 'true') return;
            canvas.dataset.initialized = 'true';
            
            const ctx = canvas.getContext('2d');
            
            // Set drawing style for black signature lines
            ctx.strokeStyle = '#000000';
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            
            let isDrawing = false;
            let lastX = 0;
            let lastY = 0;
            
            // Helper function to get mouse/touch position
            function getPos(e) {
                const rect = canvas.getBoundingClientRect();
                const clientX = e.clientX || (e.touches && e.touches[0] ? e.touches[0].clientX : 0);
                const clientY = e.clientY || (e.touches && e.touches[0] ? e.touches[0].clientY : 0);
                return {
                    x: clientX - rect.left,
                    y: clientY - rect.top
                };
            }
            
            // Mouse events
            canvas.addEventListener('mousedown', (e) => {
                isDrawing = true;
                const pos = getPos(e);
                lastX = pos.x;
                lastY = pos.y;
                ctx.beginPath();
                ctx.moveTo(lastX, lastY);
            });
            
            canvas.addEventListener('mousemove', (e) => {
                if (!isDrawing) return;
                e.preventDefault();
                
                const pos = getPos(e);
                
                ctx.lineTo(pos.x, pos.y);
                ctx.stroke();
                ctx.beginPath();
                ctx.moveTo(pos.x, pos.y);
                
                lastX = pos.x;
                lastY = pos.y;
            });
            
            canvas.addEventListener('mouseup', () => {
                if (isDrawing) {
                    isDrawing = false;
                    saveTenantSignature(id);
                }
            });
            
            canvas.addEventListener('mouseleave', () => {
                if (isDrawing) {
                    isDrawing = false;
                    saveTenantSignature(id);
                }
            });
            
            // Touch support
            canvas.addEventListener('touchstart', (e) => {
                e.preventDefault();
                isDrawing = true;
                const pos = getPos(e);
                lastX = pos.x;
                lastY = pos.y;
                ctx.beginPath();
                ctx.moveTo(lastX, lastY);
            });
            
            canvas.addEventListener('touchmove', (e) => {
                if (!isDrawing) return;
                e.preventDefault();
                
                const pos = getPos(e);
                
                ctx.lineTo(pos.x, pos.y);
                ctx.stroke();
                ctx.beginPath();
                ctx.moveTo(pos.x, pos.y);
                
                lastX = pos.x;
                lastY = pos.y;
            });
            
            canvas.addEventListener('touchend', (e) => {
                e.preventDefault();
                if (isDrawing) {
                    isDrawing = false;
                    saveTenantSignature(id);
                }
            });
        }
        
        function saveTenantSignature(id) {
            const canvas = document.getElementById(`tenantCanvas_${id}`);
            if (!canvas) {
                console.error(`Canvas not found for tenant ID: ${id}`);
                return;
            }
            
            // Create a temporary canvas to add white background before JPEG conversion
            // JPEG doesn't support transparency, so we need to fill with white
            const tempCanvas = document.createElement('canvas');
            tempCanvas.width = canvas.width;
            tempCanvas.height = canvas.height;
            const tempCtx = tempCanvas.getContext('2d');
            
            // Fill with white background (JPEG doesn't support transparency)
            tempCtx.fillStyle = '#FFFFFF';
            tempCtx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);
            
            // Draw the signature on top of the white background
            tempCtx.drawImage(canvas, 0, 0);
            
            // Convert to JPEG with white background
            const signatureData = tempCanvas.toDataURL('image/jpeg', SIGNATURE_JPEG_QUALITY);
            
            // Get the hidden field and verify it's the correct one for this tenant
            const hiddenField = document.getElementById(`tenantSignature_${id}`);
            if (!hiddenField) {
                console.error(`Hidden field not found for tenant ID: ${id}`);
                return;
            }
            
            // Set the value - this is tenant-specific due to the unique ID
            hiddenField.value = signatureData;
            console.log(`Signature saved for tenant ${id}, length: ${signatureData.length} bytes`);
        }
        
        function clearTenantSignature(id) {
            const canvas = document.getElementById(`tenantCanvas_${id}`);
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            document.getElementById(`tenantSignature_${id}`).value = '';
        }
        
        // Handle form submission with validation
        document.getElementById('inventaireForm').addEventListener('submit', function(e) {
            // Save all tenant signatures before submission using array indices
            <?php foreach ($existing_tenants as $index => $tenant): ?>
                saveTenantSignature(<?php echo $index; ?>);
            <?php endforeach; ?>
            
            // Validate that all tenants have signed, checked "Certifié exact", and uploaded recto
            let allValid = true;
            let errors = [];
            
            // Validate tenant signatures - using array index instead of DB ID
            const tenantValidations = [
                <?php foreach ($existing_tenants as $index => $tenant): ?>
                {
                    tenantIndex: <?php echo $index; ?>,
                    tenantDbId: <?php echo $tenant['id']; ?>,
                    name: <?php echo json_encode($tenant['prenom'] . ' ' . $tenant['nom']); ?>,
                    signatureId: 'tenantSignature_<?php echo $index; ?>',
                    certifieId: 'certifie_exact_<?php echo $index; ?>',
                    rectoInputId: 'tenant_piece_identite_recto_<?php echo $tenant['id']; ?>',
                    hasRectoDB: <?php echo !empty($tenant['piece_identite_recto']) ? 'true' : 'false'; ?>
                }<?php echo ($index < count($existing_tenants) - 1) ? ',' : ''; ?>
                <?php endforeach; ?>
            ];
            
            tenantValidations.forEach(function(tenant) {
                const signatureValue = document.getElementById(tenant.signatureId).value;
                const certifieChecked = document.getElementById(tenant.certifieId).checked;
                const rectoInput = document.getElementById(tenant.rectoInputId);
                
                if (!signatureValue || signatureValue.trim() === '') {
                    errors.push('La signature de ' + tenant.name + ' est obligatoire');
                    allValid = false;
                }
                
                if (!certifieChecked) {
                    errors.push('La case "Certifié exact" doit être cochée pour ' + tenant.name);
                    allValid = false;
                }

                // Recto required: either already in DB or newly selected
                if (!tenant.hasRectoDB && (!rectoInput || !rectoInput.files.length)) {
                    errors.push('La pièce d\'identité (recto) de ' + tenant.name + ' est obligatoire');
                    allValid = false;
                }
            });
            
            if (!allValid) {
                e.preventDefault();
                
                // Show errors using Bootstrap alert
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-danger alert-dismissible fade show';
                alertDiv.innerHTML = `
                    <strong><i class="bi bi-exclamation-triangle"></i> Erreurs de validation :</strong>
                    <ul class="mb-0 mt-2">
                        ${errors.map(err => '<li>' + err + '</li>').join('')}
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                document.querySelector('.main-content').insertBefore(alertDiv, document.querySelector('.header').nextSibling);
                
                // Scroll to top to show errors
                window.scrollTo({ top: 0, behavior: 'smooth' });
                
                return false;
            }
        });
    </script>
</body>
</html>
