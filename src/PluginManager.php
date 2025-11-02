<?php
/**
 * Walkie Talkie PWA - Plugin Manager
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

namespace WalkieTalkie;

use WalkieTalkie\Plugins\PluginInterface;
use WalkieTalkie\Plugins\PluginException;
use PDO;

class PluginManager
{
    private array $plugins = [];
    private array $hooks = [];
    private string $pluginsPath;
    private ?PDO $db = null;
    private array $enabledPlugins = [];
    private $server = null; // WebSocketServer instance

    /**
     * @param string $pluginsPath Path to plugins directory
     */
    public function __construct(string $pluginsPath = 'plugins/')
    {
        $this->pluginsPath = rtrim($pluginsPath, '/') . '/';

        // Check if plugins directory exists
        if (!is_dir($this->pluginsPath)) {
            echo "Plugins directory not found: {$this->pluginsPath}\n";
            echo "Creating plugins directory...\n";
            mkdir($this->pluginsPath, 0755, true);
        }
    }

    /**
     * Set WebSocket server instance for broadcasting
     */
    public function setServer($server): void
    {
        $this->server = $server;
    }

    /**
     * Set database connection for plugins to use
     */
    public function setDatabase(?PDO $db): void
    {
        $this->db = $db;
    }

    /**
     * Get database connection
     */
    public function getDatabase(): ?PDO
    {
        return $this->db;
    }

    /**
     * Register a plugin instance
     */
    public function registerPlugin(PluginInterface $plugin): void
    {
        $name = $plugin->getName();

        if (isset($this->plugins[$name])) {
            throw new PluginException("Plugin '{$name}' is already registered");
        }

        // Check dependencies
        $dependencies = $plugin->getDependencies();
        foreach ($dependencies as $dependency) {
            if (!isset($this->plugins[$dependency])) {
                throw new PluginException("Plugin '{$name}' requires '{$dependency}' which is not loaded");
            }
        }

        $this->plugins[$name] = $plugin;
        $this->enabledPlugins[$name] = true;

        echo "Plugin registered: {$name} v{$plugin->getVersion()}\n";
    }

    /**
     * Unregister a plugin
     */
    public function unregisterPlugin(string $name): void
    {
        if (!isset($this->plugins[$name])) {
            throw new PluginException("Plugin '{$name}' is not registered");
        }

        // Call onUnload hook
        try {
            $this->plugins[$name]->onUnload();
        } catch (\Exception $e) {
            echo "Error unloading plugin '{$name}': {$e->getMessage()}\n";
        }

        // Remove all hooks for this plugin
        foreach ($this->hooks as $hookName => &$callbacks) {
            $callbacks = array_filter($callbacks, function($cb) use ($name) {
                return !is_array($cb['callback']) ||
                       !is_object($cb['callback'][0]) ||
                       get_class($cb['callback'][0])::getName() !== $name;
            });
        }

        unset($this->plugins[$name]);
        unset($this->enabledPlugins[$name]);

        echo "Plugin unregistered: {$name}\n";
    }

    /**
     * Load all plugins from a directory
     */
    public function loadPluginsFromDirectory(string $dir = null): void
    {
        $dir = $dir ?? $this->pluginsPath;

        if (!is_dir($dir)) {
            echo "Plugins directory not found: {$dir}\n";
            return;
        }

        $pluginDirs = array_filter(glob($dir . '*'), 'is_dir');

        foreach ($pluginDirs as $pluginDir) {
            // Handle example-plugins directory - load plugins inside it
            if (basename($pluginDir) === 'example-plugins') {
                $exampleDirs = array_filter(glob($pluginDir . '/*'), 'is_dir');
                foreach ($exampleDirs as $exampleDir) {
                    try {
                        $this->loadPlugin($exampleDir);
                    } catch (PluginException $e) {
                        echo "Failed to load plugin from {$exampleDir}: {$e->getMessage()}\n";
                    }
                }
                continue;
            }

            try {
                $this->loadPlugin($pluginDir);
            } catch (PluginException $e) {
                echo "Failed to load plugin from {$pluginDir}: {$e->getMessage()}\n";
            }
        }
    }

    /**
     * Load a single plugin from directory
     */
    private function loadPlugin(string $pluginDir): void
    {
        $manifestPath = $pluginDir . '/plugin.json';

        if (!file_exists($manifestPath)) {
            throw new PluginException("Plugin manifest not found: {$manifestPath}");
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);

        if (!$manifest) {
            throw new PluginException("Invalid plugin manifest: {$manifestPath}");
        }

        // Check if plugin is enabled
        if (isset($manifest['config']['enabled']) && !$manifest['config']['enabled']) {
            echo "Plugin '{$manifest['name']}' is disabled\n";
            return;
        }

        // Load config if exists
        $config = [];
        $configPath = $pluginDir . '/config.php';
        if (file_exists($configPath)) {
            $config = require $configPath;
        }

        // Merge with manifest config
        if (isset($manifest['config']['settings'])) {
            $config = array_merge($manifest['config']['settings'], $config);
        }

        // Load main plugin file
        $mainFile = $pluginDir . '/' . $manifest['main'];
        if (!file_exists($mainFile)) {
            throw new PluginException("Plugin main file not found: {$mainFile}");
        }

        require_once $mainFile;

        // Instantiate plugin class
        $className = $manifest['namespace'] . '\\' . pathinfo($manifest['main'], PATHINFO_FILENAME);

        if (!class_exists($className)) {
            throw new PluginException("Plugin class not found: {$className}");
        }

        $plugin = new $className($pluginDir, $config);

        if (!($plugin instanceof PluginInterface)) {
            throw new PluginException("Plugin class must implement PluginInterface: {$className}");
        }

        // Register plugin
        $this->registerPlugin($plugin);

        // Call onLoad hook
        try {
            $plugin->onLoad($this);
        } catch (\Exception $e) {
            echo "Error loading plugin '{$plugin->getName()}': {$e->getMessage()}\n";
            $this->unregisterPlugin($plugin->getName());
        }
    }

    /**
     * Get a plugin by name
     */
    public function getPlugin(string $name): ?PluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * Get all registered plugins
     */
    public function getAllPlugins(): array
    {
        return $this->plugins;
    }

    /**
     * Check if plugin is enabled
     */
    public function isPluginEnabled(string $name): bool
    {
        return isset($this->enabledPlugins[$name]) && $this->enabledPlugins[$name];
    }

    /**
     * Add a hook callback
     */
    public function addHook(string $hookName, callable $callback, int $priority = 10): void
    {
        if (!isset($this->hooks[$hookName])) {
            $this->hooks[$hookName] = [];
        }

        $this->hooks[$hookName][] = [
            'callback' => $callback,
            'priority' => $priority
        ];

        // Sort by priority (lower numbers run first)
        usort($this->hooks[$hookName], function($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });
    }

    /**
     * Remove a hook callback
     */
    public function removeHook(string $hookName, callable $callback): void
    {
        if (!isset($this->hooks[$hookName])) {
            return;
        }

        $this->hooks[$hookName] = array_filter(
            $this->hooks[$hookName],
            fn($cb) => $cb['callback'] !== $callback
        );
    }

    /**
     * Execute all callbacks for a hook
     *
     * @param string $hookName Name of the hook
     * @param mixed ...$args Arguments to pass to callbacks (can include references)
     * @return mixed Result from the last callback, or null
     */
    public function executeHook(string $hookName, &...$args): mixed
    {
        if (!isset($this->hooks[$hookName])) {
            return null;
        }

        $result = null;

        foreach ($this->hooks[$hookName] as $hook) {
            try {
                $result = call_user_func_array($hook['callback'], $args);
            } catch (\Exception $e) {
                echo "Error executing hook '{$hookName}': {$e->getMessage()}\n";
                echo "Stack trace: {$e->getTraceAsString()}\n";
            }
        }

        return $result;
    }

    /**
     * Check if a hook has any callbacks
     */
    public function hasHook(string $hookName): bool
    {
        return isset($this->hooks[$hookName]) && !empty($this->hooks[$hookName]);
    }

    /**
     * Initialize all plugins
     */
    public function initializeAll(): void
    {
        echo "Initializing " . count($this->plugins) . " plugins...\n";

        $this->executeHook('plugin.server.init', $this);

        foreach ($this->plugins as $plugin) {
            echo "  - {$plugin->getName()} v{$plugin->getVersion()} by {$plugin->getAuthor()}\n";
        }
    }

    /**
     * Shutdown all plugins
     */
    public function shutdownAll(): void
    {
        echo "Shutting down plugins...\n";

        $this->executeHook('plugin.server.shutdown', $this);

        foreach ($this->plugins as $plugin) {
            try {
                $plugin->onUnload();
            } catch (\Exception $e) {
                echo "Error shutting down plugin '{$plugin->getName()}': {$e->getMessage()}\n";
            }
        }
    }

    /**
     * Broadcast message to channel (helper for plugins)
     */
    public function broadcastToChannel(string $channelId, array $message): void
    {
        // This will be called from plugins to broadcast messages
        if ($this->server && method_exists($this->server, 'broadcastToChannelPublic')) {
            $this->server->broadcastToChannelPublic($channelId, $message);
        }
    }

    /**
     * Log a message (helper for plugins)
     */
    public function log(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] [{$level}] {$message}\n";
    }
}
