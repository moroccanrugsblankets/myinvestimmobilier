<?php
/**
 * Génération du PDF de décompte d'intervention
 * My Invest Immobilier
 *
 * Utilise TCPDF pour générer un PDF professionnel du décompte d'intervention.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

/**
 * Générer le PDF d'un décompte d'intervention.
 *
 * @param  int   $decompteId  ID du décompte
 * @param  array $vars        Variables de substitution (même tableau qu'envoyé dans l'e-mail)
 * @return string|false       Chemin absolu du fichier PDF généré, ou false en cas d'erreur
 */
function generateDecomptePDF(int $decompteId, array $vars)
{
    global $config, $pdo;

    if ($decompteId <= 0) {
        error_log('generateDecomptePDF: ID décompte invalide.');
        return false;
    }

    try {
        // Récupérer le template HTML depuis la base de données
        $stmt = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'decompte_template_html'");
        $stmt->execute();
        $templateHtml = $stmt->fetchColumn();

        if (empty($templateHtml)) {
            $templateHtml = getDefaultDecompteTemplate();
        }

        // Récupérer les lignes du décompte
        $stmtL = $pdo->prepare("SELECT * FROM signalements_decomptes_lignes WHERE decompte_id = ? ORDER BY ordre ASC, id ASC");
        $stmtL->execute([$decompteId]);
        $lignes = $stmtL->fetchAll(PDO::FETCH_ASSOC);

        // Construire le tableau HTML des lignes
        $lignesHtml = '';
        if (!empty($lignes)) {
            $lignesHtml = '<table style="width:100%;border-collapse:collapse;margin:10px 0;">'
                . '<thead><tr style="background:#2c3e50;color:#ffffff;">'
                . '<th style="padding:10px;text-align:left;border:1px solid #dee2e6;">Intitulé</th>'
                . '<th style="padding:10px;text-align:right;border:1px solid #dee2e6;white-space:nowrap;">Montant (€)</th>'
                . '</tr></thead><tbody>';
            foreach ($lignes as $lg) {
                $lignesHtml .= '<tr style="border-bottom:1px solid #dee2e6;">'
                    . '<td style="padding:8px;border:1px solid #dee2e6;">' . htmlspecialchars($lg['intitule']) . '</td>'
                    . '<td style="padding:8px;text-align:right;border:1px solid #dee2e6;">' . number_format((float)$lg['montant'], 2, ',', ' ') . ' €</td>'
                    . '</tr>';
            }
            $montantTotal = $vars['montant_total'] ?? '0,00';
            $lignesHtml .= '<tr style="background:#f8f9fa;font-weight:bold;">'
                . '<td style="padding:10px;border:1px solid #dee2e6;text-align:right;">Total</td>'
                . '<td style="padding:10px;text-align:right;border:1px solid #dee2e6;">' . htmlspecialchars($montantTotal) . ' €</td>'
                . '</tr></tbody></table>';
        }

        // Récupérer la signature société si disponible et construire l'URL correcte pour TCPDF
        $signatureHtml = '';
        try {
            $signatureUrl = getCompanySignatureUrl($config, 'signature_societe_image', '');
            if (!empty($signatureUrl)) {
                $signatureHtml = '<div><strong>Signature :</strong><br>'
                    . '<img src="' . htmlspecialchars($signatureUrl) . '" alt="Signature" style="width:80px;height:auto;">'
                    . '</div>';
            }
        } catch (Exception $e) {
            // ignore — signature is optional
        }

        // Informations société
        $nomSociete    = $config['COMPANY_NAME']    ?? 'MY INVEST IMMOBILIER';
        $adresseSociete = $config['COMPANY_ADDRESS'] ?? '';
        $telSociete    = $config['COMPANY_PHONE']   ?? '';
        $emailSociete  = $config['COMPANY_EMAIL']   ?? '';

        // Remplacer les variables dans le template
        $templateVars = [
            '{{reference_decompte}}'    => htmlspecialchars($vars['reference']           ?? ''),
            '{{reference_signalement}}' => htmlspecialchars($vars['reference_sig']        ?? ''),
            '{{titre}}'                 => htmlspecialchars($vars['titre']               ?? ''),
            '{{adresse}}'               => htmlspecialchars($vars['adresse']             ?? ''),
            '{{logement_reference}}'    => htmlspecialchars($vars['logement_reference']  ?? ''),
            '{{montant_total}}'         => htmlspecialchars($vars['montant_total']        ?? ''),
            '{{lignes_tableau}}'        => $lignesHtml,
            '{{date_facture}}'          => htmlspecialchars($vars['date_facture']         ?? date('d/m/Y')),
            '{{prenom}}'                => htmlspecialchars($vars['prenom']              ?? ''),
            '{{nom}}'                   => htmlspecialchars($vars['nom']                 ?? ''),
            '{{locataire_nom}}'         => htmlspecialchars($vars['nom']                 ?? ''),
            '{{locataire_prenom}}'      => htmlspecialchars($vars['prenom']              ?? ''),
            '{{contrat_ref}}'           => htmlspecialchars($vars['contrat_ref']          ?? ''),
            '{{company}}'               => htmlspecialchars($vars['company']             ?? $nomSociete),
            '{{nom_societe}}'           => htmlspecialchars($nomSociete),
            '{{adresse_societe}}'       => htmlspecialchars($adresseSociete),
            '{{tel_societe}}'           => htmlspecialchars($telSociete),
            '{{email_societe}}'         => htmlspecialchars($emailSociete),
            '{{signature_societe}}'     => $signatureHtml,
        ];

        $html = str_replace(array_keys($templateVars), array_values($templateVars), $templateHtml);

        // Générer le PDF avec TCPDF
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('MY INVEST IMMOBILIER');
        $pdf->SetTitle('Décompte d\'intervention — ' . ($vars['reference'] ?? (string)$decompteId));
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        // Sauvegarder le PDF
        $pdfDir = dirname(__DIR__) . '/pdf/decomptes/';
        if (!is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }

        $safeRef   = preg_replace('/[^A-Za-z0-9\-_]/', '_', $vars['reference'] ?? (string)$decompteId);
        $filename  = 'decompte-' . $safeRef . '.pdf';
        $filepath  = $pdfDir . $filename;
        $pdf->Output($filepath, 'F');

        error_log('PDF décompte généré : ' . $filepath);
        return $filepath;

    } catch (Exception $e) {
        error_log('generateDecomptePDF error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Retourner le template HTML par défaut pour le décompte d'intervention.
 */
function getDefaultDecompteTemplate(): string
{
    return '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Décompte d\'Intervention</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #333;
        }
        .header {
            background-color: #2c3e50;
            color: #ffffff;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 18pt;
            margin: 0 0 8px 0;
        }
        .header p { margin: 4px 0; }
        .info-block {
            background-color: #f8f9fa;
            border-left: 4px solid #3498db;
            padding: 12px 16px;
            margin-bottom: 16px;
        }
        .info-block p { margin: 5px 0; }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
            font-size: 10pt;
            color: #666;
            text-align: center;
        }
        .signature-section {
            margin-top: 40px;
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>DÉCOMPTE D\'INTERVENTION</h1>
        <p>{{nom_societe}}</p>
        <p>{{adresse_societe}}</p>
        <p>Tél : {{tel_societe}} &nbsp;|&nbsp; Email : {{email_societe}}</p>
    </div>

    <div class="info-block">
        <p><strong>N° Décompte :</strong> {{reference_decompte}}</p>
        <p><strong>Signalement :</strong> {{reference_signalement}} — {{titre}}</p>
        <p><strong>Logement :</strong> {{adresse}}</p>
        <p><strong>Réf. logement :</strong> {{logement_reference}}</p>
        <p><strong>Contrat :</strong> {{contrat_ref}}</p>
        <p><strong>Date :</strong> {{date_facture}}</p>
    </div>

    <div class="info-block">
        <p><strong>Locataire :</strong> {{prenom}} {{nom}}</p>
    </div>

    {{lignes_tableau}}

    <div class="signature-section">
        {{signature_societe}}
    </div>

    <div class="footer">
        <p>{{nom_societe}} — {{adresse_societe}}</p>
        <p>Tél : {{tel_societe}} | Email : {{email_societe}}</p>
    </div>
</body>
</html>';
}
