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

1. Edit `plugin.json` and set `"enabled": true`
2. Restart server: `php server.php restart`
3. Plugin will load automatically

## Usage

Once enabled, the plugin automatically limits transmission rates for all users.
Users who exceed limits will see error messages explaining the restriction.

## Testing

```bash
# Test with CLI tool
php cli/walkie-cli.php send test.wav --channel 1 --screen-name TestUser

# Send multiple times rapidly to trigger rate limit
```

## License

AGPL-3.0-or-later
