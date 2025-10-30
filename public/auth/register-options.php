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
 * WebAuthn Registration - Step 1: Generate Options
 *
 * Generates a registration challenge for creating a new passkey
 */

require_once __DIR__ . '/_common.php';

use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialDescriptor;

// Check if registration is enabled
if (!isRegistrationEnabled()) {
    sendJson(['success' => false, 'error' => 'Registration is currently disabled'], 403);
}

// Get input
$input = getJsonInput();
$username = $input['username'] ?? '';

// Validate input
if (empty($username)) {
    sendJson(['success' => false, 'error' => 'Username is required'], 400);
}

// Rate limiting
if (!checkRateLimit("register:$username", 5, 300)) {
    sendJson(['success' => false, 'error' => 'Too many attempts. Please try again later.'], 429);
}

// Validate screen name format
if (!WalkieTalkie\AuthManager::validateScreenName($username)) {
    sendJson(['success' => false, 'error' => 'Invalid screen name format. Use 2-20 characters (letters, numbers, underscore, hyphen only)'], 400);
}

// Check if username is available
if (!WalkieTalkie\AuthManager::isScreenNameAvailable($username)) {
    sendJson(['success' => false, 'error' => 'Screen name already taken'], 400);
}

// Generate user ID (temporary for registration)
$userId = bin2hex(random_bytes(16));

// Create user entity
$userEntity = PublicKeyCredentialUserEntity::create(
    $username,
    $userId,
    $username,
    null
);

// Create relying party entity
$rpEntity = getRelyingParty();

// Generate challenge
$challenge = generateChallenge();

// Supported algorithms (ES256, RS256)
$pubKeyCredParams = [
    PublicKeyCredentialParameters::create('public-key', -7),  // ES256
    PublicKeyCredentialParameters::create('public-key', -257) // RS256
];

// Authenticator selection criteria
$authenticatorSelection = AuthenticatorSelectionCriteria::create(
    null, // authenticatorAttachment (null = no preference)
    AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
    AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_DISCOURAGED
);

// Create options
$options = PublicKeyCredentialCreationOptions::create(
    $rpEntity,
    $userEntity,
    $challenge,
    $pubKeyCredParams
)
    ->setTimeout((int)($_ENV['WEBAUTHN_TIMEOUT'] ?? 60000))
    ->setAuthenticatorSelection($authenticatorSelection)
    ->setAttestation(PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE);

// Store challenge and username in session for verification
$_SESSION['webauthn_challenge'] = $challenge;
$_SESSION['webauthn_user_id'] = $userId;
$_SESSION['webauthn_username'] = $username;

// Return options
sendJson([
    'success' => true,
    'options' => [
        'challenge' => $challenge,
        'rp' => [
            'name' => $rpEntity->name,
            'id' => $rpEntity->id
        ],
        'user' => [
            'id' => base64_encode($userId),
            'name' => $username,
            'displayName' => $username
        ],
        'pubKeyCredParams' => array_map(fn($p) => [
            'type' => $p->type,
            'alg' => $p->alg
        ], $pubKeyCredParams),
        'timeout' => $options->timeout,
        'attestation' => $options->attestation,
        'authenticatorSelection' => [
            'residentKey' => $authenticatorSelection->residentKey,
            'userVerification' => $authenticatorSelection->userVerification
        ]
    ]
]);
