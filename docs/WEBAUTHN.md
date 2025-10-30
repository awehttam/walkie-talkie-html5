# WebAuthn/Passkeys Authentication Implementation Plan

## Overview
Add WebAuthn/passkeys authentication with optional anonymous mode, JWT-based sessions, unique screen names, and multi-device support.

## Requirements Summary
- **Authentication Method**: WebAuthn/passkeys (no passwords)
- **Anonymous Support**: Optional via configuration flag
- **Screen Names**: Unique across all users (registered + anonymous)
- **Anonymous Screen Names**: User-chosen, validated against registered users
- **Session Strategy**: JWT token-based (stateless, WebSocket-friendly)
- **Multi-device**: Users can register multiple passkeys with friendly nicknames

## Phase 1: Database Schema & Configuration

### 1.1 Database Schema Updates

#### New Tables

**users table**
```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,           -- Screen name (unique)
    created_at INTEGER NOT NULL,             -- Unix timestamp
    last_login INTEGER,                      -- Unix timestamp
    is_active INTEGER DEFAULT 1              -- Soft delete flag
);

CREATE INDEX idx_username ON users(username);
```

**webauthn_credentials table**
```sql
CREATE TABLE webauthn_credentials (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    credential_id TEXT UNIQUE NOT NULL,      -- Base64 credential ID from authenticator
    public_key TEXT NOT NULL,                -- Base64 public key (COSE format)
    counter INTEGER NOT NULL DEFAULT 0,      -- Signature counter for replay protection
    aaguid TEXT,                             -- Authenticator AAGUID
    transports TEXT,                         -- JSON array: ["usb", "nfc", "ble", "internal"]
    created_at INTEGER NOT NULL,
    last_used INTEGER,
    nickname TEXT,                           -- User-friendly name (e.g., "iPhone", "YubiKey")
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_credential_user ON webauthn_credentials(user_id);
CREATE INDEX idx_credential_id ON webauthn_credentials(credential_id);
```

**jwt_refresh_tokens table**
```sql
CREATE TABLE jwt_refresh_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT UNIQUE NOT NULL,         -- SHA256 hash of refresh token
    expires_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    ip_address TEXT,
    user_agent TEXT,
    revoked INTEGER DEFAULT 0,               -- For logout/revocation
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_token_hash ON jwt_refresh_tokens(token_hash);
CREATE INDEX idx_token_expiry ON jwt_refresh_tokens(expires_at);
```

#### Update Existing Tables

**message_history table updates**
```sql
-- Add columns to existing table
ALTER TABLE message_history ADD COLUMN user_id INTEGER;
ALTER TABLE message_history ADD COLUMN screen_name TEXT;

-- Add foreign key reference (note: SQLite doesn't enforce this on ALTER)
-- FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL

-- Add index for user queries
CREATE INDEX idx_message_user ON message_history(user_id);
```

**Migration strategy for existing data:**
- Existing records: Set `screen_name` to truncated `client_id` (e.g., "client_1234...")
- `user_id` remains NULL for legacy anonymous messages

### 1.2 Environment Configuration

Add to `.env`:

```env
# WebAuthn/Passkeys Configuration
WEBAUTHN_RP_NAME="Walkie Talkie"           # Relying Party name shown to users
WEBAUTHN_RP_ID=localhost                   # Must match domain (no port, no protocol)
WEBAUTHN_ORIGIN=http://localhost:3000      # Full origin URL with protocol
WEBAUTHN_TIMEOUT=60000                     # Challenge timeout in milliseconds

# JWT Configuration
JWT_SECRET=                                # REQUIRED: Generate with: openssl rand -base64 64
JWT_ACCESS_EXPIRATION=3600                 # Access token lifetime (1 hour)
JWT_REFRESH_EXPIRATION=604800              # Refresh token lifetime (7 days)

# Authentication Settings
ANONYMOUS_MODE_ENABLED=true                # Allow unauthenticated users
REGISTRATION_ENABLED=true                  # Allow new user registration

# Screen Name Validation
SCREEN_NAME_MIN_LENGTH=2
SCREEN_NAME_MAX_LENGTH=20
SCREEN_NAME_PATTERN=^[a-zA-Z0-9_-]+$      # Alphanumeric, underscore, hyphen
```

**Configuration notes:**
- `WEBAUTHN_RP_ID`: Must match your domain. For localhost development, use "localhost". For production at "example.com", use "example.com".
- `WEBAUTHN_ORIGIN`: Must match exactly where users access the app, including protocol and port.
- `JWT_SECRET`: Generate securely. Never commit to version control.
- Production: Set `WEBAUTHN_ORIGIN` to `https://yourdomain.com` (HTTPS required)

### 1.3 Migration Script

Create `migrations/001_add_authentication.php`:
- Check if tables exist before creating
- Safely add columns to existing tables
- Provide rollback capability
- Run automatically on server startup or via CLI

## Phase 2: WebAuthn Backend Implementation

### 2.1 PHP Dependencies

Add to `composer.json`:
```json
{
    "require": {
        "web-auth/webauthn-lib": "^4.0",
        "web-auth/cose-lib": "^4.0",
        "firebase/php-jwt": "^6.0",
        "ramsey/uuid": "^4.0"
    }
}
```

**Library responsibilities:**
- `web-auth/webauthn-lib`: WebAuthn protocol handling (challenges, verification)
- `web-auth/cose-lib`: COSE key parsing (cryptographic keys)
- `firebase/php-jwt`: JWT generation and validation
- `ramsey/uuid`: Generate secure random identifiers

### 2.2 Authentication Endpoints

Create directory structure:
```
public/
└── auth/
    ├── register-options.php      # Step 1 of registration
    ├── register-verify.php       # Step 2 of registration
    ├── login-options.php         # Step 1 of login
    ├── login-verify.php          # Step 2 of login
    ├── refresh.php               # Get new access token
    ├── logout.php                # Revoke refresh token
    ├── user-info.php             # Get current user data
    └── passkeys.php              # Manage user's passkeys (list/delete/add)
```

#### register-options.php
**Purpose**: Generate WebAuthn registration challenge

**Request**:
```json
POST /auth/register-options.php
Content-Type: application/json

{
    "username": "alice"
}
```

**Validation**:
- Check `REGISTRATION_ENABLED` config
- Validate username against `SCREEN_NAME_*` rules
- Check username uniqueness in database
- Check username not in use by active anonymous session

**Response**:
```json
{
    "success": true,
    "options": {
        "challenge": "base64-encoded-random-bytes",
        "rp": {
            "name": "Walkie Talkie",
            "id": "localhost"
        },
        "user": {
            "id": "base64-encoded-user-id",
            "name": "alice",
            "displayName": "alice"
        },
        "pubKeyCredParams": [
            {"type": "public-key", "alg": -7},   // ES256
            {"type": "public-key", "alg": -257}  // RS256
        ],
        "timeout": 60000,
        "attestation": "none",
        "authenticatorSelection": {
            "authenticatorAttachment": "platform",
            "requireResidentKey": false,
            "userVerification": "preferred"
        }
    }
}
```

