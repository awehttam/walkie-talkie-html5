<?php
/**
 * Walkie Talkie PWA - WebAuthn Login Options
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
