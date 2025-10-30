#!/usr/bin/env php
<?php
/**
 * Walkie Talkie CLI - Audio Transmission Tool
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
 * Send audio files to WebSocket server channels
 *
 * Usage:
 *   php walkie-cli.php send <audio-file> --channel <id> [options]
 *
 * Examples:
 *   # Send with screen name
 *   php walkie-cli.php send announcement.wav --channel 1 --screen-name "AudioBot"
 *
 *   # Send with JWT token
 *   php walkie-cli.php send announcement.wav --channel 1 --token "eyJhbGc..."
 *
 *   # Send from stdin
 *   cat audio.pcm | php walkie-cli.php send - --channel 1 --screen-name "Bot"
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/AudioProcessor.php';
require_once __DIR__ . '/lib/WebSocketClient.php';
require_once __DIR__ . '/lib/AudioSender.php';

use WalkieTalkie\CLI\AudioProcessor;
use WalkieTalkie\CLI\WebSocketClient;
use WalkieTalkie\CLI\AudioSender;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

/**
 * Display usage information
 */
function showUsage(): void
{
    echo <<<USAGE
Walkie Talkie CLI - Audio Transmission Tool

Usage:
    php walkie-cli.php send <audio-file> [options]

Arguments:
    audio-file          Path to audio file (WAV or PCM16), or "-" for stdin

Required Options:
    --channel <id>      Channel to send to

Authentication (one required):
    --token <jwt>       JWT access token for authentication
    --screen-name <n>   Screen name for anonymous mode

Optional:
    --url <url>         WebSocket server URL (default: ws://localhost:8080)
    --sample-rate <r>   Sample rate for raw PCM16 (default: 48000)
    --chunk-size <b>    Chunk size in bytes (default: 4096)
    --verbose           Show detailed progress
    --help              Show this help message

Examples:
    # Send audio with screen name
    php walkie-cli.php send announcement.wav --channel 1 --screen-name "AudioBot"

    # Send audio with JWT token
    php walkie-cli.php send message.wav --channel 1 --token "eyJhbGc..."

    # Send raw PCM16 from stdin
    cat audio.pcm | php walkie-cli.php send - --channel 1 --screen-name "Bot" --sample-rate 48000

    # Custom WebSocket URL
    php walkie-cli.php send msg.wav --channel 1 --screen-name "Bot" --url "ws://example.com:8080"

Exit Codes:
    0  - Success
    1  - Invalid arguments
    2  - Connection failed
    3  - Authentication failed
    4  - Audio processing error
    5  - Transmission failed

USAGE;
}

/**
 * Parse command line arguments
 */
function parseArgs(array $argv): array
{
    $args = [
        'command' => null,
        'audio_file' => null,
        'channel' => null,
        'token' => null,
        'screen_name' => null,
        'url' => $_ENV['CLI_DEFAULT_WEBSOCKET_URL'] ?? 'ws://localhost:8080',
        'sample_rate' => (int)($_ENV['CLI_DEFAULT_SAMPLE_RATE'] ?? 48000),
        'chunk_size' => (int)($_ENV['CLI_DEFAULT_CHUNK_SIZE'] ?? 4096),
        'verbose' => false,
        'help' => false
    ];

    $i = 1; // Skip script name
    while ($i < count($argv)) {
        $arg = $argv[$i];

        if ($arg === '--help' || $arg === '-h') {
            $args['help'] = true;
            $i++;
        } elseif ($arg === '--verbose' || $arg === '-v') {
            $args['verbose'] = true;
            $i++;
        } elseif ($arg === '--channel') {
            $args['channel'] = $argv[++$i] ?? null;
            $i++;
        } elseif ($arg === '--token') {
            $args['token'] = $argv[++$i] ?? null;
            $i++;
        } elseif ($arg === '--screen-name') {
            $args['screen_name'] = $argv[++$i] ?? null;
            $i++;
        } elseif ($arg === '--url') {
            $args['url'] = $argv[++$i] ?? null;
            $i++;
        } elseif ($arg === '--sample-rate') {
            $args['sample_rate'] = (int)($argv[++$i] ?? 0);
            $i++;
        } elseif ($arg === '--chunk-size') {
            $args['chunk_size'] = (int)($argv[++$i] ?? 0);
            $i++;
        } elseif ($args['command'] === null) {
            $args['command'] = $arg;
            $i++;
        } elseif ($args['audio_file'] === null) {
            $args['audio_file'] = $arg;
            $i++;
        } else {
            echo "Error: Unknown argument: {$arg}\n\n";
            showUsage();
            exit(1);
        }
    }

    return $args;
}

/**
 * Validate arguments
 */
function validateArgs(array $args): bool
{
    if ($args['help']) {
        return true;
    }

    if ($args['command'] !== 'send') {
        echo "Error: Invalid command: {$args['command']}\n";
        echo "Available commands: send\n\n";
        return false;
    }

    if ($args['audio_file'] === null) {
        echo "Error: Audio file is required\n\n";
        return false;
    }

    if ($args['channel'] === null) {
        echo "Error: --channel is required\n\n";
        return false;
    }

    if ($args['token'] === null && $args['screen_name'] === null) {
        echo "Error: Either --token or --screen-name is required\n\n";
        return false;
    }

    if ($args['sample_rate'] <= 0) {
        echo "Error: Invalid sample rate: {$args['sample_rate']}\n\n";
        return false;
    }

    if ($args['chunk_size'] <= 0) {
        echo "Error: Invalid chunk size: {$args['chunk_size']}\n\n";
        return false;
    }

    return true;
}

/**
 * Main entry point
 */
function main(array $argv): int
{
    // Parse arguments
    $args = parseArgs($argv);

    // Show help
    if ($args['help']) {
        showUsage();
        return 0;
    }

    // Validate arguments
    if (!validateArgs($args)) {
        showUsage();
        return 1;
    }

    // Execute command
    if ($args['command'] === 'send') {
        return sendAudio($args);
    }

    echo "Error: Unknown command: {$args['command']}\n";
    return 1;
}

/**
 * Send audio command
 */
function sendAudio(array $args): int
{
    try {
        // Connect to WebSocket server
        if ($args['verbose']) {
            echo "Connecting to {$args['url']}...\n";
        }

        $client = new WebSocketClient($args['url'], $args['verbose']);

        // Prepare authentication
        $auth = [];
        if ($args['token']) {
            $auth['token'] = $args['token'];
        } elseif ($args['screen_name']) {
            $auth['screen_name'] = $args['screen_name'];
        }

        // Connect
        try {
            $client->connect($auth);
        } catch (Exception $e) {
            echo "Error: Failed to connect to server\n";
            echo "  {$e->getMessage()}\n";
            return 2;
        }

        // Create audio sender
        $sender = new AudioSender($client, $args['verbose']);

        // Send audio
        try {
            $sender->sendAudio(
                $args['audio_file'],
                $args['channel'],
                [
                    'sample_rate' => $args['sample_rate'],
                    'chunk_size' => $args['chunk_size'],
                    'verbose' => $args['verbose']
                ]
            );
        } catch (Exception $e) {
            echo "Error: Failed to send audio\n";
            echo "  {$e->getMessage()}\n";
            $client->close();
            return 5;
        }

        // Close connection
        $client->close();

        return 0;

    } catch (Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        return 4;
    }
}

// Run
exit(main($argv));
