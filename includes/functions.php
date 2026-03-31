<?php
/**
 * Fonctions utilitaires
 * My Invest Immobilier
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail-templates.php';

/**
 * Générer un token CSRF
 * @return string
 */
function generateCsrfToken() {
    global $config;
    if (!isset($_SESSION[$config['CSRF_TOKEN_NAME']])) {
        $_SESSION[$config['CSRF_TOKEN_NAME']] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$config['CSRF_TOKEN_NAME']];
}

/**
 * Vérifier un token CSRF
 * @param string $token
 * @return bool
 */
function verifyCsrfToken($token) {
    global $config;
    return isset($_SESSION[$config['CSRF_TOKEN_NAME']]) && hash_equals($_SESSION[$config['CSRF_TOKEN_NAME']], $token);
}

/**
 * Générer un token unique pour un contrat
 * @return string
 */
function generateContractToken() {
    return bin2hex(random_bytes(32));
}

/**
 * Nettoyer et échapper une chaîne
 * @param string $data
 * @return string
 */
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Obtenir l'adresse IP du client
 * @return string
 */
function getClientIp() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}

/**
 * Enregistrer un log dans la base de données
 * @param int|null $contratId
 * @param string $action
 * @param string $details
 * @return bool
 */
function logAction($contratId, $action, $details = '') {
    $sql = "INSERT INTO logs (type_entite, entite_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)";
    $stmt = executeQuery($sql, ['contrat', $contratId, $action, $details, getClientIp()]);
    return $stmt !== false;
}

/**
 * Créer un nouveau contrat
 * @param int $logementId
 * @param int $nbLocataires
 * @return array|false ['id' => int, 'token' => string, 'expiration' => string]
 */
function createContract($logementId, $nbLocataires = 1) {
    global $config;
    $token = generateContractToken();
    
    // Get expiration delay from parameters table, fallback to config
    $expiryHours = getParameter('delai_expiration_lien_contrat', $config['TOKEN_EXPIRY_HOURS']);
    $expiration = date('Y-m-d H:i:s', strtotime('+' . $expiryHours . ' hours'));
    
    $sql = "INSERT INTO contrats (reference_unique, logement_id, nb_locataires, date_expiration) 
            VALUES (?, ?, ?, ?)";
    
    if (executeQuery($sql, [$token, $logementId, $nbLocataires, $expiration])) {
        $contractId = getLastInsertId();
        logAction($contractId, 'creation_contrat', "Logement ID: $logementId, Nb locataires: $nbLocataires");
        return [
            'id' => $contractId,
            'token' => $token,
            'expiration' => $expiration
        ];
    }
    
    return false;
}

/**
 * Obtenir un contrat par son token
 * @param string $token
 * @return array|false
 */
function getContractByToken($token) {
    // Note: Select l.* first, then c.* to ensure contract fields (especially statut) 
    // take precedence over logement fields in case of column name collisions
    $sql = "SELECT l.*, c.* 
            FROM contrats c 
            INNER JOIN logements l ON c.logement_id = l.id 
            WHERE c.token_signature = ?";
    return fetchOne($sql, [$token]);
}

/**
 * Vérifier si un contrat est valide (non expiré)
 * @param array $contract
 * @return bool
 */
function isContractValid($contract) {
    if (!$contract) {
        return false;
    }
    
    // Only 'en_attente' status is valid for unsigned contracts
    $validStatuses = ['en_attente'];
    if (!in_array($contract['statut'], $validStatuses)) {
        return false;
    }
    
    // Check if date_expiration exists and is valid
    if (!isset($contract['date_expiration']) || empty($contract['date_expiration'])) {
        error_log("Contract expiration date is missing or empty for contract ID: " . ($contract['id'] ?? 'unknown'));
        return false;
    }
    
    $expiration = strtotime($contract['date_expiration']);
    
    // Check if strtotime successfully parsed the date
    if ($expiration === false || $expiration === -1) {
        error_log("Failed to parse expiration date '{$contract['date_expiration']}' for contract ID: " . ($contract['id'] ?? 'unknown'));
        return false;
    }
    
    // Contract is valid if current time is before expiration time
    return time() < $expiration;
}

/**
 * Obtenir les locataires d'un contrat
 * IMPORTANT: Returns tenants ordered by 'ordre ASC' to ensure tenant 1 is always processed before tenant 2
 * This ordering is critical for the signature workflow (fix #212)
 * @param int $contratId
 * @return array
 */
function getTenantsByContract($contratId) {
    $sql = "SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC";
    return fetchAll($sql, [$contratId]);
}

/**
 * Créer un locataire
 * @param int $contratId
 * @param int $ordre
 * @param array $data
 * @return int|false
 */
function createTenant($contratId, $ordre, $data) {
    $sql = "INSERT INTO locataires (contrat_id, ordre, nom, prenom, date_naissance, email, telephone) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    if (executeQuery($sql, [
        $contratId,
        $ordre,
        $data['nom'],
        $data['prenom'],
        $data['date_naissance'],
        $data['email'],
        $data['telephone'] ?? null
    ])) {
        return getLastInsertId();
    }
    
    return false;
}

/**
 * Mettre à jour la signature d'un locataire
 * @param int $locataireId
 * @param string $signatureData
 * @param string $mentionLuApprouve
 * @return bool
 */
