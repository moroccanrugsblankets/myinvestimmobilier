<?php
/**
 * CONFIGURATION DU PAIEMENT EN LIGNE (STRIPE/SEPA)
 *
 * Interface pour configurer :
 * - Les clés API Stripe (test / production)
 * - Le secret webhook
 * - Les jours d'envoi automatique (invitation + rappels)
 * - Les méthodes de paiement acceptées
 */

require_once '../includes/config.php';
require_once 'auth.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Charger le SDK Stripe si disponible (pour tester la connexion)
$stripeDisponible = file_exists(__DIR__ . '/../vendor/autoload.php');
if ($stripeDisponible) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Récupérer la valeur d'un paramètre
function getParam(PDO $pdo, string $cle, $default = null) {
    try {
        $stmt = $pdo->prepare("SELECT valeur, type FROM parametres WHERE cle = ?");
        $stmt->execute([$cle]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $default;
        switch ($row['type']) {
            case 'boolean': return filter_var($row['valeur'], FILTER_VALIDATE_BOOLEAN);
            case 'integer': return (int)$row['valeur'];
            case 'json':    return json_decode($row['valeur'], true);
            default:        return $row['valeur'];
        }
    } catch (Exception $e) {
        return $default;
    }
}

// Enregistrer un paramètre
function setParam(PDO $pdo, string $cle, $valeur): void {
    $pdo->prepare("UPDATE parametres SET valeur = ?, updated_at = NOW() WHERE cle = ?")
        ->execute([$valeur, $cle]);
}

// ─── Traitement du formulaire ────────────────────────────────────────────────
$errors = [];
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Vérification CSRF
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token CSRF invalide. Veuillez recharger la page.';
    } else {
        $action = $_POST['action'];

        if ($action === 'save_config') {
            $stripeActif  = isset($_POST['stripe_actif']) ? '1' : '0';
            $stripeMode   = in_array($_POST['stripe_mode'] ?? 'test', ['test', 'live']) ? $_POST['stripe_mode'] : 'test';
            $pkTest       = trim($_POST['stripe_public_key_test'] ?? '');
            $skTest       = trim($_POST['stripe_secret_key_test'] ?? '');
            $pkLive       = trim($_POST['stripe_public_key_live'] ?? '');
            $skLive       = trim($_POST['stripe_secret_key_live'] ?? '');
            $webhookSecret = trim($_POST['stripe_webhook_secret'] ?? '');
            $invitationOuvrable = in_array($_POST['stripe_paiement_invitation_ouvrable'] ?? 'ouvrable', ['ouvrable', 'non_ouvrable']) ? $_POST['stripe_paiement_invitation_ouvrable'] : 'ouvrable';
            $jourMax = ($invitationOuvrable === 'non_ouvrable') ? 31 : 23;
            $jourInvitation = max(1, min($jourMax, (int)($_POST['stripe_paiement_invitation_jour'] ?? 1)));
            $liensHeures  = max(24, min(720, (int)($_POST['stripe_lien_expiration_heures'] ?? 168)));
            $maxMoisArrieres = max(0, min(24, (int)($_POST['stripe_rappel_mois_arrieres_max'] ?? 3)));

            // Rappel jours (liste séparée par virgules)
            $rappelJoursRaw = $_POST['stripe_paiement_rappel_jours'] ?? '';
            $rappelJours = array_values(array_filter(array_map('intval', explode(',', $rappelJoursRaw))));
            $rappelJoursJson = json_encode($rappelJours);

            // Méthodes de paiement
            $methodesAcceptees = [];
            if (!empty($_POST['methode_card'])) $methodesAcceptees[] = 'card';
            if (!empty($_POST['methode_sepa']))  $methodesAcceptees[] = 'sepa_debit';
            if (empty($methodesAcceptees)) $methodesAcceptees = ['card'];
            $methodesJson = json_encode($methodesAcceptees);

            // Validation
            if ($stripeActif === '1') {
                $activeKey = ($stripeMode === 'live') ? $skLive : $skTest;
                if (empty($activeKey)) {
                    // Le champ a été laissé vide (ne pas modifier) : vérifier qu'une clé existe déjà en base
                    $existingKey = getParam($pdo, ($stripeMode === 'live') ? 'stripe_secret_key_live' : 'stripe_secret_key_test', '');
                    if (empty($existingKey)) {
                        $errors[] = 'La clé secrète Stripe (' . $stripeMode . ') est obligatoire pour activer le paiement.';
                    }
                }
            }

            if (empty($errors)) {
                setParam($pdo, 'stripe_actif', $stripeActif);
                setParam($pdo, 'stripe_mode', $stripeMode);
                setParam($pdo, 'stripe_public_key_test', $pkTest);
                if (!empty($skTest)) setParam($pdo, 'stripe_secret_key_test', $skTest);
                setParam($pdo, 'stripe_public_key_live', $pkLive);
                if (!empty($skLive)) setParam($pdo, 'stripe_secret_key_live', $skLive);
                if (!empty($webhookSecret)) setParam($pdo, 'stripe_webhook_secret', $webhookSecret);
                setParam($pdo, 'stripe_paiement_invitation_jour', (string)$jourInvitation);
                setParam($pdo, 'stripe_paiement_invitation_ouvrable', $invitationOuvrable);
                setParam($pdo, 'stripe_lien_expiration_heures', (string)$liensHeures);
                setParam($pdo, 'stripe_paiement_rappel_jours', $rappelJoursJson);
                setParam($pdo, 'stripe_methodes_paiement', $methodesJson);
                setParam($pdo, 'stripe_rappel_mois_arrieres_max', (string)$maxMoisArrieres);
                $successMsg = 'Configuration Stripe enregistrée avec succès.';
            }
        }

        if ($action === 'test_connection') {
            $stripeMode = getParam($pdo, 'stripe_mode', 'test');
            $sk = ($stripeMode === 'live')
                ? getParam($pdo, 'stripe_secret_key_live', '')
                : getParam($pdo, 'stripe_secret_key_test', '');

            if (empty($sk)) {
                $errors[] = 'Clé secrète Stripe non configurée.';
            } elseif (!$stripeDisponible) {
                $errors[] = 'Le SDK Stripe n\'est pas installé. Exécutez : composer install';
            } else {
                try {
                    \Stripe\Stripe::setApiKey($sk);
                    $account = \Stripe\Account::retrieve();
                    $successMsg = '✅ Connexion Stripe réussie ! Compte : ' . htmlspecialchars($account->email ?? $account->id);
                } catch (\Stripe\Exception\AuthenticationException $e) {
                    $errors[] = '❌ Clé API invalide : ' . $e->getMessage();
                } catch (Exception $e) {
                    $errors[] = '❌ Erreur de connexion Stripe : ' . $e->getMessage();
                }
            }
        }
    }
}

