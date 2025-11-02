# Plugin System Architecture

## Overview

This document describes the plugin system architecture for the Walkie-Talkie PWA, enabling 3rd party extensions without modifying core code.

## Current Architecture Analysis

### Technology Stack
- **Backend**: PHP 8.1+, Ratchet WebSocket server (ReactPHP-based)
- **Frontend**: Vanilla JavaScript PWA with Web Audio API
- **Database**: SQLite with PDO
- **Authentication**: WebAuthn/JWT with anonymous mode support

### Key Components
- `src/WebSocketServer.php` - Core WebSocket message handler
- `src/AuthManager.php` - Authentication/authorization logic
- Message handling via `onMessage()` switch statement
- Channel-based communication model
- CLI tools for automation (walkie-cli.php, welcome-manager.php)

### Identified Extension Points

Based on code analysis, the natural extension points are:

1. **Message Processing** - Before/after audio transmission
2. **Connection Lifecycle** - Connect, authenticate, join/leave channel, disconnect
3. **Channel Events** - User joined, user left, channel created
4. **Authentication** - Custom auth providers, screen name validation
5. **Audio Pipeline** - Audio filtering, transcoding, recording
6. **CLI Commands** - Custom CLI tools and automation
7. **Frontend UI** - Custom themes, widgets, controls
8. **Database Operations** - Custom storage backends, analytics

---

## Plugin System Design

### 1. Core Plugin Manager

**Location**: `src/PluginManager.php`

**Responsibilities**:
- Load and initialize plugins
- Manage plugin lifecycle
- Execute hooks with plugin callbacks
- Handle plugin errors and isolation

**Interface**:
```php
class PluginManager {
    private array $plugins = [];
    private array $hooks = [];
    private string $pluginsPath;

    public function __construct(string $pluginsPath = 'plugins/')

    // Plugin Management
    public function registerPlugin(PluginInterface $plugin): void
    public function unregisterPlugin(string $name): void
    public function loadPluginsFromDirectory(string $dir): void
    public function getPlugin(string $name): ?PluginInterface
    public function getAllPlugins(): array
    public function isPluginEnabled(string $name): bool

    // Hook Management
    public function addHook(string $hookName, callable $callback, int $priority = 10): void
    public function removeHook(string $hookName, callable $callback): void
    public function executeHook(string $hookName, ...$args): mixed
    public function hasHook(string $hookName): bool

    // Lifecycle
    public function initializeAll(): void
    public function shutdownAll(): void
}
```

### 2. Plugin Interface

**Location**: `src/Plugins/PluginInterface.php`

```php
namespace WalkieTalkie\Plugins;

interface PluginInterface {
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
```

### 3. Abstract Plugin Base Class

**Location**: `src/Plugins/AbstractPlugin.php`

Provides common functionality for plugins:

```php
namespace WalkieTalkie\Plugins;

abstract class AbstractPlugin implements PluginInterface {
    protected PluginManager $manager;
    protected array $config = [];
    protected string $pluginPath;

    public function __construct(string $pluginPath, array $config = []) {
        $this->pluginPath = $pluginPath;
        $this->config = $config;
    }

    // Helper methods
    protected function getConfig(string $key, mixed $default = null): mixed
    protected function setConfig(string $key, mixed $value): void
    protected function hasPermission(string $permission): bool
    protected function log(string $message, string $level = 'info'): void
    protected function getDatabase(): ?PDO
    protected function broadcastToChannel(string $channel, array $message): void

    // Default implementations
    public function getAuthor(): string { return 'Unknown'; }
    public function getConfigSchema(): array { return []; }
    public function getRequiredPermissions(): array { return []; }
    public function getDependencies(): array { return []; }
    public function onUnload(): void {}
}
```

---

## Hook System

### Hook Types and Event Flow

#### Server Lifecycle Hooks

**`plugin.server.init`**
- **When**: Server initialization, before accepting connections
- **Parameters**: `(PluginManager $manager)`
- **Use cases**: Initialize resources, connect to external services

**`plugin.server.shutdown`**
- **When**: Server shutdown, before process exit
- **Parameters**: `(PluginManager $manager)`
- **Use cases**: Clean up resources, save state

---

#### Connection Hooks

