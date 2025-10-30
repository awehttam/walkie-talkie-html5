<?php
/**
 * WebAuthn Login - Step 1: Generate Options
 *
 * Generates an authentication challenge for logging in with a passkey
 */

require_once __DIR__ . '/_common.php';

use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;

// Get input
$input = getJsonInput();
$username = $input['username'] ?? '';

// Validate input
if (empty($username)) {
    sendJson(['success' => false, 'error' => 'Username is required'], 400);
}

// Rate limiting
if (!checkRateLimit("login:$username", 10, 300)) {
    sendJson(['success' => false, 'error' => 'Too many attempts. Please try again later.'], 429);
}

// Get user
$user = WalkieTalkie\AuthManager::getUserByUsername($username);
if (!$user) {
    // Don't reveal if user exists or not (timing attack prevention)
    // Return same structure but with empty credentials
    $user = ['id' => 0];
}

// Get user's credentials
$credentials = [];
if ($user['id'] > 0) {
    $userCredentials = WalkieTalkie\AuthManager::getUserCredentials($user['id']);

    foreach ($userCredentials as $cred) {
        $transports = $cred['transports'] ? json_decode($cred['transports'], true) : [];

        $credentials[] = PublicKeyCredentialDescriptor::create(
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            base64_decode($cred['credential_id']),
            $transports
        );
    }
}

// Generate challenge
$challenge = generateChallenge();

// Create request options
$rpId = $_ENV['WEBAUTHN_RP_ID'] ?? 'localhost';
$timeout = (int)($_ENV['WEBAUTHN_TIMEOUT'] ?? 60000);

$options = PublicKeyCredentialRequestOptions::create($challenge)
    ->setRpId($rpId)
    ->setTimeout($timeout)
    ->setUserVerification(PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED);

if (!empty($credentials)) {
    $options = $options->allowCredentials(...$credentials);
}

// Store challenge and username in session for verification
$_SESSION['webauthn_challenge'] = $challenge;
$_SESSION['webauthn_username'] = $username;

// Return options
$allowCredentials = array_map(function ($cred) {
    return [
        'type' => $cred->type,
        'id' => base64_encode($cred->id),
        'transports' => $cred->transports
    ];
}, $credentials);

sendJson([
    'success' => true,
    'options' => [
        'challenge' => $challenge,
        'timeout' => $timeout,
        'rpId' => $rpId,
        'allowCredentials' => $allowCredentials,
        'userVerification' => PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED
    ]
]);
