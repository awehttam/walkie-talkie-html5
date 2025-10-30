#!/usr/bin/env php
<?php
/**
 * Walkie Talkie CLI - JWT Token Generator
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
 * Generate JWT access tokens for authenticated users
 *
 * Usage:
 *   php cli/generate-token.php <username>
 *   php cli/generate-token.php --create <username>
 *   php cli/generate-token.php --list
 *
 * Examples:
 *   # Generate token for existing user
 *   php cli/generate-token.php john_doe
 *
 *   # Create a new service account and generate token
 *   php cli/generate-token.php --create AudioBot
 *
 *   # List all users
 *   php cli/generate-token.php --list
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/AuthManager.php';

use WalkieTalkie\AuthManager;
use Dotenv\Dotenv;

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Check if JWT_SECRET is configured
if (empty($_ENV['JWT_SECRET'] ?? '')) {
    echo "❌ Error: JWT_SECRET not configured in .env file\n";
    echo "Generate one with: openssl rand -base64 64\n";
    exit(1);
}

// Parse command line arguments
if ($argc < 2) {
    echo "Usage: php cli/generate-token.php <username>\n";
    echo "       php cli/generate-token.php --create <username>\n";
    echo "       php cli/generate-token.php --list\n\n";
    echo "Examples:\n";
    echo "  php cli/generate-token.php john_doe          # Generate token for existing user\n";
    echo "  php cli/generate-token.php --create AudioBot # Create service account\n";
    echo "  php cli/generate-token.php --list            # List all users\n";
    exit(1);
}

$command = $argv[1];
$isCreateMode = ($command === '--create' || $command === '-c');

// List users command
if ($command === '--list' || $command === '-l') {
    try {
        $db = new PDO("sqlite:" . __DIR__ . "/../data/walkie-talkie.db");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->query("SELECT id, username, created_at FROM users ORDER BY created_at DESC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($users)) {
            echo "No users found. Register a user first at /login.html\n";
            exit(0);
        }

        echo "Registered users:\n";
        echo str_repeat('-', 60) . "\n";
        printf("%-5s %-30s %s\n", "ID", "Username", "Created");
        echo str_repeat('-', 60) . "\n";

        foreach ($users as $user) {
            printf("%-5d %-30s %s\n",
                $user['id'],
                $user['username'],
                date('Y-m-d H:i:s', $user['created_at'])
            );
        }

        echo str_repeat('-', 60) . "\n";
        exit(0);
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Create user mode
if ($isCreateMode) {
    if ($argc < 3) {
        echo "❌ Error: Username required for --create\n";
        echo "Usage: php cli/generate-token.php --create <username>\n";
        exit(1);
    }

    $username = $argv[2];

    try {
        // Validate username
        $pattern = $_ENV['SCREEN_NAME_PATTERN'] ?? '^[a-zA-Z0-9_-]+$';
        $minLength = (int)($_ENV['SCREEN_NAME_MIN_LENGTH'] ?? 2);
        $maxLength = (int)($_ENV['SCREEN_NAME_MAX_LENGTH'] ?? 20);

        if (strlen($username) < $minLength || strlen($username) > $maxLength) {
            echo "❌ Error: Username must be between $minLength and $maxLength characters\n";
            exit(1);
        }

        if (!preg_match("/$pattern/", $username)) {
            echo "❌ Error: Username contains invalid characters\n";
            echo "Allowed: letters, numbers, underscore, hyphen\n";
            exit(1);
        }

        // Check if user already exists
        $existingUser = AuthManager::getUserByUsername($username);
        if ($existingUser) {
            echo "❌ Error: User '$username' already exists (ID: {$existingUser['id']})\n";
            echo "Use without --create to generate token for existing user\n";
            exit(1);
        }

        // Create user (service account - no credentials needed)
        $db = new PDO("sqlite:" . __DIR__ . "/../data/walkie-talkie.db");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->prepare("INSERT INTO users (username, created_at) VALUES (?, ?)");
        $stmt->execute([$username, time()]);
        $userId = (int)$db->lastInsertId();

        echo "✅ Service account created successfully\n\n";
        echo "Username:   $username\n";
        echo "User ID:    $userId\n";
        echo "Type:       Service Account (no passkey required)\n\n";

        // Generate token for the new user
        $user = ['id' => $userId, 'username' => $username];

    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    // Generate token for existing username
    $username = $command;

    try {
        // Get user from database
        $user = AuthManager::getUserByUsername($username);

        if (!$user) {
            echo "❌ Error: User '$username' not found\n";
            echo "Run 'php cli/generate-token.php --list' to see available users\n";
            echo "Or use --create to create a new service account\n";
            exit(1);
        }

    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Generate access token (common for both create and existing user)
try {
    $token = AuthManager::generateAccessToken($user['id'], $user['username']);

    // Get token expiration
    $expiration = (int)($_ENV['JWT_ACCESS_EXPIRATION'] ?? 3600);
    $expiresAt = date('Y-m-d H:i:s', time() + $expiration);

    if (!$isCreateMode) {
        echo "✅ JWT Token generated successfully\n\n";
        echo "User:       {$user['username']}\n";
        echo "User ID:    {$user['id']}\n";
    }

    echo "Expires:    $expiresAt (in " . ($expiration / 3600) . " hours)\n\n";
    echo "Token:\n";
    echo str_repeat('-', 80) . "\n";
    echo "$token\n";
    echo str_repeat('-', 80) . "\n\n";
    echo "Usage with walkie-cli:\n";
    echo "  php cli/walkie-cli.php send audio.wav --channel 1 --token \"$token\"\n\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
