<?php
/**
 * WebAuthn Registration - Step 2: Verify Credential
 *
 * Verifies the registration response and creates the user account
 */

require_once __DIR__ . '/_common.php';

use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\PublicKeyCredentialLoader;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorData;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialUserEntity;
use Cose\Algorithm\Manager as AlgorithmManager;

// Check if registration is enabled
if (!isRegistrationEnabled()) {
    sendJson(['success' => false, 'error' => 'Registration is currently disabled'], 403);
}

// Get input
$input = getJsonInput();
$credential = $input['credential'] ?? null;
$nickname = $input['nickname'] ?? null;

if (!$credential) {
    sendJson(['success' => false, 'error' => 'Credential is required'], 400);
}

// Get stored challenge from session
$challenge = $_SESSION['webauthn_challenge'] ?? null;
$userId = $_SESSION['webauthn_user_id'] ?? null;
$username = $_SESSION['webauthn_username'] ?? null;

if (!$challenge || !$userId || !$username) {
    sendJson(['success' => false, 'error' => 'No registration in progress. Please start registration again.'], 400);
}

try {
    // Decode client data JSON
    $clientDataJSON = base64_decode($credential['response']['clientDataJSON']);
    $clientData = json_decode($clientDataJSON, true);

    // Verify challenge
    if (!isset($clientData['challenge']) || $clientData['challenge'] !== $challenge) {
        throw new Exception('Challenge mismatch');
    }

    // Verify origin
    $expectedOrigin = $_ENV['WEBAUTHN_ORIGIN'] ?? 'http://localhost:3000';
    if (!isset($clientData['origin']) || $clientData['origin'] !== $expectedOrigin) {
        throw new Exception('Origin mismatch');
    }

    // Verify type
    if (!isset($clientData['type']) || $clientData['type'] !== 'webauthn.create') {
        throw new Exception('Invalid type');
    }

    // Decode attestation object
    $attestationObject = base64_decode($credential['response']['attestationObject']);

    // Parse CBOR-encoded attestation object
    $decoder = new \CBOR\Decoder();
    $stream = new \CBOR\StringStream($attestationObject);
    $attestationData = $decoder->decode($stream)->getNormalizedData();

    if (!isset($attestationData['authData'])) {
        throw new Exception('Invalid attestation object');
    }

    // Parse authenticator data
    $authData = $attestationData['authData'];
    if (strlen($authData) < 37) {
        throw new Exception('Invalid authenticator data');
    }

    // Extract credential ID and public key
    // RPID hash (32 bytes) + flags (1 byte) + counter (4 bytes) = 37 bytes minimum
    $flags = ord($authData[32]);
    $counter = unpack('N', substr($authData, 33, 4))[1];

    // Check if attested credential data is present (bit 6 of flags)
    if (($flags & 0x40) === 0) {
        throw new Exception('No attested credential data present');
    }

    // Parse attested credential data
    $offset = 37; // After RPID hash, flags, and counter

    // AAGUID (16 bytes)
    $aaguid = bin2hex(substr($authData, $offset, 16));
    $offset += 16;

    // Credential ID length (2 bytes)
    $credIdLength = unpack('n', substr($authData, $offset, 2))[1];
    $offset += 2;

    // Credential ID
    $credentialId = substr($authData, $offset, $credIdLength);
    $credentialIdBase64 = base64_encode($credentialId);
    $offset += $credIdLength;

    // Public key (COSE encoded)
    $publicKeyCose = substr($authData, $offset);
    $publicKeyBase64 = base64_encode($publicKeyCose);

    // Create user in database
    $userDbId = WalkieTalkie\AuthManager::createUser($username);

    // Store credential
    WalkieTalkie\AuthManager::storeCredential($userDbId, [
        'credential_id' => $credentialIdBase64,
        'public_key' => $publicKeyBase64,
        'counter' => $counter,
        'aaguid' => $aaguid,
        'transports' => $credential['response']['transports'] ?? null,
        'nickname' => $nickname
    ]);

    // Generate JWT tokens
    $accessToken = WalkieTalkie\AuthManager::generateAccessToken($userDbId, $username);
    $refreshToken = WalkieTalkie\AuthManager::generateRefreshToken($userDbId);

    // Store refresh token
    WalkieTalkie\AuthManager::storeRefreshToken($userDbId, $refreshToken, [
        'ip' => getClientIp(),
        'user_agent' => getUserAgent()
    ]);

    // Clear session
    unset($_SESSION['webauthn_challenge']);
    unset($_SESSION['webauthn_user_id']);
    unset($_SESSION['webauthn_username']);

    // Set refresh token as HTTP-only cookie
    setcookie(
        'refresh_token',
        $refreshToken,
        [
            'expires' => time() + (int)($_ENV['JWT_REFRESH_EXPIRATION'] ?? 604800),
            'path' => '/',
            'httponly' => true,
            'secure' => ($_ENV['SESSION_SECURE'] ?? 'false') === 'true',
            'samesite' => 'Strict'
        ]
    );

    // Return success with tokens
    sendJson([
        'success' => true,
        'user' => [
            'id' => $userDbId,
            'username' => $username
        ],
        'tokens' => [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => (int)($_ENV['JWT_ACCESS_EXPIRATION'] ?? 3600)
        ]
    ]);

} catch (Exception $e) {
    // Clear session on error
    unset($_SESSION['webauthn_challenge']);
    unset($_SESSION['webauthn_user_id']);
    unset($_SESSION['webauthn_username']);

    sendJson(['success' => false, 'error' => 'Registration failed: ' . $e->getMessage()], 400);
}