**Session storage**: Store challenge in PHP session for verification

#### register-verify.php
**Purpose**: Verify registration response and create user account

**Request**:
```json
POST /auth/register-verify.php
Content-Type: application/json

{
    "credential": {
        "id": "credential-id-base64",
        "rawId": "credential-id-base64",
        "response": {
            "clientDataJSON": "base64",
            "attestationObject": "base64"
        },
        "type": "public-key"
    },
    "nickname": "My iPhone"  // Optional friendly name for this passkey
}
```

**Verification steps**:
1. Retrieve challenge from session
2. Verify credential using web-auth library
3. Check credential not already registered
4. Create user record in database
5. Store credential with public key
6. Generate JWT access + refresh tokens
7. Return tokens to client

**Response**:
```json
{
    "success": true,
    "user": {
        "id": 123,
        "username": "alice"
    },
    "tokens": {
        "access_token": "jwt-access-token",
        "refresh_token": "jwt-refresh-token",
        "expires_in": 3600
    }
}
```

**Token delivery**:
- Access token: Return in JSON response (client stores in localStorage)
- Refresh token: Set as HTTP-only, Secure, SameSite=Strict cookie

#### login-options.php
**Purpose**: Generate WebAuthn authentication challenge

**Request**:
```json
POST /auth/login-options.php
Content-Type: application/json

{
    "username": "alice"  // Optional for usernameless flow
}
```

**Response**:
```json
{
    "success": true,
    "options": {
        "challenge": "base64-encoded-random-bytes",
        "timeout": 60000,
        "rpId": "localhost",
        "allowCredentials": [
            {
                "type": "public-key",
                "id": "credential-id-base64",
                "transports": ["internal", "usb"]
            }
        ],
        "userVerification": "preferred"
    }
}
```

**allowCredentials**: Include all credentials for the user (if username provided)

#### login-verify.php
**Purpose**: Verify authentication response and issue tokens

**Request**:
```json
POST /auth/login-verify.php
Content-Type: application/json

{
    "credential": {
        "id": "credential-id-base64",
        "rawId": "credential-id-base64",
        "response": {
            "clientDataJSON": "base64",
            "authenticatorData": "base64",
            "signature": "base64",
            "userHandle": "base64"  // User ID
        },
        "type": "public-key"
    }
}
```

**Verification steps**:
1. Retrieve challenge from session
2. Look up credential by ID
3. Retrieve public key from database
4. Verify signature using web-auth library
5. Update credential counter (prevent replay attacks)
6. Update user last_login timestamp
7. Generate JWT tokens
8. Return tokens to client

**Response**: Same format as register-verify.php

#### refresh.php
**Purpose**: Exchange refresh token for new access token

**Request**:
```json
POST /auth/refresh.php
Content-Type: application/json

{
    "refresh_token": "jwt-refresh-token"
}
```

(Or read from HTTP-only cookie if available)

**Verification steps**:
1. Verify JWT signature and expiration
2. Check token hash exists in database and not revoked
3. Generate new access token
4. Optionally rotate refresh token (generate new one, revoke old)
5. Return new tokens

**Response**:
```json
{
    "success": true,
    "tokens": {
        "access_token": "new-jwt-access-token",
        "expires_in": 3600
    }
}
```

#### logout.php
**Purpose**: Revoke refresh token

**Request**:
```json
POST /auth/logout.php
Content-Type: application/json
Authorization: Bearer <access-token>

{
    "refresh_token": "jwt-refresh-token"
}
```

**Actions**:
1. Verify access token
2. Mark refresh token as revoked in database
3. Clear refresh token cookie
4. Return success

#### user-info.php
**Purpose**: Get current authenticated user details

**Request**:
```json
GET /auth/user-info.php
Authorization: Bearer <access-token>
```

**Response**:
```json
{
    "success": true,
    "user": {
        "id": 123,
        "username": "alice",
        "created_at": 1698765432000,
        "last_login": 1698765432000,
        "passkeys": [
            {
                "id": 1,
                "nickname": "My iPhone",
                "created_at": 1698765432000,
                "last_used": 1698765432000,
                "transports": ["internal"]
            },
            {
                "id": 2,
                "nickname": "YubiKey",
                "created_at": 1698765432000,
                "last_used": null,
                "transports": ["usb", "nfc"]
            }
        ]
    }
}
```

#### passkeys.php
**Purpose**: Manage user's registered passkeys

**List passkeys** (included in user-info.php response)

**Add passkey** (same as register flow but for existing user):
```json
POST /auth/passkeys.php?action=add
Authorization: Bearer <access-token>
Content-Type: application/json

{
    "nickname": "Work Laptop"
}
```

Returns registration options, then client submits credential to verify endpoint.

**Delete passkey**:
```json
POST /auth/passkeys.php?action=delete
Authorization: Bearer <access-token>
Content-Type: application/json

{
    "credential_id": 123
}
```

**Validation**: Prevent deleting last passkey (user would be locked out)

### 2.3 Authentication Utilities

Create `src/AuthManager.php`:

**Key methods**:
```php
class AuthManager
{
    // JWT Methods
    public static function generateAccessToken(int $userId, string $username): string
    public static function generateRefreshToken(int $userId): string
    public static function validateAccessToken(string $token): ?array
    public static function validateRefreshToken(string $token): ?array

    // User Management
    public static function createUser(string $username): int
    public static function getUserById(int $userId): ?array
    public static function getUserByUsername(string $username): ?array

    // Screen Name Validation
    public static function validateScreenName(string $name): bool
    public static function isScreenNameAvailable(string $name): bool

    // Credential Management
    public static function storeCredential(int $userId, array $credentialData): void
    public static function getCredential(string $credentialId): ?array
    public static function getUserCredentials(int $userId): array
    public static function updateCredentialCounter(string $credentialId, int $counter): void
    public static function deleteCredential(int $credentialId, int $userId): bool

    // Refresh Token Management
    public static function storeRefreshToken(int $userId, string $token, array $metadata): void
    public static function isRefreshTokenValid(string $tokenHash): bool
    public static function revokeRefreshToken(string $tokenHash): void
    public static function cleanupExpiredTokens(): void
}
```

**JWT payload structure**:
```json
{
    "iss": "walkie-talkie",
    "sub": "123",                    // User ID
    "username": "alice",
    "iat": 1698765432,
    "exp": 1698769032,
    "type": "access"                 // or "refresh"
}
```

**Security considerations**:
- Use strong JWT secret (64+ bytes)
- Sign with HS256 or RS256
- Validate `exp` claim on every request
- Hash refresh tokens before storing (SHA256)
- Implement token rotation for refresh tokens
- Clean up expired tokens periodically

## Phase 3: WebSocket Server Integration

### 3.1 Update WebSocketServer.php