function updateTenantSignature($locataireId, $signatureData, $mentionLuApprouve = null, $certifieExact = 0) {
    // Validate signature data size (LONGTEXT max is ~4GB, but we set a reasonable limit)
    // Canvas JPEG data URLs are typically 50-300KB (smaller than PNG)
    $maxSize = 2 * 1024 * 1024; // 2MB limit
    if (strlen($signatureData) > $maxSize) {
        error_log("Signature data too large: " . strlen($signatureData) . " bytes for locataire ID: $locataireId");
        return false;
    }
    
    // Validate that signature data is a valid data URL
    if (!preg_match('/^data:image\/(png|jpeg|jpg);base64,([A-Za-z0-9+\/]+={0,2})$/', $signatureData, $matches)) {
        error_log("Invalid signature data format for locataire ID: $locataireId");
        return false;
    }
    
    $imageFormat = $matches[1];
    $base64Data = $matches[2];
    
    // Decode base64 to image data
    $imageData = base64_decode($base64Data);
    if ($imageData === false) {
        error_log("Failed to decode base64 signature for locataire ID: $locataireId");
        return false;
    }
    
    // Create uploads directory if it doesn't exist
    $baseDir = dirname(__DIR__);
    $uploadsDir = $baseDir . '/uploads/signatures';
    if (!is_dir($uploadsDir)) {
        if (!mkdir($uploadsDir, 0755, true)) {
            error_log("Failed to create signatures directory for locataire ID: $locataireId");
            return false;
        }
    }
    
    // Generate unique filename using uniqid() with more_entropy for guaranteed uniqueness
    // uniqid(prefix, more_entropy=true) generates a 23-char unique ID based on microtime
    // with additional random component, eliminating collision risk even in rapid succession
    $uniqueId = uniqid('', true);  // e.g., "65a1b2c3d4e5f.12345678"
    // Replace dot with underscore for filesystem safety
    $uniqueId = str_replace('.', '_', $uniqueId);
    $filename = "tenant_locataire_{$locataireId}_{$uniqueId}.jpg";
    $filepath = $uploadsDir . '/' . $filename;
    
    // Save physical file
    if (file_put_contents($filepath, $imageData) === false) {
        error_log("Failed to save signature file for locataire ID: $locataireId");
        return false;
    }
    
    // Store relative path instead of base64
    $relativePath = 'uploads/signatures/' . $filename;
    error_log("=== SIGNATURE SAVE START ===");
    error_log("Locataire ID being updated: $locataireId");
    error_log("Signature file path: $relativePath");
    error_log("Physical file saved at: $filepath");
    error_log("✓ Signature enregistrée physiquement et intégrée sans bordure - Locataire ID: $locataireId");
    
    // Build SQL based on whether mention_lu_approuve is provided
    if ($mentionLuApprouve !== null) {
        $sql = "UPDATE locataires 
                SET signature_data = ?, signature_ip = ?, signature_timestamp = NOW(), mention_lu_approuve = ?, certifie_exact = ?
                WHERE id = ?";
        $stmt = executeQuery($sql, [$relativePath, getClientIp(), $mentionLuApprouve, $certifieExact, $locataireId]);
    } else {
        $sql = "UPDATE locataires 
                SET signature_data = ?, signature_ip = ?, signature_timestamp = NOW(), certifie_exact = ?
                WHERE id = ?";
        $stmt = executeQuery($sql, [$relativePath, getClientIp(), $certifieExact, $locataireId]);
    }
    
    if ($stmt === false) {
        error_log("✗ FAILED to update signature in database for locataire ID: $locataireId");
        error_log("SQL query failed, cleaning up physical file");
        // Clean up the file if database update failed
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        return false;
    }
    
    // Verify the update was successful and only affected one record
    $updatedRecords = fetchAll("SELECT id, ordre, nom, prenom, signature_data FROM locataires WHERE signature_data = ?", [$relativePath]);
    error_log("✓ Database updated successfully for locataire ID: $locataireId");
    error_log("Number of records with this signature file: " . count($updatedRecords));
    foreach ($updatedRecords as $record) {
        error_log("  - Locataire ID={$record['id']}, Ordre={$record['ordre']}, Nom={$record['prenom']} {$record['nom']}");
    }
    if (count($updatedRecords) > 1) {
        error_log("✗ WARNING: Multiple tenant records have the same signature file! This may indicate a bug.");
    }
    error_log("=== SIGNATURE SAVE COMPLETE ===");
    
    return true;
}

/**
 * Mettre à jour la signature d'un locataire pour état des lieux
 * @param int $etatLieuxLocataireId ID from etat_lieux_locataires table
 * @param string $signatureData Base64 encoded signature data
 * @param int $etatLieuxId ID of the état des lieux
 * @return bool
 */
function updateEtatLieuxTenantSignature($etatLieuxLocataireId, $signatureData, $etatLieuxId) {
    global $pdo;
    
    // Validate signature data size
    $maxSize = 2 * 1024 * 1024; // 2MB limit
    if (strlen($signatureData) > $maxSize) {
        error_log("Signature data too large: " . strlen($signatureData) . " bytes for etat_lieux_locataire ID: $etatLieuxLocataireId");
        return false;
    }
    
    // Validate that signature data is a valid data URL
    if (!preg_match('/^data:image\/(png|jpeg|jpg);base64,([A-Za-z0-9+\/]+={0,2})$/', $signatureData, $matches)) {
        error_log("Invalid signature data format for etat_lieux_locataire ID: $etatLieuxLocataireId");
        return false;
    }
    
    $imageFormat = $matches[1];
    $base64Data = $matches[2];
    
    // Decode base64 to image data
    $imageData = base64_decode($base64Data);
    if ($imageData === false) {
        error_log("Failed to decode base64 signature for etat_lieux_locataire ID: $etatLieuxLocataireId");
        return false;
    }
    
    // Create uploads directory if it doesn't exist
    $baseDir = dirname(__DIR__);
    $uploadsDir = $baseDir . '/uploads/signatures';
    if (!is_dir($uploadsDir)) {
        if (!mkdir($uploadsDir, 0755, true)) {
            error_log("Failed to create signatures directory for etat_lieux_locataire ID: $etatLieuxLocataireId");
            return false;
        }
    }
    
    // Generate unique filename using uniqid() for guaranteed uniqueness
    // This prevents collisions even if multiple tenants sign simultaneously
    $uniqueId = uniqid('', true);
    $uniqueId = str_replace('.', '_', $uniqueId);
    $filename = "etat_lieux_tenant_{$etatLieuxId}_{$etatLieuxLocataireId}_{$uniqueId}.jpg";
    $filepath = $uploadsDir . '/' . $filename;
    
    // Save physical file
    if (file_put_contents($filepath, $imageData) === false) {
        error_log("Failed to save signature file for etat_lieux_locataire ID: $etatLieuxLocataireId");
        return false;
    }
    
    // Store relative path instead of base64
    $relativePath = 'uploads/signatures/' . $filename;
    error_log("État des lieux signature saved as physical file: $relativePath for etat_lieux_locataire ID: $etatLieuxLocataireId");
    
    $sql = "UPDATE etat_lieux_locataires 
            SET signature_data = ?, signature_ip = ?, signature_timestamp = NOW()
            WHERE id = ? AND etat_lieux_id = ?";
    
    $stmt = executeQuery($sql, [$relativePath, getClientIp(), $etatLieuxLocataireId, $etatLieuxId]);
    
    if ($stmt === false) {
        error_log("Failed to update signature for etat_lieux_locataire ID: $etatLieuxLocataireId");
        // Clean up the file if database update failed
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        return false;
    }
    
    return true;
}

/**
 * Update inventaire tenant signature as physical file
 * @param int $inventaireLocataireId
 * @param string $signatureData Base64 image data
 * @param int $inventaireId
 * @return bool
 */
