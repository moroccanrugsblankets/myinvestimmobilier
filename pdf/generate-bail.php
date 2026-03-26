<?php
/**
 * Génération du PDF du bail signé
 * My Invest Immobilier
 * 
 * Utilise TCPDF pour générer un PDF professionnel conforme au modèle MY INVEST IMMOBILIER
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/generate-contrat-pdf.php';

/**
 * Générer le PDF du bail
 * @param int $contratId
 * @return string|false Chemin du fichier PDF généré
 */
function generateBailPDF($contratId) {
    // Validate and sanitize contract ID: ensure it's a positive integer
    $originalId = $contratId;
    $contratId = (int)$contratId;
    
    // Return early if invalid ID
    if ($contratId <= 0) {
        // Sanitize original ID for logging (remove newlines and control characters)
        $safeOriginalId = preg_replace('/[\x00-\x1F\x7F]/', '', (string)$originalId);
        error_log("generateBailPDF: ERREUR - ID de contrat invalide: '$safeOriginalId' (cast: $contratId)");
        return false;
    }
    
    error_log("=== generateBailPDF START pour contrat #$contratId ===");
    error_log("generateBailPDF: Redirection vers generateContratPDF()");
    
    // Utiliser la nouvelle fonction de génération PDF avec TCPDF
    $result = generateContratPDF($contratId);
    
    if ($result) {
        error_log("generateBailPDF: Succès - PDF généré: $result");
    } else {
        error_log("generateBailPDF: ÉCHEC - Aucun PDF généré");
    }
    error_log("=== generateBailPDF END pour contrat #$contratId ===");
    
    return $result;
}

/**
 * Vérifier si une commande existe
 */
function commandExists($command) {
    $return = shell_exec(sprintf("which %s", escapeshellarg($command)));
    return !empty($return);
}

/**
 * Générer le contenu HTML du bail
 */
