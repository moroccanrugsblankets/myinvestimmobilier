<?php
/**
 * Default HTML templates for contract types
 * Shared between admin-v2/contrat-configuration.php and pdf/generate-contrat-pdf.php
 */

/**
 * Returns the human-readable label for a contract type.
 * @param string $typeContrat  'meuble', 'non_meuble', or 'sur_mesure'
 * @return string
 */
function getTypeContratLabel($typeContrat) {
    $labels = [
        'meuble'     => 'Meublé',
        'non_meuble' => 'Non meublé',
        'sur_mesure' => 'Sur mesure',
    ];
    return $labels[$typeContrat] ?? 'Meublé';
}

/**
 * Returns the default HTML template for a given contract type.
 * @param string $typeContrat  'meuble', 'non_meuble', or 'sur_mesure'
 * @return string
 */
function getDefaultContractTemplateByType($typeContrat) {
    switch ($typeContrat) {
        case 'non_meuble':
            return getDefaultContractTemplateNonMeuble();
        case 'sur_mesure':
            return getDefaultContractTemplateSurMesure();
        case 'meuble':
        default:
            return getDefaultContractTemplateMeuble();
    }
}

function getDefaultContractTemplateMeuble() {
    return <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Contrat de Bail Meublé - {{reference_unique}}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10pt; line-height: 1.5; color: #000; max-width: 800px; margin: 0 auto; padding: 20px; }
        h1 { text-align: center; font-size: 14pt; margin-bottom: 10px; font-weight: bold; }
        h2 { font-size: 11pt; margin-top: 20px; margin-bottom: 10px; font-weight: bold; }
        h3 { font-size: 10pt; margin-top: 15px; margin-bottom: 8px; font-weight: bold; }
        .header { text-align: center; margin-bottom: 20px; }
        .subtitle { text-align: center; font-style: italic; margin-bottom: 30px; }
        p { margin: 8px 0; text-align: justify; }
    </style>
</head>
<body>
    <div class="header">
        <h1>MY INVEST IMMOBILIER</h1>
    </div>

    <div class="subtitle">
        CONTRAT DE BAIL ({{type_contrat_label}})<br>
        (Location meublée – résidence principale)
    </div>

    <h2>1. Parties</h2>

    <h3>Bailleur</h3>
    <p>My Invest Immobilier (SCI)<br>
    Représentée par : Maxime ALEXANDRE<br>
    Adresse électronique de notification : contact@myinvest-immobilier.com</p>

    <h3>Locataire(s)</h3>
    <p>{{locataires_info}}</p>

    <h2>2. Désignation du logement</h2>

    <p><strong>Adresse :</strong><br>{{adresse}}</p>

    <p><strong>Type :</strong> {{type}} — Logement meublé<br>
    <strong>Surface habitable :</strong> ~ {{surface}} m²<br>
    <strong>Usage :</strong> Résidence principale<br>
    <strong>Parking :</strong> {{parking}}</p>

    <p>Le logement est loué meublé conformément au décret n°2015-981. La liste des meubles et équipements fournis est annexée au présent contrat.</p>

    <h2>3. Durée</h2>

    <p>Le présent contrat est conclu pour une durée de 1 an, à compter du : <strong>{{date_prise_effet}}</strong></p>
    <p>Il est renouvelable par tacite reconduction.</p>

    <h2>4. Conditions financières</h2>

    <p><strong>Loyer mensuel hors charges :</strong> {{loyer}} €<br>
    <strong>Provision sur charges mensuelles :</strong> {{charges}} €<br>
    <strong>Total mensuel :</strong> {{loyer_total}} €</p>
    <p><strong>Modalité de paiement :</strong> mensuel, payable d'avance, au plus tard le 5 de chaque mois.</p>

    <h2>5. Dépôt de garantie</h2>

    <p>Le dépôt de garantie, d'un montant de <strong>{{depot_garantie}} €</strong> (deux mois de loyer hors charges), est versé à la signature du présent contrat.</p>

    <h2>6. Coordonnées bancaires</h2>

    <p><strong>IBAN :</strong> {{iban}}<br>
    <strong>BIC :</strong> {{bic}}<br>
    <strong>Titulaire :</strong> MY INVEST IMMOBILIER</p>

    <h2>7. Signatures</h2>

    <p>Fait à Annemasse, le {{date_signature}}</p>
    {{signatures_table}}

    <div style="margin-top: 40px; font-size: 8pt; text-align: center; color: #666;">
        <p>Document généré électroniquement par My Invest Immobilier</p>
        <p>Contrat de bail ({{type_contrat_label}}) — Référence : {{reference_unique}}</p>
    </div>
</body>
</html>
HTML;
}