**`plugin.connection.open`**
- **When**: New WebSocket connection established
- **Parameters**: `(ConnectionInterface $conn, &$allowConnection)`
- **Use cases**: IP filtering, connection limiting, logging
- **Can modify**: `$allowConnection` (bool) - set to false to reject connection

**`plugin.connection.authenticate`**
- **When**: After successful authentication (JWT or screen name)
- **Parameters**: `(ConnectionInterface $conn, array $identity)`
- **Use cases**: Log authentication, send welcome messages, initialize user session

**`plugin.connection.close`**
- **When**: WebSocket connection closed
- **Parameters**: `(ConnectionInterface $conn, array $identity)`
- **Use cases**: Clean up resources, log disconnection

**`plugin.screen_name.validate`**
- **When**: User attempts to set screen name (anonymous mode)
- **Parameters**: `(string $screenName, ConnectionInterface $conn, &$isValid, &$errorMessage)`
- **Use cases**: Custom validation rules, profanity filtering
- **Can modify**: `$isValid` (bool), `$errorMessage` (string)

**`plugin.screen_name.generate`**
- **When**: Auto-generating anonymous screen name
- **Parameters**: `(ConnectionInterface $conn, &$screenName)`
- **Use cases**: Custom name generation algorithms
- **Can modify**: `$screenName` (string)

---

#### Channel Hooks

**`plugin.channel.join`**
- **When**: User joining a channel
- **Parameters**: `(ConnectionInterface $conn, string $channelId, array $identity, &$allowJoin)`
- **Use cases**: Access control, channel limits, logging
- **Can modify**: `$allowJoin` (bool)

**`plugin.channel.leave`**
- **When**: User leaving a channel
- **Parameters**: `(ConnectionInterface $conn, string $channelId, array $identity)`
- **Use cases**: Logging, notifications

**`plugin.channel.created`**
- **When**: New channel is created (first user joins)
- **Parameters**: `(string $channelId)`
- **Use cases**: Initialize channel resources, logging

**`plugin.channel.empty`**
- **When**: Channel becomes empty (last user leaves)
- **Parameters**: `(string $channelId)`
- **Use cases**: Clean up channel resources, archiving

---

#### Audio Hooks

**`plugin.audio.transmit.start`**
- **When**: User presses push-to-talk button
- **Parameters**: `(ConnectionInterface $conn, string $channel, array $identity, &$allowTransmission)`
- **Use cases**: Rate limiting, permission checks, notifications
- **Can modify**: `$allowTransmission` (bool)

**`plugin.audio.chunk`**
- **When**: Audio data chunk received during transmission
- **Parameters**: `(ConnectionInterface $conn, string $channel, array &$audioData)`
- **Use cases**: Audio filtering, transcoding, real-time analysis
- **Can modify**: `$audioData` (array with 'data', 'format', 'sampleRate')

**`plugin.audio.transmit.end`**
- **When**: User releases push-to-talk button
- **Parameters**: `(ConnectionInterface $conn, string $channel, array $identity, array $audioData)`
- **Use cases**: Transcription, recording, analytics

**`plugin.audio.save`**
- **When**: Before saving complete transmission to database
- **Parameters**: `(ConnectionInterface $conn, string $channel, array &$messageData, &$shouldSave)`
- **Use cases**: Add metadata, modify retention, prevent saving
- **Can modify**: `$messageData` (array), `$shouldSave` (bool)

**`plugin.audio.broadcast`**
- **When**: Before broadcasting audio to channel participants
- **Parameters**: `(ConnectionInterface $sender, string $channel, array &$message, array &$recipients)`
- **Use cases**: Selective broadcasting, message modification
- **Can modify**: `$message` (array), `$recipients` (array of ConnectionInterface)

---

#### Message Hooks

**`plugin.message.receive`**
- **When**: Any WebSocket message received
- **Parameters**: `(ConnectionInterface $conn, array &$message, &$shouldProcess)`
- **Use cases**: Custom message types, message logging, filtering
- **Can modify**: `$message` (array), `$shouldProcess` (bool)

**`plugin.message.send`**
- **When**: Before sending any message to client
- **Parameters**: `(ConnectionInterface $conn, array &$message, &$shouldSend)`
- **Use cases**: Message modification, filtering, logging
- **Can modify**: `$message` (array), `$shouldSend` (bool)