function updateInventaireTenantSignature($inventaireLocataireId, $signatureData, $inventaireId) {
    global $pdo;
    
    error_log("updateInventaireTenantSignature: START - inventaire_locataire ID: $inventaireLocataireId, inventaire ID: $inventaireId");
    
    // Validate signature data size
    $maxSize = 2 * 1024 * 1024; // 2MB limit
    if (strlen($signatureData) > $maxSize) {
        error_log("updateInventaireTenantSignature: Signature data too large: " . strlen($signatureData) . " bytes for inventaire_locataire ID: $inventaireLocataireId");
        return false;
    }
    
    // Validate that signature data is a valid data URL
    if (!preg_match('/^data:image\/(png|jpeg|jpg);base64,([A-Za-z0-9+\/]+={0,2})$/', $signatureData, $matches)) {
        error_log("updateInventaireTenantSignature: Invalid signature data format for inventaire_locataire ID: $inventaireLocataireId");
        return false;
    }
    
    $imageFormat = $matches[1];
    $base64Data = $matches[2];
    
    // Decode base64 to image data
    $imageData = base64_decode($base64Data);
    if ($imageData === false) {
        error_log("updateInventaireTenantSignature: Failed to decode base64 signature for inventaire_locataire ID: $inventaireLocataireId");
        return false;
    }
    
    // Create uploads directory if it doesn't exist
    $baseDir = dirname(__DIR__);
    $uploadsDir = $baseDir . '/uploads/signatures';
    if (!is_dir($uploadsDir)) {
        if (!mkdir($uploadsDir, 0755, true)) {
            error_log("updateInventaireTenantSignature: Failed to create signatures directory for inventaire_locataire ID: $inventaireLocataireId");
            return false;
        }
    }
    
    // Generate unique filename using uniqid() for guaranteed uniqueness
    // This prevents collisions even if multiple tenants sign simultaneously
    $uniqueId = uniqid('', true);
    $uniqueId = str_replace('.', '_', $uniqueId);
    $filename = "inventaire_tenant_{$inventaireId}_{$inventaireLocataireId}_{$uniqueId}.jpg";
    $filepath = $uploadsDir . '/' . $filename;
    
    error_log("updateInventaireTenantSignature: Saving signature to file: $filename");
    
    // Save physical file
    if (file_put_contents($filepath, $imageData) === false) {
        error_log("updateInventaireTenantSignature: Failed to save signature file for inventaire_locataire ID: $inventaireLocataireId");
        return false;
    }
    
    // Store relative path instead of base64
    $relativePath = 'uploads/signatures/' . $filename;
    error_log("updateInventaireTenantSignature: ✓ Signature saved as physical file: $relativePath");
    
    // Update the database with the specific tenant's signature
    // CRITICAL: Use both id AND inventaire_id to ensure we update the correct tenant record
    $sql = "UPDATE inventaire_locataires 
            SET signature = ?, date_signature = NOW()
            WHERE id = ? AND inventaire_id = ?";
    
    error_log("updateInventaireTenantSignature: Executing UPDATE for inventaire_locataire ID: $inventaireLocataireId");
    
    $stmt = executeQuery($sql, [$relativePath, $inventaireLocataireId, $inventaireId]);
    
    if ($stmt === false) {
        error_log("updateInventaireTenantSignature: ❌ Failed to update signature for inventaire_locataire ID: $inventaireLocataireId");
        // Clean up the file if database update failed
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        return false;
    }
    
    error_log("updateInventaireTenantSignature: ✓ SUCCESS - Updated signature for inventaire_locataire ID: $inventaireLocataireId");
    
    return true;
}

/**
 * Mettre à jour les pièces d'identité d'un locataire
 * @param int $locataireId
 * @param string $recto
 * @param string $verso
 * @return bool
 */
function updateTenantDocuments($locataireId, $recto, $verso) {
    $sql = "UPDATE locataires SET piece_identite_recto = ?, piece_identite_verso = ? WHERE id = ?";
    $stmt = executeQuery($sql, [$recto, $verso, $locataireId]);
    return $stmt !== false;
}

/**
 * Mettre à jour la preuve de paiement du dépôt de garantie d'un locataire
 * @param int $locataireId
 * @param string $preuvePaiement
 * @return bool
 */
function updateTenantPaymentProof($locataireId, $preuvePaiement) {
    $sql = "UPDATE locataires SET preuve_paiement_depot = ? WHERE id = ?";
    $stmt = executeQuery($sql, [$preuvePaiement, $locataireId]);
    return $stmt !== false;
}

/**
 * Finaliser un contrat (marquer comme signé)
 * @param int $contratId
 * @return bool
 */
function finalizeContract($contratId) {
    $sql = "UPDATE contrats SET statut = 'signe', date_signature = NOW() WHERE id = ?";
    $stmt = executeQuery($sql, [$contratId]);
    
    if ($stmt) {
        logAction($contratId, 'signature_contrat', 'Contrat finalisé et signé');
        
        return true;
    }
    
    return false;
}

/**
 * Valider un fichier uploadé
 * @param array $file
 * @return array ['success' => bool, 'error' => string, 'filename' => string]
 */
function validateUploadedFile($file) {
    global $config;
    // Vérifier les erreurs d'upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Erreur lors de l\'upload du fichier.'];
    }
    
    // Vérifier la taille
    if ($file['size'] > $config['MAX_FILE_SIZE']) {
        return ['success' => false, 'error' => 'Le fichier est trop volumineux (max 5 Mo).'];
    }
    
    // Vérifier l'extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $config['ALLOWED_EXTENSIONS'])) {
        return ['success' => false, 'error' => 'Type de fichier non autorisé. Utilisez JPG, PNG ou PDF.'];
    }
    
    // Vérifier le type MIME réel
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $config['ALLOWED_MIME_TYPES'])) {
        return ['success' => false, 'error' => 'Type de fichier invalide.'];
    }
    
    // Générer un nom de fichier unique
    $newFilename = bin2hex(random_bytes(16)) . '.' . $extension;
    
    return ['success' => true, 'filename' => $newFilename];
}

/**
 * Sauvegarder un fichier uploadé
 * @param array $file
 * @param string $newFilename
 * @return bool
 */
function saveUploadedFile($file, $newFilename) {
    global $config;
    $destination = $config['UPLOAD_DIR'] . $newFilename;
    
    // Créer le dossier uploads s'il n'existe pas
    if (!is_dir($config['UPLOAD_DIR'])) {
        mkdir($config['UPLOAD_DIR'], 0755, true);
    }
    
    return move_uploaded_file($file['tmp_name'], $destination);
}

/**
 * Obtenir un logement par sa référence
 * @param string $reference
 * @return array|false
 */
function getLogementByReference($reference) {
    $sql = "SELECT * FROM logements WHERE reference = ?";
    return fetchOne($sql, [$reference]);
}

/**
 * Obtenir tous les logements
 * @return array
 */
function getAllLogements() {
    $sql = "SELECT * FROM logements ORDER BY reference ASC";
    return fetchAll($sql);
}

/**
 * Obtenir tous les contrats avec informations sur le logement
 * @param string|null $statut
 * @return array
 */
function getAllContracts($statut = null) {
    if ($statut) {
        $sql = "SELECT c.*, l.reference, l.adresse 
                FROM contrats c 
                INNER JOIN logements l ON c.logement_id = l.id 
                WHERE c.statut = ?
                ORDER BY c.date_creation DESC";
        return fetchAll($sql, [$statut]);
    } else {
        $sql = "SELECT c.*, l.reference, l.adresse 
                FROM contrats c 
                INNER JOIN logements l ON c.logement_id = l.id 
                ORDER BY c.date_creation DESC";
        return fetchAll($sql);
    }
}

