<?php
/**
 * État des Lieux Template Functions
 * Contains default template for états des lieux PDF generation
 * My Invest Immobilier
 */

/**
 * Get the default HTML template for état des lieux PDF
 * This template contains placeholders that will be replaced with actual data
 * 
 * @return string HTML template with placeholders
 */
function getDefaultEtatLieuxTemplate() {
    return <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>État des lieux {{type}} - {{reference}}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 9.5pt;
            line-height: 1.4;
            color: #000;
            margin: 0;
            padding: 10px;
        }
        h1 {
            text-align: center;
            font-size: 13pt;
            margin-bottom: 8px;
            font-weight: bold;
        }
        h2 {
            font-size: 11pt;
            margin-top: 12px;
            margin-bottom: 6px;
            font-weight: bold;
            border-bottom: 1px solid #333;
            padding-bottom: 3px;
        }
        h2.no-border {
            border-bottom: none;
        }
        h3 {
            font-size: 10pt;
            margin-top: 10px;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .header {
            text-align: center;
            margin-bottom: 15px;
        }
        .subtitle {
            text-align: center;
            font-style: italic;
            margin-bottom: 20px;
        }
        p {
            margin: 4px 0;
        }
        p.description {
            line-height: 1.5;
        }
        table {
            width: 100%;
            margin: 8px 0;
        }
        table td {
            padding: 4px 6px;
            vertical-align: top;
        }
        .info-label {
            font-weight: bold;
            width: 35%;
        }
        .observations {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .signatures-section {
            margin-top: 20px;
        }
        .signature-block {
            display: inline-block;
            width: 48%;
            vertical-align: top;
            text-align: center;
        }
        .signature-box {
            margin: 10px auto;
        }
        /* Signature image styles - must match ETAT_LIEUX_SIGNATURE_IMG_STYLE in pdf/generate-etat-lieux.php */
        /* Simplified for TCPDF compatibility - removed outline, box-shadow, border-color (not well supported) */
        .signature-box img {
            width: 150px !important;
            height: auto !important;
            display: block !important;
            border: 0 !important;
            border-width: 0 !important;
            border-style: none !important;
            background: transparent !important;
            padding: 0 !important;
            margin: 0 auto !important;
        }
        /* Signature table - ensure no borders on table or cells */
        .signature-table {
            border: 0 !important;
        }
        .signature-table td {
            border: 0 !important;
            border-width: 0 !important;
            border-style: none !important;
            padding: 10px !important;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>MY INVEST IMMOBILIER</h1>
    </div>
    
    <div class="subtitle">
        ÉTAT DES LIEUX {{type_label}}<br>
        Référence : {{reference}}
    </div>

    <h2>1. Informations générales</h2>
    
    <table cellspacing="0" cellpadding="4">
        <tr>
            <td class="info-label">Date de l'état des lieux :</td>
            <td>{{date_etat}}</td>
        </tr>
        <tr>
            <td class="info-label">Type :</td>
            <td>{{type_label}}</td>
        </tr>
    </table>

    <h2>2. Bien loué</h2>
    
    <p><strong>Adresse : </strong>{{adresse}}</p>
    {{appartement_row}}
    <p><strong>Type de logement : </strong>{{type_logement}}</p>
    <p><strong>Surface : </strong>{{surface}} m²</p>

    <h2>3. Parties</h2>
    
    <h3>Bailleur</h3>
    <p><strong>Nom : </strong>{{bailleur_nom}}</p>
    {{bailleur_representant_row}}
    
    <h3>Locataire(s)</h3>
    {{locataires_info}}

    <h2>4. Relevé des compteurs</h2>
    
    <table cellspacing="0" cellpadding="4">
        <tr>
            <td class="info-label">Électricité :</td>
            <td>{{compteur_electricite}}</td>
        </tr>
        <tr>
            <td class="info-label">Eau froide :</td>
            <td>{{compteur_eau_froide}}</td>
        </tr>
    </table>

    <h2>5. Remise des clés</h2>
    
    <table cellspacing="0" cellpadding="4">
        <tr>
            <td class="info-label">Clés de l'appartement :</td>
            <td>{{cles_appartement}}</td>
        </tr>
        <tr>
            <td class="info-label">Clés de la boîte aux lettres :</td>
            <td>{{cles_boite_lettres}}</td>
        </tr>
        <tr>
            <td class="info-label">Autre :</td>
            <td>{{cles_autre}}</td>
        </tr>
        <tr>
            <td class="info-label">TOTAL :</td>
            <td>{{cles_total}}</td>
        </tr>
    </table>

    <h2 class="no-border">6. Description de l'état du logement</h2>
    
    <p class="observations description">{{etat_logement}}</p>
    
    <h3>État général</h3>
    <p class="observations">{{etat_general}}</p>
    
    {{observations_section}}

    <h2 class="no-border">7. Signatures</h2>
    
    <p>Fait à {{lieu_signature}}, le {{date_signature}}</p>
    
    <div class="signatures-section">
        {{signatures_table}}
    </div>

    <div style="margin-top: 30px; font-size: 8pt; text-align: center; color: #666;">
        <p>Document généré électroniquement par My Invest Immobilier</p>
        <p>État des lieux - Référence : {{reference}}</p>
    </div>
</body>
</html>
HTML;
}

/**
 * Get the HTML template for état des lieux de SORTIE (exit inventory) PDF
 * This template includes sortie-specific sections like deposit guarantee and property assessment
 * 
 * @return string HTML template with placeholders for exit inventory
 */
function getDefaultExitEtatLieuxTemplate() {
    return <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>État des lieux de sortie - {{reference}}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 9.5pt;
            line-height: 1.4;
            color: #000;
            margin: 0;
            padding: 10px;
        }
        h1 {
            text-align: center;
            font-size: 13pt;
            margin-bottom: 8px;
            font-weight: bold;
        }
        h2 {
            font-size: 11pt;
            margin-top: 12px;
            margin-bottom: 6px;
            font-weight: bold;
            border-bottom: 1px solid #333;
            padding-bottom: 3px;
        }
        h2.no-border {
            border-bottom: none;
        }
        h3 {
            font-size: 10pt;
            margin-top: 10px;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .header {
            text-align: center;
            margin-bottom: 15px;
        }
        .subtitle {
            text-align: center;
            font-style: italic;
            margin-bottom: 20px;
        }
        p {
            margin: 4px 0;
        }
        p.description {
            line-height: 1.5;
        }
        table {
            width: 100%;
            margin: 8px 0;
        }
        table td {
            padding: 4px 6px;
            vertical-align: top;
        }
        .info-label {
            font-weight: bold;
            width: 35%;
        }
        .observations {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .bilan-table {
            border-collapse: collapse;
            margin: 10px 0;
        }
        .bilan-table th,
        .bilan-table td {
            border: 1px solid #333;
            padding: 6px;
            text-align: left;
        }
        .bilan-table th {
            background-color: #f0f0f0;
            font-weight: bold;
            font-size: 9pt;
        }
        .bilan-table td {
            font-size: 9pt;
        }
        .bilan-table .text-right {
            text-align: right;
        }
        .bilan-table tfoot td {
            font-weight: bold;
            background-color: #f8f8f8;
        }
        .signatures-section {
            margin-top: 20px;
        }
        .signature-block {
            display: inline-block;
            width: 48%;
            vertical-align: top;
            text-align: center;
        }
        .signature-box {
            margin: 10px auto;
        }
        /* Signature image styles - must match ETAT_LIEUX_SIGNATURE_IMG_STYLE in pdf/generate-etat-lieux.php */
        /* Simplified for TCPDF compatibility - removed outline, box-shadow, border-color (not well supported) */
        .signature-box img {
            width: 150px !important;
            height: auto !important;
            display: block !important;
            border: 0 !important;
            border-width: 0 !important;
            border-style: none !important;
            background: transparent !important;
            padding: 0 !important;
            margin: 0 auto !important;
        }
        /* Signature table - ensure no borders on table or cells */
        .signature-table {
            border: 0 !important;
        }
        .signature-table td {
            border: 0 !important;
            border-width: 0 !important;
            border-style: none !important;
            padding: 10px !important;
        }
        .conformity-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 9pt;
        }
        .conformity-conforme {
            background-color: #d4edda;
            color: #155724;
        }
        .conformity-non-conforme {
            background-color: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>MY INVEST IMMOBILIER</h1>
    </div>
    
    <div class="subtitle">
        ÉTAT DES LIEUX DE SORTIE<br>
        Référence : {{reference}}
    </div>

    <h2>1. Informations générales</h2>
    
    <table cellspacing="0" cellpadding="4">
        <tr>
            <td class="info-label">Date de l'état des lieux :</td>
            <td>{{date_etat}}</td>
        </tr>
        <tr>
            <td class="info-label">Type :</td>
            <td>État des lieux de sortie</td>
        </tr>
    </table>

    <h2>2. Bien loué</h2>
    
    <p><strong>Adresse : </strong>{{adresse}}</p>
    {{appartement_row}}
    <p><strong>Type de logement : </strong>{{type_logement}}</p>
    <p><strong>Surface : </strong>{{surface}} m²</p>

    <h2>3. Parties</h2>
    
    <h3>Bailleur</h3>
    <p><strong>Nom : </strong>{{bailleur_nom}}</p>
    {{bailleur_representant_row}}
    
    <h3>Locataire(s)</h3>
    {{locataires_info}}

    <h2>4. Relevé des compteurs</h2>
    
    <table cellspacing="0" cellpadding="4">
        <tr>
            <td class="info-label">Électricité :</td>
            <td>{{compteur_electricite}}</td>
        </tr>
        <tr>
            <td class="info-label">Eau froide :</td>
            <td>{{compteur_eau_froide}}</td>
        </tr>
    </table>

    <h2>5. Remise des clés</h2>
    
    <table cellspacing="0" cellpadding="4">
        <tr>
            <td class="info-label">Clés de l'appartement :</td>
            <td>{{cles_appartement}}</td>
        </tr>
        <tr>
            <td class="info-label">Clés de la boîte aux lettres :</td>
            <td>{{cles_boite_lettres}}</td>
        </tr>
        <tr>
            <td class="info-label">Autre :</td>
            <td>{{cles_autre}}</td>
        </tr>
        <tr>
            <td class="info-label">TOTAL :</td>
            <td>{{cles_total}}</td>
        </tr>
        <tr>
            <td class="info-label">Conformité :</td>
            <td>{{cles_conformite}}</td>
        </tr>
    </table>
    {{cles_observations_section}}

    <h2 class="no-border">6. Description de l'état du logement</h2>
    
    <p class="observations description">{{etat_logement}}</p>
    
    <h3>État général</h3>
    <p class="observations">{{etat_general}}</p>
    <table cellspacing="0" cellpadding="4">
        <tr>
            <td class="info-label">Conformité générale :</td>
            <td>{{etat_general_conforme}}</td>
        </tr>
    </table>
    
    {{degradations_section}}
    
    {{observations_section}}

    {{depot_garantie_section}}

    {{bilan_logement_section}}

    <h2 class="no-border">{{signatures_section_number}}. Signatures</h2>
    
    <p>Fait à {{lieu_signature}}, le {{date_signature}}</p>
    
    <div class="signatures-section">
        {{signatures_table}}
    </div>

    <div style="margin-top: 30px; font-size: 8pt; text-align: center; color: #666;">
        <p>Document généré électroniquement par My Invest Immobilier</p>
        <p>État des lieux de sortie - Référence : {{reference}}</p>
    </div>
</body>
</html>
HTML;
}
