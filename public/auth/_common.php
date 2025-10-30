<?php
/**
 * Walkie Talkie PWA - Common Authentication Functions
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
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/AuthManager.php';

use WalkieTalkie\AuthManager;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA;
use Cose\Algorithm\Signature\RSA;

// Load environment
if (file_exists(__DIR__ . '/../../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->load();
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Send JSON response
 */
function sendJson(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get JSON input
 */
function getJsonInput(): ?array
{
    $input = file_get_contents('php://input');
    return json_decode($input, true);
}

/**
 * Get relying party entity
 */
function getRelyingParty(): PublicKeyCredentialRpEntity
{
    return PublicKeyCredentialRpEntity::create(
        $_ENV['WEBAUTHN_RP_NAME'] ?? 'Walkie Talkie',
        $_ENV['WEBAUTHN_RP_ID'] ?? 'localhost',
        null
    );
}

/**
 * Get algorithm manager for COSE
 */
function getAlgorithmManager(): Manager
{
    $manager = Manager::create();
    $manager->add(ECDSA\ES256::create());
    $manager->add(ECDSA\ES384::create());
    $manager->add(ECDSA\ES512::create());
    $manager->add(RSA\RS256::create());
    $manager->add(RSA\RS384::create());
    $manager->add(RSA\RS512::create());
    return $manager;
}

/**
 * Get attestation statement support manager
 */
function getAttestationSupportManager(): AttestationStatementSupportManager
{
    $manager = AttestationStatementSupportManager::create();
    $manager->add(NoneAttestationStatementSupport::create());
    return $manager;
}

/**
 * Generate random challenge
 */
function generateChallenge(): string
{
    return base64_encode(random_bytes(32));
}

/**
 * Get client IP address
 */
function getClientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Get user agent
 */
function getUserAgent(): string
{
    return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
}

/**
 * Check if registration is enabled
 */
function isRegistrationEnabled(): bool
{
    return ($_ENV['REGISTRATION_ENABLED'] ?? 'true') === 'true';
}

/**
 * Check if anonymous mode is enabled
 */
function isAnonymousModeEnabled(): bool
{
    return ($_ENV['ANONYMOUS_MODE_ENABLED'] ?? 'true') === 'true';
}

/**
 * Get authorization header token
 */
function getBearerToken(): ?string
{
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return $matches[1];
    }

    return null;
}

/**
 * Require authentication
 */
function requireAuth(): array
{
    $token = getBearerToken();
    if (!$token) {
        sendJson(['success' => false, 'error' => 'No authorization token provided'], 401);
    }

    $payload = AuthManager::validateAccessToken($token);
    if (!$payload) {
        sendJson(['success' => false, 'error' => 'Invalid or expired token'], 401);
    }

    return $payload;
}

/**
 * Rate limiting check (simple in-memory implementation)
 */
function checkRateLimit(string $identifier, int $maxAttempts = 5, int $windowSeconds = 300): bool
{
    // For production, use Redis or database
    // This is a basic implementation using APCu if available
    if (!function_exists('apcu_fetch')) {
        return true; // No rate limiting if APCu not available
    }

    $key = "rate_limit:$identifier";
    $attempts = apcu_fetch($key) ?: 0;

    if ($attempts >= $maxAttempts) {
        return false;
    }

    apcu_store($key, $attempts + 1, $windowSeconds);
    return true;
}