/**
 * Formater une date en français
 * @param string $date
 * @param string $format
 * @return string
 */
function formatDateFr($date, $format = 'd/m/Y') {
    if (empty($date)) return '';
    return date($format, strtotime($date));
}

/**
 * Formater un montant en euros
 * @param float $montant
 * @return string
 */
function formatMontant($montant) {
    return number_format($montant, 0, ',', '') . '€';
}

/**
 * Redirection avec message
 * @param string $url
 * @param string|null $message
 * @param string $type
 */
function redirect($url, $message = null, $type = 'success') {
    if ($message) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }
    header("Location: $url");
    exit();
}

/**
 * Afficher et supprimer le message flash
 * @return array|null ['message' => string, 'type' => string]
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = [
            'message' => $_SESSION['flash_message'],
            'type' => $_SESSION['flash_type'] ?? 'success'
        ];
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        return $message;
    }
    return null;
}

/**
 * Get parameter value from database
 * @param string $cle Parameter key
 * @param mixed $default Default value if parameter not found
 * @return mixed
 */
function getParameter($cle, $default = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT valeur, type FROM parametres WHERE cle = ?");
        $stmt->execute([$cle]);
        $param = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$param) {
            return $default;
        }
        
        // Cast value based on type
        switch ($param['type']) {
            case 'integer':
                return (int)$param['valeur'];
            case 'float':
                return (float)$param['valeur'];
            case 'boolean':
                return $param['valeur'] === 'true' || $param['valeur'] === '1';
            case 'json':
                return json_decode($param['valeur'], true);
            default:
                return $param['valeur'];
        }
    } catch (PDOException $e) {
        error_log("Error getting parameter $cle: " . $e->getMessage());
        return $default;
    }
}

/**
 * Set parameter value in database
 * @param string $cle Parameter key
 * @param mixed $valeur Parameter value
 * @return bool
 */
function setParameter($cle, $valeur) {
    global $pdo;
    
    try {
        // Convert value to string based on type
        if (is_bool($valeur)) {
            $valeur = $valeur ? 'true' : 'false';
        } elseif (is_array($valeur)) {
            $valeur = json_encode($valeur);
        }
        
        // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both insert and update
        $stmt = $pdo->prepare("
            INSERT INTO parametres (cle, valeur, updated_at) 
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE valeur = ?, updated_at = NOW()
        ");
        return $stmt->execute([$cle, $valeur, $valeur]);
    } catch (PDOException $e) {
        error_log("Error setting parameter $cle: " . $e->getMessage());
        return false;
    }
}

/**
 * Get email template from database by identifier
 * @param string $identifiant Template identifier
 * @return array|false Template data or false if not found
 */
function getEmailTemplate($identifiant) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM email_templates WHERE identifiant = ? AND actif = 1");
        $stmt->execute([$identifiant]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting email template $identifiant: " . $e->getMessage());
        return false;
    }
}

/**
 * Replace template variables with actual values
 * @param string $template Template string with {{variable}} placeholders
 * @param array $data Associative array of variable => value pairs
 * @return string Processed template
 */
function replaceTemplateVariables($template, $data) {
    foreach ($data as $key => $value) {
        $placeholder = '{{' . $key . '}}';
        // Ensure value is a string
        $value = $value !== null ? (string)$value : '';
        // Don't escape HTML for variables that contain HTML content
        // SECURITY NOTE: These variables should already be sanitized before being passed here
        // - 'signature' is from trusted database/admin input
        // - 'commentaire' is user input that's already escaped with htmlspecialchars() before being wrapped in HTML
        // - 'status_paiements' is HTML generated server-side with htmlspecialchars() on user data
        // - 'bouton_interface' is HTML generated server-side with a trusted URL
        // - 'photos_html' / 'disponibilites_html' are HTML generated server-side with htmlspecialchars() on user data
        // - 'bouton_paiement' is HTML generated server-side with a trusted URL (Stripe payment button)
        $htmlExemptKeys = ['signature', 'commentaire', 'status_paiements', 'bouton_interface', 'photos_html', 'disponibilites_html', 'action_buttons_html', 'nb_heures_html', 'cout_materiaux_html', 'notes_intervention_html', 'motif_html', 'lignes_html', 'bouton_paiement'];
        if (in_array($key, $htmlExemptKeys, true)) {
            $template = str_replace($placeholder, $value, $template);
        } else {
            $template = str_replace($placeholder, htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $template);
        }
    }
    
    // Log warning if there are unreplaced variables (but ignore {{signature}} as it's handled in sendEmail)
    if (preg_match_all('/\{\{([^}]+)\}\}/', $template, $matches)) {
        $unreplaced = array_diff($matches[1], ['signature']);
        if (!empty($unreplaced)) {
            error_log("Warning: Unreplaced variables in template: " . implode(', ', $unreplaced));
        }
    }
    
    return $template;
}

/**
 * Send email using database template
 * @param string $templateId Template identifier
 * @param string $to Recipient email
 * @param array $variables Variables to replace in template
 * @param string|null $attachmentPath Optional attachment path
 * @param bool $isAdminEmail Whether this is an admin email (for CC to secondary admin)
 * @param bool $addAdminBcc Whether to add admins in BCC (for client emails where admins need invisible copy)
 * @param array $logContext Additional log context ['contexte' => 'contrat_id=5', ...]
 * @return bool Success status
 */
function sendTemplatedEmail($templateId, $to, $variables = [], $attachmentPath = null, $isAdminEmail = false, $addAdminBcc = false, $logContext = []) {
    global $config, $pdo;
    $template = getEmailTemplate($templateId);
    
    if (!$template) {
        error_log("Email template not found: $templateId");
        return false;
    }

    // Automatically inject 'company' if not already provided
    if (!isset($variables['company'])) {
        $companyName = '';
        try {
            $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'company_name' LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $companyName = ($row && !empty($row['valeur'])) ? $row['valeur'] : ($config['COMPANY_NAME'] ?? 'My Invest Immobilier');
        } catch (Exception $e) {
            $companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';
        }
        $variables['company'] = $companyName;
    }
    
    // Replace variables in subject and body
    $subject = replaceTemplateVariables($template['sujet'], $variables);
    $body = replaceTemplateVariables($template['corps_html'], $variables);
    
    // Send email using the existing sendEmail function
    return sendEmail($to, $subject, $body, $attachmentPath, true, $isAdminEmail, null, null, $addAdminBcc, array_merge(['template_id' => $templateId], $logContext));
}

