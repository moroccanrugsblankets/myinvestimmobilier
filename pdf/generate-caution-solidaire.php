<?php
/**
 * Génération du PDF du document de caution solidaire
 *
 * My Invest Immobilier
 */

if (!function_exists('generateCautionSolidairePDF')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/functions.php';
}

/**
 * Générer le PDF du document de caution solidaire.
 *
 * @param int $garantId  ID du garant dans la table garants
 * @return string|false  Chemin relatif du fichier généré (depuis la racine du projet)
 */
function generateCautionSolidairePDF(int $garantId) {
    global $config, $pdo;

    if ($garantId <= 0) {
        error_log("generateCautionSolidairePDF: ID garant invalide ($garantId)");
        return false;
    }

    try {
        // Charger les données du garant
        $stmt = $pdo->prepare("
            SELECT g.*,
                   c.reference_unique AS reference_contrat,
                   c.date_prise_effet,
                   l.adresse          AS adresse_logement,
                   l.reference        AS reference_logement,
                   l.loyer,
                   l.charges,
                   l.type             AS type_logement
            FROM garants g
            INNER JOIN contrats c ON g.contrat_id = c.id
            INNER JOIN logements l ON c.logement_id = l.id
            WHERE g.id = ?
        ");
        $stmt->execute([$garantId]);
        $garant = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$garant) {
            error_log("generateCautionSolidairePDF: garant ID $garantId introuvable");
            return false;
        }

        // Locataire principal
        $locataire = fetchOne("
            SELECT * FROM locataires WHERE contrat_id = ? ORDER BY ordre ASC LIMIT 1
        ", [$garant['contrat_id']]);

        // Construire le HTML du document
        $html = buildCautionSolidaireHTML($garant, $locataire, $config);

        // Initialiser TCPDF
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('MY INVEST IMMOBILIER');
        $pdf->SetTitle('Acte de caution solidaire – ' . $garant['reference_contrat']);
        $pdf->SetMargins(20, 20, 20);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        // Enregistrer le fichier
        $dir = dirname(__DIR__) . '/pdf/cautions/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'caution-solidaire-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $garant['reference_contrat'])
                  . '-' . $garantId . '.pdf';
        $filepath = $dir . $filename;
        $pdf->Output($filepath, 'F');

        // Retourner le chemin relatif (depuis la racine du projet)
        $relativePath = 'pdf/cautions/' . $filename;
        error_log("generateCautionSolidairePDF: PDF généré → $relativePath");

        return $relativePath;

    } catch (Exception $e) {
        error_log("generateCautionSolidairePDF: erreur – " . $e->getMessage());
        return false;
    }
}

/**
 * Construire le contenu HTML du document de caution solidaire.
 *
 * @param array  $garant
 * @param array|false $locataire
 * @param array  $config
 * @return string HTML
 */
