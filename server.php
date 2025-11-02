<?php
/**
 * Walkie Talkie PWA - WebSocket Server Daemon
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

require_once 'vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use WalkieTalkie\WebSocketServer;
use WalkieTalkie\PluginManager;
use Dotenv\Dotenv;

class WalkieTalkieDaemon {
    private $pidFile;
    private $logFile;
    private $host;
    private $port;
    private $debug;
    private $quiet;

    public function __construct($quiet = false) {
        $this->quiet = $quiet;
        // Load environment variables
        if (file_exists(__DIR__ . '/.env')) {
            $dotenv = Dotenv::createImmutable(__DIR__);
            $dotenv->load();
        }

        $this->host = $_ENV['WEBSOCKET_HOST'] ?? 'localhost';
        $this->port = (int)($_ENV['WEBSOCKET_PORT'] ?? 8080);
        $this->debug = filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->pidFile = __DIR__ . '/walkie-talkie.pid';
        $this->logFile = __DIR__ . '/walkie-talkie.log';
    }

    public function isRunning() {
        if (!file_exists($this->pidFile)) {
            return false;
        }

        $pid = trim(file_get_contents($this->pidFile));
        if (!$pid || !is_numeric($pid)) {
            return false;
        }

        // Check if process is actually running
        if (PHP_OS_FAMILY === 'Windows') {
            exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output);
            return count($output) > 1;
        } else {
            return posix_kill($pid, 0);
        }
    }

    public function start($complainIfRunning=true) {
        if ($this->isRunning()) {
            if($complainIfRunning)
                $this->log("Daemon already running, exiting");
            exit(0);
        }

        $this->log("Starting Walkie Talkie daemon on {$this->host}:{$this->port}");

        // Fork process on Unix-like systems
        if (PHP_OS_FAMILY !== 'Windows') {
            $pid = pcntl_fork();
            if ($pid == -1) {
                die("Could not fork process\n");
            } elseif ($pid) {
                // Parent process
                exit(0);
            }

            // Child process
            posix_setsid();

            // Change working directory
            chdir(__DIR__);

            // Close file descriptors
            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);

            // Redirect output to log file
            $stdout = fopen($this->logFile, 'a');
            $stderr = fopen($this->logFile, 'a');
        }

        // Save PID
        file_put_contents($this->pidFile, getmypid());

        // Set up signal handlers on Unix-like systems
        if (PHP_OS_FAMILY !== 'Windows') {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }

        $this->runServer();
    }

    public function stop() {
        if (!$this->isRunning()) {
            $this->log("Daemon not running");
            return;
        }

        $pid = trim(file_get_contents($this->pidFile));
        $this->log("Stopping daemon (PID: $pid)");

        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /PID $pid /F");
        } else {
            posix_kill($pid, SIGTERM);
        }

        unlink($this->pidFile);
    }

    public function handleSignal($signal) {
        $this->log("Received signal $signal, shutting down gracefully");
        unlink($this->pidFile);
        exit(0);
    }

    private function runServer() {
        try {
            // Initialize plugin manager
            $pluginManager = null;
            $pluginsEnabled = filter_var($_ENV['PLUGINS_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);

            if ($pluginsEnabled) {
                $this->log("Initializing plugin system...");
                $pluginsPath = __DIR__ . '/' . ($_ENV['PLUGINS_PATH'] ?? 'plugins/');
                $pluginManager = new PluginManager($pluginsPath);

                // Load plugins from directory
                $pluginManager->loadPluginsFromDirectory();

                // Initialize all plugins
                $pluginManager->initializeAll();
            } else {
                $this->log("Plugin system disabled");
            }

            // Create WebSocket server with plugin manager
            $wsServer = new WebSocketServer($pluginManager);

            // Pass server instance and database connection to plugin manager if plugins are enabled
            if ($pluginManager) {
                $pluginManager->setServer($wsServer);

                if (property_exists($wsServer, 'db')) {
                    $reflection = new ReflectionClass($wsServer);
                    $dbProperty = $reflection->getProperty('db');
                    $dbProperty->setAccessible(true);
                    $db = $dbProperty->getValue($wsServer);
                    $pluginManager->setDatabase($db);
                }
            }

            $server = IoServer::factory(
                new HttpServer(
                    new WsServer($wsServer)
                ),
                $this->port,
                $this->host
            );

            $this->log("Walkie Talkie WebSocket server started successfully");
            if ($this->debug) {
                $this->log("Debug mode enabled");
            }

            // Register shutdown handler for plugins
            if ($pluginManager) {
                register_shutdown_function(function() use ($pluginManager) {
                    $pluginManager->shutdownAll();
                });
            }

            $server->run();
        } catch (Exception $e) {
            $this->log("Error running server: " . $e->getMessage());
            unlink($this->pidFile);
            exit(1);
        }
    }

    private function log($message, $quietOk = false) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);

        // Echo to console unless:
        // 1. We're in quiet mode AND the message allows quiet suppression
        // Note: We always log to file regardless of quiet mode
        if (PHP_SAPI === 'cli') {
            if (!$this->quiet || !$quietOk) {
                echo $logMessage;
            }
        }
    }
}

// Handle command line arguments
$quiet = in_array('--quiet', $argv) || in_array('-q', $argv);
$daemon = new WalkieTalkieDaemon($quiet);

// Remove quiet flags from argv for command parsing
$args = array_values(array_filter($argv, function($arg) {
    return $arg !== '--quiet' && $arg !== '-q';
}));

$command = $args[1] ?? 'start';

switch ($command) {
    case 'start':
        $daemon->start(!$quiet);
        break;
    case 'stop':
        $daemon->stop();
        break;
    case 'restart':
        $daemon->stop();
        sleep(2);
        $daemon->start();
        break;
    case 'status':
        if ($daemon->isRunning()) {
            echo "Daemon is running\n";
            exit(0);
        } else {
            echo "Daemon is not running\n";
            exit(1);
        }
        break;
    default:
        echo "Usage: php server.php {start|stop|restart|status} [--quiet|-q]\n";
        echo "\nOptions:\n";
        echo "  --quiet, -q    Suppress 'already running' messages (useful for cron)\n";
        exit(1);
}