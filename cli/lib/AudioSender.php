<?php
/**
 * AudioSender - Audio Transmission Orchestration
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
 * High-level library that combines AudioProcessor and WebSocketClient
 * to send audio to the server following the proper protocol
 *
 * Usage:
 *   $client = new WebSocketClient('ws://localhost:8080');
 *   $client->connect(['screen_name' => 'Bot']);
 *
 *   $sender = new AudioSender($client);
 *   $sender->sendAudio('announcement.wav', '1', ['verbose' => true]);
 */

namespace WalkieTalkie\CLI;

class AudioSender
{
    private WebSocketClient $client;
    private bool $verbose;

    /**
     * Constructor
     *
     * @param WebSocketClient $client Connected WebSocket client
     * @param bool $verbose Enable verbose output
     */
    public function __construct(WebSocketClient $client, bool $verbose = false)
    {
        $this->client = $client;
        $this->verbose = $verbose;
    }

    /**
     * Send audio file to channel
     *
     * @param string $audioFile Path to audio file or "-" for stdin
     * @param string $channel Channel to send to
     * @param array $options Options:
     *                       - 'sample_rate' (int): Sample rate for raw PCM16 (default: 48000)
     *                       - 'chunk_size' (int): Chunk size in bytes (default: 4096)
     *                       - 'screen_name' (string): Override screen name for transmission
     *                       - 'verbose' (bool): Override verbose setting
     * @return bool True on success
     * @throws \Exception On transmission error
     */
    public function sendAudio(string $audioFile, string $channel, array $options = []): bool
    {
        if (!$this->client->isConnected()) {
            throw new \Exception("WebSocket client is not connected");
        }

        // Parse options
        $sampleRate = $options['sample_rate'] ?? 48000;
        $chunkSize = $options['chunk_size'] ?? 4096;
        $screenName = $options['screen_name'] ?? null;
        $verbose = $options['verbose'] ?? $this->verbose;

        // Load audio
        if ($verbose) {
            $displayFile = $audioFile === '-' ? 'stdin' : $audioFile;
            echo "Loading audio: {$displayFile}\n";
        }

        try {
            $audio = AudioProcessor::loadAudio($audioFile, $sampleRate, $chunkSize);
        } catch (\Exception $e) {
            throw new \Exception("Failed to load audio: " . $e->getMessage());
        }

        if ($verbose) {
            $info = AudioProcessor::formatInfo($audio);
            echo "Format: {$info}\n";
            echo "Sending to channel: {$channel}\n";
        }

        // Join the channel first
        try {
            $this->client->send(['type' => 'join_channel', 'channel' => $channel]);
            // Give the server a moment to process the join
            usleep(100000); // 100ms
        } catch (\Exception $e) {
            throw new \Exception("Failed to join channel: " . $e->getMessage());
        }

        // Send push_to_talk_start to notify other clients
        $startMsg = [
            'type' => 'push_to_talk_start',
            'channel' => $channel
        ];

        try {
            $this->client->send($startMsg);
        } catch (\Exception $e) {
            throw new \Exception("Failed to send push_to_talk_start: " . $e->getMessage());
        }

        // Send audio chunks
        $totalChunks = count($audio['chunks']);
        $sentChunks = 0;

        if ($verbose) {
            echo "Progress: ";
        }

        // Generate a unique client ID for this transmission
        $clientId = uniqid('cli_', true);

        foreach ($audio['chunks'] as $chunk) {
            $audioMsg = [
                'type' => 'audio_data',
                'channel' => $channel,
                'data' => $chunk,
                'format' => 'pcm16',
                'sampleRate' => $audio['sample_rate'],
                'channels' => 1,
                'clientId' => $clientId
            ];

            try {
                $this->client->send($audioMsg);
            } catch (\Exception $e) {
                throw new \Exception("Failed to send audio chunk: " . $e->getMessage());
            }

            $sentChunks++;

            // Show progress
            if ($verbose) {
                $this->showProgress($sentChunks, $totalChunks);
            }

            // Small delay to avoid overwhelming the server
            usleep(10000); // 10ms
        }

        if ($verbose) {
            echo "\n";
        }

        // Send push_to_talk_end to notify other clients
        $endMsg = [
            'type' => 'push_to_talk_end',
            'channel' => $channel
        ];

        try {
            $this->client->send($endMsg);
        } catch (\Exception $e) {
            throw new \Exception("Failed to send push_to_talk_end: " . $e->getMessage());
        }

        if ($verbose) {
            echo "Transmission complete.\n";
        }

        return true;
    }

    /**
     * Send a single audio chunk
     *
     * For advanced usage where you want manual control over the protocol
     *
     * @param string $base64Audio Base64-encoded PCM16 audio
     * @param string $channel Channel to send to
     * @param int $sampleRate Sample rate
     * @param string|null $screenName Override screen name
     * @return bool True on success
     * @throws \Exception On transmission error
     */
    public function sendChunk(string $base64Audio, string $channel, int $sampleRate = 48000, ?string $screenName = null): bool
    {
        if (!$this->client->isConnected()) {
            throw new \Exception("WebSocket client is not connected");
        }

        $msg = [
            'type' => 'audio',
            'channel' => $channel,
            'audio' => $base64Audio,
            'sample_rate' => $sampleRate
        ];

        if ($screenName) {
            $msg['screen_name'] = $screenName;
        }

        $this->client->send($msg);

        return true;
    }

    /**
     * Show progress bar
     *
     * @param int $current Current chunk number
     * @param int $total Total chunks
     */
    private function showProgress(int $current, int $total): void
    {
        $percent = ($current / $total) * 100;
        $barLength = 50;
        $filledLength = (int)(($current / $total) * $barLength);

        $bar = str_repeat('#', $filledLength) . str_repeat('-', $barLength - $filledLength);

        // Use \r to overwrite the same line
        printf("\rProgress: [%s] %d%% (%d/%d chunks)", $bar, (int)$percent, $current, $total);
    }

    /**
     * Set verbose output
     *
     * @param bool $verbose Enable verbose output
     */
    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }
}