**`plugin.history.request`**
- **When**: Client requests message history
- **Parameters**: `(ConnectionInterface $conn, string $channel, &$allowRequest)`
- **Use cases**: Access control, rate limiting
- **Can modify**: `$allowRequest` (bool)

**`plugin.history.response`**
- **When**: Before sending history response to client
- **Parameters**: `(ConnectionInterface $conn, string $channel, array &$messages)`
- **Use cases**: Filter messages, add metadata, modify order
- **Can modify**: `$messages` (array)

---

## Plugin Configuration

### Plugin Manifest (`plugin.json`)

Each plugin must have a `plugin.json` file in its directory:

```json
{
    "name": "example-plugin",
    "version": "1.0.0",
    "description": "Example plugin demonstrating the plugin system",
    "author": "Your Name",
    "license": "MIT",
    "main": "ExamplePlugin.php",
    "namespace": "WalkieTalkie\\Plugins\\Example",
    "requires": {
        "php": ">=8.1",
        "walkie-talkie": ">=1.0.0",
        "ext-json": "*"
    },
    "config": {
        "enabled": true,
        "autoload": true,
        "settings": {
            "example_setting": "default_value"
        }
    },
    "hooks": [
        "plugin.audio.transmit.start",
        "plugin.message.receive",
        "plugin.connection.authenticate"
    ],
    "permissions": [
        "database.read",
        "database.write",
        "websocket.broadcast",
        "http.fetch"
    ],
    "dependencies": []
}
```

### Plugin Configuration File (`config.php`)

Optional per-plugin configuration:

```php
<?php
return [
    'enabled' => true,
    'log_level' => 'info',
    'custom_setting' => 'value',
];
```

### Environment Variables

Plugins can read environment variables:

```env
# Plugin Configuration
PLUGINS_ENABLED=true
PLUGINS_PATH=plugins/
PLUGINS_AUTOLOAD=true
PLUGIN_SANDBOX_MEMORY_LIMIT=128M
PLUGIN_SANDBOX_EXECUTION_TIME=30

# Plugin-specific settings (optional)
RATE_LIMITER_MAX_TRANSMISSIONS=10
ANALYTICS_DATABASE_PATH=data/analytics.db
```

---

## Directory Structure

```
walkie-talkie/
├── plugins/
│   ├── .gitignore              # Ignore 3rd party plugins
│   ├── README.md               # Plugin installation instructions
│   ├── example-plugins/        # Example plugins (tracked in git)
│   │   ├── hello-world/
│   │   │   ├── plugin.json
│   │   │   ├── HelloWorldPlugin.php
│   │   │   └── README.md
│   │   ├── profanity-filter/
│   │   │   ├── plugin.json
│   │   │   ├── ProfanityFilterPlugin.php
│   │   │   ├── config.php
│   │   │   └── wordlist.txt
│   │   ├── rate-limiter/
│   │   │   ├── plugin.json
│   │   │   ├── RateLimiterPlugin.php
│   │   │   └── README.md
│   │   └── analytics/
│   │       ├── plugin.json
│   │       ├── AnalyticsPlugin.php
│   │       ├── config.php
│   │       └── schema.sql
│   └── {plugin-name}/         # 3rd party plugins (not tracked)
│       ├── plugin.json        # Required: Plugin manifest
│       ├── {PluginName}.php   # Required: Main plugin class
│       ├── config.php         # Optional: Default configuration
│       ├── README.md          # Optional: Plugin documentation
│       └── assets/            # Optional: Plugin assets
├── src/
│   ├── PluginManager.php
│   ├── Plugins/
│   │   ├── PluginInterface.php
│   │   ├── AbstractPlugin.php
│   │   └── PluginException.php
│   ├── WebSocketServer.php    # Modified to call hooks
│   └── AuthManager.php
├── cli/
│   └── plugin-manager.php     # CLI tool for plugin management
├── public/
│   └── plugins/               # Frontend plugins (optional)
│       └── {plugin-name}/
│           ├── plugin.js
│           └── assets/
└── docs/
    └── PLUGINS.md             # This file
```

---

## Plugin Types & Use Cases

### 1. Audio Processing Plugins

