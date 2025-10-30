<?php
/**
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
