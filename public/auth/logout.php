<?php
/**
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
