<?php
/**
 * Admin Authentication Check
 * Include this file at the top of all admin pages
 */
// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    if ($isAjax || $expectsJson) {
        // Clean up session before responding
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

// Auto-logout after 2 hours of inactivity
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 7200)) {
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