if (!function_exists('formatRevenus')) {
/**
 * Format revenus_mensuels value for display
 * @param string|null $revenus Raw enum value from database
 * @return string Formatted display value
 */
function formatRevenus($revenus) {
    if ($revenus === null || $revenus === '') {
        return 'N/A';
    }
    
    if ($revenus === '< 2300') {
        return '< 2300 €';
    } elseif ($revenus === '2300-3000') {
        return '2300-3000 €';
    } elseif ($revenus === '3000+') {
        return '3000 € et +';
    }
    
    // Return raw value for any unexpected values
    return $revenus;
}
}

/**
 * Format statut value for display
 * @param string|null $statut Raw enum value from database
 * @return string Formatted display value
 */
function formatStatut($statut) {
    if ($statut === null || $statut === '') {
        return 'N/A';
    }
    
    $mapping = [
        'en_cours' => 'En cours',
        'refuse' => 'Refusé',
        'accepte' => 'Accepté',
        'visite_planifiee' => 'Refus après visite',
        'refus_apres_visite' => 'Refus après visite',
        'contrat_envoye' => 'Envoyé',
        'contrat_signe' => 'Signé'
    ];
    
    return $mapping[$statut] ?? ucfirst(str_replace('_', ' ', $statut));
}

/**
 * Evaluate if a candidature should be accepted based on defined criteria
 * 
 * Accepted candidatures receive status 'en_cours' (awaiting further processing)
 * Rejected candidatures receive status 'refuse' with a detailed rejection reason
 * 
 * Expected enum values for revenus_mensuels: '< 2300', '2300-3000', '3000+'
 * If database enum changes, this function must be updated accordingly.
 * 
 * Returns array with 'accepted' (bool) and 'motif' (string) keys
 * 
 * @param array $candidature Candidature data from database
 * @return array ['accepted' => bool, 'motif' => string, 'statut' => string]
 */
function evaluateCandidature($candidature) {
    // Get parameters from database
    $revenusMinRequis = getParameter('revenus_min_requis', 3000);
    $statutsProAcceptes = getParameter('statuts_pro_acceptes', ['CDI', 'CDD']);
    $typeRevenusAccepte = getParameter('type_revenus_accepte', 'Salaires');
    $nbOccupantsAcceptes = getParameter('nb_occupants_acceptes', ['1', '2']);
    $garantieVisaleRequise = getParameter('garantie_visale_requise', true);
    $cdiPeriodeEssaiBloque = getParameter('cdi_periode_essai_bloque', true);
    
    $motifs = [];
    
    // RULE 1: Professional situation - must be CDI or CDD
    if (!in_array($candidature['statut_professionnel'], $statutsProAcceptes)) {
        $motifs[] = "Statut professionnel non accepté (doit être CDI ou CDD)";
    }
    
    // RULE 2: Monthly net income - must be >= 3000€
    // Convert enum values to numeric for comparison
    $revenus = $candidature['revenus_mensuels'];
    if ($revenus === '< 2300' || $revenus === '2300-3000') {
        $motifs[] = "Revenus nets mensuels insuffisants (minimum 3000€ requis)";
    }
    
    // RULE 3: Income type - must be Salaires
    if ($candidature['type_revenus'] !== $typeRevenusAccepte) {
        $motifs[] = "Type de revenus non accepté (doit être: $typeRevenusAccepte)";
    }
    
    // RULE 4: Number of occupants - must be 1 or 2 (not "Autre")
    if (!in_array($candidature['nb_occupants'], $nbOccupantsAcceptes)) {
        $motifs[] = "Nombre d'occupants non accepté (doit être 1 ou 2)";
    }
    
    // RULE 5: Visale guarantee
    // Normalize the stored parameter value
    $gvParam = is_bool($garantieVisaleRequise)
        ? ($garantieVisaleRequise ? 'oui' : 'je_ne_sais_pas')
        : strtolower((string)$garantieVisaleRequise);
    $gvParam = ($gvParam === 'true') ? 'oui' : $gvParam;
    $gvParam = ($gvParam === 'false') ? 'je_ne_sais_pas' : $gvParam;

    $candidatVisale = $candidature['garantie_visale'] ?? '';

    if ($gvParam === 'oui') {
        // Visale is required: only "Oui" is acceptable
        if ($candidatVisale !== 'Oui') {
            $motifs[] = "Garantie Visale requise";
        }
    } elseif ($gvParam === 'non') {
        // Visale is not required, but an explicit "Non" from the candidate is still rejected
        if ($candidatVisale === 'Non') {
            $motifs[] = "Candidat ne peut pas bénéficier de la garantie Visale";
        }
    }
    // For 'je_ne_sais_pas': no rejection based on Visale
    
    // RULE 6: If CDI, trial period must be passed (configurable via parameter)
    if ($cdiPeriodeEssaiBloque &&
        $candidature['statut_professionnel'] === 'CDI' && 
        isset($candidature['periode_essai']) && 
        $candidature['periode_essai'] === 'En cours') {
        $motifs[] = "Période d'essai en cours";
    }
    
    // All criteria must be met for acceptance
    $accepted = empty($motifs);
    $motif = $accepted ? '' : implode(', ', $motifs);
    
    // Determine the status value to use
    $statut = $accepted ? 'en_cours' : 'refuse';
    
    return [
        'accepted' => $accepted,
        'motif' => $motif,
        'statut' => $statut
    ];
}

/**
 * Calculate scheduled response date based on current delay parameters
 * @param DateTime $fromDate The starting date (usually candidature creation date)
 * @return DateTime The calculated scheduled response date
 */
function calculateScheduledResponseDate($fromDate) {
    $delaiValeur = (int)getParameter('delai_reponse_valeur', 4);
    $delaiUnite = getParameter('delai_reponse_unite', 'jours');
    
    $scheduledDate = clone $fromDate;
    
    if ($delaiUnite === 'jours') {
        // Add business days (skip weekends)
        $daysAdded = 0;
        while ($daysAdded < $delaiValeur) {
            $scheduledDate->modify('+1 day');
            // Skip weekends (Saturday = 6, Sunday = 7)
            if ($scheduledDate->format('N') < 6) {
                $daysAdded++;
            }
        }
    } elseif ($delaiUnite === 'heures') {
        $scheduledDate->modify("+{$delaiValeur} hours");
    } elseif ($delaiUnite === 'minutes') {
        $scheduledDate->modify("+{$delaiValeur} minutes");
    }
    
    return $scheduledDate;
}

/**
 * Get parameter value from parametres table
 * @param string $cle Parameter key
 * @return string|null Parameter value or null if not found
 */
function getParametreValue($cle) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = ?");
    $stmt->execute([$cle]);
    $result = $stmt->fetchColumn();
    
    return $result !== false ? $result : null;
}