function getDefaultContractTemplateNonMeuble() {
    return <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Contrat de Bail Non Meublé - {{reference_unique}}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10pt; line-height: 1.5; color: #000; max-width: 800px; margin: 0 auto; padding: 20px; }
        h1 { text-align: center; font-size: 14pt; margin-bottom: 10px; font-weight: bold; }
        h2 { font-size: 11pt; margin-top: 20px; margin-bottom: 10px; font-weight: bold; }
        h3 { font-size: 10pt; margin-top: 15px; margin-bottom: 8px; font-weight: bold; }
        .header { text-align: center; margin-bottom: 20px; }
        .subtitle { text-align: center; font-style: italic; margin-bottom: 30px; }
        p { margin: 8px 0; text-align: justify; }
    </style>
</head>
<body>
    <div class="header">
        <h1>MY INVEST IMMOBILIER</h1>
    </div>

    <div class="subtitle">
        CONTRAT DE BAIL ({{type_contrat_label}})<br>
        (Location vide – résidence principale)
    </div>

    <h2>1. Parties</h2>

    <h3>Bailleur</h3>
    <p>My Invest Immobilier (SCI)<br>
    Représentée par : Maxime ALEXANDRE<br>
    Adresse électronique de notification : contact@myinvest-immobilier.com</p>

    <h3>Locataire(s)</h3>
    <p>{{locataires_info}}</p>

    <h2>2. Désignation du logement</h2>

    <p><strong>Adresse :</strong><br>{{adresse}}</p>

    <p><strong>Type :</strong> {{type}} — Logement non meublé<br>
    <strong>Surface habitable :</strong> ~ {{surface}} m²<br>
    <strong>Usage :</strong> Résidence principale<br>
    <strong>Parking :</strong> {{parking}}</p>

    <p>Le logement est loué vide (non meublé) conformément à la loi n°89-462 du 6 juillet 1989.</p>

    <h2>3. Durée</h2>

    <p>Le présent contrat est conclu pour une durée de 3 ans, à compter du : <strong>{{date_prise_effet}}</strong></p>
    <p>Il est renouvelable par tacite reconduction.</p>

    <h2>4. Conditions financières</h2>

    <p><strong>Loyer mensuel hors charges :</strong> {{loyer}} €<br>
    <strong>Provision sur charges mensuelles :</strong> {{charges}} €<br>
    <strong>Total mensuel :</strong> {{loyer_total}} €</p>
    <p><strong>Modalité de paiement :</strong> mensuel, payable d'avance, au plus tard le 5 de chaque mois.</p>

    <h2>5. Dépôt de garantie</h2>

    <p>Le dépôt de garantie, d'un montant de <strong>{{depot_garantie}} €</strong> (un mois de loyer hors charges), est versé à la signature du présent contrat.</p>

    <h2>6. Coordonnées bancaires</h2>

    <p><strong>IBAN :</strong> {{iban}}<br>
    <strong>BIC :</strong> {{bic}}<br>
    <strong>Titulaire :</strong> MY INVEST IMMOBILIER</p>

    <h2>7. Signatures</h2>

    <p>Fait à Annemasse, le {{date_signature}}</p>
    {{signatures_table}}

    <div style="margin-top: 40px; font-size: 8pt; text-align: center; color: #666;">
        <p>Document généré électroniquement par My Invest Immobilier</p>
        <p>Contrat de bail ({{type_contrat_label}}) — Référence : {{reference_unique}}</p>
    </div>
</body>
</html>
HTML;
}

function getDefaultContractTemplateSurMesure() {
    return <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Contrat Sur Mesure - {{reference_unique}}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10pt; line-height: 1.5; color: #000; max-width: 800px; margin: 0 auto; padding: 20px; }
        h1 { text-align: center; font-size: 14pt; margin-bottom: 10px; font-weight: bold; }
        h2 { font-size: 11pt; margin-top: 20px; margin-bottom: 10px; font-weight: bold; }
        h3 { font-size: 10pt; margin-top: 15px; margin-bottom: 8px; font-weight: bold; }
        .header { text-align: center; margin-bottom: 20px; }
        .subtitle { text-align: center; font-style: italic; margin-bottom: 30px; }
        p { margin: 8px 0; text-align: justify; }
    </style>
</head>
<body>
    <div class="header">
        <h1>MY INVEST IMMOBILIER</h1>
    </div>

    <div class="subtitle">
        CONTRAT DE BAIL ({{type_contrat_label}})<br>
        (Conditions particulières)
    </div>

    <h2>1. Parties</h2>

    <h3>Bailleur</h3>
    <p>My Invest Immobilier (SCI)<br>
    Représentée par : Maxime ALEXANDRE<br>
    Adresse électronique de notification : contact@myinvest-immobilier.com</p>

    <h3>Locataire(s)</h3>
    <p>{{locataires_info}}</p>

    <h2>2. Désignation du logement</h2>

    <p><strong>Adresse :</strong><br>{{adresse}}</p>

    <p><strong>Type :</strong> {{type}}<br>
    <strong>Surface habitable :</strong> ~ {{surface}} m²<br>
    <strong>Parking :</strong> {{parking}}</p>

    <h2>3. Durée</h2>

    <p>Le présent contrat prend effet le : <strong>{{date_prise_effet}}</strong></p>
    <p>Les conditions particulières de durée sont définies d'un commun accord entre les parties.</p>

    <h2>4. Conditions financières</h2>

    <p><strong>Loyer mensuel hors charges :</strong> {{loyer}} €<br>
    <strong>Provision sur charges mensuelles :</strong> {{charges}} €<br>
    <strong>Total mensuel :</strong> {{loyer_total}} €</p>
    <p><strong>Modalité de paiement :</strong> selon les modalités définies entre les parties.</p>

    <h2>5. Dépôt de garantie</h2>

    <p>Le dépôt de garantie, d'un montant de <strong>{{depot_garantie}} €</strong>, est versé à la signature du présent contrat selon les conditions convenues.</p>

    <h2>6. Coordonnées bancaires</h2>

    <p><strong>IBAN :</strong> {{iban}}<br>
    <strong>BIC :</strong> {{bic}}<br>
    <strong>Titulaire :</strong> MY INVEST IMMOBILIER</p>

    <h2>7. Signatures</h2>

    <p>Fait à Annemasse, le {{date_signature}}</p>
    {{signatures_table}}

    <div style="margin-top: 40px; font-size: 8pt; text-align: center; color: #666;">
        <p>Document généré électroniquement par My Invest Immobilier</p>
        <p>Contrat de bail ({{type_contrat_label}}) — Référence : {{reference_unique}}</p>
    </div>
</body>
</html>
HTML;
}

// Legacy alias for backward compatibility
function getDefaultContractTemplate() {
    return getDefaultContractTemplateMeuble();
}