**Profanity Filter**
```php
class ProfanityFilterPlugin extends AbstractPlugin {
    private array $wordlist;

    public function onLoad(PluginManager $manager): void {
        $this->manager = $manager;
        $this->wordlist = $this->loadWordlist();
        $manager->addHook('plugin.audio.save', [$this, 'filterAudio']);
    }

    public function filterAudio(
        ConnectionInterface $conn,
        string $channel,
        array &$messageData,
        bool &$shouldSave
    ): void {
        // Analyze audio for profanity
        // Could integrate with speech-to-text API
        // Mark as filtered or reject save
        if ($this->containsProfanity($messageData['audio_data'])) {
            $messageData['filtered'] = true;
            $this->log("Filtered profanity from transmission");
        }
    }
}
```

**Audio Transcription**
- Hook: `plugin.audio.transmit.end`
- Sends audio to speech-to-text API (Google, AWS, Azure)
- Stores transcript in database alongside audio
- Optionally broadcasts transcript as text message
- Enables searchable message history

**Voice Effects**
- Hook: `plugin.audio.chunk`
- Applies effects in real-time (pitch shift, echo, distortion)
- Useful for voice privacy or entertainment

---

### 2. Security & Moderation Plugins

**Rate Limiter**
```php
class RateLimiterPlugin extends AbstractPlugin {
    private array $transmissionLog = [];

    public function onLoad(PluginManager $manager): void {
        $this->manager = $manager;
        $manager->addHook('plugin.audio.transmit.start', [$this, 'checkRateLimit'], priority: 10);
    }

    public function checkRateLimit(
        ConnectionInterface $conn,
        string $channel,
        array $identity,
        bool &$allowTransmission
    ): void {
        $userId = $conn->resourceId;
        $now = time();
        $limit = $this->getConfig('max_transmissions_per_minute', 10);

        // Clean old entries (older than 1 minute)
        $this->transmissionLog[$userId] = array_filter(
            $this->transmissionLog[$userId] ?? [],
            fn($time) => ($now - $time) < 60
        );

        // Check limit
        if (count($this->transmissionLog[$userId]) >= $limit) {
            $allowTransmission = false;
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Rate limit exceeded. Please wait before transmitting again.'
            ]));
            $this->log("Rate limit exceeded for user {$userId}");
            return;
        }

        // Log this transmission
        $this->transmissionLog[$userId][] = $now;
    }
}
```

**IP Blocklist**
- Hook: `plugin.connection.open`
- Checks IP against blocklist (database or file)
- Closes connection if IP is blocked
- Supports CIDR ranges and wildcard patterns

**Channel Access Control**
- Hook: `plugin.channel.join`
- Implements ACL for channels (private, password-protected, invite-only)
- Checks user permissions before allowing join
- Supports role-based access (admin, moderator, user)

**Spam Detection**
- Hook: `plugin.audio.transmit.end`
- Detects rapid repeated transmissions
- Identifies audio spam (silence, noise, repeated content)
- Temporarily mutes or kicks offenders

---

### 3. Analytics & Logging Plugins

**Usage Analytics**
```php
class AnalyticsPlugin extends AbstractPlugin {
    private PDO $analyticsDb;

    public function onLoad(PluginManager $manager): void {
        $this->manager = $manager;
        $this->initAnalyticsDatabase();

        $manager->addHook('plugin.connection.authenticate', [$this, 'trackConnection']);
        $manager->addHook('plugin.audio.transmit.end', [$this, 'trackTransmission']);
        $manager->addHook('plugin.channel.join', [$this, 'trackChannelJoin']);
    }

    public function trackTransmission(
        ConnectionInterface $conn,
        string $channel,
        array $identity,
        array $audioData
    ): void {
        $this->analyticsDb->prepare('
            INSERT INTO transmissions (user_id, channel, duration, timestamp)
            VALUES (?, ?, ?, ?)
        ')->execute([
            $identity['user_id'] ?? null,
            $channel,
            $audioData['duration'],
            time()
        ]);
    }
}
```

**Metrics tracked:**
- Active users by time period
- Transmissions per channel
- Average transmission duration
- Peak usage times
- Most active users/channels

**Audit Logging**
- Hook: Multiple (all connection, auth, channel hooks)
- Logs all security-relevant events
- Stores: timestamp, user, action, IP, result
- Supports syslog, file, or database output
- Complies with audit requirements

