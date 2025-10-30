<?php
/**
 * WelcomeManager - Welcome Message Database Operations
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
 * Manages CRUD operations for welcome messages
 *
 * Usage:
 *   $db = new PDO('sqlite:data/walkie-talkie.db');
 *   $manager = new WelcomeManager($db);
 *   $id = $manager->addMessage('Welcome', 'audio/welcome.wav', 'connect');
 *   $messages = $manager->getMessages('connect');
 */

namespace WalkieTalkie\CLI;

class WelcomeManager
{
    private \PDO $db;

    /**
     * Constructor
     *
     * @param \PDO $db Database connection
     */
    public function __construct(\PDO $db)
    {
        $this->db = $db;
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Add a new welcome message
     *
     * @param string $name Friendly name for the message
     * @param string $audioFile Path to audio file
     * @param string $trigger Trigger type: 'connect', 'channel_join', or 'both'
     * @param array $options Optional settings:
     *                       - 'channel' (string|null): Specific channel, or null for all
     *                       - 'enabled' (bool): Whether message is enabled (default: true)
     * @return int Message ID
     * @throws \Exception On invalid trigger type or database error
     */
    public function addMessage(string $name, string $audioFile, string $trigger, array $options = []): int
    {
        // Validate trigger type
        $validTriggers = ['connect', 'channel_join', 'both'];
        if (!in_array($trigger, $validTriggers)) {
            throw new \Exception("Invalid trigger type: {$trigger}. Must be one of: " . implode(', ', $validTriggers));
        }

        // Validate audio file exists
        if (!file_exists($audioFile)) {
            throw new \Exception("Audio file not found: {$audioFile}");
        }

        // Get absolute path
        $audioFile = realpath($audioFile);

        // Parse options
        $channel = $options['channel'] ?? null;
        $enabled = ($options['enabled'] ?? true) ? 1 : 0;

        // Insert into database
        $stmt = $this->db->prepare('
            INSERT INTO welcome_messages (name, audio_file, trigger_type, channel, enabled, created_at, play_count)
            VALUES (:name, :audio_file, :trigger_type, :channel, :enabled, :created_at, 0)
        ');

        $stmt->execute([
            ':name' => $name,
            ':audio_file' => $audioFile,
            ':trigger_type' => $trigger,
            ':channel' => $channel,
            ':enabled' => $enabled,
            ':created_at' => time()
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get welcome messages
     *
     * @param string|null $trigger Filter by trigger type, or null for all
     * @param string|null $channel Filter by channel, or null for all
     * @param bool $enabledOnly Only return enabled messages
     * @return array Array of message records
     */
    public function getMessages(?string $trigger = null, ?string $channel = null, bool $enabledOnly = false): array
    {
        $sql = 'SELECT * FROM welcome_messages WHERE 1=1';
        $params = [];

        if ($trigger !== null) {
            $sql .= ' AND (trigger_type = :trigger OR trigger_type = "both")';
            $params[':trigger'] = $trigger;
        }

        if ($channel !== null) {
            $sql .= ' AND (channel IS NULL OR channel = :channel)';
            $params[':channel'] = $channel;
        }

        if ($enabledOnly) {
            $sql .= ' AND enabled = 1';
        }

        $sql .= ' ORDER BY created_at ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get a specific welcome message by ID
     *
     * @param int $id Message ID
     * @return array|null Message record, or null if not found
     */
    public function getMessage(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM welcome_messages WHERE id = :id');
        $stmt->execute([':id' => $id]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Delete a welcome message
     *
     * @param int $id Message ID
     * @return bool True if deleted, false if not found
     */
    public function deleteMessage(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM welcome_messages WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Enable a welcome message
     *
     * @param int $id Message ID
     * @return bool True if updated, false if not found
     */
    public function enableMessage(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE welcome_messages SET enabled = 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Disable a welcome message
     *
     * @param int $id Message ID
     * @return bool True if updated, false if not found
     */
    public function disableMessage(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE welcome_messages SET enabled = 0 WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Update last played timestamp and increment play count
     *
     * @param int $id Message ID
     */
    public function updateLastPlayed(int $id): void
    {
        $stmt = $this->db->prepare('
            UPDATE welcome_messages
            SET last_played_at = :timestamp, play_count = play_count + 1
            WHERE id = :id
        ');

        $stmt->execute([
            ':timestamp' => time(),
            ':id' => $id
        ]);
    }

    /**
     * Update welcome message details
     *
     * @param int $id Message ID
     * @param array $updates Array of fields to update:
     *                       - 'name' (string)
     *                       - 'audio_file' (string)
     *                       - 'trigger_type' (string)
     *                       - 'channel' (string|null)
     *                       - 'enabled' (bool)
     * @return bool True if updated, false if not found
     * @throws \Exception On invalid trigger type or file not found
     */
    public function updateMessage(int $id, array $updates): bool
    {
        // Validate trigger type if provided
        if (isset($updates['trigger_type'])) {
            $validTriggers = ['connect', 'channel_join', 'both'];
            if (!in_array($updates['trigger_type'], $validTriggers)) {
                throw new \Exception("Invalid trigger type: {$updates['trigger_type']}");
            }
        }

        // Validate audio file if provided
        if (isset($updates['audio_file'])) {
            if (!file_exists($updates['audio_file'])) {
                throw new \Exception("Audio file not found: {$updates['audio_file']}");
            }
            $updates['audio_file'] = realpath($updates['audio_file']);
        }

        // Convert enabled to integer if provided
        if (isset($updates['enabled'])) {
            $updates['enabled'] = $updates['enabled'] ? 1 : 0;
        }

        // Build update query
        $fields = [];
        $params = [':id' => $id];

        foreach ($updates as $field => $value) {
            $fields[] = "{$field} = :{$field}";
            $params[":{$field}"] = $value;
        }

        if (empty($fields)) {
            return false;
        }

        $sql = 'UPDATE welcome_messages SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get statistics about welcome messages
     *
     * @return array Statistics array
     */
    public function getStatistics(): array
    {
        $stats = [
            'total' => 0,
            'enabled' => 0,
            'disabled' => 0,
            'by_trigger' => [
                'connect' => 0,
                'channel_join' => 0,
                'both' => 0
            ],
            'total_plays' => 0
        ];

        // Get counts
        $result = $this->db->query('
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as enabled,
                SUM(CASE WHEN enabled = 0 THEN 1 ELSE 0 END) as disabled,
                SUM(play_count) as total_plays
            FROM welcome_messages
        ')->fetch(\PDO::FETCH_ASSOC);

        $stats['total'] = (int)$result['total'];
        $stats['enabled'] = (int)$result['enabled'];
        $stats['disabled'] = (int)$result['disabled'];
        $stats['total_plays'] = (int)$result['total_plays'];

        // Get counts by trigger type
        $triggers = $this->db->query('
            SELECT trigger_type, COUNT(*) as count
            FROM welcome_messages
            GROUP BY trigger_type
        ')->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($triggers as $row) {
            $stats['by_trigger'][$row['trigger_type']] = (int)$row['count'];
        }

        return $stats;
    }
}
