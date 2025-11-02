<?php
/**
 * Walkie Talkie PWA - Plugin Manager CLI
 *
 * Copyright (C) 2025 Matthew Asham
 *
 * This program is dual-licensed under AGPL-3.0-or-later or Commercial License.
 * See LICENSE.md for details.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use WalkieTalkie\PluginManager;
use Dotenv\Dotenv;

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

class PluginManagerCLI
{
    private PluginManager $pluginManager;
    private string $pluginsPath;

    public function __construct()
    {
        $this->pluginsPath = __DIR__ . '/../' . ($_ENV['PLUGINS_PATH'] ?? 'plugins/');
        $this->pluginManager = new PluginManager($this->pluginsPath);
    }

    public function run(array $args): void
    {
        $command = $args[1] ?? 'help';

        switch ($command) {
            case 'list':
                $this->listPlugins($args);
                break;
            case 'info':
                $this->showPluginInfo($args[2] ?? null);
                break;
            case 'enable':
                $this->enablePlugin($args[2] ?? null);
                break;
            case 'disable':
                $this->disablePlugin($args[2] ?? null);
                break;
            case 'validate':
                $this->validatePlugin($args[2] ?? null);
                break;
            case 'help':
            default:
                $this->showHelp();
                break;
        }
    }

    private function listPlugins(array $args): void
    {
        $filter = $args[2] ?? 'all'; // all, enabled, disabled

        echo "Scanning plugins directory: {$this->pluginsPath}\n\n";

        if (!is_dir($this->pluginsPath)) {
            echo "Error: Plugins directory not found!\n";
            return;
        }

        $pluginDirs = array_filter(glob($this->pluginsPath . '*'), 'is_dir');
        $plugins = [];

        foreach ($pluginDirs as $pluginDir) {
            // Skip example-plugins directory in listing
            if (basename($pluginDir) === 'example-plugins') {
                // List plugins inside example-plugins
                $exampleDirs = array_filter(glob($pluginDir . '/*'), 'is_dir');
                foreach ($exampleDirs as $exampleDir) {
                    $manifest = $this->loadManifest($exampleDir);
                    if ($manifest) {
                        $manifest['_path'] = $exampleDir;
                        $manifest['_example'] = true;
                        $plugins[] = $manifest;
                    }
                }
                continue;
            }

            $manifest = $this->loadManifest($pluginDir);
            if ($manifest) {
                $manifest['_path'] = $pluginDir;
                $manifest['_example'] = false;
                $plugins[] = $manifest;
            }
        }

        if (empty($plugins)) {
            echo "No plugins found.\n";
            return;
        }

        // Filter plugins
        if ($filter === 'enabled') {
            $plugins = array_filter($plugins, fn($p) => $p['config']['enabled'] ?? false);
        } elseif ($filter === 'disabled') {
            $plugins = array_filter($plugins, fn($p) => !($p['config']['enabled'] ?? false));
        }

        echo "Found " . count($plugins) . " plugin(s):\n\n";

        foreach ($plugins as $plugin) {
            $enabled = ($plugin['config']['enabled'] ?? false) ? '[ENABLED]' : '[DISABLED]';
            $example = $plugin['_example'] ? ' (example)' : '';
            echo "  {$enabled} {$plugin['name']} v{$plugin['version']}{$example}\n";
            echo "    {$plugin['description']}\n";
            echo "    Author: {$plugin['author']}\n";
            echo "    Path: {$plugin['_path']}\n";
            echo "\n";
        }
    }

    private function showPluginInfo(?string $pluginName): void
    {
        if (!$pluginName) {
            echo "Error: Plugin name required\n";
            echo "Usage: php plugin-manager.php info <plugin-name>\n";
            return;
        }

        $manifest = $this->findPlugin($pluginName);
        if (!$manifest) {
            echo "Error: Plugin '{$pluginName}' not found\n";
            return;
        }

        echo "Plugin Information:\n";
        echo "==================\n\n";
        echo "Name:        {$manifest['name']}\n";
        echo "Version:     {$manifest['version']}\n";
        echo "Description: {$manifest['description']}\n";
        echo "Author:      {$manifest['author']}\n";
        echo "License:     {$manifest['license']}\n";
        echo "Enabled:     " . (($manifest['config']['enabled'] ?? false) ? 'Yes' : 'No') . "\n";
        echo "\n";

        echo "Requirements:\n";
        foreach ($manifest['requires'] as $req => $version) {
            echo "  - {$req}: {$version}\n";
        }
        echo "\n";

        echo "Hooks:\n";
        foreach ($manifest['hooks'] as $hook) {
            echo "  - {$hook}\n";
        }
        echo "\n";

        if (!empty($manifest['permissions'])) {
            echo "Permissions:\n";
            foreach ($manifest['permissions'] as $permission) {
                echo "  - {$permission}\n";
            }
            echo "\n";
        }

        if (!empty($manifest['dependencies'])) {
            echo "Dependencies:\n";
            foreach ($manifest['dependencies'] as $dep) {
                echo "  - {$dep}\n";
            }
            echo "\n";
        }

        if (!empty($manifest['config']['settings'])) {
            echo "Configuration:\n";
            foreach ($manifest['config']['settings'] as $key => $value) {
                $valueStr = is_array($value) ? json_encode($value) : $value;
                echo "  {$key}: {$valueStr}\n";
            }
            echo "\n";
        }

        echo "Path: {$manifest['_path']}\n";
    }

    private function enablePlugin(?string $pluginName): void
    {
        if (!$pluginName) {
            echo "Error: Plugin name required\n";
            echo "Usage: php plugin-manager.php enable <plugin-name>\n";
            return;
        }

        $manifest = $this->findPlugin($pluginName);
        if (!$manifest) {
            echo "Error: Plugin '{$pluginName}' not found\n";
            return;
        }

        $manifestPath = $manifest['_path'] . '/plugin.json';
        $manifest['config']['enabled'] = true;

        // Remove internal keys
        unset($manifest['_path']);
        unset($manifest['_example']);

        if (file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
            echo "Plugin '{$pluginName}' enabled successfully.\n";
            echo "Restart the server for changes to take effect: php server.php restart\n";
        } else {
            echo "Error: Failed to update plugin manifest\n";
        }
    }

    private function disablePlugin(?string $pluginName): void
    {
        if (!$pluginName) {
            echo "Error: Plugin name required\n";
            echo "Usage: php plugin-manager.php disable <plugin-name>\n";
            return;
        }

        $manifest = $this->findPlugin($pluginName);
        if (!$manifest) {
            echo "Error: Plugin '{$pluginName}' not found\n";
            return;
        }

        $manifestPath = $manifest['_path'] . '/plugin.json';
        $manifest['config']['enabled'] = false;

        // Remove internal keys
        unset($manifest['_path']);
        unset($manifest['_example']);

        if (file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
            echo "Plugin '{$pluginName}' disabled successfully.\n";
            echo "Restart the server for changes to take effect: php server.php restart\n";
        } else {
            echo "Error: Failed to update plugin manifest\n";
        }
    }

    private function validatePlugin(?string $pluginPath): void
    {
        if (!$pluginPath) {
            echo "Error: Plugin path required\n";
            echo "Usage: php plugin-manager.php validate <plugin-path>\n";
            return;
        }

        if (!is_dir($pluginPath)) {
            echo "Error: Plugin directory not found: {$pluginPath}\n";
            return;
        }

        echo "Validating plugin at: {$pluginPath}\n\n";

        // Check for plugin.json
        $manifestPath = $pluginPath . '/plugin.json';
        if (!file_exists($manifestPath)) {
            echo "❌ plugin.json not found\n";
            return;
        }
        echo "✓ plugin.json found\n";

        // Validate JSON
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!$manifest) {
            echo "❌ plugin.json is invalid JSON\n";
            return;
        }
        echo "✓ plugin.json is valid JSON\n";

        // Check required fields
        $requiredFields = ['name', 'version', 'description', 'author', 'main', 'namespace'];
        foreach ($requiredFields as $field) {
            if (!isset($manifest[$field])) {
                echo "❌ Missing required field: {$field}\n";
                return;
            }
            echo "✓ Required field present: {$field}\n";
        }

        // Check main file exists
        $mainFile = $pluginPath . '/' . $manifest['main'];
        if (!file_exists($mainFile)) {
            echo "❌ Main file not found: {$manifest['main']}\n";
            return;
        }
        echo "✓ Main file found: {$manifest['main']}\n";

        // Try to load the main file
        require_once $mainFile;
        $className = $manifest['namespace'] . '\\' . pathinfo($manifest['main'], PATHINFO_FILENAME);

        if (!class_exists($className)) {
            echo "❌ Plugin class not found: {$className}\n";
            return;
        }
        echo "✓ Plugin class found: {$className}\n";

        // Check if implements PluginInterface
        $reflection = new ReflectionClass($className);
        if (!$reflection->implementsInterface('WalkieTalkie\\Plugins\\PluginInterface')) {
            echo "❌ Plugin class does not implement PluginInterface\n";
            return;
        }
        echo "✓ Plugin implements PluginInterface\n";

        echo "\n✓ Plugin is valid!\n";
    }

    private function loadManifest(string $pluginDir): ?array
    {
        $manifestPath = $pluginDir . '/plugin.json';
        if (!file_exists($manifestPath)) {
            return null;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        return $manifest ?: null;
    }

    private function findPlugin(string $pluginName): ?array
    {
        $pluginDirs = array_filter(glob($this->pluginsPath . '*'), 'is_dir');

        foreach ($pluginDirs as $pluginDir) {
            // Check in example-plugins
            if (basename($pluginDir) === 'example-plugins') {
                $exampleDirs = array_filter(glob($pluginDir . '/*'), 'is_dir');
                foreach ($exampleDirs as $exampleDir) {
                    $manifest = $this->loadManifest($exampleDir);
                    if ($manifest && $manifest['name'] === $pluginName) {
                        $manifest['_path'] = $exampleDir;
                        $manifest['_example'] = true;
                        return $manifest;
                    }
                }
                continue;
            }

            $manifest = $this->loadManifest($pluginDir);
            if ($manifest && $manifest['name'] === $pluginName) {
                $manifest['_path'] = $pluginDir;
                $manifest['_example'] = false;
                return $manifest;
            }
        }

        return null;
    }

    private function showHelp(): void
    {
        echo "Walkie-Talkie Plugin Manager\n";
        echo "============================\n\n";
        echo "Usage: php plugin-manager.php <command> [options]\n\n";
        echo "Commands:\n";
        echo "  list [filter]           List installed plugins\n";
        echo "                          filter: all (default), enabled, disabled\n";
        echo "  info <plugin-name>      Show detailed plugin information\n";
        echo "  enable <plugin-name>    Enable a plugin\n";
        echo "  disable <plugin-name>   Disable a plugin\n";
        echo "  validate <plugin-path>  Validate a plugin manifest and structure\n";
        echo "  help                    Show this help message\n\n";
        echo "Examples:\n";
        echo "  php plugin-manager.php list\n";
        echo "  php plugin-manager.php list enabled\n";
        echo "  php plugin-manager.php info rate-limiter\n";
        echo "  php plugin-manager.php enable hello-world\n";
        echo "  php plugin-manager.php disable rate-limiter\n";
        echo "  php plugin-manager.php validate plugins/my-plugin\n";
    }
}

// Run CLI
$cli = new PluginManagerCLI();
$cli->run($argv);