---

### 4. Integration Plugins

**Discord Bridge**
```php
class DiscordBridgePlugin extends AbstractPlugin {
    private $discordBot;

    public function onLoad(PluginManager $manager): void {
        $this->manager = $manager;
        $this->connectToDiscord();

        $manager->addHook('plugin.audio.transmit.end', [$this, 'forwardToDiscord']);
        $manager->addHook('plugin.channel.join', [$this, 'announceJoin']);
    }

    public function forwardToDiscord(
        ConnectionInterface $conn,
        string $channel,
        array $identity,
        array $audioData
    ): void {
        // Convert audio format if needed
        // Send to Discord voice channel
        $this->discordBot->playAudio($channel, $audioData);
    }
}
```

**Slack/Teams Notifications**
- Hook: `plugin.channel.join`, `plugin.audio.transmit.end`
- Posts notifications to Slack/Teams channels
- Configurable notification rules
- Supports @mentions for specific events

**Webhook Integration**
- Hook: Configurable (any hook)
- Sends HTTP POST requests to external URLs
- Configurable payloads and filtering
- Useful for custom integrations

**MQTT Publisher**
- Hook: Multiple
- Publishes events to MQTT broker
- Enables IoT integrations
- Examples: trigger lights, update displays

---

### 5. Feature Enhancement Plugins

**Voice Commands**
- Hook: `plugin.audio.transmit.end`
- Transcribes audio and detects commands
- Examples: "switch to channel 2", "mute all", "replay last message"
- Executes commands automatically
- Responds with text or audio confirmation

**Call Recording**
- Hook: `plugin.audio.transmit.start`, `plugin.audio.transmit.end`
- Records all transmissions to audio files
- Organizes by date, channel, user
- Generates daily/weekly archives
- Supports compression and encryption

**Auto-Reply Bot**
- Hook: `plugin.audio.transmit.end`
- Detects specific keywords or phrases
- Responds with pre-recorded audio
- Examples: "What time is it?" → speaks current time
- FAQ automation

**Channel Announcements**
- Hook: `plugin.channel.join`
- Announces channel rules or MOTD when users join
- Different announcements per channel
- Scheduled announcements (time-based)

---

### 6. Frontend Plugins

**Location**: `public/plugins/{plugin-name}/`

**Custom Theme Plugin**
```javascript
class CustomThemePlugin {
    constructor(config) {
        this.config = config;
    }

    onLoad(uiManager) {
        this.injectCSS();
        this.modifyUI();
    }

    injectCSS() {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = `/plugins/${this.config.name}/theme.css`;
        document.head.appendChild(link);
    }

    modifyUI() {
        // Modify DOM elements, add custom controls
        const pttButton = document.getElementById('pttButton');
        pttButton.classList.add('custom-style');
    }
}
```

**Audio Visualizer Widget**
- Hooks into Web Audio API
- Displays real-time waveform or spectrum analyzer
- Configurable visualization styles
- Shows audio level meters

**Chat Widget**
- Adds text chat alongside voice
- Persists messages in history
- Supports emoji, markdown
- Real-time synchronization

**User List Panel**
- Shows active users in channel
- Displays speaking status
- Shows user avatars/badges
- Click to private message

---

## Permission System

Plugins must declare required permissions in `plugin.json`:

### Available Permissions

**Database Permissions:**
- `database.read` - Read from database
- `database.write` - Write to database
- `database.create` - Create tables
- `database.delete` - Delete records

**WebSocket Permissions:**
- `websocket.broadcast` - Broadcast messages to channels
- `websocket.send` - Send messages to specific connections
- `websocket.close` - Close connections

**Network Permissions:**
- `http.fetch` - Make HTTP requests
- `socket.connect` - Create socket connections

**Filesystem Permissions:**
- `file.read` - Read files
- `file.write` - Write files
- `file.delete` - Delete files

**System Permissions:**
- `exec.command` - Execute system commands (requires admin)
- `env.read` - Read environment variables

### Permission Checking

```php
class ExamplePlugin extends AbstractPlugin {
    public function someMethod(): void {
        if (!$this->hasPermission('database.write')) {
            $this->log('Missing database.write permission', 'error');
            return;
        }

        // Proceed with database operation
        $db = $this->getDatabase();
        // ...
    }
}
```

