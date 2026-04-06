<?php
/**
 * Fonctions partagées pour le module de rappel des loyers.
 *
 * Utilisées par :
 * - cron/rappel-loyers.php (exécution automatique planifiée)
 * - admin-v2/gestion-loyers.php (envoi manuel via l'interface)
 *
 * Ce fichier centralise la logique de génération du contenu de l'email
 * afin que les deux points d'entrée produisent exactement le même résultat.
 */

/**
 * Génère le message de statut des loyers pour l'email de rappel.
 *
 * Le tableau est général sur TOUS les mois (pas seulement le mois courant).
 * - Le statut d'un logement est "impayé" si au moins un loyer est impayé sur n'importe quel mois.
 * - La somme des montants impayés est calculée sur tous les mois.
 *
 * @param PDO $pdo  Instance PDO
 * @param int $mois  Mois courant (1-12), conservé pour compatibilité de signature
 * @param int $annee Année courante, conservé pour compatibilité de signature
 * @return array ['tous_payes' => bool, 'message' => string, 'nb_total' => int, 'nb_payes' => int, 'nb_impayes' => int]
 */
function genererMessageStatutLoyers($pdo, $mois, $annee) {
    try {
        // Récupérer tous les biens avec contrat actif et leur statut agrégé sur TOUS les mois
        // Use contrat_logement for frozen reference/adresse/loyer/charges with fallback to logements
        $stmt = $pdo->query("
            SELECT
                COALESCE(cl.reference, l.reference) as reference,
                COALESCE(cl.adresse, l.adresse) as adresse,
                COALESCE(cl.loyer, l.loyer) as loyer,
                COALESCE(cl.charges, l.charges) as charges,
                (SELECT GROUP_CONCAT(CONCAT(loc.prenom, ' ', loc.nom) SEPARATOR ', ')
                 FROM locataires loc
                 INNER JOIN contrats c2 ON loc.contrat_id = c2.id
                 WHERE c2.logement_id = l.id AND c2.statut = 'valide'
                 AND c2.date_prise_effet IS NOT NULL AND c2.date_prise_effet <= CURDATE()
                 AND c2.id = (
                     SELECT id FROM contrats c3
                     WHERE c3.logement_id = l.id AND c3.statut = 'valide'
                     AND c3.date_prise_effet IS NOT NULL AND c3.date_prise_effet <= CURDATE()
                     ORDER BY c3.date_prise_effet DESC, c3.id DESC LIMIT 1
                 )) as locataires,
                COALESCE(SUM(CASE WHEN lt.statut_paiement = 'impaye' THEN lt.montant_attendu ELSE 0 END), 0) as montant_total_impaye,
                COUNT(CASE WHEN lt.statut_paiement = 'impaye' THEN 1 END) as nb_mois_impayes,
                COUNT(CASE WHEN lt.statut_paiement = 'attente' THEN 1 END) as nb_mois_attente,
                COUNT(CASE WHEN lt.statut_paiement = 'paye' THEN 1 END) as nb_mois_payes,
                CASE
                    WHEN COUNT(lt.id) = 0 THEN 'attente'
                    WHEN COUNT(CASE WHEN lt.statut_paiement = 'impaye' THEN 1 END) > 0 THEN 'impaye'
                    WHEN COUNT(CASE WHEN lt.statut_paiement = 'attente' THEN 1 END) > 0 THEN 'attente'
                    ELSE 'paye'
                END as statut_global
            FROM logements l
            INNER JOIN contrats c ON c.logement_id = l.id
            LEFT JOIN contrat_logement cl ON cl.contrat_id = c.id
            INNER JOIN (
                SELECT logement_id, MAX(id) AS max_contrat_id
                FROM contrats
                WHERE statut = 'valide'
                AND date_prise_effet IS NOT NULL AND date_prise_effet <= CURDATE()
                GROUP BY logement_id
            ) dc ON c.id = dc.max_contrat_id
            LEFT JOIN loyers_tracking lt ON lt.logement_id = l.id AND lt.contrat_id = c.id AND lt.deleted_at IS NULL
            GROUP BY l.id, COALESCE(cl.reference, l.reference), COALESCE(cl.adresse, l.adresse), COALESCE(cl.loyer, l.loyer), COALESCE(cl.charges, l.charges)
            ORDER BY COALESCE(cl.reference, l.reference)
        ");
        $biens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($biens)) {
            return [
                'tous_payes' => true,
                'message' => '<p><strong>Aucun bien en location actuellement.</strong></p>'
            ];
        }

        $nbTotal = count($biens);
        $nbPayes = 0;
        $nbImpayes = 0;
        $nbAttente = 0;
        $montantTotalImpaye = 0;

        $listeBiens = [];

        foreach ($biens as $bien) {
            $statusIcon = '⏳';
            $statusText = 'En attente';
            $statusColor = '#ffc107';

            if ($bien['statut_global'] === 'paye') {
                $nbPayes++;
                $statusIcon = '✅';
                $statusText = 'Payé';
                $statusColor = '#28a745';
            } elseif ($bien['statut_global'] === 'impaye') {
                $nbImpayes++;
                $statusIcon = '❌';
                $statusText = 'Impayé';
                $statusColor = '#dc3545';
                $montantTotalImpaye += $bien['montant_total_impaye'];
            } else {
                $nbAttente++;
            }

            $locataires = $bien['locataires'] ?: 'Non assigné';

            $listeBiens[] = sprintf(
                '<tr>
                    <td style="padding: 10px; border: 1px solid #dee2e6;"><strong>%s</strong></td>
                    <td style="padding: 10px; border: 1px solid #dee2e6;">%s</td>
                    <td style="padding: 10px; border: 1px solid #dee2e6; text-align: center; background-color: %s; color: white; font-weight: bold;">%s %s</td>
                </tr>',
                htmlspecialchars($bien['reference']),
                htmlspecialchars($locataires),
                htmlspecialchars($statusColor),
                $statusIcon,
                htmlspecialchars($statusText)
            );
        }

        $tousPayes = ($nbImpayes === 0 && $nbAttente === 0);

        $resume = sprintf(
            '<p><strong>Récapitulatif général (tous les mois) :</strong></p>
            <ul>
                <li>Total de biens en location: <strong>%d</strong></li>
                <li style="color: #28a745;">✅ Biens à jour: <strong>%d</strong></li>
                <li style="color: #dc3545;">❌ Biens avec loyers impayés: <strong>%d</strong></li>
            </ul>',
            $nbTotal,
            $nbPayes,
            $nbImpayes
        );

        if ($tousPayes) {
            $message = $resume . '<p style="color: #28a745; font-size: 16px; font-weight: bold;">🎉 Excellente nouvelle ! Tous les loyers sont à jour.</p>';
        } else {
            $message = $resume . '<p style="color: #dc3545; font-size: 16px; font-weight: bold;">⚠️ Attention ! Il reste des loyers impayés ou en attente de confirmation.</p>';
        }

        $message .= '<table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
            <thead>
                <tr style="background-color: #f8f9fa;">
                    <th style="padding: 10px; border: 1px solid #dee2e6; text-align: left;">Référence</th>
                    <th style="padding: 10px; border: 1px solid #dee2e6; text-align: left;">Locataire(s)</th>
                    <th style="padding: 10px; border: 1px solid #dee2e6; text-align: center;">Statut global</th>
                </tr>
            </thead>
            <tbody>' . implode('', $listeBiens) . '</tbody>
        </table>';

        return [
            'tous_payes' => $tousPayes,
            'message' => $message,
            'nb_total' => $nbTotal,
            'nb_payes' => $nbPayes,
            'nb_impayes' => $nbImpayes
        ];

    } catch (Exception $e) {
        error_log("Erreur génération message statut loyers: " . $e->getMessage());
        return [
            'tous_payes' => false,
            'message' => '<p>Erreur lors de la récupération des données de paiement.</p>'
        ];
    }
}
