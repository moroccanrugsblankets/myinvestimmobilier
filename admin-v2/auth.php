<?php
/**
 * Admin Authentication Check
 * Include this file at the top of all admin pages
 */
// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if this is an AJAX request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
// Also check if the request expects JSON response (for fetch API calls)
$expectsJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

/**
 * Send JSON error response for AJAX requests
 */
function sendAjaxAuthError($message, $redirectUrl) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => $message, 'redirect' => $redirectUrl]);
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    // Try to restore session from persistent cookie before giving up
    if (isset($_COOKIE['admin_remember'])) {
        $cookieValue = $_COOKIE['admin_remember'];
        if (preg_match('/^([a-f0-9]{64}):(\d+)$/', $cookieValue, $matches)) {
            $cookieToken = $matches[1];
            $cookieAdminId = (int)$matches[2];
            try {
                require_once __DIR__ . '/../includes/db.php';
                $stmt = $pdo->prepare("SELECT * FROM administrateurs WHERE id = ? AND actif = 1 AND remember_token IS NOT NULL");
                $stmt->execute([$cookieAdminId]);
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($admin && hash_equals($admin['remember_token'], $cookieToken)) {
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_username'] = $admin['username'];
                    $_SESSION['admin_nom'] = $admin['nom'] ?? '';
                    $_SESSION['admin_prenom'] = $admin['prenom'] ?? '';
                    $_SESSION['last_activity'] = time();
                    $_SESSION['remember_me'] = true;
                    $pdo->prepare("UPDATE administrateurs SET derniere_connexion = NOW() WHERE id = ?")->execute([$admin['id']]);
                }
            } catch (Exception $e) {
                error_log("Auth remember cookie error: " . $e->getMessage());
            }
        }
    }
    
    // If still not authenticated, redirect to login
    if (!isset($_SESSION['admin_id'])) {
        if ($isAjax || $expectsJson) {
            session_destroy();
            sendAjaxAuthError('Session expirée. Veuillez vous reconnecter.', 'login.php');
        }
        // Save the originally requested URL so we can redirect after login
        $requestedUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . ($_SERVER['REQUEST_URI'] ?? '');
        // Only save URLs on the same host to prevent open redirect attacks
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host && strpos($requestedUrl, 'http') === 0) {
            $parsedHost = parse_url($requestedUrl, PHP_URL_HOST);
            if ($parsedHost === $host) {
                $_SESSION['redirect_after_login'] = $requestedUrl;
            }
        }
        header('Location: login.php');
        exit;
    }
}

// Session timeout: 2 hours for normal sessions, 30 days for "Rester connecté"
$sessionTimeout = !empty($_SESSION['remember_me']) ? (30 * 24 * 3600) : 7200;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $sessionTimeout)) {
    // Clear remember cookie if set
    if (isset($_COOKIE['admin_remember'])) {
        setcookie('admin_remember', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
    }
    session_destroy();
    if ($isAjax || $expectsJson) {
        sendAjaxAuthError('Session expirée (timeout). Veuillez vous reconnecter.', 'login.php?timeout=1');
    }
    header('Location: login.php?timeout=1');
    exit;
}

$_SESSION['last_activity'] = time();

// Make admin info available
$admin_id = $_SESSION['admin_id'];
$admin_username = $_SESSION['admin_username'];
$admin_nom = $_SESSION['admin_nom'] ?? '';
$admin_prenom = $_SESSION['admin_prenom'] ?? '';
?>
