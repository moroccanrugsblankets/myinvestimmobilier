<?php
/**
 * Aperçu du document de caution solidaire (avant signature)
 *
 * Ce script génère un aperçu HTML du document de caution solidaire
 * pour permettre au garant de le consulter avant de signer.
 *
 * Accessible via : apercu-caution.php?token=GARANT_TOKEN
 *
 * My Invest Immobilier
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    http_response_code(403);
    die('Token manquant.');
}

// Vérifier le token garant
$garant = getGarantByToken($token);

if (!$garant) {
    http_response_code(403);
    die('Token invalide ou expiré.');
}

if ($garant['type_garantie'] !== 'caution_solidaire') {
    http_response_code(403);
    die('Ce lien n\'est pas applicable à votre type de garantie.');
}

// Récupérer les infos du logement/contrat
$contratInfo = fetchOne("
    SELECT c.reference_unique AS reference_contrat,
           c.date_prise_effet,
           l.adresse          AS adresse_logement,
           l.loyer,
           l.charges,
           l.type             AS type_logement
    FROM contrats c
    INNER JOIN logements l ON c.logement_id = l.id
    WHERE c.id = ?
", [$garant['contrat_id']]);

if (!$contratInfo) {
    http_response_code(500);
    die('Erreur lors du chargement des données.');
}

// Fusionner les données pour le template
$garant = array_merge($garant, $contratInfo);

// Locataire principal
$locataire = fetchOne("
    SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 1
", [$garant['contrat_id']]);

// Construire l'aperçu HTML (sans signature)
require_once __DIR__ . '/pdf/generate-caution-solidaire.php';

// Utiliser le template personnalisé si défini, sinon le template par défaut
$customTemplate = null;
try {
    global $pdo;
    $tplStmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'caution_template_html'");
    $tplStmt->execute();
    $tplRow = $tplStmt->fetchColumn();
    if ($tplRow && trim($tplRow) !== '') {
        $customTemplate = $tplRow;
    }
} catch (Exception $e) {
    // Table parametres non disponible – on utilise le template par défaut
}

// Forcer la signature à vide pour l'aperçu (le document n'est pas encore signé)
$garantPreview = $garant;
$garantPreview['signature_data'] = '';

if ($customTemplate !== null) {
    $html = applyCautionTemplateVariables($customTemplate, $garantPreview, $locataire, $config);
} else {
    $html = buildCautionSolidaireHTML($garantPreview, $locataire, $config);
}

// Ajouter une bannière d'aperçu en haut du document
$bannerStyle = 'background:#fff3cd;color:#856404;border-bottom:2px solid #ffc107;padding:10px 20px;'
             . 'text-align:center;font-family:Arial,sans-serif;font-size:13px;font-weight:bold;';
$bannerHtml  = '<div style="' . $bannerStyle . '">⚠ Aperçu du document – Ce document sera finalisé après votre signature électronique.</div>';

// Injecter la bannière juste après <body>
$htmlWithBanner = preg_replace('/<body[^>]*>/i', '$0' . $bannerHtml, $html, 1);
if ($htmlWithBanner !== null && $htmlWithBanner !== $html) {
    $html = $htmlWithBanner;
} else {
    // Fallback : ajouter la bannière au début du contenu
    $html = $bannerHtml . $html;
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: SAMEORIGIN');
echo $html;