**New properties**:
```php
class WebSocketServer implements MessageComponentInterface
{
    // Existing
    protected $clients;              // SplObjectStorage of all connections
    protected $channels;             // Array of channel => SplObjectStorage
    protected $activeTransmissions;  // Buffered audio chunks
    protected $db;                   // PDO database connection

    // NEW: Authentication tracking
    protected $authenticatedUsers;   // Map: resourceId => user data
    protected $anonymousSessions;    // Map: resourceId => screen name
    protected $activeScreenNames;    // Set of currently active screen names
}
```

**User data structure**:
```php
[
    'user_id' => 123,
    'username' => 'alice',
    'authenticated_at' => 1698765432000,
    'connection' => $conn  // Reference for quick lookup
]
```

**New methods**:
```php
private function authenticateConnection(ConnectionInterface $conn, string $token): bool
private function setAnonymousScreenName(ConnectionInterface $conn, string $screenName): bool
private function getConnectionIdentity(ConnectionInterface $conn): ?array
private function isScreenNameInUse(string $screenName): bool
private function requireAuthentication(): bool  // Check ANONYMOUS_MODE_ENABLED
```

### 3.2 Connection Flow Updates

**Current flow**:
```
1. WebSocket connection established (onOpen)
2. Client joins channel
3. Client starts transmitting
```

**New authenticated flow**:
```
1. WebSocket connection established (onOpen)
2. Client sends 'authenticate' message with JWT
3. Server validates JWT and marks connection as authenticated
4. Client joins channel
5. Client starts transmitting (with screen name)
```

**New anonymous flow** (if enabled):
```
1. WebSocket connection established (onOpen)
2. Client sends 'set_screen_name' message
3. Server validates screen name uniqueness
4. Server marks connection with temporary screen name
5. Client joins channel
6. Client starts transmitting (with screen name)
```

**If anonymous mode disabled**:
```
1. WebSocket connection established (onOpen)
2. Server immediately sends 'authentication_required' message
3. Client redirects to login page
4. After login, client reconnects with JWT
```

### 3.3 Message Protocol Updates

**New message types (Client → Server)**:

**Authenticate**:
```json
{
    "type": "authenticate",
    "token": "jwt-access-token"
}
```

**Set screen name** (anonymous only):
```json
{
    "type": "set_screen_name",
    "screen_name": "Guest123"
}
```

**New message types (Server → Client)**:

**Authentication required**:
```json
{
    "type": "authentication_required",
    "message": "Please log in to continue"
}
```

**Authentication success**:
```json
{
    "type": "authenticated",
    "user": {
        "id": 123,
        "username": "alice"
    }
}
```

**Screen name set**:
```json
{
    "type": "screen_name_set",
    "screen_name": "Guest123"
}
```

**Screen name taken**:
```json
{
    "type": "error",
    "code": "screen_name_taken",
    "message": "That name is already in use"
}
```

**Updated message types**:

All broadcast messages now include `screen_name`:

**user_speaking**:
```json
{
    "type": "user_speaking",
    "client_id": "client_123",
    "screen_name": "alice",        // NEW
    "speaking": true
}
```

**audio_data**:
```json
{
    "type": "audio_data",
    "client_id": "client_123",
    "screen_name": "alice",        // NEW
    "data": "base64-audio-data"
}
```

**participant_joined/left**:
```json
{
    "type": "participant_joined",
    "screen_name": "alice",        // NEW
    "count": 3
}
```

**history_response**:
```json
{
    "type": "history_response",
    "messages": [
        {
            "client_id": "client_123",
            "screen_name": "alice",    // NEW
            "audio_data": "...",
            "sample_rate": 48000,
            "duration": 3500,
            "timestamp": 1698765432000
        }
    ]
}
```

### 3.4 Message Handler Updates

**onOpen() - Connection established**:
```php
public function onOpen(ConnectionInterface $conn)
{
    $this->clients->attach($conn);

    // Check if authentication is required
    if ($this->requireAuthentication()) {
        $conn->send(json_encode([
            'type' => 'authentication_required',
            'message' => 'Please log in to continue'
        ]));
    }

    echo "[CONNECT] Client {$conn->resourceId} from {$this->getClientIp($conn)}\n";
}
```

**onMessage() - Handle authenticate message**:
```php
case 'authenticate':
    $token = $data->token ?? null;
    if (!$token) {
        $conn->send(json_encode(['type' => 'error', 'message' => 'Token required']));
        return;
    }

    $userData = AuthManager::validateAccessToken($token);
    if (!$userData) {
        $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid token']));
        return;
    }

    // Mark connection as authenticated
    $this->authenticatedUsers[$conn->resourceId] = [
        'user_id' => $userData['sub'],
        'username' => $userData['username'],
        'authenticated_at' => time() * 1000,
        'connection' => $conn
    ];

    // Add screen name to active set
    $this->activeScreenNames[$userData['username']] = true;

    $conn->send(json_encode([
        'type' => 'authenticated',
        'user' => [
            'id' => $userData['sub'],
            'username' => $userData['username']
        ]
    ]));
    break;
```

**onMessage() - Handle set_screen_name message**:
```php
case 'set_screen_name':
    // Only allow if anonymous mode enabled
    if ($this->requireAuthentication()) {
        $conn->send(json_encode(['type' => 'error', 'message' => 'Anonymous mode disabled']));
        return;
    }

    // Check if already authenticated
    if (isset($this->authenticatedUsers[$conn->resourceId])) {
        $conn->send(json_encode(['type' => 'error', 'message' => 'Already authenticated']));
        return;
    }

    $screenName = $data->screen_name ?? null;

    // Validate screen name
    if (!AuthManager::validateScreenName($screenName)) {
        $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid screen name']));
        return;
    }

    // Check uniqueness (against DB and active anonymous sessions)
    if ($this->isScreenNameInUse($screenName)) {
        $conn->send(json_encode([
            'type' => 'error',
            'code' => 'screen_name_taken',
            'message' => 'That name is already in use'
        ]));
        return;
    }

    // Store anonymous session
    $this->anonymousSessions[$conn->resourceId] = $screenName;
    $this->activeScreenNames[$screenName] = true;

    $conn->send(json_encode([
        'type' => 'screen_name_set',
        'screen_name' => $screenName
    ]));
    break;
```

**joinChannel() - Require identity**:
```php
private function joinChannel(ConnectionInterface $conn, string $channelId)
{
    // Check if user has identity (authenticated or anonymous)
    $identity = $this->getConnectionIdentity($conn);
    if (!$identity) {
        $conn->send(json_encode([
            'type' => 'error',
            'message' => 'Must authenticate or set screen name first'
        ]));
        return;
    }

    // ... rest of existing join logic
}
```

**getConnectionIdentity() - Helper method**:
```php
private function getConnectionIdentity(ConnectionInterface $conn): ?array
{
    $resourceId = $conn->resourceId;

    // Check if authenticated user
    if (isset($this->authenticatedUsers[$resourceId])) {
        $user = $this->authenticatedUsers[$resourceId];
        return [
            'type' => 'authenticated',
            'user_id' => $user['user_id'],
            'screen_name' => $user['username']
        ];
    }

    // Check if anonymous session
    if (isset($this->anonymousSessions[$resourceId])) {
        return [
            'type' => 'anonymous',
            'user_id' => null,
            'screen_name' => $this->anonymousSessions[$resourceId]
        ];
    }

    return null;
}
```

