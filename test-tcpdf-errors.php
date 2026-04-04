<?php
/**
 * Test TCPDF with the generated HTML to see if it produces errors
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/etat-lieux-template.php';

echo "=== Test TCPDF with État des lieux HTML ===\n\n";

// Mock config
$config = [
    'COMPANY_NAME' => 'MY INVEST IMMOBILIER',
    'BAILLEUR_REPRESENTANT' => 'John Doe',
    'DEFAULT_SIGNATURE_LOCATION' => 'Annemasse',
    'SITE_URL' => 'http://example.com'
];

// Generate the same HTML as before
$templateHtml = getDefaultEtatLieuxTemplate();

$type = 'entree';
$typeLabel = 'D\'ENTRÉE';
$reference = 'EDL-TEST-001';
$dateEtat = date('d/m/Y');
$dateSignature = date('d/m/Y');

$vars = [
    '{{reference}}' => htmlspecialchars($reference),
    '{{type}}' => strtolower($type),
    '{{type_label}}' => $typeLabel,
    '{{date_etat}}' => $dateEtat,
    '{{adresse}}' => htmlspecialchars('123 Test Street'),
    '{{type_logement}}' => htmlspecialchars('Studio'),
    '{{surface}}' => htmlspecialchars('25'),
    '{{bailleur_nom}}' => htmlspecialchars('MY INVEST IMMOBILIER'),
    '{{bailleur_representant}}' => htmlspecialchars('John Doe'),
    '{{locataires_info}}' => '<br><strong>Locataire : </strong>User Test',
    '{{compteur_electricite}}' => htmlspecialchars('12345'),
    '{{compteur_eau_froide}}' => htmlspecialchars('67890'),
    '{{cles_appartement}}' => '2',
    '{{cles_boite_lettres}}' => '1',
    '{{cles_autre}}' => '0',
    '{{cles_total}}' => '3',
    '{{etat_logement}}' => htmlspecialchars('Good condition'),
    '{{coin_cuisine}}' => htmlspecialchars('Clean'),
    '{{salle_eau_wc}}' => htmlspecialchars('Good'),
    '{{etat_general}}' => htmlspecialchars('Excellent'),
    '{{observations}}' => '',
    '{{lieu_signature}}' => htmlspecialchars('Annemasse'),
    '{{date_signature}}' => $dateSignature,
    '{{signatures_table}}' => '<table cellspacing="0" cellpadding="0"><tr><td>Mock signatures</td></tr></table>',
    '{{signature_agence}}' => 'MY INVEST IMMOBILIER',
    '{{appartement_row}}' => '',
    '{{bailleur_representant_row}}' => '<br><strong>Représenté par : </strong>John Doe',
    '{{observations_section}}' => ''
];

$html = str_replace(array_keys($vars), array_values($vars), $templateHtml);

echo "HTML Generated - Length: " . strlen($html) . " characters\n\n";

// Create TCPDF instance
echo "Creating TCPDF instance...\n";
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('MY INVEST IMMOBILIER');
$pdf->SetTitle("État des lieux Test");
$pdf->SetMargins(15, 10, 15);
$pdf->SetAutoPageBreak(true, 10);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

echo "Writing HTML to PDF...\n";

// Capture any PHP notices/warnings
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    static $errorCount = 0;
    $errorCount++;
    
    // Only log the first few errors to avoid flooding
    if ($errorCount <= 10) {
        echo "PHP Notice/Warning #$errorCount: $errstr in $errfile on line $errline\n";
    }
    
    // Don't halt execution
    return true;
});

try {
    $pdf->writeHTML($html, true, false, true, false, '');
    echo "✓ HTML written to PDF successfully\n\n";
} catch (Exception $e) {
    echo "❌ TCPDF Error: " . $e->getMessage() . "\n";
    exit(1);
}

restore_error_handler();

// Save PDF (cross-platform path)
$pdfPath = sys_get_temp_dir() . '/test-etat-lieux.pdf';
echo "Saving PDF to: $pdfPath\n";
$pdf->Output($pdfPath, 'F');

if (file_exists($pdfPath)) {
    $size = filesize($pdfPath);
    echo "✅ PDF generated successfully - Size: $size bytes\n";
    
    if ($size > 1000) {
        echo "✓ PDF size looks reasonable (>1KB)\n";
    } else {
        echo "⚠ PDF size is very small, might be empty\n";
    }
} else {
    echo "❌ PDF file not created\n";
    exit(1);
}

echo "\n=== Test completed ===\n";