// ─── Charger la configuration actuelle ──────────────────────────────────────
$stripeActif         = getParam($pdo, 'stripe_actif', false);
$stripeMode          = getParam($pdo, 'stripe_mode', 'test');
$pkTest              = getParam($pdo, 'stripe_public_key_test', '');
$pkLive              = getParam($pdo, 'stripe_public_key_live', '');
$webhookConfigured   = !empty(getParam($pdo, 'stripe_webhook_secret', ''));
$jourInvitation      = (int)getParam($pdo, 'stripe_paiement_invitation_jour', 1);
$invitationOuvrable  = getParam($pdo, 'stripe_paiement_invitation_ouvrable', 'ouvrable');
$rappelJours         = getParam($pdo, 'stripe_paiement_rappel_jours', [7, 14]);
$liensHeures         = (int)getParam($pdo, 'stripe_lien_expiration_heures', 168);
$maxMoisArrieres     = (int)getParam($pdo, 'stripe_rappel_mois_arrieres_max', 3);
$methodesAcceptees   = getParam($pdo, 'stripe_methodes_paiement', ['card', 'sepa_debit']);
$rappelJoursStr      = is_array($rappelJours) ? implode(', ', $rappelJours) : '7, 14';
$webhookUrl          = rtrim($config['SITE_URL'], '/') . '/payment/stripe-webhook.php';

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration Paiement Stripe - Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <?php require_once __DIR__ . '/includes/sidebar-styles.php'; ?>
    <style>
        .section-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 24px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }
        .section-card h5 {
            color: #2c3e50;
            padding-bottom: 12px;
            border-bottom: 2px solid #635bff;
            margin-bottom: 20px;
        }
        .stripe-badge {
            background: #635bff;
            color: white;
            border-radius: 6px;
            padding: 2px 10px;
            font-size: 12px;
            font-weight: bold;
        }
        .key-input { font-family: monospace; font-size: 0.85em; }
        .status-badge-active   { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status-badge-inactive { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/menu.php'; ?>

    <div class="main-content">
        <div class="container-fluid mt-4">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1><i class="bi bi-credit-card-2-front"></i> Configuration Paiement en Ligne</h1>
                <p class="text-muted mb-0">
                    Intégration <span class="stripe-badge">Stripe</span> pour le paiement des loyers par carte bancaire et prélèvement SEPA
                    &nbsp;—&nbsp;
                    <span class="badge rounded-pill <?php echo $stripeActif ? 'status-badge-active' : 'status-badge-inactive'; ?>">
                        <i class="bi bi-<?php echo $stripeActif ? 'check-circle' : 'x-circle'; ?>"></i>
                        <?php echo $stripeActif ? 'Activé' : 'Désactivé'; ?>
                    </span>
                </p>
            </div>
            <a href="gestion-loyers.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($successMsg): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($successMsg); ?>
            </div>
        <?php endif; ?>

        <?php if (!$stripeDisponible): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>SDK Stripe non installé.</strong> Exécutez la commande suivante sur le serveur :
                <code class="ms-2">composer install</code>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="save_config">

            <!-- Section Activation -->
            <div class="section-card">
                <h5><i class="bi bi-toggle-on me-2"></i>Activation</h5>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="stripe_actif"
                           name="stripe_actif" <?php echo $stripeActif ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="stripe_actif">
                        Activer le paiement en ligne via Stripe
                    </label>
                    <div class="form-text">Lorsqu'activé, les locataires recevront un lien de paiement sécurisé chaque mois.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Mode Stripe</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="stripe_mode" id="mode_test"
                                   value="test" <?php echo $stripeMode !== 'live' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="mode_test">
                                <span class="badge bg-warning text-dark">Test</span> — Aucun vrai débit
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="stripe_mode" id="mode_live"
                                   value="live" <?php echo $stripeMode === 'live' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="mode_live">
                                <span class="badge bg-danger">Production</span> — Vrais paiements
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Clés API -->
            <div class="section-card">
                <h5><i class="bi bi-key me-2"></i>Clés API Stripe</h5>
                <p class="text-muted small">
                    Retrouvez vos clés API dans le
                    <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener">tableau de bord Stripe</a>.
                </p>

                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-warning"><i class="bi bi-bug me-1"></i>Mode Test</h6>
                        <div class="mb-3">
                            <label class="form-label">Clé publique (pk_test_...)</label>
                            <input type="text" class="form-control key-input" name="stripe_public_key_test"
                                   value="<?php echo htmlspecialchars($pkTest); ?>"
                                   placeholder="pk_test_...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Clé secrète (sk_test_...)</label>
                            <input type="password" class="form-control key-input" name="stripe_secret_key_test"
                                   placeholder="Laisser vide pour ne pas modifier">
                            <div class="form-text">
                                <?php echo !empty(getParam($pdo, 'stripe_secret_key_test', '')) ? '✅ Clé configurée' : '⚠️ Non configurée'; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-danger"><i class="bi bi-shield-check me-1"></i>Production</h6>
                        <div class="mb-3">
                            <label class="form-label">Clé publique (pk_live_...)</label>
                            <input type="text" class="form-control key-input" name="stripe_public_key_live"
                                   value="<?php echo htmlspecialchars($pkLive); ?>"
                                   placeholder="pk_live_...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Clé secrète (sk_live_...)</label>
                            <input type="password" class="form-control key-input" name="stripe_secret_key_live"
                                   placeholder="Laisser vide pour ne pas modifier">
                            <div class="form-text">
                                <?php echo !empty(getParam($pdo, 'stripe_secret_key_live', '')) ? '✅ Clé configurée' : '⚠️ Non configurée'; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Secret Webhook (whsec_...)</label>
                    <input type="password" class="form-control key-input" name="stripe_webhook_secret"
                           placeholder="Laisser vide pour ne pas modifier">
                    <div class="form-text">
                        <?php echo $webhookConfigured ? '✅ Configuré' : '⚠️ Non configuré'; ?>
                        — URL du webhook à configurer dans Stripe :
                        <code><?php echo htmlspecialchars($webhookUrl); ?></code>
                    </div>
                </div>
            </div>

            <!-- Section Méthodes de paiement -->
            <div class="section-card">
                <h5><i class="bi bi-wallet2 me-2"></i>Méthodes de paiement acceptées</h5>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="methode_card" name="methode_card"
                           <?php echo in_array('card', $methodesAcceptees ?? []) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="methode_card">
                        <i class="bi bi-credit-card me-1"></i><strong>Carte bancaire</strong> (Visa, Mastercard, CB)
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="methode_sepa" name="methode_sepa"
                           <?php echo in_array('sepa_debit', $methodesAcceptees ?? []) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="methode_sepa">
                        <i class="bi bi-bank me-1"></i><strong>Prélèvement SEPA</strong> (débit bancaire)
                        <span class="badge bg-info text-white ms-1" style="font-size:10px;">Recommandé pour loyers récurrents</span>
                    </label>
                </div>
            </div>

            <!-- Section Automatisation -->
            <div class="section-card">
                <h5><i class="bi bi-calendar-event me-2"></i>Automatisation des envois</h5>

                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Jour d'envoi de l'invitation mensuelle
                            </label>
                            <input type="number" class="form-control" name="stripe_paiement_invitation_jour"
                                   id="invitation_jour"
                                   value="<?php echo $jourInvitation; ?>" min="1"
                                   max="<?php echo $invitationOuvrable === 'non_ouvrable' ? 31 : 23; ?>">
                            <div class="form-text">Numéro du jour du mois pour l'envoi de l'invitation (défaut : 1)</div>
                            <div class="mt-2">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="stripe_paiement_invitation_ouvrable"
                                           id="invitation_ouvrable" value="ouvrable"
                                           <?php echo $invitationOuvrable !== 'non_ouvrable' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="invitation_ouvrable">Ouvrable</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="stripe_paiement_invitation_ouvrable"
                                           id="invitation_non_ouvrable" value="non_ouvrable"
                                           <?php echo $invitationOuvrable === 'non_ouvrable' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="invitation_non_ouvrable">Non Ouvrable</label>
                                </div>
                                <div class="form-text">
                                    <strong>Ouvrable</strong> : Nième jour ouvrable (lun–ven) ;
                                    <strong>Non Ouvrable</strong> : Nième jour calendaire
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Jours de rappel (en retard)
                            </label>
                            <input type="text" class="form-control" name="stripe_paiement_rappel_jours"
                                   value="<?php echo htmlspecialchars($rappelJoursStr); ?>"
                                   placeholder="ex: 7, 14">
                            <div class="form-text">Jours du mois où un rappel est envoyé aux locataires n'ayant pas payé</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Durée de validité du lien
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="stripe_lien_expiration_heures"
                                       value="<?php echo $liensHeures; ?>" min="24" max="720">
                                <span class="input-group-text">heures</span>
                            </div>
                            <div class="form-text"><?php echo round($liensHeures / 24, 1); ?> jour(s) — défaut: 168h (7 jours)</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Mois arriérés max par rappel
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="stripe_rappel_mois_arrieres_max"
                                       value="<?php echo $maxMoisArrieres; ?>" min="0" max="24">
                                <span class="input-group-text">mois</span>
                            </div>
                            <div class="form-text">Nombre de mois passés non payés inclus dans chaque rappel (0 = illimité, défaut : 3)</div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Cron job requis :</strong> Activez le cron <code>cron/stripe-paiements.php</code> dans
                    <a href="cron-jobs.php">Gestion des crons</a> avec l'expression <code>0 8 * * *</code> (tous les jours à 8h).
                </div>
            </div>

            <div class="d-flex gap-2 mb-4">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-save me-2"></i>Enregistrer la configuration
                </button>
            </div>
        </form>

        <!-- Test de connexion -->
        <form method="POST" action="" class="mb-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="test_connection">
            <button type="submit" class="btn btn-outline-secondary" <?php echo !$stripeDisponible ? 'disabled' : ''; ?>>
                <i class="bi bi-wifi me-2"></i>Tester la connexion Stripe
            </button>
        </form>

        <!-- Instructions -->
        <div class="section-card">
            <h5><i class="bi bi-book me-2"></i>Guide de mise en place</h5>
            <ol class="mb-0">
                <li class="mb-2">Créez un compte Stripe sur <a href="https://stripe.com" target="_blank" rel="noopener">stripe.com</a> si vous n'en avez pas.</li>
                <li class="mb-2">Récupérez vos clés API dans <strong>Tableau de bord → Développeurs → Clés API</strong>.</li>
                <li class="mb-2">Configurez un webhook dans <strong>Tableau de bord → Développeurs → Webhooks</strong> :<br>
                    URL : <code><?php echo htmlspecialchars($webhookUrl); ?></code><br>
                    Événements : <code>checkout.session.completed</code>, <code>checkout.session.expired</code>
                </li>
                <li class="mb-2">Entrez le secret webhook (<code>whsec_...</code>) dans ce formulaire.</li>
                <li class="mb-2">Activez le cron <code>cron/stripe-paiements.php</code> dans <a href="cron-jobs.php">Gestion des crons</a>.</li>
                <li>Testez en mode <strong>Test</strong> avant de passer en production.</li>
            </ol>
        </div>

    </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Afficher la durée en jours en temps réel
        document.querySelector('[name="stripe_lien_expiration_heures"]').addEventListener('input', function() {
            const h = parseInt(this.value) || 0;
            this.closest('.mb-3').querySelector('.form-text').textContent =
                (h / 24).toFixed(1) + ' jour(s) — défaut: 168h (7 jours)';
        });

        // Mettre à jour le max du champ "Jour" selon le type (ouvrable/non_ouvrable)
        document.querySelectorAll('[name="stripe_paiement_invitation_ouvrable"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                var jourInput = document.getElementById('invitation_jour');
                var maxVal = (this.value === 'non_ouvrable') ? 31 : 23;
                jourInput.max = maxVal;
                if (parseInt(jourInput.value) > maxVal) {
                    jourInput.value = maxVal;
                }
            });
        });
    </script>
</body>
</html>