---

## Security Considerations

### Sandboxing

Plugins run in the same process as the server, so sandboxing is limited:

1. **Permission System** - Declarative permissions checked at runtime
2. **Resource Limits** - Memory and execution time limits via PHP settings
3. **Error Isolation** - Plugin errors don't crash server
4. **Namespace Isolation** - Plugins use separate namespaces

### Code Review

For production deployments:

1. **Review all 3rd party plugins** before installation
2. **Check permissions** - only grant what's necessary
3. **Verify source** - download from trusted sources
4. **Code signing** (optional) - verify plugin authenticity
5. **Test in development** - always test plugins before production

### Best Practices

- **Validate all input** from hooks
- **Use prepared statements** for database queries
- **Sanitize output** before broadcasting
- **Log security events** for audit trails
- **Handle errors gracefully** without crashing
- **Document permissions** clearly in README

---

## CLI Tools

### Plugin Manager CLI

**Location**: `cli/plugin-manager.php`

```bash
# List installed plugins
php cli/plugin-manager.php list
php cli/plugin-manager.php list --enabled
php cli/plugin-manager.php list --disabled

# Show plugin information
php cli/plugin-manager.php info <plugin-name>

# Enable/disable plugins
php cli/plugin-manager.php enable <plugin-name>
php cli/plugin-manager.php disable <plugin-name>

# Install plugin from directory or ZIP
php cli/plugin-manager.php install <path-to-plugin>
php cli/plugin-manager.php install plugin.zip

# Uninstall plugin
php cli/plugin-manager.php uninstall <plugin-name>

# Validate plugin manifest
php cli/plugin-manager.php validate <plugin-path>

# Check for plugin updates (if registry implemented)
php cli/plugin-manager.php check-updates
php cli/plugin-manager.php update <plugin-name>
```

### Plugin Scaffolding Tool

```bash
# Generate plugin skeleton
php cli/plugin-scaffold.php <plugin-name>

# Generate with options
php cli/plugin-scaffold.php <plugin-name> \
    --author "Your Name" \
    --description "Plugin description" \
    --hooks "plugin.audio.transmit.start,plugin.message.receive"
```

Generates:
```
plugins/<plugin-name>/
├── plugin.json
├── <PluginName>Plugin.php
├── config.php
└── README.md
```

---

## Implementation Phases

### Phase 1: Core Infrastructure (Backend)

**Tasks:**
1. Create `src/PluginManager.php`
   - Plugin loading and registration
   - Hook execution system
   - Error handling and logging

2. Create plugin interfaces
   - `src/Plugins/PluginInterface.php`
   - `src/Plugins/AbstractPlugin.php`
   - `src/Plugins/PluginException.php`

3. Integrate hooks into `src/WebSocketServer.php`
   - Add hook calls at all identified extension points
   - Pass appropriate parameters to hooks
   - Handle hook return values (allow/deny, modifications)

4. Update `server.php`
   - Initialize PluginManager
   - Load plugins before starting server
   - Handle plugin errors gracefully

**Deliverables:**
- Functional plugin system with hook support
- Can load and execute plugins
- Proper error handling and logging

---

### Phase 2: Configuration & Security

**Tasks:**
1. Plugin configuration system
   - Load plugin.json manifests
   - Parse and validate configuration
   - Support environment variables
   - Merge default and custom configs

2. Permission system
   - Define available permissions
   - Check permissions before granting access
   - Log permission violations

3. Resource limits
   - Memory limits per plugin
   - Execution time limits
   - Connection/channel limits

4. Dependency management
   - Check PHP version requirements
   - Verify core version compatibility
   - Load plugins in dependency order
   - Handle circular dependencies

**Deliverables:**
- Complete configuration system
- Security and permission framework
- Resource management

---

### Phase 3: CLI Tools

**Tasks:**
1. Create `cli/plugin-manager.php`
   - List, info, enable, disable commands
   - Install/uninstall functionality
   - Validation tools

2. Create `cli/plugin-scaffold.php`
   - Generate plugin skeleton
   - Template system for boilerplate

3. Update existing CLI tools
   - Allow plugins to register custom CLI commands
   - Plugin-specific CLI utilities

