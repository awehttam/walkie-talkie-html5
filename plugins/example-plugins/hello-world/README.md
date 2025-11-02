# Hello World Plugin

A simple example plugin that demonstrates the basic plugin system functionality.

## Features

- Logs messages when server initializes
- Logs when users authenticate
- Demonstrates configuration usage

## Installation

This plugin is included as an example. To enable it:

1. Edit `plugin.json` and set `"enabled": true`
2. Restart the server: `php server.php restart`

## Configuration

Edit `plugin.json` to customize the greeting message:

```json
"settings": {
    "greeting_message": "Your custom message here!"
}
```

## Usage

Once enabled, the plugin will automatically:
- Print a greeting message when the server starts
- Log authentication events

## License

AGPL-3.0-or-later
