<?php
/**
 * Walkie Talkie PWA - Configuration
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

require_once '../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Set content type to JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Get WebSocket URL from environment or construct from host/port
$websocketUrl = $_ENV['WEBSOCKET_URL'] ?? null;

if (!$websocketUrl) {
    $host = $_ENV['WEBSOCKET_HOST'] ?? 'localhost';
    $port = $_ENV['WEBSOCKET_PORT'] ?? '8080';
    $websocketUrl = "ws://{$host}:{$port}";
}

// Return configuration as JSON
echo json_encode([
    'websocketUrl' => $websocketUrl,
    'debug' => filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'anonymousModeEnabled' => filter_var($_ENV['ANONYMOUS_MODE_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
    'registrationEnabled' => filter_var($_ENV['REGISTRATION_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
    'screenNameMinLength' => (int)($_ENV['SCREEN_NAME_MIN_LENGTH'] ?? 2),
    'screenNameMaxLength' => (int)($_ENV['SCREEN_NAME_MAX_LENGTH'] ?? 20),
    'screenNamePattern' => $_ENV['SCREEN_NAME_PATTERN'] ?? '^[a-zA-Z0-9_-]+$'
]);