<?php
/**
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
