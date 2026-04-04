<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Clear remember_token from DB if admin was logged in
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['admin_id'])) {
    try {
        $pdo->prepare("UPDATE administrateurs SET remember_token = NULL WHERE id = ?")->execute([$_SESSION['admin_id']]);
    } catch (Exception $e) {
        error_log("Logout clear token error: " . $e->getMessage());
    }
}

// Clear remember cookie
if (isset($_COOKIE['admin_remember'])) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('admin_remember', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => $isSecure]);
}

session_destroy();
header('Location: login.php');
exit;
?>
