<?php
/**
 * WebAuthn Login - Step 2: Verify Assertion
 *
 * Verifies the authentication response and issues JWT tokens
 */

require_once __DIR__ . '/_common.php';

// Get input
$input = getJsonInput();
$credential = $input['credential'] ?? null;

if (!$credential) {
    sendJson(['success' => false, 'error' => 'Credential is required'], 400);
}

// Get stored challenge from session
$challenge = $_SESSION['webauthn_challenge'] ?? null;
$username = $_SESSION['webauthn_username'] ?? null;

if (!$challenge || !$username) {
    sendJson(['success' => false, 'error' => 'No login in progress. Please start login again.'], 400);
}

try {
    // Decode credential ID
    $credentialId = base64_decode($credential['rawId']);
    $credentialIdBase64 = base64_encode($credentialId);

    // Get credential from database
    $storedCredential = WalkieTalkie\AuthManager::getCredential($credentialIdBase64);
    if (!$storedCredential) {
        throw new Exception('Credential not found');
    }

    // Get user
    $user = WalkieTalkie\AuthManager::getUserById($storedCredential['user_id']);
    if (!$user) {
        throw new Exception('User not found');
    }

    // Verify username matches
    if ($user['username'] !== $username) {
        throw new Exception('Username mismatch');
    }

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
        throw new Exception('Origin mismatch');
    }

    // Verify type
    if (!isset($clientData['type']) || $clientData['type'] !== 'webauthn.get') {
        throw new Exception('Invalid type');
    }

    // Decode authenticator data (URL-safe base64)
    $authenticatorDataBase64 = $credential['response']['authenticatorData'];
    // Convert URL-safe base64 to standard base64
    $authenticatorDataBase64 = str_pad(strtr($authenticatorDataBase64, '-_', '+/'), strlen($authenticatorDataBase64) % 4, '=', STR_PAD_RIGHT);
    $authenticatorData = base64_decode($authenticatorDataBase64);
    if (strlen($authenticatorData) < 37) {
        throw new Exception('Invalid authenticator data');
    }

    // Parse authenticator data
    $flags = ord($authenticatorData[32]);
    $counter = unpack('N', substr($authenticatorData, 33, 4))[1];

    // Verify user present flag (bit 0)
    if (($flags & 0x01) === 0) {
        throw new Exception('User not present');
    }

    // Verify counter (replay attack prevention)
    if ($counter > 0 && $counter <= $storedCredential['counter']) {
        throw new Exception('Invalid counter (possible replay attack)');
    }

    // Decode signature (URL-safe base64)
    $signatureBase64 = $credential['response']['signature'];
    // Convert URL-safe base64 to standard base64
    $signatureBase64 = str_pad(strtr($signatureBase64, '-_', '+/'), strlen($signatureBase64) % 4, '=', STR_PAD_RIGHT);
    $signature = base64_decode($signatureBase64);

    // Verify signature
    $clientDataHash = hash('sha256', $clientDataJSON, true);
    $dataToVerify = $authenticatorData . $clientDataHash;

    // Decode public key (COSE format)
    $publicKeyCose = base64_decode($storedCredential['public_key']);
    $decoder = new \CBOR\Decoder();
    $stream = new \CBOR\StringStream($publicKeyCose);
    $publicKeyObject = $decoder->decode($stream);

    // Convert CBOR map to array
    $publicKeyData = [];
    if ($publicKeyObject instanceof \CBOR\MapObject) {
        foreach ($publicKeyObject as $item) {
            if ($item instanceof \CBOR\MapItem) {
                $key = $item->getKey();
                $value = $item->getValue();

                // Get numeric key
                $keyInt = (is_numeric($key)) ? (int)$key : $key;

                // Handle ByteStringObject values
                if ($value instanceof \CBOR\ByteStringObject) {
                    $publicKeyData[$keyInt] = $value->getValue();
                } else {
                    $publicKeyData[$keyInt] = $value;
                }
            }
        }
    }

    // Extract key type and algorithm
    $kty = $publicKeyData[1] ?? null; // Key type
    $alg = $publicKeyData[3] ?? null; // Algorithm

    // For EC2 keys (most common with WebAuthn)
    if ($kty === 2) { // EC2
        $crv = $publicKeyData[-1] ?? null; // Curve
        $x = $publicKeyData[-2] ?? null;   // X coordinate
        $y = $publicKeyData[-3] ?? null;   // Y coordinate

        if ($crv === 1 && $x && $y) { // P-256
            // Create public key in PEM format
            $publicKeyPem = self::createECPublicKeyPem($x, $y);

            // Verify signature using OpenSSL
            $verified = openssl_verify(
                $dataToVerify,
                $signature,
                $publicKeyPem,
                OPENSSL_ALGO_SHA256
            );

            if ($verified !== 1) {
                throw new Exception('Signature verification failed');
            }
        } else {
            throw new Exception('Unsupported curve');
        }
    } else {
        throw new Exception('Unsupported key type');
    }

    // Update credential counter
    WalkieTalkie\AuthManager::updateCredentialCounter($credentialIdBase64, $counter);

    // Update last login
    WalkieTalkie\AuthManager::updateLastLogin($user['id']);

    // Generate JWT tokens
    $accessToken = WalkieTalkie\AuthManager::generateAccessToken($user['id'], $user['username']);
    $refreshToken = WalkieTalkie\AuthManager::generateRefreshToken($user['id']);

    // Store refresh token
    WalkieTalkie\AuthManager::storeRefreshToken($user['id'], $refreshToken, [
        'ip' => getClientIp(),
        'user_agent' => getUserAgent()
    ]);

    // Clear session
    unset($_SESSION['webauthn_challenge']);
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
            'id' => $user['id'],
            'username' => $user['username']
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
    unset($_SESSION['webauthn_username']);

    sendJson(['success' => false, 'error' => 'Login failed: ' . $e->getMessage()], 400);
}

/**
 * Create EC public key in PEM format from X and Y coordinates
 */
function createECPublicKeyPem(string $x, string $y): string
{
    // For P-256 curve
    // ASN.1 structure for EC public key
    $publicKeyHex = '04' . bin2hex($x) . bin2hex($y); // Uncompressed point format

    // EC Parameters for P-256 (secp256r1 / prime256v1)
    $ecParams = '06082a8648ce3d030107'; // OID for prime256v1

    // Build the public key structure
    $publicKeyBitString = '03' . self::encodeLength(strlen($publicKeyHex) / 2 + 1) . '00' . $publicKeyHex;
    $algorithmIdentifier = '30' . self::encodeLength(strlen($ecParams . '0500') / 2) . '06072a8648ce3d0201' . $ecParams;
    $publicKeyInfo = '30' . self::encodeLength(strlen($algorithmIdentifier . $publicKeyBitString) / 2) . $algorithmIdentifier . $publicKeyBitString;

    $pem = "-----BEGIN PUBLIC KEY-----\n";
    $pem .= chunk_split(base64_encode(hex2bin($publicKeyInfo)), 64, "\n");
    $pem .= "-----END PUBLIC KEY-----";

    return $pem;
}

/**
 * Encode ASN.1 length
 */
function encodeLength(int $length): string
{
    if ($length < 128) {
        return str_pad(dechex($length), 2, '0', STR_PAD_LEFT);
    } else {
        $lengthBytes = dechex($length);
        if (strlen($lengthBytes) % 2) {
            $lengthBytes = '0' . $lengthBytes;
        }
        $numLengthBytes = strlen($lengthBytes) / 2;
        return str_pad(dechex(0x80 + $numLengthBytes), 2, '0', STR_PAD_LEFT) . $lengthBytes;
    }
}
