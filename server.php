<?php

require_once 'vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use WalkieTalkie\WebSocketServer;
use Dotenv\Dotenv;

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Get configuration from environment variables
$host = $_ENV['WEBSOCKET_HOST'] ?? 'localhost';
$port = (int)($_ENV['WEBSOCKET_PORT'] ?? 8080);
$debug = filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new WebSocketServer()
        )
    ),
    $port,
    $host
);

echo "Walkie Talkie WebSocket server running on {$host}:{$port}\n";
if ($debug) {
    echo "Debug mode enabled\n";
}

$server->run();