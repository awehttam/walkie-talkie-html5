<?php
/**
 * Hello World Plugin - Example Plugin
 *
 * Copyright (C) 2025 Matthew Asham
 *
 * This program is dual-licensed under AGPL-3.0-or-later or Commercial License.
 * See LICENSE.md for details.
 */

namespace WalkieTalkie\Plugins\HelloWorld;

use WalkieTalkie\Plugins\AbstractPlugin;
use WalkieTalkie\PluginManager;
use Ratchet\ConnectionInterface;

class HelloWorldPlugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'hello-world';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Simple hello world plugin demonstrating the plugin system';
    }

    public function getAuthor(): string
    {
        return 'Walkie-Talkie Team';
    }

    public function onLoad(PluginManager $manager): void
    {
        $this->manager = $manager;
        $this->log('Hello World plugin loading...');

        // Register hooks
        $manager->addHook('plugin.server.init', [$this, 'onServerInit']);
        $manager->addHook('plugin.connection.authenticate', [$this, 'onConnectionAuthenticate']);

        $this->log('Hello World plugin loaded successfully');
    }

    public function onUnload(): void
    {
        $this->log('Hello World plugin unloading... Goodbye!');
    }

    /**
     * Called when server initializes
     */
    public function onServerInit(PluginManager $manager): void
    {
        $greeting = $this->getConfig('greeting_message', 'Hello from the plugin system!');
        $this->log($greeting);
        $this->log('Plugin system is working!');
    }

    /**
     * Called when a user authenticates
     */
    public function onConnectionAuthenticate(ConnectionInterface $conn, array $identity): void
    {
        $screenName = $identity['screen_name'] ?? 'unknown';
        $this->log("User authenticated: {$screenName}");
    }
}
