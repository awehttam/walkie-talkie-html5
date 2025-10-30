<?php
/**
 * Walkie Talkie PWA - Authentication Manager
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
 */

namespace WalkieTalkie;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;

/**
 * AuthManager - Handles all authentication-related operations
 *
 * Provides JWT token management, user management, screen name validation,
 * and WebAuthn credential storage.
 */
class AuthManager
{
    private static ?PDO $db = null;

    /**
     * Initialize the database connection
     */
    private static function getDb(): PDO
    {
        if (self::$db === null) {
            $dbPath = __DIR__ . '/../data/walkie-talkie.db';
            self::$db = new PDO("sqlite:$dbPath");
            self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$db->exec('PRAGMA journal_mode=WAL');
        }
        return self::$db;
    }

    /**
     * Generate JWT access token
     *
     * @param int $userId User ID
     * @param string $username Screen name
     * @return string JWT token
     */
    public static function generateAccessToken(int $userId, string $username): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if (empty($secret)) {
            throw new \Exception('JWT_SECRET not configured');
        }

        $expiration = (int)($_ENV['JWT_ACCESS_EXPIRATION'] ?? 3600);
        $issuedAt = time();

        $payload = [
            'iss' => 'walkie-talkie',
            'sub' => (string)$userId,
            'username' => $username,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $expiration,
            'type' => 'access'
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * Generate JWT refresh token
     *
     * @param int $userId User ID
     * @return string JWT token
     */
    public static function generateRefreshToken(int $userId): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if (empty($secret)) {
            throw new \Exception('JWT_SECRET not configured');
        }

        $expiration = (int)($_ENV['JWT_REFRESH_EXPIRATION'] ?? 604800);
        $issuedAt = time();

        $payload = [
            'iss' => 'walkie-talkie',
            'sub' => (string)$userId,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $expiration,
            'type' => 'refresh',
            'jti' => bin2hex(random_bytes(16)) // Unique token ID
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * Validate and decode JWT access token
     *
     * @param string $token JWT token
     * @return array|null Decoded payload or null if invalid
     */
    public static function validateAccessToken(string $token): ?array
    {
        try {
            $secret = $_ENV['JWT_SECRET'] ?? '';
            if (empty($secret)) {
                return null;
            }

            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            $payload = (array)$decoded;

            // Verify token type
            if (($payload['type'] ?? '') !== 'access') {
                return null;
            }

            return $payload;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Validate and decode JWT refresh token
     *
     * @param string $token JWT token
     * @return array|null Decoded payload or null if invalid
     */
    public static function validateRefreshToken(string $token): ?array
    {
        try {
            $secret = $_ENV['JWT_SECRET'] ?? '';
            if (empty($secret)) {
                return null;
            }

            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            $payload = (array)$decoded;

            // Verify token type
            if (($payload['type'] ?? '') !== 'refresh') {
                return null;
            }

            // Check if token is revoked
            $tokenHash = hash('sha256', $token);
            if (!self::isRefreshTokenValid($tokenHash)) {
                return null;
            }

            return $payload;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Create a new user
     *
     * @param string $username Screen name
     * @return int User ID
     * @throws \Exception if username already exists
     */
    public static function createUser(string $username): int
    {
        $db = self::getDb();

        // Validate screen name
        if (!self::validateScreenName($username)) {
            throw new \Exception('Invalid screen name format');
        }

        // Check availability
        if (!self::isScreenNameAvailable($username)) {
            throw new \Exception('Screen name already taken');
        }

        $stmt = $db->prepare("
            INSERT INTO users (username, created_at, last_login)
            VALUES (?, ?, ?)
        ");

        $now = floor(microtime(true) * 1000);
        $stmt->execute([$username, $now, $now]);

        return (int)$db->lastInsertId();
    }

    /**
     * Get user by ID
     *
     * @param int $userId User ID
     * @return array|null User data or null if not found
     */
    public static function getUserById(int $userId): ?array
    {
        $db = self::getDb();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$userId]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    /**
     * Get user by username
     *
     * @param string $username Screen name
     * @return array|null User data or null if not found
     */
    public static function getUserByUsername(string $username): ?array
    {
        $db = self::getDb();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    /**
     * Update user's last login timestamp
     *
     * @param int $userId User ID
     */
    public static function updateLastLogin(int $userId): void
    {
        $db = self::getDb();
        $stmt = $db->prepare("UPDATE users SET last_login = ? WHERE id = ?");
        $stmt->execute([floor(microtime(true) * 1000), $userId]);
    }

    /**
     * Validate screen name format
     *
     * @param string $name Screen name
     * @return bool True if valid
     */
    public static function validateScreenName(string $name): bool
    {
        $minLength = (int)($_ENV['SCREEN_NAME_MIN_LENGTH'] ?? 2);
        $maxLength = (int)($_ENV['SCREEN_NAME_MAX_LENGTH'] ?? 20);
        $pattern = $_ENV['SCREEN_NAME_PATTERN'] ?? '^[a-zA-Z0-9_-]+$';

        // Length check
        if (strlen($name) < $minLength || strlen($name) > $maxLength) {
            return false;
        }

        // Pattern check
        if (!preg_match("/$pattern/", $name)) {
            return false;
        }

        // Reserved names
        $reserved = ['admin', 'system', 'anonymous', 'guest', 'moderator', 'root', 'owner'];
        if (in_array(strtolower($name), $reserved)) {
            return false;
        }

        return true;
    }

    /**
     * Check if screen name is available
     *
     * @param string $name Screen name
     * @return bool True if available
     */
    public static function isScreenNameAvailable(string $name): bool
    {
        $db = self::getDb();
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$name]);

        return $stmt->fetchColumn() === 0;
    }

    /**
     * Store WebAuthn credential
     *
     * @param int $userId User ID
     * @param array $credentialData Credential data from WebAuthn
     */
    public static function storeCredential(int $userId, array $credentialData): void
    {
        $db = self::getDb();

        $stmt = $db->prepare("
            INSERT INTO webauthn_credentials
            (user_id, credential_id, public_key, counter, aaguid, transports, created_at, nickname)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $userId,
            $credentialData['credential_id'],
            $credentialData['public_key'],
            $credentialData['counter'] ?? 0,
            $credentialData['aaguid'] ?? null,
            isset($credentialData['transports']) ? json_encode($credentialData['transports']) : null,
            floor(microtime(true) * 1000),
            $credentialData['nickname'] ?? null
        ]);
    }

    /**
     * Get credential by credential ID
     *
     * @param string $credentialId Credential ID
     * @return array|null Credential data or null if not found
     */
    public static function getCredential(string $credentialId): ?array
    {
        $db = self::getDb();
        $stmt = $db->prepare("SELECT * FROM webauthn_credentials WHERE credential_id = ?");
        $stmt->execute([$credentialId]);

        $credential = $stmt->fetch(PDO::FETCH_ASSOC);
        return $credential ?: null;
    }

    /**
     * Get all credentials for a user
     *
     * @param int $userId User ID
     * @return array Array of credentials
     */
    public static function getUserCredentials(int $userId): array
    {
        $db = self::getDb();
        $stmt = $db->prepare("
            SELECT id, credential_id, created_at, last_used, nickname, transports, aaguid
            FROM webauthn_credentials
            WHERE user_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update credential counter (for replay attack prevention)
     *
     * @param string $credentialId Credential ID
     * @param int $counter New counter value
     */
    public static function updateCredentialCounter(string $credentialId, int $counter): void
    {
        $db = self::getDb();
        $stmt = $db->prepare("
            UPDATE webauthn_credentials
            SET counter = ?, last_used = ?
            WHERE credential_id = ?
        ");
        $stmt->execute([$counter, floor(microtime(true) * 1000), $credentialId]);
    }

    /**
     * Delete a credential
     *
     * @param int $credentialDbId Database ID of credential
     * @param int $userId User ID (for authorization check)
     * @return bool True if deleted
     */
    public static function deleteCredential(int $credentialDbId, int $userId): bool
    {
        $db = self::getDb();

        // Check user has more than one credential
        $stmt = $db->prepare("SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ?");
        $stmt->execute([$userId]);

        if ($stmt->fetchColumn() <= 1) {
            throw new \Exception('Cannot delete last credential');
        }

        // Delete credential (only if belongs to user)
        $stmt = $db->prepare("DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?");
        $stmt->execute([$credentialDbId, $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Store refresh token in database
     *
     * @param int $userId User ID
     * @param string $token Refresh token
     * @param array $metadata Additional metadata (ip, user_agent)
     */
    public static function storeRefreshToken(int $userId, string $token, array $metadata = []): void
    {
        $db = self::getDb();

        $tokenHash = hash('sha256', $token);
        $expiration = (int)($_ENV['JWT_REFRESH_EXPIRATION'] ?? 604800);

        $stmt = $db->prepare("
            INSERT INTO jwt_refresh_tokens
            (user_id, token_hash, expires_at, created_at, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $userId,
            $tokenHash,
            (time() + $expiration) * 1000,
            floor(microtime(true) * 1000),
            $metadata['ip'] ?? null,
            $metadata['user_agent'] ?? null
        ]);
    }

    /**
     * Check if refresh token is valid (not revoked, not expired)
     *
     * @param string $tokenHash SHA256 hash of token
     * @return bool True if valid
     */
    public static function isRefreshTokenValid(string $tokenHash): bool
    {
        $db = self::getDb();
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM jwt_refresh_tokens
            WHERE token_hash = ? AND revoked = 0 AND expires_at > ?
        ");
        $stmt->execute([$tokenHash, floor(microtime(true) * 1000)]);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Revoke a refresh token
     *
     * @param string $tokenHash SHA256 hash of token
     */
    public static function revokeRefreshToken(string $tokenHash): void
    {
        $db = self::getDb();
        $stmt = $db->prepare("UPDATE jwt_refresh_tokens SET revoked = 1 WHERE token_hash = ?");
        $stmt->execute([$tokenHash]);
    }

    /**
     * Clean up expired tokens
     */
    public static function cleanupExpiredTokens(): void
    {
        $db = self::getDb();
        $stmt = $db->prepare("DELETE FROM jwt_refresh_tokens WHERE expires_at < ?");
        $stmt->execute([floor(microtime(true) * 1000)]);
    }
}
