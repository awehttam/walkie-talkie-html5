<?php

namespace WalkieTalkie;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use SplObjectStorage;
use PDO;
use PDOException;

class WebSocketServer implements MessageComponentInterface
{
    protected $clients;
    protected $channels;
    protected $db;
    protected $activeTransmissions; // Buffer for active transmissions
    protected $trustedProxies = []; // List of trusted proxy IP addresses

    // Message history configuration
    protected $maxMessagesPerChannel = 10; // Maximum number of messages to keep per channel
    protected $maxMessageAge = 300; // Maximum message age in seconds (default: 5 minutes)

    public function __construct()
    {
        $this->clients = new SplObjectStorage;
        $this->channels = ['1' => new SplObjectStorage];
        $this->activeTransmissions = [];
        $this->loadConfiguration();
        $this->initDatabase();
    }

    private function loadConfiguration()
    {
        // Load configuration from environment variables
        $maxCount = $_ENV['MESSAGE_HISTORY_MAX_COUNT'] ?? null;
        if ($maxCount !== null && is_numeric($maxCount)) {
            $this->maxMessagesPerChannel = (int)$maxCount;
        }

        $maxAge = $_ENV['MESSAGE_HISTORY_MAX_AGE'] ?? null;
        if ($maxAge !== null && is_numeric($maxAge)) {
            $this->maxMessageAge = (int)$maxAge;
        }

        // Load trusted proxy IPs from environment variable
        $trustedProxiesEnv = $_ENV['TRUSTED_PROXIES'] ?? '';
        if (!empty($trustedProxiesEnv)) {
            $this->trustedProxies = array_map('trim', explode(',', $trustedProxiesEnv));
            echo "Trusted proxies configured: " . implode(', ', $this->trustedProxies) . "\n";
        } else {
            echo "No trusted proxies configured - X-Forwarded-For will be ignored\n";
        }

        echo "Message history config: Max {$this->maxMessagesPerChannel} messages, Max age {$this->maxMessageAge} seconds\n";
    }