/**
 * Normalise le chemin brut de la signature société tel que stocké en base de données.
 *
 * La signature peut être stockée comme simple nom de fichier (ex: company_signature_xxx.png)
 * ou comme chemin relatif déjà préfixé par uploads/ ou uploads/signatures/.
 * Cette fonction retourne toujours un chemin relatif commençant par uploads/.
 *
 * @param string $rawPath Valeur brute issue de la table parametres
 * @return string Chemin relatif normalisé (ex: uploads/company_signature_xxx.png)
 */
function normalizeCompanySignaturePath(string $rawPath): string {
    if (empty($rawPath) || strpos($rawPath, 'data:image') === 0) {
        return $rawPath;
    }
    // Already a relative path with uploads/ prefix — return as-is
    if (strpos($rawPath, 'uploads/') === 0) {
        return $rawPath;
    }
    // Just a filename — prepend uploads/
    return 'uploads/' . $rawPath;
}

/**
 * Retourne l'URL absolue (publique) de la signature société configurée dans les paramètres.
 *
 * Gère les cas suivants :
 *  - Fichier stocké comme nom seul : company_signature_xxx.png  → uploads/company_signature_xxx.png
 *  - Fichier avec préfixe uploads/ ou uploads/signatures/ (anciens formats)
 *  - Data URI base64 (héritage)
 *
 * @param array  $config     Configuration applicative (doit contenir SITE_URL)
 * @param string $paramKey   Clé du paramètre d'image (défaut : signature_societe_image)
 * @param string $enabledKey Clé du paramètre d'activation (défaut : signature_societe_enabled).
 *                           Passer une chaîne vide pour ignorer la vérification d'activation.
 * @return string URL absolue ou data URI, chaîne vide si indisponible/désactivée
 */
function getCompanySignatureUrl(array $config, string $paramKey = 'signature_societe_image', string $enabledKey = 'signature_societe_enabled'): string {
    // Check enabled flag if a key is provided
    if (!empty($enabledKey)) {
        $enabled = getParameter($enabledKey, false);
        if (!toBooleanParam($enabled)) {
            return '';
        }
    }

    $rawPath = getParameter($paramKey, '');
    if (empty($rawPath)) {
        return '';
    }

    // Handle legacy base64 data URIs
    if (strpos($rawPath, 'data:image') === 0) {
        return $rawPath;
    }

    $relPath = normalizeCompanySignaturePath($rawPath);

    // Verify the file exists on disk
    $baseDir = dirname(__DIR__);
    if (!file_exists($baseDir . '/' . $relPath)) {
        error_log("getCompanySignatureUrl: fichier signature introuvable : {$baseDir}/{$relPath}");
        return '';
    }

    return rtrim($config['SITE_URL'], '/') . '/' . $relPath;
}

/**
 * Convert a parameter value to boolean
 * Handles both boolean and string 'true'/'false' values for consistency
 * @param mixed $value The value to convert (can be bool, string, or null)
 * @return bool
 */
function toBooleanParam($value) {
    if (is_bool($value)) {
        return $value;
    }
    if (is_string($value)) {
        return $value === 'true' || $value === '1';
    }
    return false;
}

/**
 * Get admin email from config, falling back to the first active admin in the database
 * @return string Admin email address, or empty string if none found
 */
function getAdminEmail() {
    global $config, $pdo;

    // Prefer the configured value when it is set
    $email = $config['ADMIN_EMAIL'] ?? '';
    if (!empty($email)) {
        return $email;
    }

    // Fallback: retrieve the first active administrator email from the database
    if ($pdo) {
        try {
            $stmt = $pdo->prepare(
                "SELECT email FROM administrateurs WHERE actif = TRUE AND email IS NOT NULL AND email != '' ORDER BY id ASC LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row['email'];
            }
        } catch (Exception $e) {
            error_log("getAdminEmail: impossible de récupérer l'email admin depuis la base de données: " . $e->getMessage());
        }
    }

    return '';
}

/**
 * Get the email address of the collaborateur designated as Service Technique
 * @return string|null Email address or null if not configured
 */
