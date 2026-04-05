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
            // Add newline to preserve token boundaries, then skip until end of line
            $currentStatement .= "\n";
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
        
        // Add character to current statement
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
    
    // Add any remaining statement
    $trimmed = trim($currentStatement);
    if (!empty($trimmed)) {
        $statements[] = $currentStatement;
    }
    
    return $statements;
}

echo "=== Migration Runner ===\n\n";

try {
    // First, ensure the migrations tracking table exists
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

// Get all migration files (.sql and numbered .php)
$migrationDir = __DIR__ . '/migrations';
$sqlFiles = glob($migrationDir . '/*.sql') ?: [];
// Only include PHP files that start with a number (e.g. 135_create_*.php), not utility scripts
$allPhpFiles = glob($migrationDir . '/*.php') ?: [];
$phpFiles = array_filter($allPhpFiles, fn($f) => preg_match('/\/[0-9]/', $f));
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
    
    // Skip the tracking table creation file since we handle it above
    if ($filename === '000_create_migrations_table.sql') {
        continue;
    }
    
    // Check if migration has already been executed
    try {
        $stmt = $pdo->prepare("SELECT id FROM migrations WHERE migration_file = ?");
        $stmt->execute([$filename]);
        $result = $stmt->fetch();
        $stmt->closeCursor(); // Close cursor to free resources
        
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
        // PHP migrations manage their own transactions; just include and track them
        try {
            include $file;
            
            // Record the migration as executed
            $stmt = $pdo->prepare("INSERT INTO migrations (migration_file) VALUES (?)");
            $stmt->execute([$filename]);
            
            echo "  ✓ Success\n";
            $executed++;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo "  ✗ Error: " . $e->getMessage() . "\n";
            echo "  Migration failed - changes rolled back\n";
            echo "  Please fix the error and run migrations again.\n";
            exit(1);
        }
    } else {
        try {
            $sql = file_get_contents($file);
            
            // Start transaction for this migration
            $pdo->beginTransaction();
            
            // Split SQL into statements, respecting string literals
            $statements = splitSqlStatements($sql);
            
            foreach ($statements as $statement) {
                $trimmed = trim($statement);
                // Skip empty statements
                if (empty($trimmed)) {
                    continue;
                }
                
                // Execute the statement and consume any result set
                // query() always returns PDOStatement (or false on error)
                $result = $pdo->query($statement);
                if ($result instanceof PDOStatement) {
                    // Fetch all results to consume the result set
                    // This prevents "Cannot execute queries while other unbuffered queries are active" errors
                    $result->fetchAll();
                    $result->closeCursor();
                }
            }
            
            // Record the migration as executed
            $stmt = $pdo->prepare("INSERT INTO migrations (migration_file) VALUES (?)");
            $stmt->execute([$filename]);
            
            // Commit transaction
            $pdo->commit();
            
            echo "  ✓ Success\n";
            $executed++;
        } catch (PDOException $e) {
            // Rollback on error
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            echo "  ✗ Error: " . $e->getMessage() . "\n";
            echo "  Migration failed - changes rolled back\n";
            echo "  Please fix the error and run migrations again.\n";
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
