<?php
/**
 * Rate Limiter Plugin - Prevents transmission spam
 *
 * Copyright (C) 2025 Matthew Asham
 *
 * This program is dual-licensed under AGPL-3.0-or-later or Commercial License.
 * See LICENSE.md for details.
 */

namespace WalkieTalkie\Plugins\RateLimiter;

use WalkieTalkie\Plugins\AbstractPlugin;
use WalkieTalkie\PluginManager;
use Ratchet\ConnectionInterface;

class RateLimiterPlugin extends AbstractPlugin
{
    private array $transmissionLog = [];
    private array $lastTransmission = [];

    public function getName(): string
    {
        return 'rate-limiter';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Prevents users from transmitting too frequently';
    }

    public function getAuthor(): string
    {
        return 'Walkie-Talkie Team';
    }

    public function onLoad(PluginManager $manager): void
    {
        $this->manager = $manager;
        $this->log('Rate limiter plugin loaded');

        // Register with high priority (10) to run early
        $manager->addHook('plugin.audio.transmit.start', [$this, 'checkRateLimit'], priority: 10);
    }

    public function checkRateLimit(
        ConnectionInterface $conn,
        string $channel,
        array $identity,
        bool &$allowTransmission
    ): void {
        $userId = $conn->resourceId;
        $screenName = $identity['screen_name'] ?? 'unknown';
        $now = time();

        // Check if user is exempt
        $exemptUsers = $this->getConfig('exempt_users', []);
        if (in_array($screenName, $exemptUsers)) {
            return; // Allow transmission
        }

        // Check cooldown period
        $cooldown = $this->getConfig('cooldown_seconds', 2);
        if (isset($this->lastTransmission[$userId])) {
            $timeSinceLastTransmission = $now - $this->lastTransmission[$userId];
            if ($timeSinceLastTransmission < $cooldown) {
                $allowTransmission = false;
                $waitTime = $cooldown - $timeSinceLastTransmission;
                $conn->send(json_encode([
                    'type' => 'error',
                    'message' => "Please wait {$waitTime} seconds before transmitting again."
                ]));
                $this->log("Cooldown violation for user {$screenName} ({$userId})");
                return;
            }
        }

        // Clean old entries (older than 1 minute)
        $this->transmissionLog[$userId] = array_filter(
            $this->transmissionLog[$userId] ?? [],
            fn($time) => ($now - $time) < 60
        );

        // Check per-minute limit
        $limit = $this->getConfig('max_transmissions_per_minute', 10);
        if (count($this->transmissionLog[$userId]) >= $limit) {
            $allowTransmission = false;
            $conn->send(json_encode([
                'type' => 'error',
                'message' => "Rate limit exceeded. Maximum {$limit} transmissions per minute."
            ]));
            $this->log("Rate limit exceeded for user {$screenName} ({$userId})");
            return;
        }

        // Log this transmission
        $this->transmissionLog[$userId][] = $now;
        $this->lastTransmission[$userId] = $now;
    }

    public function onUnload(): void
    {
        $this->log('Rate limiter plugin unloaded');
    }
}
