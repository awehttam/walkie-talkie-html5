# Walkie-Talkie Plugins

This directory contains plugins for the Walkie-Talkie PWA.

## Directory Structure

```
plugins/
├── .gitignore              # Ignore 3rd party plugins
├── README.md               # This file
├── example-plugins/        # Example plugins (tracked in git)
│   ├── hello-world/        # Simple example plugin
│   └── rate-limiter/       # Rate limiting plugin
└── {your-plugin}/          # Your custom plugins (not tracked)
```

## Installing Plugins

### From Example Plugins

1. Copy an example plugin from `example-plugins/` to the `plugins/` directory
2. Edit the plugin's `plugin.json` and set `"enabled": true`
3. Restart the server: `php server.php restart`

### Installing 3rd Party Plugins

1. Download or clone the plugin into the `plugins/` directory
2. Verify the `plugin.json` manifest
3. Configure the plugin (edit `config.php` if present)
4. Set `"enabled": true` in `plugin.json`
5. Restart the server

## Creating Your Own Plugin

See the [Plugin Development Guide](../docs/PLUGINS.md) for detailed information.

### Quick Start

1. Create a directory in `plugins/` with your plugin name
2. Create `plugin.json` manifest file
3. Create your main plugin PHP file implementing `PluginInterface`
4. Optionally create `config.php` for configuration
5. Enable and test your plugin

### Minimal Plugin Structure

```
my-plugin/
├── plugin.json          # Required: Plugin manifest
├── MyPlugin.php         # Required: Main plugin class
├── config.php          # Optional: Configuration
└── README.md           # Optional: Documentation
```

## Example Plugins

### Hello World
A simple demonstration plugin that logs messages on server events.
- Location: `example-plugins/hello-world/`
- Hooks: `plugin.server.init`, `plugin.connection.authenticate`
- Purpose: Learn the basics of plugin development

### Rate Limiter
Prevents users from transmitting too frequently to avoid spam.
- Location: `example-plugins/rate-limiter/`
- Hooks: `plugin.audio.transmit.start`
- Purpose: Demonstrates audio transmission control

## Available Hooks

See [docs/PLUGINS.md](../docs/PLUGINS.md) for a complete list of available hooks:

- **Server Lifecycle**: `plugin.server.init`, `plugin.server.shutdown`
- **Connection**: `plugin.connection.open`, `plugin.connection.authenticate`, `plugin.connection.close`
- **Channels**: `plugin.channel.join`, `plugin.channel.leave`, `plugin.channel.created`, `plugin.channel.empty`
- **Audio**: `plugin.audio.transmit.start`, `plugin.audio.transmit.end`, `plugin.audio.chunk`
- **Messages**: `plugin.message.receive`, `plugin.message.send`
- **Screen Names**: `plugin.screen_name.validate`, `plugin.screen_name.generate`

## Configuration

Plugins can be configured via:

1. **plugin.json** - Plugin manifest and default settings
2. **config.php** - Plugin-specific configuration file
3. **Environment variables** - System-wide settings

### Disabling the Plugin System

Set in `.env`:
```env
PLUGINS_ENABLED=false
```

### Custom Plugins Directory

Set in `.env`:
```env
PLUGINS_PATH=custom-plugins/
```

## Security

- Review all 3rd party plugins before installation
- Only grant necessary permissions
- Test plugins in development first
- Keep plugins updated
- Report security issues to plugin authors

## Resources

- **Plugin Development Guide**: [docs/PLUGINS.md](../docs/PLUGINS.md)
- **Example Plugins**: [example-plugins/](example-plugins/)
- **API Reference**: [docs/API_REFERENCE.md](../docs/API_REFERENCE.md) (if available)

## License

Example plugins are licensed under AGPL-3.0-or-later.
3rd party plugins have their own licenses - check each plugin's LICENSE file.
