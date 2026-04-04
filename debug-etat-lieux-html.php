<?php
/**
 * Debug script to check the HTML generated for état des lieux
 * This will help identify any table structure issues
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/etat-lieux-template.php';

echo "=== Debug État des lieux HTML Generation ===\n\n";

// Mock config
$config = [
    'COMPANY_NAME' => 'MY INVEST IMMOBILIER',
    'BAILLEUR_REPRESENTANT' => 'John Doe',
    'DEFAULT_SIGNATURE_LOCATION' => 'Annemasse',
    'SITE_URL' => 'http://example.com'
];

// Mock database connection (needed by some functions)
$pdo = null;

echo "Using mock data (no database connection)...\n";
$contrat = [
    'id' => 1,
    'reference' => 'TEST-001',
    'adresse' => '123 Test Street',
    
    'type_logement' => 'Studio',
    'surface' => '25'
];

$locataires = [[
    'id' => 1,
    'nom' => 'Test',
    'prenom' => 'User',
    'email' => 'test@example.com'
]];

$etatLieux = [
    'id' => 1,
    'reference_unique' => 'EDL-TEST-001',
    'type' => 'entree',
    'compteur_electricite' => '12345',
    'compteur_eau_froide' => '67890',
    'cles_appartement' => 2,
    'cles_boite_lettres' => 1,
    'cles_autre' => 0,
    'cles_total' => 3,
    'adresse' => '123 Test Street',
    
    'bailleur_nom' => 'MY INVEST IMMOBILIER',
    'bailleur_representant' => 'John Doe',
    'locataire_email' => 'test@example.com',
    'locataire_nom_complet' => 'User Test',
    'etat_logement' => 'Good condition',
    'coin_cuisine' => 'Clean',
    'salle_eau_wc' => 'Good',
    'etat_general' => 'Excellent',
    'lieu_signature' => 'Annemasse',
    'date_etat' => date('Y-m-d')
];

// Generate HTML using a simplified version without DB
echo "Generating HTML...\n";
$templateHtml = getDefaultEtatLieuxTemplate();

// Manually replace variables (simplified version of replaceEtatLieuxTemplateVariables)
$type = 'entree';
$typeLabel = 'D\'ENTRÉE';
$reference = $etatLieux['reference_unique'];
$dateEtat = date('d/m/Y');
$dateSignature = date('d/m/Y');

$vars = [
    '{{reference}}' => htmlspecialchars($reference),
    '{{type}}' => strtolower($type),
    '{{type_label}}' => $typeLabel,
    '{{date_etat}}' => $dateEtat,
    '{{adresse}}' => htmlspecialchars($contrat['adresse']),
    '{{type_logement}}' => htmlspecialchars($contrat['type_logement']),
    '{{surface}}' => htmlspecialchars($contrat['surface']),
    '{{bailleur_nom}}' => htmlspecialchars($etatLieux['bailleur_nom']),
    '{{bailleur_representant}}' => htmlspecialchars($etatLieux['bailleur_representant']),
    '{{locataires_info}}' => '<br><strong>Locataire : </strong>' . htmlspecialchars($locataires[0]['prenom']) . ' ' . htmlspecialchars($locataires[0]['nom']),
    '{{compteur_electricite}}' => htmlspecialchars($etatLieux['compteur_electricite']),
    '{{compteur_eau_froide}}' => htmlspecialchars($etatLieux['compteur_eau_froide']),
    '{{cles_appartement}}' => $etatLieux['cles_appartement'],
    '{{cles_boite_lettres}}' => $etatLieux['cles_boite_lettres'],
    '{{cles_autre}}' => $etatLieux['cles_autre'],
    '{{cles_total}}' => $etatLieux['cles_total'],
    '{{etat_logement}}' => htmlspecialchars($etatLieux['etat_logement']),
    '{{coin_cuisine}}' => htmlspecialchars($etatLieux['coin_cuisine']),
    '{{salle_eau_wc}}' => htmlspecialchars($etatLieux['salle_eau_wc']),
    '{{etat_general}}' => htmlspecialchars($etatLieux['etat_general']),
    '{{observations}}' => '',
    '{{lieu_signature}}' => htmlspecialchars($etatLieux['lieu_signature']),
    '{{date_signature}}' => $dateSignature,
    '{{signatures_table}}' => '<table cellspacing="0" cellpadding="0"><tr><td>Mock signatures</td></tr></table>',
    '{{signature_agence}}' => 'MY INVEST IMMOBILIER',
    '{{bailleur_representant_row}}' => '<br><strong>Représenté par : </strong>' . htmlspecialchars($etatLieux['bailleur_representant']),
    '{{observations_section}}' => ''
];

$html = str_replace(array_keys($vars), array_values($vars), $templateHtml);

echo "HTML Generated - Length: " . strlen($html) . " characters\n\n";

// Check for all <table tags and their attributes
echo "=== Checking all <table> tags ===\n";
preg_match_all('/<table[^>]*>/i', $html, $matches);
$tableCount = count($matches[0]);
echo "Total tables found: $tableCount\n\n";

$issues = [];
foreach ($matches[0] as $i => $tableTag) {
    $num = $i + 1;
    echo "Table $num: $tableTag\n";
    
    // Check for cellspacing
    if (stripos($tableTag, 'cellspacing') === false) {
        echo "  ❌ Missing cellspacing attribute\n";
        $issues[] = "Table $num missing cellspacing";
    } else {
        echo "  ✓ Has cellspacing\n";
    }
    
    // Check for cellpadding
    if (stripos($tableTag, 'cellpadding') === false) {
        echo "  ❌ Missing cellpadding attribute\n";
        $issues[] = "Table $num missing cellpadding";
    } else {
        echo "  ✓ Has cellpadding\n";
    }
    
    echo "\n";
}

echo "=== Summary ===\n";
if (empty($issues)) {
    echo "✅ All tables have proper TCPDF attributes (cellspacing and cellpadding)\n";
} else {
    echo "❌ Found " . count($issues) . " issue(s):\n";
    foreach ($issues as $issue) {
        echo "  - $issue\n";
    }
}

echo "\n=== Variable Replacement Check ===\n";
// Check if variables are properly replaced
$unreplacedVars = [];
preg_match_all('/\{\{[^}]+\}\}/', $html, $varMatches);
if (!empty($varMatches[0])) {
    echo "❌ Found unreplaced variables:\n";
    foreach (array_unique($varMatches[0]) as $var) {
        echo "  - $var\n";
        $unreplacedVars[] = $var;
    }
} else {
    echo "✓ All variables have been replaced\n";
}

echo "\n=== Meter Readings Check ===\n";
if (strpos($html, '{{compteur_electricite}}') !== false) {
    echo "❌ Electricity meter placeholder not replaced\n";
} else if (strpos($html, htmlspecialchars($etatLieux['compteur_electricite'] ?? '')) !== false || 
           preg_match('/Électricité.*?<\/td>\s*<td[^>]*>([^<]+)<\/td>/is', $html, $elecMatch)) {
    echo "✓ Electricity meter: " . (isset($elecMatch[1]) ? trim(strip_tags($elecMatch[1])) : 'found') . "\n";
} else {
    echo "⚠ Electricity meter value unclear\n";
}

if (strpos($html, '{{compteur_eau_froide}}') !== false) {
    echo "❌ Water meter placeholder not replaced\n";
} else if (strpos($html, htmlspecialchars($etatLieux['compteur_eau_froide'] ?? '')) !== false ||
           preg_match('/Eau froide.*?<\/td>\s*<td[^>]*>([^<]+)<\/td>/is', $html, $waterMatch)) {
    echo "✓ Water meter: " . (isset($waterMatch[1]) ? trim(strip_tags($waterMatch[1])) : 'found') . "\n";
} else {
    echo "⚠ Water meter value unclear\n";
}

echo "\n=== Keys Check ===\n";
if (strpos($html, '{{cles_appartement}}') !== false) {
    echo "❌ Apartment keys placeholder not replaced\n";
} else {
    echo "✓ Apartment keys replaced\n";
}

if (strpos($html, '{{cles_total}}') !== false) {
    echo "❌ Total keys placeholder not replaced\n";
} else {
    echo "✓ Total keys replaced\n";
}

// Save HTML to file for inspection (cross-platform path)
$htmlFile = sys_get_temp_dir() . '/etat-lieux-debug.html';
file_put_contents($htmlFile, $html);
echo "\n✓ HTML saved to: $htmlFile\n";
echo "You can open this file in a browser to inspect the output.\n";

if (empty($issues) && empty($unreplacedVars)) {
    echo "\n✅ All checks passed!\n";
    exit(0);
} else {
    echo "\n⚠ Some issues were found. Review the output above.\n";
    exit(1);
}
