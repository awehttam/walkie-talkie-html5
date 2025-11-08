#!/usr/bin/env php
<?php
/**
 * Standalone Database Migration Runner
 *
 * Runs the Opus codec migration without requiring full app setup.
 * Usage: php migrations/run_migration.php
 */

// Determine database path
$dbPath = __DIR__ . '/../data/walkie-talkie.db';

// Check if database exists
if (!file_exists($dbPath)) {
    echo "Error: Database file not found at {$dbPath}\n";
    echo "Please start the WebSocket server first to create the database.\n";
    echo "Run: php server.php\n";
    exit(1);
}

// Create backup before migration
$backupPath = $dbPath . '.backup.' . date('Y-m-d_H-i-s');
if (!copy($dbPath, $backupPath)) {
    echo "Warning: Could not create backup at {$backupPath}\n";
    echo "Continue anyway? (y/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim($line) !== 'y') {
        echo "Migration aborted.\n";
        exit(0);
    }
} else {
    echo "✓ Backup created: {$backupPath}\n";
}

try {
    // Connect to database
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "✓ Connected to database\n\n";

    // Check if migration already applied
    $result = $pdo->query("PRAGMA table_info(message_history)");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    $hasCodecColumn = false;

    foreach ($columns as $column) {
        if ($column['name'] === 'codec') {
            $hasCodecColumn = true;
            break;
        }
    }

    if ($hasCodecColumn) {
        echo "⚠ Migration already applied! The 'codec' column already exists.\n";
        echo "Schema:\n";
        foreach ($columns as $column) {
            echo "  - {$column['name']} ({$column['type']})\n";
        }
        exit(0);
    }

    // Read migration SQL
    $migrationPath = __DIR__ . '/004_add_opus_support.sql';

    if (!file_exists($migrationPath)) {
        echo "Error: Migration file not found at {$migrationPath}\n";
        exit(1);
    }

    echo "Running migration: 004_add_opus_support.sql\n";
    echo str_repeat('=', 60) . "\n\n";

    $sql = file_get_contents($migrationPath);

    // Begin transaction
    $pdo->beginTransaction();

    try {
        // Remove comments and split SQL into individual statements
        $lines = explode("\n", $sql);
        $cleanedLines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip comment-only lines
            if (empty($line) || strpos($line, '--') === 0) {
                continue;
            }
            $cleanedLines[] = $line;
        }

        $cleanedSql = implode("\n", $cleanedLines);

        // Split by semicolon
        $statements = array_filter(
            array_map('trim', explode(';', $cleanedSql)),
            function($stmt) {
                return !empty($stmt);
            }
        );

        $statementCount = 0;
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $statementCount++;
                $preview = substr(str_replace(["\n", "\r"], ' ', $statement), 0, 70);
                echo "[{$statementCount}] Executing: {$preview}...\n";
                $pdo->exec($statement);
            }
        }

        // Commit transaction
        $pdo->commit();

        echo "\n" . str_repeat('=', 60) . "\n";
        echo "✓ Migration completed successfully!\n";
        echo "  Executed {$statementCount} statements\n\n";

        // Display updated schema
        echo "Updated schema for message_history:\n";
        $result = $pdo->query("PRAGMA table_info(message_history)");
        foreach ($result as $row) {
            $default = $row['dflt_value'] ? " (default: {$row['dflt_value']})" : '';
            echo "  - {$row['name']}: {$row['type']}{$default}\n";
        }

        // Show row count
        $count = $pdo->query("SELECT COUNT(*) FROM message_history")->fetchColumn();
        echo "\nExisting messages updated: {$count}\n";

        // Show codec distribution
        $codecStats = $pdo->query("
            SELECT codec, COUNT(*) as count
            FROM message_history
            GROUP BY codec
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($codecStats)) {
            echo "\nCodec distribution:\n";
            foreach ($codecStats as $stat) {
                echo "  - {$stat['codec']}: {$stat['count']} messages\n";
            }
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    echo "\n✗ Migration failed!\n";
    echo "Error: " . $e->getMessage() . "\n";

    if (isset($backupPath) && file_exists($backupPath)) {
        echo "\nTo restore from backup:\n";
        echo "  cp {$backupPath} {$dbPath}\n";
    }

    exit(1);
} catch (Exception $e) {
    echo "\n✗ Migration failed!\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✓ Done!\n";
