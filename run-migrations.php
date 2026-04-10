#!/usr/bin/env php
<?php
/**
 * Migration Runner with tracking
 * Applies database migrations in order and tracks which ones have been executed
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

/**
 * Split SQL string into individual statements, respecting string literals
 * @param string $sql SQL content
 * @return array Array of SQL statements
 */
function splitSqlStatements($sql) {
    $statements = [];
    $currentStatement = '';
    $inString = false;
    $stringChar = null;
    $escaped = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $nextChar = ($i + 1 < $length) ? $sql[$i + 1] : null;

        // Handle line comments
        if (!$inString && $char === '-' && $nextChar === '-') {
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }

        // Handle string literals
        if (!$escaped && ($char === '"' || $char === "'")) {
            if (!$inString) {
                $inString = true;
                $stringChar = $char;
            } elseif ($char === $stringChar) {
                $inString = false;
                $stringChar = null;
            }
        }

        // Handle escape sequences in strings
        if ($inString && $char === '\\') {
            $escaped = !$escaped;
        } else {
            $escaped = false;
        }

        $currentStatement .= $char;

        // Check for statement delimiter (semicolon outside of strings)
        if (!$inString && $char === ';') {
            $trimmed = trim($currentStatement);
            if (!empty($trimmed)) {
                $statements[] = $currentStatement;
            }
            $currentStatement = '';
        }
    }

    $trimmed = trim($currentStatement);
    if (!empty($trimmed)) {
        $statements[] = $currentStatement;
    }

    return $statements;
}

echo "=== Migration Runner ===\n\n";

try {
    $trackingTableSql = "
        CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_file VARCHAR(255) UNIQUE NOT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_migration_file (migration_file)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $pdo->exec($trackingTableSql);
    echo "✓ Migration tracking table ready\n\n";
} catch (PDOException $e) {
    echo "✗ Error creating migration tracking table: " . $e->getMessage() . "\n";
    exit(1);
}

$migrationDir = __DIR__ . '/migrations';
$sqlFiles = glob($migrationDir . '/*.sql') ?: [];
$allPhpFiles = glob($migrationDir . '/*.php') ?: [];
$phpFiles = array_filter($allPhpFiles, fn($f) => preg_match('/^[0-9]/', basename($f)));
$files = array_merge($sqlFiles, array_values($phpFiles));
sort($files);

if (empty($files)) {
    echo "No migration files found.\n";
    exit(0);
}

echo "Found " . count($files) . " migration file(s).\n\n";

$executed = 0;
$skipped = 0;

foreach ($files as $file) {
    $filename = basename($file);
    $ext = pathinfo($filename, PATHINFO_EXTENSION);

    if ($filename === '000_create_migrations_table.sql') {
        continue;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM migrations WHERE migration_file = ?");
        $stmt->execute([$filename]);
        $result = $stmt->fetch();
        $stmt->closeCursor();

        if ($result) {
            echo "⊘ Skipping (already executed): $filename\n";
            $skipped++;
            continue;
        }
    } catch (PDOException $e) {
        echo "✗ Error checking migration status: " . $e->getMessage() . "\n";
        continue;
    }

    echo "Applying migration: $filename\n";

    if ($ext === 'php') {
        try {
            require_once $file;
            $stmt = $pdo->prepare("INSERT INTO migrations (migration_file) VALUES (?)");
            $stmt->execute([$filename]);
            echo "  ✓ Success\n";
            $executed++;
        } catch (\Exception $e) {
            echo "  ✗ Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    } else {
        try {
            $sql = file_get_contents($file);
            $statements = splitSqlStatements($sql);

            foreach ($statements as $statement) {
                $trimmed = trim($statement);
                if (empty($trimmed)) continue;

                echo "Executing: $trimmed\n";

                // DDL statements executed directly
                if (preg_match('/^(ALTER|CREATE|DROP)\s/i', $trimmed)) {
                    $pdo->exec($trimmed);
                } else {
                    $result = $pdo->query($trimmed);
                    if ($result instanceof PDOStatement) {
                        $result->fetchAll();
                        $result->closeCursor();
                    }
                }
            }

            $stmt = $pdo->prepare("INSERT INTO migrations (migration_file) VALUES (?)");
            $stmt->execute([$filename]);

            echo "  ✓ Success\n";
            $executed++;
        } catch (PDOException $e) {
            echo "  ✗ Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    echo "\n";
}

echo "=== Migration complete ===\n";
echo "Executed: $executed\n";
echo "Skipped: $skipped\n";

if ($executed > 0) {
    echo "\n✓ Database updated successfully!\n";
}
