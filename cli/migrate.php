#!/usr/bin/env php
<?php
/**
 * Database Migration Script
 *
 * Runs SQL migrations to update the database schema.
 * Usage: php cli/migrate.php [migration_file]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Database configuration
$dbPath = __DIR__ . '/../data/app.db';

if (!file_exists($dbPath)) {
    echo "Error: Database file not found at {$dbPath}\n";
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Determine which migration to run
    $migrationFile = $argv[1] ?? null;

    if ($migrationFile) {
        // Run specific migration
        $migrationPath = __DIR__ . '/../migrations/' . basename($migrationFile);
    } else {
        // Run latest migration (004)
        $migrationPath = __DIR__ . '/../migrations/004_add_opus_support.sql';
    }

    if (!file_exists($migrationPath)) {
        echo "Error: Migration file not found at {$migrationPath}\n";
        exit(1);
    }

    echo "Running migration: " . basename($migrationPath) . "\n";

    // Read migration SQL
    $sql = file_get_contents($migrationPath);

    // Begin transaction
    $pdo->beginTransaction();

    try {
        // Execute migration (split by semicolons for multiple statements)
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($stmt) => !empty($stmt) && !preg_match('/^\s*--/', $stmt)
        );

        foreach ($statements as $statement) {
            if (!empty($statement)) {
                echo "Executing: " . substr($statement, 0, 60) . "...\n";
                $pdo->exec($statement);
            }
        }

        // Commit transaction
        $pdo->commit();

        echo "Migration completed successfully!\n";

        // Display schema info
        echo "\nUpdated schema:\n";
        $result = $pdo->query("PRAGMA table_info(message_history)");
        foreach ($result as $row) {
            echo "  - {$row['name']} ({$row['type']})\n";
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