**onClose() - Cleanup**:
```php
public function onClose(ConnectionInterface $conn)
{
    // Get identity before cleanup
    $identity = $this->getConnectionIdentity($conn);

    // Remove from authentication tracking
    if (isset($this->authenticatedUsers[$conn->resourceId])) {
        $username = $this->authenticatedUsers[$conn->resourceId]['username'];
        unset($this->activeScreenNames[$username]);
        unset($this->authenticatedUsers[$conn->resourceId]);
    }

    if (isset($this->anonymousSessions[$conn->resourceId])) {
        $screenName = $this->anonymousSessions[$conn->resourceId];
        unset($this->activeScreenNames[$screenName]);
        unset($this->anonymousSessions[$conn->resourceId]);
    }

    // ... rest of existing cleanup logic

    if ($identity) {
        echo "[DISCONNECT] {$identity['screen_name']} (Client {$conn->resourceId})\n";
    }
}
```

### 3.5 Message History Updates

**saveMessage() - Store user_id and screen_name**:
```php
private function saveMessage(string $channel, string $clientId, string $audioData,
                             int $sampleRate, int $duration): void
{
    // Get connection from client ID
    $conn = $this->getConnectionByClientId($clientId);
    if (!$conn) return;

    // Get identity
    $identity = $this->getConnectionIdentity($conn);
    if (!$identity) return;

    $stmt = $this->db->prepare("
        INSERT INTO message_history
        (channel, client_id, user_id, screen_name, audio_data, sample_rate, duration, timestamp)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $channel,
        $clientId,
        $identity['user_id'],      // NULL for anonymous
        $identity['screen_name'],
        $audioData,
        $sampleRate,
        $duration,
        floor(microtime(true) * 1000)
    ]);
}
```

**getChannelHistory() - Return screen_name**:
```php
private function getChannelHistory(string $channel): array
{
    $maxCount = $_ENV['MESSAGE_HISTORY_MAX_COUNT'] ?? 10;
    $maxAge = $_ENV['MESSAGE_HISTORY_MAX_AGE'] ?? 300;
    $maxTimestamp = floor(microtime(true) * 1000) - ($maxAge * 1000);

    $stmt = $this->db->prepare("
        SELECT client_id, screen_name, audio_data, sample_rate, duration, timestamp
        FROM message_history
        WHERE channel = ? AND timestamp > ?
        ORDER BY timestamp DESC
        LIMIT ?
    ");

    $stmt->execute([$channel, $maxTimestamp, $maxCount]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_reverse($messages);
}
```

## Phase 4: Frontend Implementation

### 4.1 Authentication UI

**Create public/login.html**:
```html
<!DOCTYPE html>
<html>
<head>
    <title>Login - Walkie Talkie</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <div class="auth-container">
        <h1>Walkie Talkie</h1>

        <div id="registerSection">
            <h2>Create Account</h2>
            <input type="text" id="registerUsername" placeholder="Choose a screen name">
            <button id="registerBtn">Register with Passkey</button>
        </div>

        <div id="loginSection">
            <h2>Login</h2>
            <input type="text" id="loginUsername" placeholder="Screen name">
            <button id="loginBtn">Login with Passkey</button>
        </div>

        <div id="error" class="error-message"></div>
    </div>

    <script src="/assets/auth.js"></script>
</body>
</html>
```

**Create public/assets/auth.js**:
```javascript
// WebAuthn helper functions
async function registerWithPasskey(username, nickname = null) {
    // Step 1: Get registration options
    const optionsResponse = await fetch('/auth/register-options.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({username})
    });

    const {success, options, error} = await optionsResponse.json();
    if (!success) throw new Error(error);

    // Step 2: Call WebAuthn API
    const credential = await navigator.credentials.create({
        publicKey: {
            ...options,
            challenge: base64ToArrayBuffer(options.challenge),
            user: {
                ...options.user,
                id: base64ToArrayBuffer(options.user.id)
            }
        }
    });

    // Step 3: Send credential to server
    const verifyResponse = await fetch('/auth/register-verify.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            credential: credentialToJSON(credential),
            nickname
        })
    });

    const result = await verifyResponse.json();
    if (!result.success) throw new Error(result.error);

    return result;
}

async function loginWithPasskey(username) {
    // Step 1: Get authentication options
    const optionsResponse = await fetch('/auth/login-options.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({username})
    });

    const {success, options, error} = await optionsResponse.json();
    if (!success) throw new Error(error);

    // Step 2: Call WebAuthn API
    const credential = await navigator.credentials.get({
        publicKey: {
            ...options,
            challenge: base64ToArrayBuffer(options.challenge),
            allowCredentials: options.allowCredentials?.map(cred => ({
                ...cred,
                id: base64ToArrayBuffer(cred.id)
            }))
        }
    });

    // Step 3: Send credential to server
    const verifyResponse = await fetch('/auth/login-verify.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            credential: credentialToJSON(credential)
        })
    });

    const result = await verifyResponse.json();
    if (!result.success) throw new Error(result.error);

    return result;
}

// Helper functions
function base64ToArrayBuffer(base64) {
    const binary = atob(base64.replace(/-/g, '+').replace(/_/g, '/'));
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
}

function arrayBufferToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

function credentialToJSON(credential) {
    return {
        id: credential.id,
        rawId: arrayBufferToBase64(credential.rawId),
        response: {
            clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
            attestationObject: credential.response.attestationObject
                ? arrayBufferToBase64(credential.response.attestationObject)
                : undefined,
            authenticatorData: credential.response.authenticatorData
                ? arrayBufferToBase64(credential.response.authenticatorData)
                : undefined,
            signature: credential.response.signature
                ? arrayBufferToBase64(credential.response.signature)
                : undefined,
            userHandle: credential.response.userHandle
                ? arrayBufferToBase64(credential.response.userHandle)
                : undefined
        },
        type: credential.type
    };
}

// UI event handlers
document.getElementById('registerBtn').addEventListener('click', async () => {
    const username = document.getElementById('registerUsername').value.trim();
    if (!username) {
        showError('Please enter a screen name');
        return;
    }

    try {
        const result = await registerWithPasskey(username);

        // Store tokens
        localStorage.setItem('access_token', result.tokens.access_token);

        // Redirect to app
        window.location.href = '/';
    } catch (error) {
        showError(error.message);
    }
});

document.getElementById('loginBtn').addEventListener('click', async () => {
    const username = document.getElementById('loginUsername').value.trim();
    if (!username) {
        showError('Please enter your screen name');
        return;
    }

    try {
        const result = await loginWithPasskey(username);

        // Store tokens
        localStorage.setItem('access_token', result.tokens.access_token);

        // Redirect to app
        window.location.href = '/';
    } catch (error) {
        showError(error.message);
    }
});

function showError(message) {
    document.getElementById('error').textContent = message;
}
```

### 4.2 Update walkie-talkie.js

