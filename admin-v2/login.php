<?php
/**
 * Admin Login Page
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$error = '';

if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM administrateurs WHERE username = ? AND actif = 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin && password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_nom'] = $admin['nom'];
            $_SESSION['admin_prenom'] = $admin['prenom'];
            
            // Update last login
            $updateStmt = $pdo->prepare("UPDATE administrateurs SET derniere_connexion = NOW() WHERE id = ?");
            $updateStmt->execute([$admin['id']]);
            
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
        
        <form method="POST">
            <div class="mb-3">
                <label for="username" class="form-label">Nom d'utilisateur</label>
                <input type="text" class="form-control" id="username" name="username" required autofocus>
            </div>
            
            <div class="mb-3">
                <label for="password" class="form-label">Mot de passe</label>
                <input type="password" class="form-control" id="password" name="password" required>
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