**Deliverables:**
- Complete CLI plugin management
- Plugin development tools
- Documentation for CLI usage

---

### Phase 4: Frontend Plugin System

**Tasks:**
1. Create `public/assets/plugin-loader.js`
   - Load frontend plugins dynamically
   - Manage plugin lifecycle
   - Provide UI hooks

2. Define frontend hooks
   - UI initialization
   - Channel events
   - Audio events
   - History rendering

3. Create example frontend plugins
   - Theme switcher
   - Audio visualizer
   - User list panel

**Deliverables:**
- Frontend plugin system
- UI customization capabilities
- Example frontend plugins

---

### Phase 5: Example Plugins & Documentation

**Tasks:**
1. Create example plugins
   - `plugins/example-plugins/hello-world/` - Minimal plugin
   - `plugins/example-plugins/rate-limiter/` - Security plugin
   - `plugins/example-plugins/analytics/` - Data plugin
   - `plugins/example-plugins/profanity-filter/` - Audio processing

2. Write comprehensive documentation
   - Plugin development guide
   - Hook reference with examples
   - API documentation
   - Security best practices
   - Troubleshooting guide

3. Create plugin registry (optional)
   - Curated list of approved plugins
   - Installation via registry
   - Version management

**Deliverables:**
- 4+ example plugins demonstrating different use cases
- Complete developer documentation
- Plugin development guide
- Optional: plugin registry

---

## Migration Path

### Migrating Existing Features

Some current features could be migrated to plugins to demonstrate the system:

1. **Welcome Messages** → Plugin
   - Already somewhat modular
   - Good demonstration of audio hooks
   - Shows database integration

2. **Authentication Providers** → Pluggable
   - Keep WebAuthn/JWT as default
   - Allow plugins to add OAuth, SAML, LDAP
   - Demonstrates extensibility

3. **CLI Tools** → Plugin Commands
   - Plugins can register custom CLI commands
   - Shows CLI integration capabilities

### Backward Compatibility

- All existing functionality remains in core
- Plugins are optional and disabled by default
- Configuration maintains current format
- No breaking changes to API or frontend

---

## Example: Complete Rate Limiter Plugin

### Directory Structure
```
plugins/rate-limiter/
├── plugin.json
├── RateLimiterPlugin.php
├── config.php
└── README.md
```

### plugin.json
```json
{
    "name": "rate-limiter",
    "version": "1.0.0",
    "description": "Prevents users from transmitting too frequently to avoid spam",
    "author": "Walkie-Talkie Team",
    "license": "AGPL-3.0-or-later",
    "main": "RateLimiterPlugin.php",
    "namespace": "WalkieTalkie\\Plugins\\RateLimiter",
    "requires": {
        "php": ">=8.1",
        "walkie-talkie": ">=1.0.0"
    },
    "config": {
        "enabled": true,
        "autoload": true,
        "settings": {
            "max_transmissions_per_minute": 10,
            "cooldown_seconds": 2,
            "exempt_users": []
        }
    },
    "hooks": [
        "plugin.audio.transmit.start"
    ],
    "permissions": [
        "websocket.send"
    ],
    "dependencies": []
}
```

### RateLimiterPlugin.php
```php
<?php
namespace WalkieTalkie\Plugins\RateLimiter;

use WalkieTalkie\Plugins\AbstractPlugin;
use WalkieTalkie\PluginManager;
use Ratchet\ConnectionInterface;

class RateLimiterPlugin extends AbstractPlugin {
    private array $transmissionLog = [];
    private array $lastTransmission = [];

    public function getName(): string {
        return 'rate-limiter';
    }

    public function getVersion(): string {
        return '1.0.0';
    }

    public function getDescription(): string {
        return 'Prevents users from transmitting too frequently';
    }

    public function getAuthor(): string {
        return 'Walkie-Talkie Team';
    }

    public function onLoad(PluginManager $manager): void {
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

    public function onUnload(): void {
        $this->log('Rate limiter plugin unloaded');
    }
}
```

### config.php
```php
<?php
return [
    'enabled' => true,
    'max_transmissions_per_minute' => 10,
    'cooldown_seconds' => 2,
    'exempt_users' => [
        // Add usernames to exempt from rate limiting
        // 'AdminUser',
        // 'BotAccount',
    ],
];
```

