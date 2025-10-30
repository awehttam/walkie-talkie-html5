<?php
/**
 * WebSocketClient - WebSocket Communication Library
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
 * Establishes and maintains WebSocket connections to the server
 * Supports JWT token and anonymous screen name authentication
 *
 * Usage:
 *   $client = new WebSocketClient('ws://localhost:8080');
 *   $client->connect(['screen_name' => 'Bot']);
 *   $client->send(['type' => 'join_channel', 'channel' => '1']);
 *   $response = $client->receive();
 *   $client->close();
 */

namespace WalkieTalkie\CLI;

use React\EventLoop\Loop;
use Ratchet\Client\WebSocket;

class WebSocketClient
{
    private string $url;
    private $connection = null;
    private $loop = null;
    private bool $connected = false;
    private array $receivedMessages = [];
    private ?\Exception $lastError = null;
    private bool $verbose;

    /**
     * Constructor
     *
     * @param string $url WebSocket URL (e.g., ws://localhost:8080)
     * @param bool $verbose Enable verbose output
     */
    public function __construct(string $url, bool $verbose = false)
    {
        $this->url = $url;
        $this->verbose = $verbose;
    }

    /**
     * Connect to WebSocket server
     *
     * @param array $auth Authentication data:
     *                    ['token' => 'jwt_token'] or
     *                    ['screen_name' => 'username']
     * @return bool True on success
     * @throws \Exception On connection failure
     */
    public function connect(array $auth = []): bool
    {
        if ($this->connected) {
            return true;
        }

        $this->loop = Loop::get();

        $connected = false;
        $error = null;

        if ($this->verbose) {
            echo "Connecting to {$this->url}...\n";
        }

        // Use Pawl's connect function directly
        \Ratchet\Client\connect($this->url, [], [], $this->loop)->then(
            function (WebSocket $conn) use (&$connected, $auth) {
                $this->connection = $conn;
                $this->connected = true;

                if ($this->verbose) {
                    echo "Connected.\n";
                }

                // Send authentication
                if (!empty($auth)) {
                    if (isset($auth['token'])) {
                        $authMsg = [
                            'type' => 'auth',
                            'token' => $auth['token']
                        ];
                    } elseif (isset($auth['screen_name'])) {
                        $authMsg = [
                            'type' => 'set_screen_name',
                            'screen_name' => $auth['screen_name']
                        ];
                    } else {
                        $authMsg = null;
                    }

                    if ($authMsg && $this->verbose) {
                        echo "Authenticating...\n";
                    }

                    if ($authMsg) {
                        $conn->send(json_encode($authMsg));
                    }
                }

                // Handle incoming messages
                $conn->on('message', function ($msg) {
                    $this->receivedMessages[] = $msg;
                });

                $conn->on('close', function ($code = null, $reason = null) {
                    $this->connected = false;
                    if ($this->verbose && $reason) {
                        echo "Connection closed: {$reason}\n";
                    }
                });

                $connected = true;
                $this->loop->stop();
            },
            function (\Exception $e) use (&$error) {
                $error = $e;
                $this->loop->stop();
            }
        );

        // Run event loop until connected or error
        $this->loop->run();

        if ($error) {
            throw new \Exception("Failed to connect: " . $error->getMessage());
        }

        if (!$connected) {
            throw new \Exception("Connection failed");
        }

        // Wait for auth response if we sent auth
        if (!empty($auth)) {
            $authResponse = $this->receive(5.0);
            if ($authResponse) {
                if (isset($authResponse['type'])) {
                    if ($authResponse['type'] === 'auth_success') {
                        if ($this->verbose) {
                            $name = $authResponse['screen_name'] ?? 'Unknown';
                            echo "Authenticated as: {$name}\n";
                        }
                    } elseif ($authResponse['type'] === 'error') {
                        throw new \Exception("Authentication failed: " . ($authResponse['message'] ?? 'Unknown error'));
                    }
                }
            }
        }

        return true;
    }

    /**
     * Send message to server
     *
     * @param array $message Message data (will be JSON-encoded)
     * @return bool True on success
     * @throws \Exception If not connected
     */
    public function send(array $message): bool
    {
        if (!$this->connected || !$this->connection) {
            throw new \Exception("Not connected");
        }

        $json = json_encode($message);
        $this->connection->send($json);

        // Process any incoming messages
        $this->tick(0.01);

        return true;
    }

    /**
     * Receive message from server
     *
     * @param float $timeout Timeout in seconds
     * @return array|null Message data (JSON-decoded), or null if timeout
     */
    public function receive(float $timeout = 1.0): ?array
    {
        if (!$this->connected) {
            return null;
        }

        $endTime = microtime(true) + $timeout;

        while (microtime(true) < $endTime) {
            // Check if we have buffered messages
            if (!empty($this->receivedMessages)) {
                $msg = array_shift($this->receivedMessages);
                $data = json_decode($msg, true);
                return $data ?: null;
            }

            // Process events
            $this->tick(0.05);

            if (!$this->connected) {
                break;
            }
        }

        return null;
    }

    /**
     * Process event loop for a short time
     *
     * @param float $duration Duration in seconds
     */
    private function tick(float $duration): void
    {
        if (!$this->loop) {
            return;
        }

        $timer = $this->loop->addTimer($duration, function () {
            $this->loop->stop();
        });

        $this->loop->run();
    }

    /**
     * Close connection
     */
    public function close(): void
    {
        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
        }
        $this->connected = false;

        if ($this->verbose) {
            echo "Connection closed.\n";
        }
    }

    /**
     * Check if connected
     *
     * @return bool True if connected
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Get WebSocket URL
     *
     * @return string URL
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct()
    {
        $this->close();
    }
}
