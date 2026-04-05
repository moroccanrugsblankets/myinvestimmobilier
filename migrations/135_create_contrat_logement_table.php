<?php
/**
 * Migration 135 : Créer la table contrat_logement
 *
 * Objectif : Éviter que les contrats récupèrent automatiquement les données actuelles
 * du logement (prix, équipements, inventaire, etc.), ce qui entraînerait des modifications
 * en cascade sur les anciens contrats.
 *
 * La table contrat_logement stocke un instantané (snapshot) de toutes les informations
 * du logement au moment de la création du contrat. Chaque contrat conserve ainsi ses
 * propres informations figées, indépendamment des évolutions ultérieures du logement.
 *
 * Portée :
 * - Création de la table contrat_logement
 * - Population depuis les données existantes (logements + contrats)
 */

require_once __DIR__ . '/../includes/db.php';

echo "Migration 135 : Création de la table contrat_logement\n";

try {
    $pdo->beginTransaction();

    // -------------------------------------------------------------------------
    // 1. Créer la table contrat_logement
    // -------------------------------------------------------------------------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS contrat_logement (
            id INT AUTO_INCREMENT PRIMARY KEY,

            -- Relations
            contrat_id INT NOT NULL,
            logement_id INT NOT NULL,

            -- Identification du logement
            reference VARCHAR(20) NOT NULL,
            adresse TEXT NOT NULL,
            type VARCHAR(50) NOT NULL,
            surface DECIMAL(5,2) NULL,

            -- Conditions financières (figées à la signature)
            loyer DECIMAL(10,2) NOT NULL,
            charges DECIMAL(10,2) NOT NULL DEFAULT 0,
            depot_garantie DECIMAL(10,2) NOT NULL DEFAULT 0,

            -- Caractéristiques
            parking ENUM('Aucun', '1 place') DEFAULT 'Aucun',
            description TEXT NULL,
            type_contrat ENUM('meuble', 'non_meuble', 'sur_mesure') NOT NULL DEFAULT 'meuble',
            duree_garantie TINYINT UNSIGNED NOT NULL DEFAULT 1,

            -- Liens et documents
            lien_externe VARCHAR(2048) NULL,
            dpe_file VARCHAR(500) NULL,
            dpe_classe VARCHAR(1) NULL,
            dpe_ges VARCHAR(100) NULL,
            dpe_numero VARCHAR(255) NULL,
            dpe_valable_jusqu_a VARCHAR(100) NULL,

            -- Valeurs par défaut état des lieux
            default_cles_appartement INT DEFAULT 2,
            default_cles_boite_lettres INT DEFAULT 1,
            default_etat_logement TEXT NULL,

            -- Inventaire et équipements (snapshot JSON)
            equipements TEXT NULL,
            equipements_json JSON NULL COMMENT 'Snapshot JSON de inventaire_equipements au moment du contrat',

            -- Métadonnées
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            -- Contraintes
            UNIQUE KEY uk_contrat_logement (contrat_id),
            INDEX idx_logement (logement_id),
            FOREIGN KEY (contrat_id) REFERENCES contrats(id) ON DELETE CASCADE,
            FOREIGN KEY (logement_id) REFERENCES logements(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Snapshot figé des données du logement au moment de la création du contrat'
    ");
    echo "  ✓ Table contrat_logement créée\n";

    // -------------------------------------------------------------------------
    // 2. Vérifier si default_etat_logement existe (migration 134 peut ne pas avoir
    //    encore été jouée sur certains environnements — on utilise COALESCE pour sécurité)
    // -------------------------------------------------------------------------
    $hasNewColumn = false;
    try {
        $colCheck = $pdo->query("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'logements'
              AND COLUMN_NAME = 'default_etat_logement'
        ");
        $hasNewColumn = (bool)$colCheck->fetch();
    } catch (\Exception $e) {
        // ignore
    }
    $etatLogementCol = $hasNewColumn ? 'l.default_etat_logement' : 'l.default_etat_piece_principale';

    // -------------------------------------------------------------------------
    // 3. Vérifier colonnes optionnelles dans logements
    // -------------------------------------------------------------------------
    $optionalCols = ['lien_externe', 'type_contrat', 'duree_garantie', 'dpe_file',
                     'dpe_classe', 'dpe_ges', 'dpe_numero', 'dpe_valable_jusqu_a',
                     'default_cles_appartement', 'default_cles_boite_lettres',
                     'description', 'equipements'];
    $existingLogementCols = [];
    $colResult = $pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logements'
    ");
    foreach ($colResult->fetchAll(PDO::FETCH_COLUMN) as $col) {
        $existingLogementCols[$col] = true;
    }

    // Build SELECT for optional columns
    $selectParts = [
        'c.id as contrat_id',
        'l.id as logement_id',
        'l.reference',
        'l.adresse',
        'l.type',
        'l.surface',
        'l.loyer',
        'l.charges',
        'l.depot_garantie',
        'l.parking',
    ];
    if (isset($existingLogementCols['description']))          $selectParts[] = 'l.description';
    else                                                       $selectParts[] = "'' as description";
    if (isset($existingLogementCols['type_contrat']))         $selectParts[] = 'l.type_contrat';
    else                                                       $selectParts[] = "'meuble' as type_contrat";
    if (isset($existingLogementCols['duree_garantie']))       $selectParts[] = 'l.duree_garantie';
    else                                                       $selectParts[] = '1 as duree_garantie';
    if (isset($existingLogementCols['lien_externe']))         $selectParts[] = 'l.lien_externe';
    else                                                       $selectParts[] = 'NULL as lien_externe';
    if (isset($existingLogementCols['dpe_file']))             $selectParts[] = 'l.dpe_file';
    else                                                       $selectParts[] = 'NULL as dpe_file';
    if (isset($existingLogementCols['dpe_classe']))           $selectParts[] = 'l.dpe_classe';
    else                                                       $selectParts[] = 'NULL as dpe_classe';
    if (isset($existingLogementCols['dpe_ges']))              $selectParts[] = 'l.dpe_ges';
    else                                                       $selectParts[] = 'NULL as dpe_ges';
    if (isset($existingLogementCols['dpe_numero']))           $selectParts[] = 'l.dpe_numero';
    else                                                       $selectParts[] = 'NULL as dpe_numero';
    if (isset($existingLogementCols['dpe_valable_jusqu_a'])) $selectParts[] = 'l.dpe_valable_jusqu_a';
    else                                                       $selectParts[] = 'NULL as dpe_valable_jusqu_a';
    if (isset($existingLogementCols['default_cles_appartement']))   $selectParts[] = 'COALESCE(l.default_cles_appartement, 2) as default_cles_appartement';
    else                                                             $selectParts[] = '2 as default_cles_appartement';
    if (isset($existingLogementCols['default_cles_boite_lettres'])) $selectParts[] = 'COALESCE(l.default_cles_boite_lettres, 1) as default_cles_boite_lettres';
    else                                                             $selectParts[] = '1 as default_cles_boite_lettres';
    $selectParts[] = "$etatLogementCol as default_etat_logement";
    if (isset($existingLogementCols['equipements']))          $selectParts[] = 'l.equipements';
    else                                                       $selectParts[] = 'NULL as equipements';

    $selectSQL = implode(",\n        ", $selectParts);

    // -------------------------------------------------------------------------
    // 4. Peupler contrat_logement depuis les contrats existants
    //    On ignore les contrats déjà présents (INSERT IGNORE) pour idempotence
    // -------------------------------------------------------------------------
    $insertSQL = "
        INSERT IGNORE INTO contrat_logement (
            contrat_id, logement_id, reference, adresse, type, surface,
            loyer, charges, depot_garantie, parking, description,
            type_contrat, duree_garantie, lien_externe,
            dpe_file, dpe_classe, dpe_ges, dpe_numero, dpe_valable_jusqu_a,
            default_cles_appartement, default_cles_boite_lettres, default_etat_logement,
            equipements
        )
        SELECT
            $selectSQL
        FROM contrats c
        INNER JOIN logements l ON c.logement_id = l.id
    ";

    $affected = $pdo->exec($insertSQL);
    echo "  ✓ $affected contrat(s) peuplé(s) dans contrat_logement\n";

    // -------------------------------------------------------------------------
    // 5. Pour chaque contrat, créer un snapshot JSON des équipements du logement
    // -------------------------------------------------------------------------
    $equipStmt = $pdo->query("
        SELECT cl.contrat_id, cl.logement_id
        FROM contrat_logement cl
        WHERE cl.equipements_json IS NULL
    ");
    $rows = $equipStmt->fetchAll(PDO::FETCH_ASSOC);

    $updateEquip = $pdo->prepare("
        UPDATE contrat_logement SET equipements_json = ?
        WHERE contrat_id = ?
    ");

    $inventaireTable = false;
    try {
        $tableCheck = $pdo->query("
            SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventaire_equipements'
        ");
        $inventaireTable = (bool)$tableCheck->fetch();
    } catch (\Exception $e) {}

    $snapshotted = 0;
    foreach ($rows as $row) {
        $equipements = [];
        if ($inventaireTable) {
            $eqStmt = $pdo->prepare("
                SELECT ie.*, ic.nom as categorie_nom
                FROM inventaire_equipements ie
                LEFT JOIN inventaire_categories ic ON ie.categorie_id = ic.id
                WHERE ie.logement_id = ?
                ORDER BY ie.ordre ASC
            ");
            $eqStmt->execute([$row['logement_id']]);
            $equipements = $eqStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $updateEquip->execute([
            empty($equipements) ? null : json_encode($equipements, JSON_UNESCAPED_UNICODE),
            $row['contrat_id']
        ]);
        $snapshotted++;
    }
    echo "  ✓ $snapshotted snapshot(s) d'équipements créé(s)\n";

    $pdo->commit();
    echo "\nMigration 135 terminée avec succès.\n";

} catch (\Exception $e) {
    $pdo->rollBack();
    echo "ERREUR : " . $e->getMessage() . "\n";
    exit(1);
}
