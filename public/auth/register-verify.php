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
    // Decode client data JSON (URL-safe base64)
    $clientDataBase64 = $credential['response']['clientDataJSON'];
    // Convert URL-safe base64 to standard base64
    $clientDataBase64 = str_pad(strtr($clientDataBase64, '-_', '+/'), strlen($clientDataBase64) % 4, '=', STR_PAD_RIGHT);
    $clientDataJSON = base64_decode($clientDataBase64);
    $clientData = json_decode($clientDataJSON, true);

    // Verify challenge - convert both to URL-safe base64 for comparison
    // The browser returns URL-safe base64 (no padding, - and _ instead of + and /)
    $challengeUrlSafe = rtrim(strtr($challenge, '+/', '-_'), '=');
    $clientChallenge = $clientData['challenge'] ?? '';
    if ($clientChallenge !== $challengeUrlSafe) {
        throw new Exception('Challenge mismatch');
    }

    // Verify origin
    $expectedOrigin = $_ENV['WEBAUTHN_ORIGIN'] ?? 'http://localhost:3000';
    if (!isset($clientData['origin']) || $clientData['origin'] !== $expectedOrigin) {
        throw new Exception('Origin mismatch. Expected: ' . $expectedOrigin . ', Got: ' . ($clientData['origin'] ?? 'none'));
    }

    // Verify type
    if (!isset($clientData['type']) || $clientData['type'] !== 'webauthn.create') {
        throw new Exception('Invalid type');
    }

    // Decode attestation object (URL-safe base64)
    $attestationObjectBase64 = $credential['response']['attestationObject'];
    // Convert URL-safe base64 to standard base64
    $attestationObjectBase64 = str_pad(strtr($attestationObjectBase64, '-_', '+/'), strlen($attestationObjectBase64) % 4, '=', STR_PAD_RIGHT);
    $attestationObject = base64_decode($attestationObjectBase64);
    if ($attestationObject === false || empty($attestationObject)) {
        throw new Exception('Failed to decode attestation object from base64');
    }

    // Parse CBOR-encoded attestation object
    try {
        $decoder = new \CBOR\Decoder();
        $stream = new \CBOR\StringStream($attestationObject);
        $attestationDataObject = $decoder->decode($stream);
    } catch (\Throwable $e) {
        throw new Exception('CBOR decode failed: ' . $e->getMessage());
    }

    // Get the map data - CBOR returns a MapObject
    if (!($attestationDataObject instanceof \CBOR\MapObject)) {
        throw new Exception('Invalid attestation object format');
    }

    // Extract authData from CBOR map
    $authData = null;
    foreach ($attestationDataObject as $item) {
        if ($item instanceof \CBOR\MapItem) {
            $key = $item->getKey();
            $value = $item->getValue();

            // Get key as string
            $keyStr = ($key instanceof \CBOR\TextStringObject) ? $key->getValue() : (string)$key;

            if ($keyStr === 'authData') {
                // Get value as binary
                if ($value instanceof \CBOR\ByteStringObject) {
                    $authData = $value->getValue();
                } else {
                    $authData = (string)$value;
                }
                break;
            }
        }
    }

    if ($authData === null) {
        throw new Exception('Invalid attestation object - missing authData');
    }

    $authDataLen = strlen($authData);
    if ($authDataLen < 37) {
        throw new Exception('Invalid authenticator data length: ' . $authDataLen . ' bytes (minimum 37)');
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
    if ($offset + 16 > $authDataLen) {
        throw new Exception('Out of range reading AAGUID at offset ' . $offset . ', length ' . $authDataLen);
    }
    $aaguid = bin2hex(substr($authData, $offset, 16));
    $offset += 16;

    // Credential ID length (2 bytes)
    if ($offset + 2 > $authDataLen) {
        throw new Exception('Out of range reading credential ID length at offset ' . $offset . ', length ' . $authDataLen);
    }
    $credIdLength = unpack('n', substr($authData, $offset, 2))[1];
    $offset += 2;

    // Credential ID
    if ($offset + $credIdLength > $authDataLen) {
        throw new Exception('Out of range reading credential ID at offset ' . $offset . ', credIdLength ' . $credIdLength . ', total length ' . $authDataLen);
    }
    $credentialId = substr($authData, $offset, $credIdLength);
    $credentialIdBase64 = base64_encode($credentialId);
    $offset += $credIdLength;

    // Public key (COSE encoded) - rest of the data
    if ($offset >= $authDataLen) {
        throw new Exception('Out of range reading public key at offset ' . $offset . ', length ' . $authDataLen);
    }
    $publicKeyCose = substr($authData, $offset);
    $publicKeyBase64 = base64_encode($publicKeyCose);

    // Create user in database
    $userDbId = WalkieTalkie\AuthManager::createUser($username);

    // Get transports from credential, default to 'internal' if empty
    // Windows Hello often doesn't report transports, but it's a platform authenticator
    $transports = $credential['response']['transports'] ?? [];
    if (empty($transports)) {
        $transports = ['internal'];
    }

    // Store credential
    WalkieTalkie\AuthManager::storeCredential($userDbId, [
        'credential_id' => $credentialIdBase64,
        'public_key' => $publicKeyBase64,
        'counter' => $counter,
        'aaguid' => $aaguid,
        'transports' => $transports,
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