### README.md
```markdown
# Rate Limiter Plugin

Prevents users from transmitting too frequently to avoid spam and ensure fair usage.

## Features

- Limits transmissions per minute per user
- Enforces cooldown period between transmissions
- Supports exempt users (admins, bots)
- Provides clear error messages to users

## Configuration

Edit `config.php` or set environment variables:

```php
'max_transmissions_per_minute' => 10,  // Maximum transmissions per minute
'cooldown_seconds' => 2,               // Minimum seconds between transmissions
'exempt_users' => ['AdminUser'],       // Users exempt from rate limiting
```

## Environment Variables

```env
RATE_LIMITER_MAX_TRANSMISSIONS=10
RATE_LIMITER_COOLDOWN=2
```

## Installation

1. Copy plugin to `plugins/rate-limiter/`
2. Restart server: `php server.php restart`
3. Plugin will load automatically

## Usage

Once enabled, the plugin automatically limits transmission rates for all users.
Users who exceed limits will see error messages explaining the restriction.

## Testing

```bash
# Test with curl or CLI tool
php cli/walkie-cli.php send test.wav --channel 1 --screen-name TestUser

# Send multiple times rapidly to trigger rate limit
```

## License

AGPL-3.0-or-later
```

---

## Testing Strategy

### Unit Tests

Test individual plugin components:

```php
class RateLimiterPluginTest extends TestCase {
    public function testRateLimitEnforcement() {
        $plugin = new RateLimiterPlugin('plugins/rate-limiter/', [
            'max_transmissions_per_minute' => 2,
        ]);

        // Simulate multiple transmissions
        // Assert rate limit is enforced
    }
}
```

### Integration Tests

Test plugin integration with server:

```php
class PluginIntegrationTest extends TestCase {
    public function testPluginHooksAreExecuted() {
        $manager = new PluginManager();
        $manager->registerPlugin($testPlugin);

        // Trigger hook
        $manager->executeHook('plugin.audio.transmit.start', $conn, $channel, $identity, $allow);

        // Assert plugin modified behavior
        $this->assertFalse($allow);
    }
}
```

### Manual Testing

1. **Load plugin** - Verify plugin loads without errors
2. **Test hooks** - Trigger each hook and verify plugin responds
3. **Test configuration** - Modify config and verify behavior changes
4. **Test permissions** - Attempt operations without permissions
5. **Test errors** - Trigger error conditions and verify graceful handling

---

## Performance Considerations

### Plugin Load Time

- Lazy loading: only load enabled plugins
- Cache plugin manifests
- Use autoloading for plugin classes

### Hook Execution Overhead

- Minimize hook execution time
- Use priority system to optimize order
- Allow plugins to skip irrelevant hooks

### Memory Management

- Unload inactive plugins
- Limit plugin resource usage
- Monitor memory consumption

### Database Performance

- Use connection pooling
- Cache frequently accessed data
- Optimize plugin queries

---

## Future Enhancements

### Plugin Registry

- Centralized plugin repository
- Version management and updates
- Security scanning and validation
- User ratings and reviews

### Plugin Marketplace

- Browse and install plugins from web UI
- Purchase premium plugins
- Automatic updates

### Advanced Features

- Hot reload: update plugins without server restart
- Plugin isolation: separate processes for plugins
- Plugin API: RESTful API for plugin management
- Plugin testing framework
- Plugin debugging tools

---

## Resources

### Developer Resources

- **Plugin Development Guide**: See docs/PLUGIN_DEVELOPMENT.md
- **API Reference**: See docs/API_REFERENCE.md
- **Example Plugins**: See plugins/example-plugins/
- **Hook Reference**: See docs/HOOKS.md

### Community

- **GitHub Issues**: Report bugs and request features
- **Discussions**: Ask questions and share plugins
- **Wiki**: Community-maintained documentation

---

## Conclusion

This plugin system provides a powerful, flexible framework for extending the Walkie-Talkie PWA without modifying core code. It supports:

- **Easy development** with clear interfaces and examples
- **Security** with permissions and resource limits
- **Flexibility** with comprehensive hook system
- **3rd party support** with manifests and configuration
- **Maintainability** with isolated, self-contained plugins

The system is designed to grow with the project, supporting both simple single-file plugins and complex multi-component extensions.