    private function initDatabase()
    {
        try {
            $dbPath = __DIR__ . '/../data/walkie-talkie.db';
            $dbAbsolutePath = realpath(__DIR__ . '/../data');

            echo "Initializing database...\n";
            echo "Database path: {$dbPath}\n";
            echo "Absolute data directory: {$dbAbsolutePath}\n";

            // Check if data directory exists
            if (!is_dir(__DIR__ . '/../data')) {
                echo "ERROR: data/ directory does not exist!\n";
                $this->db = null;
                return;
            }

            // Check if data directory is writable
            if (!is_writable(__DIR__ . '/../data')) {
                echo "ERROR: data/ directory is not writable!\n";
                $this->db = null;
                return;
            }

            $this->db = new PDO('sqlite:' . $dbPath);
            echo "PDO connection established\n";

            // Enable WAL mode for better concurrent access
            $this->db->exec('PRAGMA journal_mode=WAL');
            echo "WAL mode enabled\n";

            // Set busy timeout to 5 seconds to handle lock contention
            $this->db->exec('PRAGMA busy_timeout=5000');

            // Set error mode to exceptions
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Create table if it doesn't exist
            $this->db->exec('
                CREATE TABLE IF NOT EXISTS message_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    channel TEXT NOT NULL,
                    client_id TEXT NOT NULL,
                    audio_data TEXT NOT NULL,
                    sample_rate INTEGER NOT NULL,
                    duration INTEGER NOT NULL,
                    timestamp INTEGER NOT NULL
                )
            ');
            echo "Table created\n";

            // Create index for efficient queries
            $this->db->exec('
                CREATE INDEX IF NOT EXISTS idx_channel_timestamp
                ON message_history(channel, timestamp DESC)
            ');
            echo "Index created\n";

            // Verify database file was created
            if (file_exists($dbPath)) {
                echo "Database file confirmed at: {$dbPath}\n";
            } else {
                echo "WARNING: Database file not found at expected location!\n";
            }

            echo "Database initialized successfully\n";
        } catch (PDOException $e) {
            echo "Database initialization failed: " . $e->getMessage() . "\n";
            echo "Stack trace: " . $e->getTraceAsString() . "\n";
            // Continue without database functionality
            $this->db = null;
        } catch (Exception $e) {
            echo "Unexpected error during database initialization: " . $e->getMessage() . "\n";
            $this->db = null;
        }
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    private function getClientIp(ConnectionInterface $conn): string
    {
        $remoteAddress = $conn->remoteAddress ?? 'unknown';

        // Only trust X-Forwarded-For if the connection is from a trusted proxy
        if (!empty($this->trustedProxies) && in_array($remoteAddress, $this->trustedProxies, true)) {
            $headers = $conn->httpRequest->getHeaders();

            if (isset($headers['X-Forwarded-For'])) {
                // X-Forwarded-For can contain multiple IPs, get the first one (original client)
                $ips = array_map('trim', explode(',', $headers['X-Forwarded-For'][0]));
                return $ips[0];
            }
        }

        // Return the direct connection address
        return $remoteAddress;
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);

        if (!$data) return;

        switch ($data['type']) {
            case 'join_channel':
                $this->joinChannel($from, $data['channel'] ?? '1');
                break;

            case 'leave_channel':
                $this->leaveChannel($from, $data['channel'] ?? '1');
                break;

            case 'audio_data':
                $this->broadcastAudio($from, $data);
                break;

            case 'push_to_talk_start':
                $this->handlePushToTalkStart($from, $data['channel'] ?? '1');
                break;

            case 'push_to_talk_end':
                $this->handlePushToTalkEnd($from, $data['channel'] ?? '1');
                break;

            case 'history_request':
                $this->sendChannelHistory($from, $data['channel'] ?? '1');
                break;
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        // Clean up any active transmissions for this connection
        foreach (array_keys($this->activeTransmissions) as $key) {
            if (strpos($key, $conn->resourceId . '_') === 0) {
                unset($this->activeTransmissions[$key]);
            }
        }

        $this->removeFromAllChannels($conn);
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

    private function joinChannel(ConnectionInterface $conn, string $channelId)
    {
        // Validate channel ID (1-999)
        $channelNum = intval($channelId);
        if ($channelNum < 1 || $channelNum > 999) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Invalid channel. Channel must be between 1 and 999.'
            ]));
            return;
        }

        // Remove from all other channels first
        $this->removeFromAllChannels($conn);

        // Create channel if it doesn't exist
        if (!isset($this->channels[$channelId])) {
            $this->channels[$channelId] = new SplObjectStorage;
        }

        // Add to new channel
        $this->channels[$channelId]->attach($conn);

        $conn->send(json_encode([
            'type' => 'channel_joined',
            'channel' => $channelId,
            'participants' => count($this->channels[$channelId])
        ]));

        $this->broadcastToChannel($channelId, [
            'type' => 'participant_joined',
            'participants' => count($this->channels[$channelId])
        ], $conn);

