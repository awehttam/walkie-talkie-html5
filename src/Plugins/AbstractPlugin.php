<?php
/**
 * Walkie Talkie PWA - Abstract Plugin Base Class
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

namespace WalkieTalkie\Plugins;

use WalkieTalkie\PluginManager;
use PDO;

abstract class AbstractPlugin implements PluginInterface
{
    protected PluginManager $manager;
    protected array $config = [];
    protected string $pluginPath;

    public function __construct(string $pluginPath, array $config = [])
    {
        $this->pluginPath = $pluginPath;
        $this->config = $config;
    }

    /**
     * Get configuration value
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set configuration value
     */
    protected function setConfig(string $key, mixed $value): void
    {
        $this->config[$key] = $value;
    }

    /**
     * Check if plugin has a permission
     */
    protected function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->getRequiredPermissions());
    }

    /**
     * Log a message
     */
    protected function log(string $message, string $level = 'info'): void
    {
        $pluginName = $this->getName();
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] [{$level}] [{$pluginName}] {$message}\n";
    }

    /**
     * Get database connection
     */
    protected function getDatabase(): ?PDO
    {
        return $this->manager->getDatabase();
    }

    /**
     * Broadcast message to a channel
     */
    protected function broadcastToChannel(string $channel, array $message): void
    {
        $this->manager->broadcastToChannel($channel, $message);
    }

    // Default implementations

    public function getAuthor(): string
    {
        return 'Unknown';
    }

    public function getConfigSchema(): array
    {
        return [];
    }

    public function getRequiredPermissions(): array
    {
        return [];
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function onUnload(): void
    {
        // Default: no cleanup needed
    }
}
