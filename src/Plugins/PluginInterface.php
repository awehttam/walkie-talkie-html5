<?php
/**
 * Walkie Talkie PWA - Plugin Interface
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

interface PluginInterface
{
    /**
     * Get plugin name (must be unique)
     */
    public function getName(): string;

    /**
     * Get plugin version (semantic versioning)
     */
    public function getVersion(): string;

    /**
     * Get plugin description
     */
    public function getDescription(): string;

    /**
     * Get plugin author
     */
    public function getAuthor(): string;

    /**
     * Called when plugin is loaded
     * Register hooks and initialize resources here
     */
    public function onLoad(PluginManager $manager): void;

    /**
     * Called when plugin is unloaded
     * Clean up resources here
     */
    public function onUnload(): void;

    /**
     * Get configuration schema for validation
     * Returns array with config keys and validation rules
     */
    public function getConfigSchema(): array;

    /**
     * Get required permissions
     * Returns array of permission strings
     */
    public function getRequiredPermissions(): array;

    /**
     * Get plugin dependencies
     * Returns array of plugin names this plugin requires
     */
    public function getDependencies(): array;
}
