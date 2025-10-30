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
 * User Info
 *
 * Get current authenticated user's information and passkeys
 */

require_once __DIR__ . '/_common.php';

// Require authentication
$payload = requireAuth();

// Get user
$userId = (int)$payload['sub'];
$user = WalkieTalkie\AuthManager::getUserById($userId);

if (!$user) {
    sendJson(['success' => false, 'error' => 'User not found'], 404);
}

// Get user's passkeys
$credentials = WalkieTalkie\AuthManager::getUserCredentials($userId);

// Format passkeys for response
$passkeys = array_map(function ($cred) {
    return [
        'id' => $cred['id'],
        'nickname' => $cred['nickname'] ?? 'Unnamed',
        'created_at' => $cred['created_at'],
        'last_used' => $cred['last_used'],
        'transports' => $cred['transports'] ? json_decode($cred['transports'], true) : [],
        'aaguid' => $cred['aaguid']
    ];
}, $credentials);

sendJson([
    'success' => true,
    'user' => [
        'id' => $user['id'],
        'username' => $user['username'],
        'created_at' => $user['created_at'],
        'last_login' => $user['last_login'],
        'passkeys' => $passkeys
    ]
]);
