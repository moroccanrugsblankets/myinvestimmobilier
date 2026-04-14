<?php
/**
 * Admin Login Page
 */

/** Lifetime of the "remember me" persistent session: 30 days in seconds. */
define('ADMIN_REMEMBER_LIFETIME', 30 * 24 * 3600);

// Start session before any session variable access
if (session_status() === PHP_SESSION_NONE) {
    // When the admin already has a persistent "remember me" cookie, configure a
    // long-lived session so the PHPSESSID cookie is persistent from the very first
    // response (including the login page itself), not just after the first redirect.
    if (isset($_COOKIE['admin_remember']) && preg_match('/^[a-f0-9]{64}:\d+$/', $_COOKIE['admin_remember'])) {
        ini_set('session.gc_maxlifetime', ADMIN_REMEMBER_LIFETIME);
        session_set_cookie_params([
            'lifetime' => ADMIN_REMEMBER_LIFETIME,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
    }
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$error = '';

// If already logged in, redirect to dashboard
if (isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

// Check for persistent login cookie
if (isset($_COOKIE['admin_remember'])) {
    $cookieValue = $_COOKIE['admin_remember'];
    // Format: token:admin_id (token is 64-char hex)
    if (preg_match('/^([a-f0-9]{64}):(\d+)$/', $cookieValue, $matches)) {
        $cookieToken = $matches[1];
        $cookieAdminId = (int)$matches[2];
        try {
            $stmt = $pdo->prepare("SELECT * FROM administrateurs WHERE id = ? AND actif = 1 AND remember_token IS NOT NULL");
            $stmt->execute([$cookieAdminId]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($admin && hash_equals($admin['remember_token'], hash('sha256', $cookieToken))) {
                // Valid persistent token — restore session
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_nom'] = $admin['nom'];
                $_SESSION['admin_prenom'] = $admin['prenom'];
                $_SESSION['last_activity'] = time();
                $_SESSION['remember_me'] = true;
                $pdo->prepare("UPDATE administrateurs SET derniere_connexion = NOW() WHERE id = ?")->execute([$admin['id']]);
                header('Location: index.php');
                exit;
            }
        } catch (Exception $e) {
            error_log("Remember cookie check error: " . $e->getMessage());
        }
    }
}

if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember_me']);

    try {
        $stmt = $pdo->prepare("SELECT * FROM administrateurs WHERE username = ? AND actif = 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin && password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_nom'] = $admin['nom'];
            $_SESSION['admin_prenom'] = $admin['prenom'];
            $_SESSION['last_activity'] = time();

            // Update last login
            $pdo->prepare("UPDATE administrateurs SET derniere_connexion = NOW() WHERE id = ?")->execute([$admin['id']]);

            // Handle "Rester connecté" persistent cookie
            if ($rememberMe) {
                $token = bin2hex(random_bytes(32));
                $cookieValue = $token . ':' . $admin['id'];
                $expiry = time() + (365 * 24 * 3600); // 1 year
                try {
                    $tokenHash = hash('sha256', $token);
                    $pdo->prepare("UPDATE administrateurs SET remember_token = ? WHERE id = ?")->execute([$tokenHash, $admin['id']]);
                    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                    setcookie('admin_remember', $cookieValue, [
                        'expires' => $expiry,
                        'path' => '/',
                        'httponly' => true,
                        'samesite' => 'Strict',
                        'secure' => $isSecure,
                    ]);
                    $_SESSION['remember_me'] = true;
                    // Upgrade the PHP session cookie to a 30-day persistent cookie so it
                    // survives browser restarts from this very response, not just the next page.
                    ini_set('session.gc_maxlifetime', ADMIN_REMEMBER_LIFETIME);
                    session_set_cookie_params([
                        'lifetime' => ADMIN_REMEMBER_LIFETIME,
                        'path'     => '/',
                        'httponly' => true,
                        'samesite' => 'Strict',
                        'secure'   => $isSecure,
                    ]);
                    session_regenerate_id(true); // true = delete old session file
                } catch (Exception $e) {
                    error_log("Remember token save error: " . $e->getMessage());
                }
            }

            // Redirect to originally requested URL if available and on the same host
            $redirectUrl = 'index.php';
            if (!empty($_SESSION['redirect_after_login'])) {
                $savedUrl = $_SESSION['redirect_after_login'];
                $parsedHost = parse_url($savedUrl, PHP_URL_HOST);
                if ($parsedHost === ($_SERVER['HTTP_HOST'] ?? '')) {
                    $redirectUrl = $savedUrl;
                }
                unset($_SESSION['redirect_after_login']);
            }
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            $error = "Identifiants incorrects.";
        }
    } catch (Exception $e) {
        $error = "Erreur de connexion.";
        error_log("Login error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - My Invest Immobilier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 400px;
            width: 100%;
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
            color: #2c3e50;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo">
            <h2>My Invest Immobilier</h2>
            <p class="text-muted">Administration</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['timeout'])): ?>
            <div class="alert alert-warning">Votre session a expiré. Veuillez vous reconnecter.</div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label for="username" class="form-label">Nom d'utilisateur</label>
                <input type="text" class="form-control" id="username" name="username" required autofocus>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Mot de passe</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>

            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me">
                <label class="form-check-label" for="remember_me">Se souvenir de moi</label>
            </div>

            <div class="d-grid">
                <button type="submit" name="login" class="btn btn-primary btn-lg">Se connecter</button>
            </div>
        </form>

        <p class="text-center text-muted mt-4 mb-0">
            <small>Accès réservé aux administrateurs</small>
        </p>
    </div>
</body>
</html>
