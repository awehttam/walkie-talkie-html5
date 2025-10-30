#!/usr/bin/env php
<?php
/**
 * Welcome Message Manager CLI
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
 * Manage automated welcome messages that play when users connect or join channels
 *
 * Usage:
 *   php welcome-manager.php <command> [options]
 *
 * Commands:
 *   list        List all welcome messages
 *   add         Add new welcome message
 *   delete      Delete welcome message
 *   enable      Enable welcome message
 *   disable     Disable welcome message
 *   test        Test a welcome message
 *   stats       Show statistics
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/WelcomeManager.php';
require_once __DIR__ . '/lib/AudioProcessor.php';
require_once __DIR__ . '/lib/WebSocketClient.php';
require_once __DIR__ . '/lib/AudioSender.php';

use WalkieTalkie\CLI\WelcomeManager;
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
Welcome Message Manager

Usage:
    php welcome-manager.php <command> [options]

Commands:
    list                List all welcome messages
    add                 Add new welcome message
    delete              Delete welcome message
    enable              Enable welcome message
    disable             Disable welcome message
    test                Test a welcome message
    stats               Show statistics

Add Options:
    --name <name>       Friendly name for the message (required)
    --audio <file>      Path to audio file (required)
    --trigger <type>    Trigger type: connect, channel_join, both (required)
    --channel <id>      Specific channel (optional, omit for all channels)

Delete/Enable/Disable Options:
    --id <id>           Message ID (required)

Test Options:
    --id <id>           Message ID to test (required)
    --channel <id>      Channel to test on (default: 1)
    --screen-name <n>   Screen name for test (default: TestBot)
    --url <url>         WebSocket URL (default: ws://localhost:8080)

Examples:
    # List all messages
    php welcome-manager.php list

    # Add server welcome message
    php welcome-manager.php add \\
        --name "Server Welcome" \\
        --audio data/audio/welcome.wav \\
        --trigger connect

    # Add channel-specific welcome
    php welcome-manager.php add \\
        --name "Channel 1 Welcome" \\
        --audio data/audio/channel1.wav \\
        --trigger channel_join \\
        --channel 1

    # Delete message
    php welcome-manager.php delete --id 3

    # Test message
    php welcome-manager.php test --id 1 --channel 1

USAGE;
}

/**
 * Parse command line arguments
 */
