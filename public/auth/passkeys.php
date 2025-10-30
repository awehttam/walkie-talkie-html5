<?php
/**
 * Passkey Management
 *
 * Add or delete passkeys for authenticated users
 */

require_once __DIR__ . '/_common.php';

// Require authentication
$payload = requireAuth();
$userId = (int)$payload['sub'];

// Get action
$action = $_GET['action'] ?? '';
$input = getJsonInput();

switch ($action) {
    case 'delete':
        // Delete a passkey
        $credentialId = $input['credential_id'] ?? null;

        if (!$credentialId) {
            sendJson(['success' => false, 'error' => 'credential_id is required'], 400);
        }

        try {
            $deleted = WalkieTalkie\AuthManager::deleteCredential($credentialId, $userId);

            if ($deleted) {
                sendJson([
                    'success' => true,
                    'message' => 'Passkey deleted successfully'
                ]);
            } else {
                sendJson(['success' => false, 'error' => 'Passkey not found or not authorized'], 404);
            }
        } catch (Exception $e) {
            sendJson(['success' => false, 'error' => $e->getMessage()], 400);
        }
        break;

    case 'add':
        // Generate options for adding a new passkey
        // This is similar to registration but for existing user

        $nickname = $input['nickname'] ?? 'New Device';

        // Get user
        $user = WalkieTalkie\AuthManager::getUserById($userId);
        if (!$user) {
            sendJson(['success' => false, 'error' => 'User not found'], 404);
        }

        // Get existing credentials to exclude
        $existingCredentials = WalkieTalkie\AuthManager::getUserCredentials($userId);
        $excludeCredentials = [];

        foreach ($existingCredentials as $cred) {
            $excludeCredentials[] = [
                'type' => 'public-key',
                'id' => $cred['credential_id'],
                'transports' => $cred['transports'] ? json_decode($cred['transports'], true) : []
            ];
        }

        // Generate challenge
        $challenge = generateChallenge();

        // Create options (same as registration)
        $rpEntity = getRelyingParty();

        $options = [
            'challenge' => $challenge,
            'rp' => [
                'name' => $rpEntity->name,
                'id' => $rpEntity->id
            ],
            'user' => [
                'id' => base64_encode((string)$user['id']),
                'name' => $user['username'],
                'displayName' => $user['username']
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],  // ES256
                ['type' => 'public-key', 'alg' => -257] // RS256
            ],
            'timeout' => (int)($_ENV['WEBAUTHN_TIMEOUT'] ?? 60000),
            'attestation' => 'none',
            'excludeCredentials' => $excludeCredentials,
            'authenticatorSelection' => [
                'residentKey' => 'discouraged',
                'userVerification' => 'preferred'
            ]
        ];

        // Store challenge and info in session
        $_SESSION['webauthn_challenge'] = $challenge;
        $_SESSION['webauthn_user_id'] = (string)$user['id'];
        $_SESSION['webauthn_username'] = $user['username'];
        $_SESSION['webauthn_nickname'] = $nickname;
        $_SESSION['webauthn_add_passkey'] = true;

        sendJson([
            'success' => true,
            'options' => $options
        ]);
        break;

    case 'verify':
        // Verify and store the new passkey
        $credential = $input['credential'] ?? null;
        $nickname = $input['nickname'] ?? null;

        if (!$credential) {
            sendJson(['success' => false, 'error' => 'Credential is required'], 400);
        }

        // Get stored info from session
        $challenge = $_SESSION['webauthn_challenge'] ?? null;
        $sessionUserId = $_SESSION['webauthn_user_id'] ?? null;
        $addingPasskey = $_SESSION['webauthn_add_passkey'] ?? false;

        if (!$challenge || !$sessionUserId || !$addingPasskey) {
            sendJson(['success' => false, 'error' => 'No passkey addition in progress'], 400);
        }

        // Verify user ID matches
        if ((int)$sessionUserId !== $userId) {
            sendJson(['success' => false, 'error' => 'User mismatch'], 403);
        }

        try {
            // Decode and verify credential (similar to registration)
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
            if ($clientData['origin'] !== $expectedOrigin) {
                throw new Exception('Origin mismatch');
            }

            // Decode attestation object and extract credential (URL-safe base64)
            $attestationObjectBase64 = $credential['response']['attestationObject'];
            // Convert URL-safe base64 to standard base64
            $attestationObjectBase64 = str_pad(strtr($attestationObjectBase64, '-_', '+/'), strlen($attestationObjectBase64) % 4, '=', STR_PAD_RIGHT);
            $attestationObject = base64_decode($attestationObjectBase64);
            $decoder = new \CBOR\Decoder();
            $stream = new \CBOR\StringStream($attestationObject);
            $attestationDataObject = $decoder->decode($stream);

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

            // Parse authenticator data
            $counter = unpack('N', substr($authData, 33, 4))[1];
            $offset = 37;

            // AAGUID
            $aaguid = bin2hex(substr($authData, $offset, 16));
            $offset += 16;

            // Credential ID
            $credIdLength = unpack('n', substr($authData, $offset, 2))[1];
            $offset += 2;
            $credentialId = substr($authData, $offset, $credIdLength);
            $credentialIdBase64 = base64_encode($credentialId);
            $offset += $credIdLength;

            // Public key
            $publicKeyCose = substr($authData, $offset);
            $publicKeyBase64 = base64_encode($publicKeyCose);

            // Use nickname from session or input
            $finalNickname = $nickname ?? ($_SESSION['webauthn_nickname'] ?? 'New Device');

            // Store credential
            WalkieTalkie\AuthManager::storeCredential($userId, [
                'credential_id' => $credentialIdBase64,
                'public_key' => $publicKeyBase64,
                'counter' => $counter,
                'aaguid' => $aaguid,
                'transports' => $credential['response']['transports'] ?? null,
                'nickname' => $finalNickname
            ]);

            // Clear session
            unset($_SESSION['webauthn_challenge']);
            unset($_SESSION['webauthn_user_id']);
            unset($_SESSION['webauthn_username']);
            unset($_SESSION['webauthn_nickname']);
            unset($_SESSION['webauthn_add_passkey']);

            sendJson([
                'success' => true,
                'message' => 'Passkey added successfully'
            ]);

        } catch (Exception $e) {
            // Clear session on error
            unset($_SESSION['webauthn_challenge']);
            unset($_SESSION['webauthn_add_passkey']);

            sendJson(['success' => false, 'error' => 'Failed to add passkey: ' . $e->getMessage()], 400);
        }
        break;

    default:
        sendJson(['success' => false, 'error' => 'Invalid action'], 400);
}