**Add authentication state management**:
```javascript
class WalkieTalkie {
    constructor() {
        // Existing properties
        this.ws = null;
        this.audioContext = null;
        // ...

        // NEW: Authentication properties
        this.accessToken = null;
        this.currentUser = null;
        this.isAnonymous = false;
        this.screenName = null;
        this.tokenRefreshTimer = null;
    }

    async init() {
        // Check for existing access token
        this.accessToken = localStorage.getItem('access_token');

        if (this.accessToken) {
            // Validate and get user info
            try {
                const response = await fetch('/auth/user-info.php', {
                    headers: {
                        'Authorization': `Bearer ${this.accessToken}`
                    }
                });

                const result = await response.json();
                if (result.success) {
                    this.currentUser = result.user;
                    this.screenName = result.user.username;
                    this.showAuthenticatedUI();
                    this.scheduleTokenRefresh();
                } else {
                    // Token invalid, clear it
                    localStorage.removeItem('access_token');
                    this.accessToken = null;
                }
            } catch (error) {
                console.error('Failed to validate token:', error);
                localStorage.removeItem('access_token');
                this.accessToken = null;
            }
        }

        // Check if anonymous mode allowed
        const config = await this.fetchConfig();

        if (!this.accessToken && !config.anonymousModeEnabled) {
            // Redirect to login
            window.location.href = '/login.html';
            return;
        }

        if (!this.accessToken && config.anonymousModeEnabled) {
            // Prompt for screen name
            this.promptForScreenName();
        }

        // Continue with existing init logic
        await this.initAudio();
        this.connectWebSocket();
    }

    promptForScreenName() {
        // Show modal/prompt for screen name
        const screenName = prompt('Choose a screen name:');
        if (!screenName) {
            alert('Screen name is required');
            this.promptForScreenName();
            return;
        }

        this.screenName = screenName;
        this.isAnonymous = true;
    }

    connectWebSocket() {
        // Existing connection logic
        this.ws = new WebSocket(this.config.websocketUrl);

        this.ws.onopen = () => {
            console.log('WebSocket connected');

            // NEW: Send authentication or screen name
            if (this.accessToken) {
                this.ws.send(JSON.stringify({
                    type: 'authenticate',
                    token: this.accessToken
                }));
            } else if (this.isAnonymous && this.screenName) {
                this.ws.send(JSON.stringify({
                    type: 'set_screen_name',
                    screen_name: this.screenName
                }));
            }
        };

        this.ws.onmessage = (event) => {
            const message = JSON.parse(event.data);
            this.handleMessage(message);
        };
    }

    handleMessage(message) {
        switch (message.type) {
            case 'authenticated':
                console.log('Authenticated as', message.user.username);
                this.currentUser = message.user;
                break;

            case 'screen_name_set':
                console.log('Screen name set to', message.screen_name);
                break;

            case 'authentication_required':
                window.location.href = '/login.html';
                break;

            case 'error':
                if (message.code === 'screen_name_taken') {
                    alert(message.message);
                    this.promptForScreenName();
                } else {
                    console.error('Error:', message.message);
                }
                break;

            case 'user_speaking':
                // NEW: Display screen name
                this.updateSpeakingIndicator(message.screen_name, message.speaking);
                break;

            case 'history_response':
                // NEW: Messages include screen_name
                this.displayHistory(message.messages);
                break;

            // ... existing message handlers
        }
    }

    updateSpeakingIndicator(screenName, speaking) {
        const indicator = document.getElementById('speakingIndicator');
        if (speaking) {
            indicator.textContent = `${screenName} is speaking...`;
            indicator.style.display = 'block';
        } else {
            indicator.style.display = 'none';
        }
    }

    displayHistory(messages) {
        const historyContainer = document.getElementById('messageHistory');
        historyContainer.innerHTML = '';

        messages.forEach(msg => {
            const item = document.createElement('div');
            item.className = 'history-item';

            // NEW: Show screen name instead of client ID
            item.innerHTML = `
                <strong>${msg.screen_name}</strong>
                <span>${new Date(msg.timestamp).toLocaleTimeString()}</span>
                <button onclick="playMessage('${msg.client_id}')">Play</button>
            `;

            historyContainer.appendChild(item);
        });
    }

    scheduleTokenRefresh() {
        // Refresh token 5 minutes before expiration
        const expiresIn = 3600; // 1 hour in seconds
        const refreshIn = (expiresIn - 300) * 1000; // 55 minutes in ms

        this.tokenRefreshTimer = setTimeout(async () => {
            await this.refreshAccessToken();
        }, refreshIn);
    }

    async refreshAccessToken() {
        try {
            const response = await fetch('/auth/refresh.php', {
                method: 'POST',
                credentials: 'include' // Send refresh token cookie
            });

            const result = await response.json();
            if (result.success) {
                this.accessToken = result.tokens.access_token;
                localStorage.setItem('access_token', this.accessToken);
                this.scheduleTokenRefresh();
            } else {
                // Refresh failed, redirect to login
                window.location.href = '/login.html';
            }
        } catch (error) {
            console.error('Token refresh failed:', error);
            window.location.href = '/login.html';
        }
    }

    showAuthenticatedUI() {
        // Show logout button, passkey management, etc.
        const userMenu = document.getElementById('userMenu');
        userMenu.innerHTML = `
            <span>Logged in as ${this.currentUser.username}</span>
            <button onclick="managePasskeys()">Manage Passkeys</button>
            <button onclick="logout()">Logout</button>
        `;
    }

    async logout() {
        try {
            await fetch('/auth/logout.php', {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${this.accessToken}`
                },
                credentials: 'include'
            });
        } catch (error) {
            console.error('Logout error:', error);
        }

        // Clear local storage
        localStorage.removeItem('access_token');

        // Clear refresh timer
        if (this.tokenRefreshTimer) {
            clearTimeout(this.tokenRefreshTimer);
        }

        // Reload page
        window.location.href = '/';
    }
}

// Global functions for UI
function managePasskeys() {
    // Show passkey management modal
    // Implementation depends on your UI framework
}

function logout() {
    walkieTalkie.logout();
}
```

### 4.3 Passkey Management UI

Create modal/page for managing passkeys:

```html
<div id="passkeyModal" class="modal">
    <div class="modal-content">
        <h2>Manage Passkeys</h2>

        <div id="passkeyList">
            <!-- Dynamically populated -->
        </div>

        <button id="addPasskeyBtn">Add New Passkey</button>
        <button onclick="closePasskeyModal()">Close</button>
    </div>
</div>
```

```javascript
async function loadPasskeys() {
    const response = await fetch('/auth/user-info.php', {
        headers: {
            'Authorization': `Bearer ${walkieTalkie.accessToken}`
        }
    });

    const result = await response.json();
    if (!result.success) return;

    const list = document.getElementById('passkeyList');
    list.innerHTML = '';

    result.user.passkeys.forEach(passkey => {
        const item = document.createElement('div');
        item.className = 'passkey-item';
        item.innerHTML = `
            <strong>${passkey.nickname || 'Unnamed'}</strong>
            <span>Added ${new Date(passkey.created_at).toLocaleDateString()}</span>
            ${passkey.last_used ? `<span>Last used ${new Date(passkey.last_used).toLocaleDateString()}</span>` : ''}
            <button onclick="deletePasskey(${passkey.id})">Delete</button>
        `;
        list.appendChild(item);
    });
}