function getServiceTechniqueEmail() {
    global $pdo;
    if (!$pdo) return null;
    try {
        $stmt = $pdo->prepare(
            "SELECT email FROM collaborateurs WHERE service_technique = 1 AND actif = 1 AND email IS NOT NULL AND email <> '' LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['email'] : null;
    } catch (Exception $e) {
        return null; // Column may not exist yet if migration not applied
    }
}

/**
 * Get or create a signalements_collaborateurs entry for the Service Technique collaborateur.
 * Returns the action_token that can be used in email action buttons.
 *
 * @param int $signalementId
 * @return string|null The action token, or null if ST not configured or DB error
 */
function getOrCreateServiceTechniqueToken($signalementId) {
    global $pdo;
    if (!$pdo) return null;
    try {
        // Get the ST collaborateur
        $stStmt = $pdo->prepare(
            "SELECT id, nom, email FROM collaborateurs WHERE service_technique = 1 AND actif = 1 AND email IS NOT NULL AND email <> '' LIMIT 1"
        );
        $stStmt->execute();
        $st = $stStmt->fetch(PDO::FETCH_ASSOC);
        if (!$st || empty($st['email'])) return null;

        // Check if entry already exists for this signalement + ST email
        $existsStmt = $pdo->prepare(
            "SELECT id, action_token FROM signalements_collaborateurs WHERE signalement_id = ? AND collaborateur_email = ? ORDER BY id DESC LIMIT 1"
        );
        $existsStmt->execute([$signalementId, $st['email']]);
        $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if (!empty($existing['action_token'])) {
                return $existing['action_token'];
            }
            // Entry exists but has no token — generate and store one
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE signalements_collaborateurs SET action_token = ? WHERE id = ?")
                ->execute([$token, $existing['id']]);
            return $token;
        }

        // Create new entry
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("
            INSERT INTO signalements_collaborateurs
                (signalement_id, collaborateur_id, collaborateur_nom, collaborateur_email, mode_notification, attribue_par, action_token)
            VALUES (?, ?, ?, ?, 'email', 'system', ?)
        ")->execute([$signalementId, $st['id'], $st['nom'], $st['email'], $token]);
        return $token;
    } catch (Exception $e) {
        error_log('getOrCreateServiceTechniqueToken error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Build the HTML block containing the 4 action buttons for service technique emails.
 *
 * @param string $baseActionUrl  Base URL for collab-action.php (with ?token=…)
 * @param string $termineUrl     URL for intervention-terminee.php (with ?token=…)
 * @return string HTML snippet
 */
function buildSignalementActionButtonsHtml($baseActionUrl, $termineUrl) {
    return '<div style="margin: 25px 0; text-align: center;">'
        . '<p style="font-weight: bold; margin-bottom: 15px;">Actions rapides :</p>'
        . '<div style="display: flex; flex-wrap: wrap; gap: 10px; justify-content: center;">'
        . '<a href="' . $baseActionUrl . '&amp;action=pris_en_charge" style="display:inline-block;background:#3498db;color:white;padding:12px 18px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:14px;">🔵 Pris en charge</a>'
        . '<a href="' . $baseActionUrl . '&amp;action=sur_place" style="display:inline-block;background:#e67e22;color:white;padding:12px 18px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:14px;">🟠 Sur place</a>'
        . '<a href="' . $termineUrl . '" style="display:inline-block;background:#27ae60;color:white;padding:12px 18px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:14px;">🟢 Terminé</a>'
        . '<a href="' . $baseActionUrl . '&amp;action=impossible" style="display:inline-block;background:#e74c3c;color:white;padding:12px 18px;text-decoration:none;border-radius:6px;font-weight:bold;font-size:14px;">🔴 Impossible</a>'
        . '</div></div>';
}

/**
 * Get equipment quantity with backward compatibility
 * Supports new simplified structure and old entry/exit structure
 * 
 * @param array $eq Equipment item data
 * @return mixed Quantity value (may be empty string or integer)
 */
function getInventaireEquipmentQuantity($eq) {
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
 * Renders a property-search form that redirects to /logements.php?ref=<value>.
 *
 * This function generates the complete HTML block for the search engine widget,
 * including its icon, label, input field and submit button.
 * The rendered HTML uses the .search-logements-form CSS class defined in
 * assets/css/frontoffice.css for its visual design.
 *
 * Usage (shortcode in CMS page content):
 *   [search-logements]
 *
 * Usage (direct PHP call):
 *   <?php echo renderSearchLogementsHtml($siteUrl); ?>
 *
 * Usage with a pre-filled value (e.g. on the logements listing page):
 *   <?php echo renderSearchLogementsHtml($siteUrl, $filterRef); ?>
 *
 * @param string $siteUrl      Base URL of the application (e.g. https://example.com).
 *                             Used to build the absolute action URL for the form.
 * @param string $currentValue Optional pre-filled value for the reference input field.
 * @return string              Complete HTML of the search block.
 */
function renderSearchLogementsHtml(string $siteUrl, string $currentValue = ''): string
{
    $action      = htmlspecialchars(rtrim($siteUrl, '/') . '/logements.php');
    $inputValue  = $currentValue !== '' ? ' value="' . htmlspecialchars($currentValue) . '"' : '';
    return '<form method="GET" action="' . $action . '" class="search-logements-form" role="search">'
        . '<div class="search-icon" aria-hidden="true">🔍</div>'
        . '<div class="search-text">'
        . '<label>Référence logement :</label>'
        . '<input type="text" name="ref" class="form-control"'
        . $inputValue
        . ' placeholder="Ex: RF-001" aria-label="Référence du logement">'
        . '</div>'
        . '<button type="submit" class="search-btn" aria-label="Rechercher">'
        . '<i class="bi bi-search" aria-hidden="true"></i>'
        . '</button>'
        . '</form>';
}

/**
 * Process shortcodes embedded in page content.
 *
 * Supported shortcodes:
 *   [contact-form id=N]   — renders a dynamic contact form
 *   [search-logements]    — renders a property-search form pointing to logements.php
 *
 * Returns the HTML with shortcodes replaced by rendered HTML.
 *
 * @param string $html    Raw HTML content that may contain shortcodes.
 * @param \PDO   $pdo     Active database connection (used for [contact-form]).
 * @param string $siteUrl Base URL of the application.
 * @return string         HTML with all shortcodes replaced by their rendered output.
 */
function processShortcodes(string $html, \PDO $pdo, string $siteUrl): string
{
    // [search-logements] — search box that redirects to the properties listing page
    $html = preg_replace_callback(
        '/\[search-logements(?:\s[^\]]*)?\]/i',
        function () use ($siteUrl): string {
            return renderSearchLogementsHtml($siteUrl);
        },
        $html
    );

    // [contact-form id=N]
    $html = preg_replace_callback(
        '/\[contact-form\s+id=["\']?(\d+)["\']?\]/i',
        function (array $m) use ($pdo, $siteUrl): string {
            $formId = (int)$m[1];
            try {
                $stmt = $pdo->prepare("SELECT * FROM contact_forms WHERE id = ? AND actif = 1 LIMIT 1");
                $stmt->execute([$formId]);
                $form = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$form) {
                    return '<!-- contact-form #' . $formId . ' not found -->';
                }
                $stmtF = $pdo->prepare("SELECT * FROM contact_form_fields WHERE form_id = ? ORDER BY ordre ASC, id ASC");
                $stmtF->execute([$formId]);
                $fields = $stmtF->fetchAll(\PDO::FETCH_ASSOC);
                return renderContactFormHtml($form, $fields, $siteUrl);
            } catch (\Exception $e) {
                return '<!-- contact-form error: ' . htmlspecialchars($e->getMessage()) . ' -->';
            }
        },
        $html
    );

    return $html;
}

/**
 * Compute duree_garantie (in months) dynamically from type_contrat.
 * - meuble    → 2 mois
 * - non_meuble → 1 mois
 * - sur_mesure → 2 mois
 *
 * @param string $type_contrat  Value from logements.type_contrat
 * @return int Number of months
 */
function getDureeGarantie(string $type_contrat): int {
    return ($type_contrat === 'non_meuble') ? 1 : 2;
}

/**
 * Create a secure, expiring download token for a document.
 *
 * Inserts a record into the document_tokens table and returns the public URL
 * (SITE_URL/telecharger.php?token=xxx) that can be embedded in emails.
 *
 * @param string $filePath    Absolute path to the file on disk
 * @param string $type        Document type label, e.g. 'dpe', 'quittance', 'etat_lieux', 'inventaire', 'bilan'
 * @param string $fileName    Suggested download filename (e.g. 'DPE.pdf')
 * @param int    $expiryDays  Token validity in days (default 30)
 * @return string|null  Public download URL, or null on failure
 */
function createDocumentToken(string $filePath, string $type, string $fileName = '', int $expiryDays = 30): ?string {
    global $pdo, $config;

    if (!file_exists($filePath)) {
        error_log("createDocumentToken: file not found: $filePath");
        return null;
    }

    try {
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));
        $safeName  = $fileName ?: basename($filePath);

        $stmt = $pdo->prepare(
            "INSERT INTO document_tokens (token, file_path, file_name, type, expires_at)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$token, $filePath, $safeName, $type, $expiresAt]);

        $siteUrl = rtrim($config['SITE_URL'] ?? '', '/');
        return $siteUrl . '/telecharger.php?token=' . $token;
    } catch (Exception $e) {
        error_log("createDocumentToken error: " . $e->getMessage());
        return null;
    }
}


/**
 * Convert a document file path to a full public URL pointing to the physical file.
 *
 * Accepts either:
 * - A relative path from the project root, e.g. 'pdf/cautions/caution-solidaire-REF-1.pdf'
 * - An absolute server path, e.g. '/var/www/html/contrat-bail/pdf/quittances/quittance-REF.pdf'
 *
 * @param string $path  File path (absolute server path or relative to project root)
 * @return string  Full public URL to the physical file
 */
function documentPathToUrl(string $path): string {
    global $config;
    $siteUrl = rtrim($config['SITE_URL'] ?? '', '/');

    // If the path is absolute, strip the project root to get the web-relative portion
    if (str_starts_with($path, '/')) {
        $projectRoot = dirname(__FILE__, 2);
        if (str_starts_with($path, $projectRoot)) {
            $path = ltrim(substr($path, strlen($projectRoot)), '/');
        }
    }

    if ($path === '') {
        return $siteUrl;
    }

    return $siteUrl . '/' . ltrim($path, '/');
}


// ===========================================================
// MODULE GARANT / GARANTIE
// ===========================================================

/**
 * Créer un enregistrement garant et générer un token unique.
 *
 * @param int    $contratId      ID du contrat concerné
 * @param string $typeGarantie   'visale' ou 'caution_solidaire'
 * @param array  $data           Données du garant (nom, prenom, email, …)
 * @return array|false ['id' => int, 'token' => string] ou false en cas d'erreur
 */
function createGarant(int $contratId, string $typeGarantie, array $data = []) {
    global $pdo;

    $token = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare("
        INSERT INTO garants (
            contrat_id, type_garantie, token_garant,
            nom, prenom, date_naissance, email, telephone, adresse, ville, code_postal,
            numero_visale,
            date_envoi_invitation
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $result = $stmt->execute([
        $contratId,
        $typeGarantie,
        $token,
        $data['nom']            ?? null,
        $data['prenom']         ?? null,
        $data['date_naissance'] ?? null,
        $data['email']          ?? null,
        $data['telephone']      ?? null,
        $data['adresse']        ?? null,
        $data['ville']          ?? null,
        $data['code_postal']    ?? null,
        $data['numero_visale']  ?? null,
    ]);

    if (!$result) {
        return false;
    }

    $id = (int)$pdo->lastInsertId();
    logAction($contratId, 'creation_garant', "Type: $typeGarantie, Token: $token");

    return ['id' => $id, 'token' => $token];
}

/**
 * Supprimer les garants en attente pour un contrat avant d'en créer un nouveau.
 * Ne supprime que les garants au statut 'en_attente_garant'.
 *
 * @param int $contratId
 * @return int Nombre de lignes supprimées
 */
function deleteGarantsPending(int $contratId): int {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM garants WHERE contrat_id = ? AND statut = 'en_attente_garant'");
    $stmt->execute([$contratId]);
    return (int)$stmt->rowCount();
}

/**
 * Récupérer un garant par son token.
 *
 * @param string $token
 * @return array|false
 */
function getGarantByToken(string $token) {
    return fetchOne("
        SELECT g.*,
               c.reference_unique  AS reference_contrat,
               c.statut            AS statut_contrat,
               l.adresse           AS adresse_logement,
               l.reference         AS reference_logement,
               l.loyer,
               l.charges
        FROM garants g
        INNER JOIN contrats c ON g.contrat_id = c.id
        INNER JOIN logements l ON c.logement_id = l.id
        WHERE g.token_garant = ?
    ", [$token]);
}

/**
 * Récupérer le garant actif d'un contrat (dernier enregistrement).
 *
 * @param int $contratId
 * @return array|false
 */
function getGarantByContratId(int $contratId) {
    return fetchOne("
        SELECT * FROM garants
        WHERE contrat_id = ?
        ORDER BY id DESC
        LIMIT 1
    ", [$contratId]);
}

/**
 * Mettre à jour le statut d'un garant.
 *
 * @param int    $garantId
 * @param string $statut
 * @return bool
 */
function updateGarantStatut(int $garantId, string $statut): bool {
    $dateColMap = [
        'engage'          => 'date_engagement',
        'signe'           => 'date_signature',
        'documents_recus' => 'date_documents',
    ];
    $dateCol = $dateColMap[$statut] ?? null;

    if ($dateCol) {
        $stmt = executeQuery(
            "UPDATE garants SET statut = ?, $dateCol = NOW() WHERE id = ?",
            [$statut, $garantId]
        );
    } else {
        $stmt = executeQuery(
            "UPDATE garants SET statut = ? WHERE id = ?",
            [$statut, $garantId]
        );
    }

    return $stmt !== false;
}

/**
 * Enregistrer la signature électronique d'un garant.
 *
 * @param int    $garantId
 * @param string $signatureData  Données base64 de la signature
 * @param int    $certifieExact  1 si case cochée
 * @return bool
 */
function saveGarantSignature(int $garantId, string $signatureData, int $certifieExact = 1): bool {
    global $config;

    // Décoder le base64 et sauvegarder en fichier JPEG
    if (!preg_match('/^data:image\/(png|jpeg|jpg);base64,(.+)$/s', $signatureData, $matches)) {
        error_log("saveGarantSignature: format de données invalide pour garant ID $garantId");
        return false;
    }

    $imageData = base64_decode($matches[2]);
    if ($imageData === false) {
        error_log("saveGarantSignature: décodage base64 échoué pour garant ID $garantId");
        return false;
    }

    $sigDir = $config['UPLOAD_DIR'] . 'signatures/';
    if (!is_dir($sigDir)) {
        mkdir($sigDir, 0755, true);
    }

    $filename  = 'garant_' . bin2hex(random_bytes(16)) . '.jpg';
    $filepath  = $sigDir . $filename;

    if (file_put_contents($filepath, $imageData) === false) {
        error_log("saveGarantSignature: impossible d'écrire le fichier $filepath");
        return false;
    }

    $relativePath = 'uploads/signatures/' . $filename;

    $stmt = executeQuery(
        "UPDATE garants
         SET signature_data = ?, signature_ip = ?, signature_timestamp = NOW(), certifie_exact = ?, statut = 'signe', date_signature = NOW()
         WHERE id = ?",
        [$relativePath, getClientIp(), $certifieExact, $garantId]
    );

    return $stmt !== false;
}

/**
 * Obtenir le libellé d'un statut garant.
 *
 * @param string $statut
 * @return string
 */
function formatGarantStatut(string $statut): string {
    $labels = [
        'en_attente_garant' => 'En attente du garant',
        'engage'            => 'Garant engagé',
        'signe'             => 'Document signé',
        'documents_recus'   => 'Documents reçus',
    ];
    return $labels[$statut] ?? ucfirst(str_replace('_', ' ', $statut));
}

/**
 * Obtenir la classe Bootstrap badge pour un statut garant.
 *
 * @param string $statut
 * @return string
 */
function getGarantStatutBadgeClass(string $statut): string {
    $classes = [
        'en_attente_garant' => 'bg-warning text-dark',
        'engage'            => 'bg-info',
        'signe'             => 'bg-primary',
        'documents_recus'   => 'bg-success',
    ];
    return $classes[$statut] ?? 'bg-secondary';
}