function parseArgs(array $argv): array
{
    $args = [
        'command' => null,
        'id' => null,
        'name' => null,
        'audio' => null,
        'trigger' => null,
        'channel' => null,
        'screen_name' => 'TestBot',
        'url' => $_ENV['CLI_DEFAULT_WEBSOCKET_URL'] ?? 'ws://localhost:8080',
        'help' => false
    ];

    $i = 1;
    while ($i < count($argv)) {
        $arg = $argv[$i];

        if ($arg === '--help' || $arg === '-h') {
            $args['help'] = true;
            $i++;
        } elseif ($arg === '--id') {
            $args['id'] = (int)($argv[++$i] ?? 0);
            $i++;
        } elseif ($arg === '--name') {
            $args['name'] = $argv[++$i] ?? null;
            $i++;
        } elseif ($arg === '--audio') {
            $args['audio'] = $argv[++$i] ?? null;
            $i++;
        } elseif ($arg === '--trigger') {
            $args['trigger'] = $argv[++$i] ?? null;
            $i++;
        } elseif ($arg === '--channel') {
            $args['channel'] = $argv[++$i] ?? null;
            $i++;
        } elseif ($arg === '--screen-name') {
            $args['screen_name'] = $argv[++$i] ?? null;
            $i++;
        } elseif ($arg === '--url') {
            $args['url'] = $argv[++$i] ?? null;
            $i++;
        } elseif ($args['command'] === null) {
            $args['command'] = $arg;
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
 * Get database connection
 */
function getDatabase(): PDO
{
    $dbPath = __DIR__ . '/../data/walkie-talkie.db';

    if (!file_exists($dbPath)) {
        echo "Error: Database not found at {$dbPath}\n";
        echo "Please run the server at least once to create the database.\n";
        exit(1);
    }

    return new PDO('sqlite:' . $dbPath);
}

/**
 * List command
 */
function listMessages(WelcomeManager $manager): int
{
    $messages = $manager->getMessages();

    if (empty($messages)) {
        echo "No welcome messages found.\n";
        echo "Use 'add' command to create one.\n";
        return 0;
    }

    echo "Welcome Messages:\n";
    echo str_repeat('-', 120) . "\n";
    printf("%-4s %-25s %-15s %-10s %-8s %-8s %s\n",
        "ID", "Name", "Trigger", "Channel", "Enabled", "Played", "File");
    echo str_repeat('-', 120) . "\n";

    foreach ($messages as $msg) {
        $enabled = $msg['enabled'] ? 'yes' : 'no';
        $channel = $msg['channel'] ?? 'all';
        $fileName = basename($msg['audio_file']);

        printf("%-4d %-25s %-15s %-10s %-8s %-8d %s\n",
            $msg['id'],
            substr($msg['name'], 0, 25),
            $msg['trigger_type'],
            $channel,
            $enabled,
            $msg['play_count'],
            $fileName
        );
    }

    echo str_repeat('-', 120) . "\n";
    echo "Total: " . count($messages) . " messages\n";

    return 0;
}

/**
 * Add command
 */
function addMessage(WelcomeManager $manager, array $args): int
{
    // Validate required arguments
    if (!$args['name']) {
        echo "Error: --name is required\n";
        return 1;
    }

    if (!$args['audio']) {
        echo "Error: --audio is required\n";
        return 1;
    }

    if (!$args['trigger']) {
        echo "Error: --trigger is required\n";
        return 1;
    }

    try {
        $id = $manager->addMessage(
            $args['name'],
            $args['audio'],
            $args['trigger'],
            ['channel' => $args['channel']]
        );

        echo "Welcome message added successfully.\n";
        echo "  ID: {$id}\n";
        echo "  Name: {$args['name']}\n";
        echo "  Trigger: {$args['trigger']}\n";
        echo "  Channel: " . ($args['channel'] ?? 'all') . "\n";
        echo "\nNext step: Restart the WebSocket server\n";
        echo "  php server.php restart\n";

        return 0;

    } catch (Exception $e) {
        echo "Error: Failed to add message\n";
        echo "  {$e->getMessage()}\n";
        return 1;
    }
}

/**
 * Delete command
 */
function deleteMessage(WelcomeManager $manager, array $args): int
{
    if (!$args['id']) {
        echo "Error: --id is required\n";
        return 1;
    }

    $message = $manager->getMessage($args['id']);
    if (!$message) {
        echo "Error: Message not found (ID: {$args['id']})\n";
        return 1;
    }

    if ($manager->deleteMessage($args['id'])) {
        echo "Welcome message deleted successfully.\n";
        echo "  ID: {$args['id']}\n";
        echo "  Name: {$message['name']}\n";
        return 0;
    } else {
        echo "Error: Failed to delete message\n";
        return 1;
    }
}

/**
 * Enable command
 */
function enableMessage(WelcomeManager $manager, array $args): int
{
    if (!$args['id']) {
        echo "Error: --id is required\n";
        return 1;
    }

    if ($manager->enableMessage($args['id'])) {
        echo "Welcome message enabled.\n";
        return 0;
    } else {
        echo "Error: Message not found (ID: {$args['id']})\n";
        return 1;
    }
}

/**
 * Disable command
 */
function disableMessage(WelcomeManager $manager, array $args): int
{
    if (!$args['id']) {
        echo "Error: --id is required\n";
        return 1;
    }

    if ($manager->disableMessage($args['id'])) {
        echo "Welcome message disabled.\n";
        return 0;
    } else {
        echo "Error: Message not found (ID: {$args['id']})\n";
        return 1;
    }
}

/**
 * Test command
 */
function testMessage(WelcomeManager $manager, array $args): int
{
    if (!$args['id']) {
        echo "Error: --id is required\n";
        return 1;
    }

    // Get message
    $message = $manager->getMessage($args['id']);
    if (!$message) {
        echo "Error: Message not found (ID: {$args['id']})\n";
        return 1;
    }

    echo "Testing welcome message:\n";
    echo "  Name: {$message['name']}\n";
    echo "  Trigger: {$message['trigger_type']}\n";
    echo "  File: {$message['audio_file']}\n";
    echo "\n";

    try {
        // Connect to server
        echo "Connecting to {$args['url']}...\n";
        $client = new WebSocketClient($args['url'], true);
        $client->connect(['screen_name' => $args['screen_name']]);

        // Send audio
        $sender = new AudioSender($client, true);
        $sender->sendAudio($message['audio_file'], $args['channel'] ?? '1', ['verbose' => true]);

        // Close
        $client->close();

        echo "\nTest completed successfully.\n";
        return 0;

    } catch (Exception $e) {
        echo "\nError: Test failed\n";
        echo "  {$e->getMessage()}\n";
        return 1;
    }
}

/**
 * Stats command
 */
function showStats(WelcomeManager $manager): int
{
    $stats = $manager->getStatistics();

    echo "Welcome Message Statistics:\n";
    echo str_repeat('-', 60) . "\n";
    echo "Total messages:     {$stats['total']}\n";
    echo "  Enabled:          {$stats['enabled']}\n";
    echo "  Disabled:         {$stats['disabled']}\n";
    echo "\n";
    echo "By trigger type:\n";
    echo "  Connect:          {$stats['by_trigger']['connect']}\n";
    echo "  Channel Join:     {$stats['by_trigger']['channel_join']}\n";
    echo "  Both:             {$stats['by_trigger']['both']}\n";
    echo "\n";
    echo "Total plays:        {$stats['total_plays']}\n";

    return 0;
}

/**
 * Main entry point
 */
function main(array $argv): int
{
    // Parse arguments
    $args = parseArgs($argv);

    // Show help
    if ($args['help'] || $args['command'] === null) {
        showUsage();
        return $args['command'] === null ? 1 : 0;
    }

    // Get database
    try {
        $db = getDatabase();
        $manager = new WelcomeManager($db);
    } catch (Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        return 1;
    }

    // Execute command
    switch ($args['command']) {
        case 'list':
            return listMessages($manager);

        case 'add':
            return addMessage($manager, $args);

        case 'delete':
            return deleteMessage($manager, $args);

        case 'enable':
            return enableMessage($manager, $args);

        case 'disable':
            return disableMessage($manager, $args);

        case 'test':
            return testMessage($manager, $args);

        case 'stats':
            return showStats($manager);

        default:
            echo "Error: Unknown command: {$args['command']}\n\n";
            showUsage();
            return 1;
    }
}

// Run
exit(main($argv));