async function addPasskey() {
    const nickname = prompt('Name this passkey (e.g., "Work Laptop"):');
    if (!nickname) return;

    try {
        // Similar to registration flow, but for existing user
        const response = await fetch('/auth/passkeys.php?action=add', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${walkieTalkie.accessToken}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({nickname})
        });

        const {success, options} = await response.json();
        if (!success) throw new Error('Failed to start registration');

        // Create credential
        const credential = await navigator.credentials.create({
            publicKey: {
                ...options,
                challenge: base64ToArrayBuffer(options.challenge),
                user: {
                    ...options.user,
                    id: base64ToArrayBuffer(options.user.id)
                }
            }
        });

        // Verify credential
        const verifyResponse = await fetch('/auth/passkeys.php?action=verify', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${walkieTalkie.accessToken}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                credential: credentialToJSON(credential),
                nickname
            })
        });

        const result = await verifyResponse.json();
        if (result.success) {
            alert('Passkey added successfully!');
            loadPasskeys();
        }
    } catch (error) {
        alert('Failed to add passkey: ' + error.message);
    }
}

async function deletePasskey(credentialId) {
    if (!confirm('Are you sure you want to delete this passkey?')) return;

    try {
        const response = await fetch('/auth/passkeys.php?action=delete', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${walkieTalkie.accessToken}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({credential_id: credentialId})
        });

        const result = await response.json();
        if (result.success) {
            alert('Passkey deleted');
            loadPasskeys();
        }
    } catch (error) {
        alert('Failed to delete passkey: ' + error.message);
    }
}
```

## Phase 5: Security & Error Handling

### 5.1 Security Measures

**JWT Security**:
- Use strong secret (64+ bytes, `openssl rand -base64 64`)
- Sign with HS256 or RS256
- Validate signature and expiration on every request
- Keep access token lifetime short (1 hour)
- Store refresh token in HTTP-only, Secure, SameSite=Strict cookie
- Implement token rotation for refresh tokens

**Screen Name Validation**:
```php
function validateScreenName(string $name): bool
{
    $minLength = $_ENV['SCREEN_NAME_MIN_LENGTH'] ?? 2;
    $maxLength = $_ENV['SCREEN_NAME_MAX_LENGTH'] ?? 20;
    $pattern = $_ENV['SCREEN_NAME_PATTERN'] ?? '^[a-zA-Z0-9_-]+$';

    // Length check
    if (strlen($name) < $minLength || strlen($name) > $maxLength) {
        return false;
    }

    // Pattern check
    if (!preg_match("/$pattern/", $name)) {
        return false;
    }

    // Reserved names
    $reserved = ['admin', 'system', 'anonymous', 'guest', 'moderator'];
    if (in_array(strtolower($name), $reserved)) {
        return false;
    }

    return true;
}
```

**Rate Limiting**:
```php
// Add to authentication endpoints
function checkRateLimit(string $identifier, int $maxAttempts = 5, int $windowSeconds = 300): bool
{
    // Use APCu, Redis, or database
    $key = "rate_limit:$identifier";
    $attempts = apcu_fetch($key) ?: 0;

    if ($attempts >= $maxAttempts) {
        return false;
    }

    apcu_store($key, $attempts + 1, $windowSeconds);
    return true;
}

