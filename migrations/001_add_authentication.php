<?php
/**
 * Migration: Add WebAuthn Authentication Support
 *
 * This migration adds the necessary database tables for WebAuthn/passkeys authentication:
 * - users: Store user accounts with unique screen names
 * - webauthn_credentials: Store passkeys (multiple per user)
 * - jwt_refresh_tokens: Store refresh tokens for session management
 * - Updates message_history to link messages to users
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$dbPath = __DIR__ . '/../data/walkie-talkie.db';

if (!file_exists($dbPath)) {
    echo "Error: Database file not found at $dbPath\n";
    exit(1);
}

$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== WebAuthn Authentication Migration ===\n";
echo "Database: $dbPath\n\n";

try {
    $db->beginTransaction();

    // Create users table
    echo "[1/5] Creating users table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            created_at INTEGER NOT NULL,
            last_login INTEGER,
            is_active INTEGER DEFAULT 1
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_username ON users(username)");
    echo "  ✓ Users table created\n";

    // Create webauthn_credentials table
    echo "[2/5] Creating webauthn_credentials table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS webauthn_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            credential_id TEXT UNIQUE NOT NULL,
            public_key TEXT NOT NULL,
            counter INTEGER NOT NULL DEFAULT 0,
            aaguid TEXT,
            transports TEXT,
            created_at INTEGER NOT NULL,
            last_used INTEGER,
            nickname TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_credential_user ON webauthn_credentials(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_credential_id ON webauthn_credentials(credential_id)");
    echo "  ✓ WebAuthn credentials table created\n";

    // Create jwt_refresh_tokens table
    echo "[3/5] Creating jwt_refresh_tokens table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS jwt_refresh_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token_hash TEXT UNIQUE NOT NULL,
            expires_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            ip_address TEXT,
            user_agent TEXT,
            revoked INTEGER DEFAULT 0,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_token_hash ON jwt_refresh_tokens(token_hash)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_token_expiry ON jwt_refresh_tokens(expires_at)");
    echo "  ✓ JWT refresh tokens table created\n";

    // Update message_history table
    echo "[4/5] Updating message_history table...\n";

    // Check if columns already exist
    $columns = $db->query("PRAGMA table_info(message_history)")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'name');

    if (!in_array('user_id', $columnNames)) {
        $db->exec("ALTER TABLE message_history ADD COLUMN user_id INTEGER");
        echo "  ✓ Added user_id column\n";
    } else {
        echo "  • user_id column already exists\n";
    }

    if (!in_array('screen_name', $columnNames)) {
        $db->exec("ALTER TABLE message_history ADD COLUMN screen_name TEXT");
        echo "  ✓ Added screen_name column\n";

        // Migrate existing data: set screen_name to truncated client_id
        $result = $db->exec("UPDATE message_history SET screen_name = substr(client_id, 1, 20) WHERE screen_name IS NULL");
        echo "  ✓ Migrated $result existing messages\n";
    } else {
        echo "  • screen_name column already exists\n";
    }

    $db->exec("CREATE INDEX IF NOT EXISTS idx_message_user ON message_history(user_id)");
    echo "  ✓ Added index on user_id\n";

    // Verify tables were created
    echo "[5/5] Verifying database schema...\n";
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

    $requiredTables = ['users', 'webauthn_credentials', 'jwt_refresh_tokens', 'message_history'];
    $missingTables = array_diff($requiredTables, $tables);

    if (empty($missingTables)) {
        echo "  ✓ All required tables present\n";
    } else {
        throw new Exception("Missing tables: " . implode(', ', $missingTables));
    }

    $db->commit();

    echo "\n=== Migration completed successfully! ===\n";
    echo "\nNext steps:\n";
    echo "1. Update your .env file with authentication configuration\n";
    echo "2. Generate JWT secret: openssl rand -base64 64\n";
    echo "3. Install PHP dependencies: composer install\n";

} catch (Exception $e) {
    $db->rollBack();
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Database has been rolled back to previous state.\n";
    exit(1);
}
