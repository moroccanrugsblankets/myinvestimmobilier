<?php
/**
 * Contact form submission handler
 * My Invest Immobilier
 *
 * Receives POST from [contact-form] shortcodes rendered in public pages.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$siteUrl = rtrim($config['SITE_URL'] ?? '', '/');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $siteUrl . '/');
    exit;
}

$formId = isset($_POST['form_id']) ? (int)$_POST['form_id'] : 0;
if ($formId <= 0) {
    http_response_code(400);
    exit('Formulaire invalide.');
}

// Fetch form configuration
try {
    $stmt = $pdo->prepare("SELECT * FROM contact_forms WHERE id = ? AND actif = 1 LIMIT 1");
    $stmt->execute([$formId]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    exit('Erreur serveur.');
}

if (!$form) {
    http_response_code(404);
    exit('Formulaire introuvable.');
}

// Fetch fields for validation
try {
    $stmtF = $pdo->prepare("SELECT * FROM contact_form_fields WHERE form_id = ? ORDER BY ordre ASC, id ASC");
    $stmtF->execute([$formId]);
    $fields = $stmtF->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fields = [];
}

// Build data array and validate required fields
$donnees = [];
$errors  = [];
foreach ($fields as $field) {
    $nom   = $field['nom_champ'];
    $value = isset($_POST[$nom]) ? $_POST[$nom] : '';
    // Strip tags for safety (content is later displayed in admin only)
    if (is_array($value)) {
        $value = array_map('strip_tags', $value);
    } else {
        $value = strip_tags(trim($value));
    }
    if ($field['requis'] && ($value === '' || $value === [])) {
        $errors[] = htmlspecialchars($field['label']) . ' est obligatoire.';
    }
    $donnees[$nom] = $value;
}

if (!empty($errors)) {
    // Redirect back with error (simple approach; no JS dependency)
    $ref = $_SERVER['HTTP_REFERER'] ?? ($siteUrl . '/');
    $sep = strpos($ref, '?') !== false ? '&' : '?';
    header('Location: ' . $ref . $sep . 'cf_error=' . urlencode(implode(' ', $errors)) . '&cf_form=' . $formId);
    exit;
}

// Store submission
try {
    $ip        = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $stmt = $pdo->prepare("
        INSERT INTO contact_form_submissions (form_id, donnees, ip_address, user_agent)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$formId, json_encode($donnees, JSON_UNESCAPED_UNICODE), $ip, $userAgent]);
} catch (Exception $e) {
    error_log('contact-form-submit.php DB error: ' . $e->getMessage());
}

// Optional: send email notification
if (!empty($form['email_dest'])) {
    try {
        require_once __DIR__ . '/includes/mail-templates.php';
        $bodyLines = [];
        foreach ($donnees as $k => $v) {
            $bodyLines[] = '<strong>' . htmlspecialchars($k) . '</strong> : ' . htmlspecialchars(is_array($v) ? implode(', ', $v) : $v);
        }
        $bodyHtml = '<p>Nouvelle soumission du formulaire <strong>' . htmlspecialchars($form['nom']) . '</strong> :</p>'
                  . '<ul><li>' . implode('</li><li>', $bodyLines) . '</li></ul>';
        sendEmail(
            $form['email_dest'],
            'Nouveau message — ' . $form['nom'],
            $bodyHtml
        );
    } catch (Exception $e) {
        error_log('contact-form-submit.php mail error: ' . $e->getMessage());
    }
}

// Confirmation message or redirect
$confirmationMsg = trim($form['message_confirmation'] ?? '');
if ($confirmationMsg === '') {
    $confirmationMsg = 'Votre message a bien été envoyé. Nous vous répondrons dans les plus brefs délais.';
}

$ref = $_SERVER['HTTP_REFERER'] ?? ($siteUrl . '/');
$sep = strpos($ref, '?') !== false ? '&' : '?';
header('Location: ' . $ref . $sep . 'cf_success=1&cf_form=' . $formId);
exit;
