<?php
/**
 * Walkie Talkie PWA - Authentication Endpoint
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
 * Token Refresh
 *
 * Exchange a valid refresh token for a new access token
 */

require_once __DIR__ . '/_common.php';

// Get refresh token from cookie or request body
$refreshToken = $_COOKIE['refresh_token'] ?? null;

$input = getJsonInput();
if (!$refreshToken && isset($input['refresh_token'])) {
    $refreshToken = $input['refresh_token'];
}

if (!$refreshToken) {
    sendJson(['success' => false, 'error' => 'No refresh token provided'], 401);
}

// Validate refresh token
$payload = WalkieTalkie\AuthManager::validateRefreshToken($refreshToken);
if (!$payload) {
    sendJson(['success' => false, 'error' => 'Invalid or expired refresh token'], 401);
}

// Get user
$userId = (int)$payload['sub'];
$user = WalkieTalkie\AuthManager::getUserById($userId);
if (!$user) {
    sendJson(['success' => false, 'error' => 'User not found'], 404);
}

// Generate new access token
$accessToken = WalkieTalkie\AuthManager::generateAccessToken($user['id'], $user['username']);

// Optionally rotate refresh token (recommended for security)
$rotateRefreshToken = ($_ENV['JWT_ROTATE_REFRESH'] ?? 'false') === 'true';

if ($rotateRefreshToken) {
    // Revoke old token
    $oldTokenHash = hash('sha256', $refreshToken);
    WalkieTalkie\AuthManager::revokeRefreshToken($oldTokenHash);

    // Generate new refresh token
    $newRefreshToken = WalkieTalkie\AuthManager::generateRefreshToken($user['id']);

    // Store new refresh token
    WalkieTalkie\AuthManager::storeRefreshToken($user['id'], $newRefreshToken, [
        'ip' => getClientIp(),
        'user_agent' => getUserAgent()
    ]);

    // Set new refresh token cookie
    setcookie(
        'refresh_token',
        $newRefreshToken,
        [
            'expires' => time() + (int)($_ENV['JWT_REFRESH_EXPIRATION'] ?? 604800),
            'path' => '/',
            'httponly' => true,
            'secure' => ($_ENV['SESSION_SECURE'] ?? 'false') === 'true',
            'samesite' => 'Strict'
        ]
    );

    $refreshToken = $newRefreshToken;
}

// Return new access token
sendJson([
    'success' => true,
    'tokens' => [
        'access_token' => $accessToken,
        'expires_in' => (int)($_ENV['JWT_ACCESS_EXPIRATION'] ?? 3600)
    ]
]);