// In login-options.php, register-options.php
$ip = $_SERVER['REMOTE_ADDR'];
if (!checkRateLimit($ip)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts']);
    exit;
}
```

**CSRF Protection**:
```php
// Generate CSRF token on page load
function generateCSRFToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// Validate on POST requests
function validateCSRFToken(string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
```

**XSS Prevention**:
- Sanitize all user input (screen names, nicknames)
- Use `htmlspecialchars()` when displaying user-generated content
- Set Content-Security-Policy headers

**SQL Injection Prevention**:
- Always use prepared statements with PDO (already in place)
- Never concatenate user input into queries

### 5.2 Error Handling

**Authentication Errors**:
```javascript
// In frontend
async function handleAuthError(error) {
    if (error.message.includes('token')) {
        // Try to refresh token
        try {
            await walkieTalkie.refreshAccessToken();
            // Retry the failed request
        } catch (refreshError) {
            // Redirect to login
            window.location.href = '/login.html';
        }
    } else if (error.message.includes('screen_name_taken')) {
        alert('That name is already in use. Please choose another.');
        walkieTalkie.promptForScreenName();
    } else {
        alert('Authentication error: ' + error.message);
    }
}
```

**WebAuthn Errors**:
```javascript
try {
    const credential = await navigator.credentials.create({...});
} catch (error) {
    if (error.name === 'NotAllowedError') {
        alert('Passkey creation was cancelled or timed out');
    } else if (error.name === 'NotSupportedError') {
        alert('Your browser does not support passkeys');
    } else if (error.name === 'InvalidStateError') {
        alert('This passkey is already registered');
    } else {
        alert('Failed to create passkey: ' + error.message);
    }
}
```

**WebSocket Reconnection with Auth**:
```javascript
reconnectWebSocket() {
    console.log('Reconnecting WebSocket...');

    setTimeout(() => {
        this.connectWebSocket();

        // After reconnection, re-authenticate
        if (this.ws.readyState === WebSocket.OPEN) {
            if (this.accessToken) {
                this.ws.send(JSON.stringify({
                    type: 'authenticate',
                    token: this.accessToken
                }));
            } else if (this.isAnonymous && this.screenName) {
                this.ws.send(JSON.stringify({
                    type: 'set_screen_name',
                    screen_name: this.screenName
                }));
            }
        }
    }, 1000);
}
```

## Phase 6: Configuration & Documentation

### 6.1 Update config.php

Expose authentication settings to frontend:

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

header('Content-Type: application/json');

echo json_encode([
    'websocketUrl' => $_ENV['WEBSOCKET_URL'] ?? 'ws://localhost:8080',
    'debug' => ($_ENV['DEBUG'] ?? 'false') === 'true',
    'anonymousModeEnabled' => ($_ENV['ANONYMOUS_MODE_ENABLED'] ?? 'true') === 'true',
    'registrationEnabled' => ($_ENV['REGISTRATION_ENABLED'] ?? 'true') === 'true',
    'screenNameMinLength' => (int)($_ENV['SCREEN_NAME_MIN_LENGTH'] ?? 2),
    'screenNameMaxLength' => (int)($_ENV['SCREEN_NAME_MAX_LENGTH'] ?? 20),
]);
```

### 6.2 Documentation Updates

**Update README.md** with authentication section:

```markdown
## Authentication

This application supports WebAuthn/passkeys for passwordless authentication.

### Configuration

Add to your `.env` file:

\`\`\`env
# WebAuthn Configuration
WEBAUTHN_RP_NAME="Walkie Talkie"
WEBAUTHN_RP_ID=localhost                   # Change to your domain in production
WEBAUTHN_ORIGIN=http://localhost:3000      # Change to your URL in production

# JWT Configuration
JWT_SECRET=<generate-with-openssl>         # openssl rand -base64 64
JWT_ACCESS_EXPIRATION=3600
JWT_REFRESH_EXPIRATION=604800

# Authentication Settings
ANONYMOUS_MODE_ENABLED=true                # Allow unauthenticated users
REGISTRATION_ENABLED=true                  # Allow new user registration
\`\`\`

### Important Notes

1. **HTTPS Required**: WebAuthn requires HTTPS in production. Only localhost works with HTTP.
2. **RP ID**: Must match your domain exactly (no port, no protocol, no subdirectory)
3. **Origin**: Must match the full URL where users access the app
4. **JWT Secret**: Generate a strong secret and keep it confidential

### User Types

- **Authenticated Users**: Register with a passkey, get persistent screen name
- **Anonymous Users** (if enabled): Choose temporary screen name, lost on disconnect

### Screen Names

- Must be unique across all users (registered and anonymous)
- Length: 2-20 characters (configurable)
- Allowed characters: letters, numbers, underscore, hyphen
- Reserved names: admin, system, anonymous, guest, moderator

### Multi-device Support

Users can register multiple passkeys:
1. Login to your account
2. Click "Manage Passkeys"
3. Click "Add New Passkey"
4. Follow the prompts on your new device

### Disabling Anonymous Mode

Set `ANONYMOUS_MODE_ENABLED=false` to require authentication for all users.
```

**Create SECURITY.md**:

```markdown
# Security Considerations

## WebAuthn/Passkeys

- Uses FIDO2 standard for phishing-resistant authentication
- No passwords stored or transmitted
- Private keys never leave user's device
- Signature counter prevents replay attacks

## JWT Tokens

- Access tokens: Short-lived (1 hour), stored in localStorage
- Refresh tokens: Longer-lived (7 days), HTTP-only cookie
- Both signed with strong secret (HS256 or RS256)
- Refresh token rotation on use

## Database Security

- All queries use prepared statements (SQL injection protection)
- Passwords not applicable (passkey-only authentication)
- User IDs referenced by foreign keys

## Transport Security

- HTTPS required in production (enforced by browser for WebAuthn)
- WebSocket over TLS (wss://) recommended
- Trusted proxy configuration for correct IP detection

## Rate Limiting

- Authentication endpoints limited to 5 attempts per 5 minutes
- Prevents brute force and DoS attacks

## Input Validation

- Screen names validated with regex pattern
- XSS prevention via htmlspecialchars()
- Length limits enforced

## Session Management

- JWT tokens revoked on logout
- Expired tokens cleaned up automatically
- No server-side session state (stateless)

## Recommendations

1. Use HTTPS in production
2. Generate strong JWT secret (64+ bytes)
3. Keep dependencies updated (especially WebAuthn libraries)
4. Monitor authentication logs for suspicious activity
5. Implement IP-based rate limiting
6. Consider adding 2FA for high-security deployments
7. Regular security audits
```

## Phase 7: Testing & Migration

### 7.1 Testing Scenarios

Create `tests/authentication-test-plan.md`:

**Registration Testing**:
- [ ] Register new user with platform authenticator (TouchID, Windows Hello)
- [ ] Register new user with cross-platform authenticator (YubiKey, phone)
- [ ] Attempt to register with duplicate username (should fail)
- [ ] Attempt to register with invalid username format (should fail)
- [ ] Attempt to register with reserved username (should fail)
- [ ] Cancel passkey creation (should handle gracefully)
- [ ] Register on unsupported browser (should show error)

**Login Testing**:
- [ ] Login with registered passkey
- [ ] Login with wrong username (should fail)
- [ ] Login with unregistered passkey (should fail)
- [ ] Cancel passkey authentication (should handle gracefully)
- [ ] Login after clearing browser data (should work with passkey)

**Multi-device Testing**:
- [ ] Register passkey on desktop
- [ ] Add second passkey from mobile
- [ ] Login from both devices
- [ ] Delete passkey (should not affect other passkeys)
- [ ] Attempt to delete last passkey (should prevent)

**Anonymous Mode Testing**:
- [ ] Join as anonymous with custom screen name
- [ ] Attempt to use registered user's screen name (should fail)
- [ ] Attempt to use another anonymous user's screen name (should fail)
- [ ] Disconnect and reconnect (should lose screen name)
- [ ] Disable anonymous mode and attempt to connect (should redirect to login)

**JWT Token Testing**:
- [ ] Access token expires, automatic refresh
- [ ] Refresh token expires, redirect to login
- [ ] Logout revokes refresh token
- [ ] Use revoked token (should fail)
- [ ] Tamper with token (should fail validation)

**WebSocket Authentication Testing**:
- [ ] Connect with valid JWT token
- [ ] Connect with invalid token (should reject)
- [ ] Connect with expired token (should reject)
- [ ] Reconnect after disconnect (token still valid)
- [ ] Token expires during connection (should disconnect)

**Screen Name Uniqueness Testing**:
- [ ] Registered user joins (screen name reserved)
- [ ] Anonymous user attempts same name (should fail)
- [ ] User disconnects (screen name released)
- [ ] New user registers with previously used name (should work)

**Message History Testing**:
- [ ] Send messages as authenticated user (stores user_id and screen_name)
- [ ] Send messages as anonymous user (stores null user_id and screen_name)
- [ ] View history shows correct screen names
- [ ] Migrate old messages (shows client_id as screen_name)

**Security Testing**:
- [ ] CSRF protection on authentication endpoints
- [ ] Rate limiting blocks excessive attempts
- [ ] XSS in screen names prevented
- [ ] SQL injection attempts blocked
- [ ] JWT signature validation working

### 7.2 Database Migration

Create `migrations/001_add_authentication.php`:

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$dbPath = __DIR__ . '/../data/walkie-talkie.db';
$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Running authentication migration...\n";

try {
    $db->beginTransaction();

    // Create users table
    echo "Creating users table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            created_at INTEGER NOT NULL,
            last_login INTEGER,
            is_active INTEGER DEFAULT 1
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_username ON users(username)");

    // Create webauthn_credentials table
    echo "Creating webauthn_credentials table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS webauthn_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            credential_id TEXT UNIQUE NOT NULL,
            public_key TEXT NOT NULL,
            counter INTEGER NOT NULL DEFAULT 0,
            aaguid TEXT,
            transports TEXT,
            created_at INTEGER NOT NULL,
            last_used INTEGER,
            nickname TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_credential_user ON webauthn_credentials(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_credential_id ON webauthn_credentials(credential_id)");

    // Create jwt_refresh_tokens table
    echo "Creating jwt_refresh_tokens table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS jwt_refresh_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token_hash TEXT UNIQUE NOT NULL,
            expires_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            ip_address TEXT,
            user_agent TEXT,
            revoked INTEGER DEFAULT 0,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_token_hash ON jwt_refresh_tokens(token_hash)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_token_expiry ON jwt_refresh_tokens(expires_at)");

    // Update message_history table
    echo "Updating message_history table...\n";

    // Check if columns already exist
    $columns = $db->query("PRAGMA table_info(message_history)")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'name');

    if (!in_array('user_id', $columnNames)) {
        $db->exec("ALTER TABLE message_history ADD COLUMN user_id INTEGER");
        echo "Added user_id column\n";
    }

    if (!in_array('screen_name', $columnNames)) {
        $db->exec("ALTER TABLE message_history ADD COLUMN screen_name TEXT");
        echo "Added screen_name column\n";

        // Migrate existing data: set screen_name to truncated client_id
        $db->exec("UPDATE message_history SET screen_name = substr(client_id, 1, 20) WHERE screen_name IS NULL");
        echo "Migrated existing messages\n";
    }

    $db->exec("CREATE INDEX IF NOT EXISTS idx_message_user ON message_history(user_id)");

    $db->commit();
    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    $db->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
```

**Run migration**:
```bash
php migrations/001_add_authentication.php
```

**Rollback script** (if needed):

```php
<?php
// migrations/001_add_authentication_rollback.php

require_once __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$dbPath = __DIR__ . '/../data/walkie-talkie.db';
$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Rolling back authentication migration...\n";

try {
    $db->beginTransaction();

    // Drop new tables
    $db->exec("DROP TABLE IF EXISTS jwt_refresh_tokens");
    $db->exec("DROP TABLE IF EXISTS webauthn_credentials");
    $db->exec("DROP TABLE IF EXISTS users");

    // Note: Cannot drop columns in SQLite easily
    // Would need to recreate table without user_id/screen_name columns

    $db->commit();
    echo "Rollback completed!\n";

} catch (Exception $e) {
    $db->rollBack();
    echo "Rollback failed: " . $e->getMessage() . "\n";
    exit(1);
}
```

## Implementation Checklist

### Phase 1: Database & Config
- [ ] Create database migration script
- [ ] Add new tables (users, webauthn_credentials, jwt_refresh_tokens)
- [ ] Update message_history table
- [ ] Add authentication configuration to .env
- [ ] Update config.php to expose settings
- [ ] Run migration and verify

### Phase 2: Backend
- [ ] Install PHP dependencies (composer require)
- [ ] Create AuthManager.php utility class
- [ ] Implement register-options.php endpoint
- [ ] Implement register-verify.php endpoint
- [ ] Implement login-options.php endpoint
- [ ] Implement login-verify.php endpoint
- [ ] Implement refresh.php endpoint
- [ ] Implement logout.php endpoint
- [ ] Implement user-info.php endpoint
- [ ] Implement passkeys.php endpoint
- [ ] Test all endpoints with Postman/curl

### Phase 3: WebSocket
- [ ] Add authentication properties to WebSocketServer
- [ ] Implement authenticateConnection() method
- [ ] Implement setAnonymousScreenName() method
- [ ] Implement getConnectionIdentity() method
- [ ] Update onOpen() to check auth requirement
- [ ] Add 'authenticate' message handler
- [ ] Add 'set_screen_name' message handler
- [ ] Update joinChannel() to require identity
- [ ] Update message broadcasting to include screen_name
- [ ] Update saveMessage() to store user_id and screen_name
- [ ] Update getChannelHistory() to return screen_name
- [ ] Update onClose() to cleanup authentication state
- [ ] Test WebSocket authentication flow

### Phase 4: Frontend
- [ ] Create login.html page
- [ ] Create auth.js with WebAuthn helpers
- [ ] Update walkie-talkie.js init() for authentication
- [ ] Add token storage and management
- [ ] Add screen name prompt for anonymous users
- [ ] Update WebSocket connection to send authentication
- [ ] Add message handlers for auth events
- [ ] Update UI to display screen names
- [ ] Add logout functionality
- [ ] Create passkey management UI
- [ ] Add token refresh logic
- [ ] Test full registration/login flow

### Phase 5: Security
- [ ] Implement rate limiting
- [ ] Add CSRF protection
- [ ] Validate screen names thoroughly
- [ ] Add XSS prevention
- [ ] Test JWT validation
- [ ] Test token revocation
- [ ] Security audit

### Phase 6: Documentation
- [ ] Update README.md with authentication section
- [ ] Create SECURITY.md
- [ ] Document configuration options
- [ ] Add setup instructions
- [ ] Create troubleshooting guide

### Phase 7: Testing
- [ ] Test registration flow (multiple scenarios)
- [ ] Test login flow (multiple scenarios)
- [ ] Test multi-device support
- [ ] Test anonymous mode
- [ ] Test screen name uniqueness
- [ ] Test JWT token lifecycle
- [ ] Test WebSocket authentication
- [ ] Test message history with screen names
- [ ] Test error handling
- [ ] Load testing with authenticated users

## Deployment Considerations

### Production Checklist
- [ ] Generate strong JWT secret (openssl rand -base64 64)
- [ ] Set `WEBAUTHN_RP_ID` to production domain
- [ ] Set `WEBAUTHN_ORIGIN` to production HTTPS URL
- [ ] Enable HTTPS (required for WebAuthn)
- [ ] Set secure cookie flags (Secure, HttpOnly, SameSite)
- [ ] Configure trusted proxies if behind load balancer
- [ ] Set `ANONYMOUS_MODE_ENABLED` based on requirements
- [ ] Set `REGISTRATION_ENABLED` based on requirements
- [ ] Set up monitoring for authentication failures
- [ ] Set up log rotation for auth logs
- [ ] Back up database regularly
- [ ] Test passkey functionality on target devices/browsers

### Browser Compatibility
- Chrome 67+
- Firefox 60+
- Safari 13+
- Edge 18+

### Limitations
- WebAuthn requires HTTPS (except localhost)
- Platform authenticators tied to specific device
- Users must have compatible authenticator (biometric, security key, or platform)

## Future Enhancements

### Possible Additions
- Email verification for account recovery
- Account recovery options (recovery codes)
- Channel ownership and permissions
- Private channels (invite-only)
- User blocking/muting
- Admin panel for user management
- Audit logs for security events
- Two-factor authentication (in addition to passkeys)
- OAuth integration (Google, GitHub, etc.)
- Passwordless email magic links (alternative to passkeys)

---

## Summary

This implementation adds enterprise-grade WebAuthn/passkeys authentication to the walkie-talkie application with:

✅ **Passwordless authentication** using FIDO2 standard
✅ **Multi-device support** with multiple passkeys per user
✅ **Optional anonymous mode** with temporary screen names
✅ **JWT-based sessions** optimized for WebSocket architecture
✅ **Unique screen names** enforced across all users
✅ **Security best practices** (rate limiting, CSRF, XSS prevention)
✅ **Backward compatibility** with existing message history
✅ **Production-ready** configuration and deployment guide

The phased approach allows for incremental implementation and testing, with clear rollback procedures if needed.
