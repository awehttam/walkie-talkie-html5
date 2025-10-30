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
 * Logout
 *
 * Revoke the current refresh token
 */

require_once __DIR__ . '/_common.php';

// Require authentication
$payload = requireAuth();

// Get refresh token from cookie or request body
$refreshToken = $_COOKIE['refresh_token'] ?? null;

$input = getJsonInput();
if (!$refreshToken && isset($input['refresh_token'])) {
    $refreshToken = $input['refresh_token'];
}

if ($refreshToken) {
    // Revoke refresh token
    $tokenHash = hash('sha256', $refreshToken);
    WalkieTalkie\AuthManager::revokeRefreshToken($tokenHash);

    // Clear cookie
    setcookie(
        'refresh_token',
        '',
        [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'secure' => ($_ENV['SESSION_SECURE'] ?? 'false') === 'true',
            'samesite' => 'Strict'
        ]
    );
}

// Clean up expired tokens
WalkieTalkie\AuthManager::cleanupExpiredTokens();

sendJson([
    'success' => true,
    'message' => 'Logged out successfully'
]);
