<?php
/**
 * Walkie Talkie PWA - Database Migration: Add Welcome Messages Support
 *
 * Copyright (C) 2025 Matthew Asham
 *
 * This program is dual-licensed:
 *
 * 1. GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later)
 *    For open source use, you can redistribute it and/or modify it under
 *    the terms of the GNU Affero General Public License as published by
 *    the Free Software Foundation, either version 3 of the License, or
 *    (at your option) any later version.
 *
 * 2. Commercial License
 *    For commercial or proprietary use without AGPL-3.0 obligations,
 *    contact Matthew Asham at https://www.asham.ca/
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * ---
 *
 * Creates the welcome_messages table for storing automated welcome messages
 * that play when users connect to the server or join channels.
 *
 * Usage:
 *   php migrations/003_add_welcome_messages.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

echo "=== Welcome Messages Migration ===\n\n";

try {
    // Connect to database
    $dbPath = __DIR__ . '/../data/walkie-talkie.db';

    if (!file_exists($dbPath)) {
        echo "Error: Database not found at {$dbPath}\n";
        echo "Please run the server at least once to create the database.\n";
        exit(1);
    }

    echo "Connecting to database: {$dbPath}\n";
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if table already exists
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='welcome_messages'");
    if ($result->fetch()) {
        echo "Table 'welcome_messages' already exists.\n";
        echo "Migration skipped.\n";
        exit(0);
    }

    echo "Creating welcome_messages table...\n";

    // Create welcome_messages table
    $db->exec('
        CREATE TABLE welcome_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            audio_file TEXT NOT NULL,
            trigger_type TEXT NOT NULL CHECK(trigger_type IN ("connect", "channel_join", "both")),
            channel TEXT,
            enabled INTEGER DEFAULT 1,
            created_at INTEGER NOT NULL,
            last_played_at INTEGER,
            play_count INTEGER DEFAULT 0
        )
    ');

    echo "  ✓ Table created\n";

    // Create indexes for performance
    echo "Creating indexes...\n";

    $db->exec('CREATE INDEX idx_welcome_trigger ON welcome_messages(trigger_type, enabled)');
    echo "  ✓ Index: idx_welcome_trigger\n";

    $db->exec('CREATE INDEX idx_welcome_channel ON welcome_messages(channel, enabled)');
    echo "  ✓ Index: idx_welcome_channel\n";

    echo "\n=== Migration Completed Successfully ===\n";
    echo "\nNext steps:\n";
    echo "1. Use cli/welcome-manager.php to add welcome messages\n";
    echo "2. Restart the WebSocket server: php server.php restart\n";
    echo "3. Connect to test welcome messages\n";

} catch (PDOException $e) {
    echo "Error: Database error\n";
    echo "  {$e->getMessage()}\n";
    exit(1);
} catch (Exception $e) {
    echo "Error: {$e->getMessage()}\n";
    exit(1);
}