function generateBailHTML($contrat, $locataires) {
    $dateCreation = formatDateFr($contrat['date_signature'] ?? $contrat['date_creation'], 'd/m/Y');
    $datePriseEffet = $contrat['date_prise_effet'] ? formatDateFr($contrat['date_prise_effet'], 'd/m/Y') : '___________';
    
    // Locataires info
    $locatairesParts = [];
    foreach ($locataires as $i => $locataire) {
        $locatairesParts[] = htmlspecialchars($locataire['prenom']) . ' ' . htmlspecialchars($locataire['nom']) . 
                            ', né(e) le ' . formatDateFr($locataire['date_naissance']) . 
                            ', Email : ' . htmlspecialchars($locataire['email']);
    }
    $locatairesText = implode('<br>', $locatairesParts);
    
    $loyer = formatMontant($contrat['loyer']);
    $charges = formatMontant($contrat['charges']);
    $depotGarantie = formatMontant($contrat['depot_garantie']);
    $loyerTotal = formatMontant($contrat['loyer'] + $contrat['charges']);
    
    $parking = htmlspecialchars($contrat['parking']);
    
    $html = '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Contrat de Bail - ' . htmlspecialchars($contrat['reference']) . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.5;
            color: #000;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        h1 {
            text-align: center;
            font-size: 14pt;
            margin-bottom: 10px;
            font-weight: bold;
        }
        h2 {
            font-size: 11pt;
            margin-top: 20px;
            margin-bottom: 10px;
            font-weight: bold;
        }
        h3 {
            font-size: 10pt;
            margin-top: 15px;
            margin-bottom: 8px;
            font-weight: bold;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .subtitle {
            text-align: center;
            font-style: italic;
            margin-bottom: 30px;
        }
        p {
            margin: 8px 0;
            text-align: justify;
        }
        ul, ol {
            margin: 10px 0;
            padding-left: 30px;
        }
        li {
            margin: 5px 0;
        }
        .checkbox {
            display: inline-block;
            width: 15px;
            height: 15px;
            border: 1px solid #000;
            margin-right: 5px;
            vertical-align: middle;
        }
        .checkbox.checked::before {
            content: "☑";
            font-size: 14pt;
        }
        .signature-block {
            margin-top: 40px;
            page-break-inside: avoid;
        }
        .signature-item {
            margin-bottom: 20px;
            padding: 10px;
        }
        .signature-image {
            max-width: 40px;
            max-height: 20px;
            border: none;
            border-width: 0;
            border-style: none;
            border-color: transparent;
            outline: none;
            outline-width: 0;
            padding: 0;
            background: transparent;
        }
        .company-signature {
            max-width: 50px;
            max-height: 25px;
            border: none;
            border-width: 0;
            border-style: none;
            border-color: transparent;
            outline: none;
            outline-width: 0;
            padding: 0;
            background: transparent;
        }
        .footer {
            margin-top: 40px;
            font-size: 8pt;
            text-align: center;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <p><strong>Logo My Invest Immobilier</strong></p>
    </div>
    
    <div class="subtitle">
        (Location meublée – résidence principale)
    </div>

    <h2>1. Parties</h2>
    
    <h3>Bailleur</h3>
    <p>My Invest Immobilier<br>
    Représentée par : Maxime ALEXANDRE<br>
    Adresse électronique de notification : contact@myinvest-immobilier.com</p>
    
    <h3>Locataire</h3>
    <p>' . $locatairesText . '</p>

    <h2>2. Désignation du logement</h2>
    
    <p><strong>Adresse :</strong><br>
    ' . htmlspecialchars($contrat['adresse']) . '</p>
    
    <p><strong>Désignation :</strong> ' . htmlspecialchars($contrat['type']) . '<br>
    <strong>Type :</strong> Logement meublé<br>
    Le logement est loué meublé conformément au décret n°2015-981.<br>
    Un inventaire détaillé et estimatif du mobilier est annexé au présent contrat.</p>
    
    <p><strong>Surface habitable :</strong> ~ ' . htmlspecialchars($contrat['surface']) . ' m²<br>
    La surface habitable est indiquée à titre informatif conformément à la loi Boutin</p>
    
    <p><strong>Usage :</strong> Résidence principale<br>
    <strong>Parking :</strong> ' . $parking . '</p>
    
    <p><strong>Équipements principaux fournis :</strong><br>
    <span class="checkbox checked"></span> Mobilier conforme à la réglementation<br>
    <span class="checkbox checked"></span> Cuisine équipée<br>
    <span class="checkbox checked"></span> Installations sanitaires</p>

    <h2>3. Durée</h2>
    
    <p>Le présent contrat est conclu pour une durée de 1 an, à compter du : <strong>' . $datePriseEffet . '</strong></p>
    
    <p>Il est renouvelable par tacite reconduction.</p>

    <h2>4. Conditions financières</h2>
    
    <p><strong>Loyer mensuel hors charges :</strong> ' . $loyer . '<br>
    <strong>Montant du dernier loyer acquitté par le précédent locataire :</strong> ' . $loyer . ' hors charges<br>
    Le logement n\'est pas soumis au dispositif d\'encadrement des loyers prévu par l\'article 140 de la loi ELAN.</p>
    
    <p><strong>Provision sur charges mensuelles :</strong> ' . $charges . '<br>
    <strong>Total mensuel :</strong> ' . $loyerTotal . '</p>
    
    <p><strong>Modalité de paiement :</strong> mensuel, payable d\'avance, au plus tard le 5 de chaque mois.</p>
    
    <h3>Révision du loyer</h3>
    <p>Le loyer pourra être révisé chaque année à la date anniversaire du contrat, selon la variation de l\'indice de référence des loyers (IRL) publié par l\'INSEE.</p>

    <h2>5. Dépôt de garantie – Condition suspensive</h2>
    
    <p>Le présent contrat est conclu sous condition suspensive de la réception effective par le bailleur du dépôt de garantie.</p>
    
    <p>À défaut de règlement, le contrat ne prendra aucun effet et sera réputé nul et non avenu.</p>
    
    <p>Le dépôt de garantie, d\'un montant de <strong>' . $depotGarantie . '</strong> (correspondant à deux mois de loyer hors charges), est versé à la signature du présent contrat.</p>
    
    <p>Il sera restitué conformément aux dispositions légales après restitution des clés, déduction faite, le cas échéant, des sommes restant dues ou des réparations locatives justifiées.</p>

    <h2>6. Charges</h2>
    
    <p>Les charges sont :<br>
    <span class="checkbox checked"></span> Provisionnelles avec régularisation annuelle</p>
    
    <p><strong>Nature des charges incluses :</strong><br>
    eau, électricité, ordures ménagères, internet.</p>

    <h2>7. État des lieux</h2>
    
    <p>Un état des lieux contradictoire est établi à l\'entrée et à la sortie du logement.</p>
    
    <p>Il fait partie intégrante du présent contrat.<br>
    Les éléments non mentionnés sont réputés en bon état d\'usage.</p>

    <h2>8. Obligations du locataire</h2>
    
    <p>Le locataire s\'engage à :</p>
    <ul>
        <li>user paisiblement du logement,</li>
        <li>maintenir le logement en bon état d\'entretien,</li>
        <li>répondre des dégradations survenues durant la location,</li>
        <li>être assuré pour les risques locatifs et en justifier à la remise des clés puis chaque année à la demande du bailleur.</li>
    </ul>

    <h2>9. Clause résolutoire (impayés / assurance)</h2>
    
    <p>Le présent contrat sera résilié de plein droit en cas de non-paiement du loyer ou des charges, ou de défaut d\'assurance, après commandement resté infructueux dans les conditions prévues par la loi.</p>

    <h2>10. Clause pénale (retard de paiement)</h2>
    
    <p>En cas de retard de paiement du loyer ou des charges, et après mise en demeure restée infructueuse, il pourra être appliqué une indemnité forfaitaire de retard fixée à 10 % des sommes dues, sans préjudice des intérêts légaux et des frais de recouvrement éventuellement engagés.</p>

    <h2>11. Interdictions</h2>
    
    <p><span class="checkbox checked"></span> Sous-location interdite sans accord écrit préalable du bailleur.</p>
    
    <h3>Animaux:</h3>
    <p>La détention d\'animaux est tolérée à titre strictement conditionnel, sous réserve qu\'ils ne causent aucune dégradation, nuisance, trouble de voisinage, insalubrité ou présence de nuisibles, tant dans le logement que dans les parties communes et abords de la propriété.</p>
    
    <p>Toute dégradation, nuisance ou frais induit par la présence d\'un animal sera intégralement à la charge du locataire.</p>
    
    <h3>Par le locataire:</h3>
    <p>Le locataire peut résilier le contrat à tout moment avec un préavis d\'un mois.</p>
    
    <p>Le congé devra être notifié exclusivement par lettre recommandée électronique (LRE), disposant d\'une valeur juridique équivalente à une lettre recommandée avec accusé de réception papier.</p>
    
    <p>À ce titre, les parties acceptent expressément l\'usage de la lettre recommandée électronique (LRE) via un prestataire qualifié, notamment AR24 (https://www.ar24.fr/).</p>
    
    <p>Tout congé transmis par un autre moyen (courrier simple, courrier électronique simple, SMS, remise en main propre, etc.) ne pourra être pris en compte.</p>
    
    <h3>Par le bailleur:</h3>
    <p>Dans les conditions prévues par la loi.</p>

    <h2>12. Notifications et communications</h2>
    
    <p>Les parties conviennent expressément que toute notification ou mise en demeure relative au présent contrat, à l\'exclusion des congés, pourra être valablement effectuée par voie électronique, notamment par lettre recommandée électronique (LRE) via un prestataire qualifié tel que AR24, ou par courrier électronique adressé à l\'adresse de notification indiquée au contrat.</p>
    
    <p>Toute notification adressée à cette adresse électronique sera réputée valablement reçue.</p>

    <h2>13. Diagnostic de performance énergétique (DPE)</h2>
    
    <p>Le logement loué est issu de la division d\'un ensemble immobilier.</p>
    
    <p>Le diagnostic de performance énergétique (DPE) correspond à l\'ensemble immobilier dans son intégralité, et non exclusivement au logement loué.</p>
    
    <p>À titre informatif, les performances énergétiques du bâtiment sont les suivantes :</p>
    <ul>
        <li>Classe énergie (DPE) : D</li>
        <li>Classe climat (GES) : B</li>
        <li>Validité 01/06/2035</li>
    </ul>
    
    <p>Le locataire reconnaît avoir reçu communication du diagnostic de performance énergétique.</p>

    <h2>14. Documents contractuels</h2>
    
    <p>Les documents suivants sont mis à disposition du locataire :</p>
    <ul>
        <li>le dossier de diagnostics techniques (DDT), établi pour l\'ensemble immobilier ;</li>
        <li>la notice d\'information locataire ;</li>
        <li>l\'état des lieux d\'entrée, établi contradictoirement lors de la remise des clés ;</li>
        <li>l\'inventaire du mobilier, le cas échéant.</li>
    </ul>

    <h2>15. Signature</h2>
    
    <p>Fait à Annemasse, le ' . $dateCreation . '</p>
    
    <div class="signature-block">
        <h3>Le bailleur</h3>
        <p><strong>My Invest Immobilier (SCI)</strong><br>
        Représentée par Maxime Alexandre<br>
        Lu et approuvé</p>';
    
    // Add company signature if contract is validated and signature is enabled
    // Note: getParameter returns boolean for 'boolean' type, or the default value as fallback
    $signatureEnabled = getParameter('signature_societe_enabled', false);
    $signatureImage = getParameter('signature_societe_image', '');
    $isValidated = ($contrat['statut'] === 'valide' && !empty($contrat['date_validation']));
    
    // Log for debugging signature agence replacement
    error_log("PDF Generation Bail HTML: === TRAITEMENT SIGNATURE AGENCE ===");
    error_log("PDF Generation Bail HTML: Contrat statut: " . $contrat['statut'] . ", date_validation: " . ($contrat['date_validation'] ?? 'NON DÉFINIE'));
    error_log("PDF Generation Bail HTML: isValidated: " . ($isValidated ? 'OUI' : 'NON'));
    error_log("PDF Generation Bail HTML: signatureEnabled: " . ($signatureEnabled ? 'OUI' : 'NON') . " (type: " . gettype($signatureEnabled) . ")");
    error_log("PDF Generation Bail HTML: signatureImage présente: " . (!empty($signatureImage) ? 'OUI (' . strlen($signatureImage) . ' octets)' : 'NON'));
    
    // Use helper function to normalize boolean parameter value
    $isSignatureEnabled = toBooleanParam($signatureEnabled);
    
    if ($isValidated && $isSignatureEnabled && !empty($signatureImage)) {
        error_log("PDF Generation Bail HTML: Signature agence AJOUTÉE au HTML");
        
        // Normalize the path: company signatures are stored as plain filenames in uploads/
        $normalizedPath = normalizeCompanySignaturePath($signatureImage);

        // Check if signature is a file path or a data URI
        $isFilePath = (strpos($normalizedPath, 'data:') !== 0 && strpos($normalizedPath, 'uploads/') === 0);
        
        if ($isFilePath) {
            // Signature is stored as a file path
            $baseDir = dirname(__DIR__);
            $absolutePath = $baseDir . '/' . $normalizedPath;
            
            if (file_exists($absolutePath)) {
                error_log("PDF Generation Bail: ✓ Utilisation du fichier physique existant: $normalizedPath");
                // Use relative path from pdf/ directory
                $relativePath = '../' . $normalizedPath;
                $html .= '
                <p><strong>Signature électronique</strong></p>
                <img src="' . htmlspecialchars($relativePath) . '" alt="Signature Société" class="company-signature" style="max-width: 50px; max-height: 25px; border: none; border-width: 0; border-style: none; border-color: transparent; outline: none; outline-width: 0; padding: 0; background: transparent; margin-bottom: 10px;"><br>
                <p style="margin-top: 10px;"><strong>Validé le :</strong> ' . formatDateFr($contrat['date_validation'], 'd/m/Y à H:i:s') . '</p>';
            } else {
                error_log("PDF Generation Bail: ERREUR - Fichier de signature introuvable: $absolutePath");
            }
        } else {
            // Legacy: signature is a data URI - save as physical file
            $physicalImagePath = saveSignatureAsPhysicalFile($signatureImage, 'agency_bail', $contratId);
            
            if ($physicalImagePath !== false) {
                // Utiliser l'image physique
                error_log("PDF Generation Bail: ✓ Image physique utilisée pour la signature agence");
                $html .= '
                <p><strong>Signature électronique</strong></p>
                <img src="' . htmlspecialchars($physicalImagePath) . '" alt="Signature Société" class="company-signature" style="max-width: 50px; max-height: 25px; border: none; border-width: 0; border-style: none; border-color: transparent; outline: none; outline-width: 0; padding: 0; background: transparent; margin-bottom: 10px;"><br>
                <p style="margin-top: 10px;"><strong>Validé le :</strong> ' . formatDateFr($contrat['date_validation'], 'd/m/Y à H:i:s') . '</p>';
                error_log("PDF Generation Bail: ✓ Signature agence ajoutée avec margin-top et sans bordure");
            } else {
                // Fallback: utiliser data URI
                error_log("PDF Generation Bail: AVERTISSEMENT - Impossible de sauvegarder l'image physique, utilisation du data URI");
                $html .= '
                <p><strong>Signature électronique</strong></p>
                <img src="' . htmlspecialchars($signatureImage) . '" alt="Signature Société" class="company-signature" style="max-width: 50px; max-height: 25px; border: none; border-width: 0; border-style: none; border-color: transparent; outline: none; outline-width: 0; padding: 0; background: transparent; margin-bottom: 10px;"><br>
                <p style="margin-top: 10px;"><strong>Validé le :</strong> ' . formatDateFr($contrat['date_validation'], 'd/m/Y à H:i:s') . '</p>';
            }
        }
    } else {
        error_log("PDF Generation Bail HTML: Signature agence NON ajoutée - Raisons: isValidated=" . ($isValidated ? 'true' : 'false') . ", isSignatureEnabled=" . ($isSignatureEnabled ? 'true' : 'false') . ", hasImage=" . (!empty($signatureImage) ? 'true' : 'false'));
        $html .= '
        <p>Signature<br>
        (Horodatage + adresse IP + tampon signé)</p>';
    }
    error_log("PDF Generation Bail HTML: === FIN TRAITEMENT SIGNATURE AGENCE ===");
    
    $html .= '
    </div>
    
    <div class="signature-block">';
    
    $nbLocataires = count($locataires);
    foreach ($locataires as $i => $locataire) {
        // Adapter le label selon le nombre de locataires
        // Si un seul locataire: "Le locataire" sans numéro
        // Si plusieurs locataires: "Le locataire 1", "Le locataire 2", etc.
        if ($nbLocataires === 1) {
            $locataireLabel = 'Le locataire';
        } else {
            $locataireLabel = 'Le locataire ' . ($i + 1);
        }
        
        $html .= '
        <div class="signature-item">
            <h3>' . $locataireLabel . '</h3>
            <p><strong>Nom et prénom :</strong> ' . htmlspecialchars($locataire['prenom']) . ' ' . htmlspecialchars($locataire['nom']) . '</p>
            <p><strong>Mention à saisir :</strong> ' . htmlspecialchars($locataire['mention_lu_approuve']) . '</p>
            <p><strong>Signature</strong></p>';
        
        if ($locataire['signature_data']) {
            // Sauvegarder la signature client comme fichier physique
            $locataireIdForFile = $locataire['id'] ?? ($i + 1);
            $physicalImagePath = saveSignatureAsPhysicalFile($locataire['signature_data'], 'tenant_bail', $contratId, $locataireIdForFile);
            
            if ($physicalImagePath !== false) {
                // Utiliser l'image physique
                error_log("PDF Generation Bail: ✓ Image physique utilisée pour la signature client " . ($i + 1));
                $html .= '<img src="' . htmlspecialchars($physicalImagePath) . '" alt="Signature" style="max-width: 40px; max-height: 20px; border: none; border-width: 0; border-style: none; border-color: transparent; outline: none; outline-width: 0; padding: 0; background: transparent; margin-bottom: 10px;"><br>';
                error_log("PDF Generation Bail: ✓ Signature client " . ($i + 1) . " ajoutée avec margin-top et sans bordure");
            } else {
                // Fallback: utiliser data URI
                error_log("PDF Generation Bail: AVERTISSEMENT - Impossible de sauvegarder l'image physique client " . ($i + 1) . ", utilisation du data URI");
                $html .= '<img src="' . htmlspecialchars($locataire['signature_data']) . '" alt="Signature" style="max-width: 40px; max-height: 20px; border: none; border-width: 0; border-style: none; border-color: transparent; outline: none; outline-width: 0; padding: 0; background: transparent; margin-bottom: 10px;"><br>';
            }
        }
        
        $html .= '<div style="margin-top: 10px;"><p style="white-space: nowrap;"><strong>Horodatage :</strong> ' . formatDateFr($locataire['signature_timestamp'], 'd/m/Y à H:i:s') . '</p>
            <p style="white-space: nowrap;"><strong>Adresse IP :</strong> ' . htmlspecialchars($locataire['signature_ip']) . '</p></div>
        </div>';
        error_log("PDF Generation Bail: ✓ Horodatage affiché sur une seule ligne");
    }
    
    $html .= '
    </div>

    <div class="footer">
        <p>Document généré électroniquement par My Invest Immobilier</p>
        <p>Contrat de bail - Référence : ' . htmlspecialchars($contrat['reference']) . '</p>
        <p>contact@myinvest-immobilier.com</p>
    </div>
</body>
</html>';
    
    return $html;
}
