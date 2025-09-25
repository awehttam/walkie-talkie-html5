<?php

require_once 'vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
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
    'debug' => filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)
]);