        echo "Connection {$conn->resourceId} joined channel {$channelId}\n";
    }

    private function leaveChannel(ConnectionInterface $conn, string $channelId)
    {
        if (isset($this->channels[$channelId]) && $this->channels[$channelId]->contains($conn)) {
            $this->channels[$channelId]->detach($conn);

            $conn->send(json_encode([
                'type' => 'channel_left',
                'channel' => $channelId
            ]));

            // Broadcast participant count update to remaining users
            if (count($this->channels[$channelId]) > 0) {
                $this->broadcastToChannel($channelId, [
                    'type' => 'participant_left',
                    'participants' => count($this->channels[$channelId])
                ]);
            } else {
                // Remove empty channel
                unset($this->channels[$channelId]);
            }

            echo "Connection {$conn->resourceId} left channel {$channelId}\n";
        }
    }

    private function removeFromAllChannels(ConnectionInterface $conn)
    {
        foreach ($this->channels as $channelId => $channel) {
            if ($channel->contains($conn)) {
                $channel->detach($conn);

                // Update participant count for remaining users
                if (count($channel) > 0) {
                    $this->broadcastToChannel($channelId, [
                        'type' => 'participant_left',
                        'participants' => count($channel)
                    ]);
                } else {
                    // Remove empty channel
                    unset($this->channels[$channelId]);
                }
            }
        }
    }

    private function broadcastAudio(ConnectionInterface $sender, array $data)
    {
        $channel = $data['channel'] ?? '1';

        if (!isset($this->channels[$channel])) return;

        foreach ($this->channels[$channel] as $client) {
            if ($client !== $sender) {
                $message = [
                    'type' => 'audio_data',
                    'data' => $data['data'],
                    'channel' => $channel
                ];

                // Pass through format information
                if (isset($data['format'])) {
                    $message['format'] = $data['format'];
                    $message['sampleRate'] = $data['sampleRate'] ?? 44100;
                    $message['channels'] = $data['channels'] ?? 1;
                } else {
                    $message['mimeType'] = $data['mimeType'] ?? 'audio/webm';
                }

                $client->send(json_encode($message));
            }
        }

        // Buffer audio chunks for complete transmission recording
        if (isset($data['format']) && $data['format'] === 'pcm16') {
            $transmissionKey = $sender->resourceId . '_' . $channel;

            if (!isset($this->activeTransmissions[$transmissionKey])) {
                $this->activeTransmissions[$transmissionKey] = [
                    'clientId' => $data['clientId'] ?? 'unknown',
                    'channel' => $channel,
                    'sampleRate' => $data['sampleRate'] ?? 44100,
                    'chunks' => [],
                    'startTime' => microtime(true)
                ];
            }

            $this->activeTransmissions[$transmissionKey]['chunks'][] = $data['data'];
        }
    }

    private function handlePushToTalkStart(ConnectionInterface $conn, string $channel)
    {
        $clientIp = $this->getClientIp($conn);
        echo "[TALK START] Channel {$channel} - Client {$conn->resourceId} from {$clientIp}\n";

        $this->broadcastToChannel($channel, [
            'type' => 'user_speaking',
            'speaking' => true
        ], $conn);
    }

    private function handlePushToTalkEnd(ConnectionInterface $conn, string $channel)
    {
        $this->broadcastToChannel($channel, [
            'type' => 'user_speaking',
            'speaking' => false
        ], $conn);

        // Save complete transmission to database
        $transmissionKey = $conn->resourceId . '_' . $channel;

        if (isset($this->activeTransmissions[$transmissionKey])) {
            $transmission = $this->activeTransmissions[$transmissionKey];

            // Decode each Base64 chunk, concatenate raw binary, then re-encode
            $binaryData = '';
            foreach ($transmission['chunks'] as $chunk) {
                $binaryData .= base64_decode($chunk);
            }

            // Re-encode the complete binary data as Base64
            $completeAudio = base64_encode($binaryData);

            // Calculate total duration
            $audioDataLength = strlen($binaryData);
            $duration = round(($audioDataLength / 2) / $transmission['sampleRate'] * 1000);

            $clientIp = $this->getClientIp($conn);
            $clientId = $transmission['clientId'];
            echo "[TALK END] Channel {$channel} - Client ID: {$clientId}, Connection: {$conn->resourceId}, IP: {$clientIp}, Duration: {$duration}ms\n";

            // Save to database
            $this->saveMessage(
                $transmission['channel'],
                $transmission['clientId'],
                $completeAudio,
                $transmission['sampleRate'],
                $duration
            );

            // Clean up transmission buffer
            unset($this->activeTransmissions[$transmissionKey]);
        }
    }

    private function saveMessage(string $channel, string $clientId, string $audioData, int $sampleRate, int $duration)
    {
        if (!$this->db) {
            return; // Database not available
        }

        try {
            $timestamp = round(microtime(true) * 1000); // milliseconds

            // Insert new message
            $stmt = $this->db->prepare('
                INSERT INTO message_history (channel, client_id, audio_data, sample_rate, duration, timestamp)
                VALUES (:channel, :client_id, :audio_data, :sample_rate, :duration, :timestamp)
            ');

            $stmt->execute([
                ':channel' => $channel,
                ':client_id' => $clientId,
                ':audio_data' => $audioData,
                ':sample_rate' => $sampleRate,
                ':duration' => $duration,
                ':timestamp' => $timestamp
            ]);

            // Clean up old messages based on count and age
            // Delete messages that are either:
            // 1. Older than the maxMessageAge (in seconds)
            // 2. Beyond the maxMessagesPerChannel limit

            $cutoffTimestamp = round((microtime(true) - $this->maxMessageAge) * 1000);

            $deleteStmt = $this->db->prepare('
                DELETE FROM message_history
                WHERE channel = :channel
                AND (
                    timestamp < :cutoff_timestamp
                    OR id NOT IN (
                        SELECT id FROM message_history
                        WHERE channel = :channel
                        ORDER BY timestamp DESC
                        LIMIT :max_messages
                    )
                )
            ');

            $deleteStmt->bindParam(':channel', $channel);
            $deleteStmt->bindParam(':cutoff_timestamp', $cutoffTimestamp, PDO::PARAM_INT);
            $deleteStmt->bindParam(':max_messages', $this->maxMessagesPerChannel, PDO::PARAM_INT);
            $deleteStmt->execute();

            echo "Message saved to channel {$channel} (Duration: {$duration}ms)\n";
        } catch (PDOException $e) {
            echo "Failed to save message: " . $e->getMessage() . "\n";

            // Retry logic for SQLITE_BUSY errors
            if ($e->getCode() == 'HY000' && strpos($e->getMessage(), 'database is locked') !== false) {
                echo "Retrying after database lock...\n";
                usleep(100000); // Wait 100ms
                try {
                    $this->saveMessage($channel, $clientId, $audioData, $sampleRate, $duration);
                } catch (PDOException $retryError) {
                    echo "Retry failed: " . $retryError->getMessage() . "\n";
                }
            }
        }
    }

    private function getChannelHistory(string $channel): array
    {
        if (!$this->db) {
            return []; // Database not available
        }

        try {
            $cutoffTimestamp = round((microtime(true) - $this->maxMessageAge) * 1000);

            $stmt = $this->db->prepare('
                SELECT client_id, audio_data, sample_rate, duration, timestamp
                FROM message_history
                WHERE channel = :channel
                AND timestamp >= :cutoff_timestamp
                ORDER BY timestamp ASC
                LIMIT :max_messages
            ');

            $stmt->bindParam(':channel', $channel);
            $stmt->bindParam(':cutoff_timestamp', $cutoffTimestamp, PDO::PARAM_INT);
            $stmt->bindParam(':max_messages', $this->maxMessagesPerChannel, PDO::PARAM_INT);
            $stmt->execute();

            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo "Retrieved " . count($messages) . " messages for channel {$channel}\n";

            return $messages;
        } catch (PDOException $e) {
            echo "Failed to retrieve history: " . $e->getMessage() . "\n";
            return [];
        }
    }

    private function sendChannelHistory(ConnectionInterface $conn, string $channel)
    {
        $messages = $this->getChannelHistory($channel);

        $conn->send(json_encode([
            'type' => 'history_response',
            'channel' => $channel,
            'messages' => $messages
        ]));

        echo "Sent history for channel {$channel} to connection {$conn->resourceId}\n";
    }

    private function broadcastToChannel(string $channelId, array $message, ConnectionInterface $exclude = null)
    {
        if (!isset($this->channels[$channelId])) return;

        foreach ($this->channels[$channelId] as $client) {
            if ($client !== $exclude) {
                $client->send(json_encode($message));
            }
        }
    }
}