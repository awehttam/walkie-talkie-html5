<?php

namespace WalkieTalkie;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use SplObjectStorage;

class WebSocketServer implements MessageComponentInterface
{
    protected $clients;
    protected $channels;

    public function __construct()
    {
        $this->clients = new SplObjectStorage;
        $this->channels = ['1' => new SplObjectStorage];
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
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
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
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
    }

    private function handlePushToTalkStart(ConnectionInterface $conn, string $channel)
    {
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