function buildCautionSolidaireHTML(array $garant, $locataire, array $config): string {
    $companyName = $config['COMPANY_NAME'] ?? 'My Invest Immobilier';

    $prenomGarant     = htmlspecialchars($garant['prenom']    ?? '');
    $nomGarant        = htmlspecialchars($garant['nom']       ?? '');
    $dateNaissance    = !empty($garant['date_naissance'])
                        ? htmlspecialchars(date('d/m/Y', strtotime($garant['date_naissance'])))
                        : '___________';
    $adresseGarant    = htmlspecialchars($garant['adresse']   ?? '');
    $cpGarant         = htmlspecialchars($garant['code_postal'] ?? '');
    $villeGarant      = htmlspecialchars($garant['ville']     ?? '');
    $emailGarant      = htmlspecialchars($garant['email']     ?? '');

    $prenomLocataire  = $locataire ? htmlspecialchars($locataire['prenom']) : '___________';
    $nomLocataire     = $locataire ? htmlspecialchars($locataire['nom'])    : '___________';
    $emailLocataire   = $locataire ? htmlspecialchars($locataire['email'])  : '___________';

    $adresseLogement  = htmlspecialchars($garant['adresse_logement'] ?? '');
    $reference        = htmlspecialchars($garant['reference_contrat'] ?? '');
    $loyer            = number_format((float)($garant['loyer'] ?? 0), 2, ',', ' ');
    $charges          = number_format((float)($garant['charges'] ?? 0), 2, ',', ' ');
    $loyerTotal       = number_format((float)($garant['loyer'] ?? 0) + (float)($garant['charges'] ?? 0), 2, ',', ' ');
    $datePriseEffet   = !empty($garant['date_prise_effet'])
                        ? htmlspecialchars(date('d/m/Y', strtotime($garant['date_prise_effet'])))
                        : '___________';
    $dateDocument     = date('d/m/Y');

    // Signature du garant (image)
    $signatureHtml = '';
    if (!empty($garant['signature_data'])) {
        $sigPath = dirname(__DIR__) . '/' . $garant['signature_data'];
        if (file_exists($sigPath)) {
            $signatureHtml = '<img src="' . $sigPath . '" style="width:50mm;height:auto;" alt="Signature garant">';
        }
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
  body         { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #222; }
  h1           { font-size: 16pt; text-align: center; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4mm; }
  h2           { font-size: 13pt; color: #2c3e50; border-bottom: 1px solid #2c3e50; padding-bottom: 2mm; margin-top: 6mm; }
  .subtitle    { text-align: center; font-size: 10pt; color: #555; margin-bottom: 8mm; }
  .ref         { text-align: center; font-size: 10pt; color: #888; margin-bottom: 10mm; }
  table.info   { width: 100%; border-collapse: collapse; margin: 4mm 0; }
  table.info td { padding: 2mm 3mm; border: 1px solid #ccc; font-size: 10pt; vertical-align: top; }
  table.info td.label { background: #f0f4f8; font-weight: bold; width: 35%; }
  .article     { margin: 4mm 0; font-size: 10pt; line-height: 1.5; }
  .article strong { color: #2c3e50; }
  .signature-block { margin-top: 12mm; }
  .sign-line   { border-top: 1px solid #333; width: 70mm; margin-top: 14mm; font-size: 9pt; color: #555; }
  .footer      { margin-top: 15mm; padding-top: 4mm; border-top: 1px solid #ccc; font-size: 8pt; color: #888; text-align: center; }
</style>
</head>
<body>

<h1>Acte de caution solidaire</h1>
<p class="subtitle">Document établi en application de l'article 22-1 de la loi n°89-462 du 6 juillet 1989</p>
<p class="ref">Référence contrat : <strong>{$reference}</strong> &nbsp;|&nbsp; Date : {$dateDocument}</p>

<h2>1. Parties</h2>

<table class="info">
  <tr>
    <td class="label">Bailleur / Mandataire</td>
    <td>{$companyName}</td>
  </tr>
  <tr>
    <td class="label">Locataire</td>
    <td>{$prenomLocataire} {$nomLocataire} &lt;{$emailLocataire}&gt;</td>
  </tr>
  <tr>
    <td class="label">Logement</td>
    <td>{$adresseLogement}</td>
  </tr>
  <tr>
    <td class="label">Loyer mensuel</td>
    <td>{$loyer} € + {$charges} € de charges = <strong>{$loyerTotal} € / mois</strong></td>
  </tr>
  <tr>
    <td class="label">Date d'entrée</td>
    <td>{$datePriseEffet}</td>
  </tr>
</table>

<h2>2. Caution solidaire</h2>

<table class="info">
  <tr>
    <td class="label">Nom et prénom</td>
    <td>{$prenomGarant} {$nomGarant}</td>
  </tr>
  <tr>
    <td class="label">Date de naissance</td>
    <td>{$dateNaissance}</td>
  </tr>
  <tr>
    <td class="label">Adresse</td>
    <td>{$adresseGarant}, {$cpGarant} {$villeGarant}</td>
  </tr>
  <tr>
    <td class="label">Email</td>
    <td>{$emailGarant}</td>
  </tr>
</table>

<h2>3. Engagement de caution solidaire</h2>

<div class="article">
  Je soussigné(e) <strong>{$prenomGarant} {$nomGarant}</strong>, demeurant à {$adresseGarant}, {$cpGarant} {$villeGarant},
  me porte <strong>caution solidaire</strong> et indivisible de <strong>{$prenomLocataire} {$nomLocataire}</strong>
  pour le paiement du loyer et des charges, ainsi que l'exécution de toutes les conditions du contrat de location
  concernant le logement situé au <strong>{$adresseLogement}</strong>.
</div>

<div class="article">
  <strong>Article 1 – Étendue de l'engagement</strong><br>
  Mon engagement porte sur le paiement du loyer mensuel de <strong>{$loyerTotal} €</strong>
  (loyer : {$loyer} € + charges : {$charges} €), ainsi que sur toutes sommes qui pourraient être dues
  par le locataire en vertu du présent bail, notamment les réparations locatives et les indemnités d'occupation.
</div>

<div class="article">
  <strong>Article 2 – Durée</strong><br>
  Le présent engagement de caution est consenti pour la durée du bail en cours et ses renouvellements successifs,
  jusqu'à la libération définitive des lieux par le locataire et l'apurement complet de toutes les sommes dues.
</div>

<div class="article">
  <strong>Article 3 – Solidarité</strong><br>
  En cas de défaillance du locataire, le bailleur pourra se retourner directement contre moi sans être tenu
  de poursuivre préalablement le locataire. Je renonce expressément au bénéfice de discussion et de division.
</div>

<div class="article">
  <strong>Article 4 – Information annuelle</strong><br>
  Conformément à l'article 22-1 de la loi du 6 juillet 1989, le bailleur s'engage à m'adresser, chaque année,
  une copie de l'état de la dette du locataire à cette date.
</div>

<h2>4. Signature du garant</h2>

<div class="article">
  En signant le présent acte, je reconnais avoir pris connaissance de l'ensemble des conditions ci-dessus
  et m'engage à les respecter.
</div>

<div class="signature-block">
  <table style="width:100%;">
    <tr>
      <td style="width:50%;vertical-align:top;">
        <p style="font-size:10pt;">Fait le {$dateDocument}</p>
        {$signatureHtml}
        <div class="sign-line">{$prenomGarant} {$nomGarant}</div>
      </td>
      <td style="width:50%;vertical-align:top;">
        <p style="font-size:10pt;color:#888;">&nbsp;</p>
      </td>
    </tr>
  </table>
</div>

<div class="footer">
  {$companyName} &ndash; Document généré électroniquement le {$dateDocument} &ndash; Référence : {$reference}
</div>

</body>
</html>
HTML;
}

// Permettre l'appel direct via le navigateur (pour tests admin)
if (basename($_SERVER['PHP_SELF']) === 'generate-caution-solidaire.php' && isset($_GET['garant_id'])) {
    require_once __DIR__ . '/../admin-v2/auth.php';
    $gId = (int)($_GET['garant_id'] ?? 0);
    if ($gId <= 0) {
        die('ID garant invalide.');
    }
    $path = generateCautionSolidairePDF($gId);
    if ($path) {
        header('Location: ' . rtrim($config['SITE_URL'], '/') . '/telecharger.php?file=' . urlencode($path));
    } else {
        die('Erreur lors de la génération du PDF.');
    }
}
