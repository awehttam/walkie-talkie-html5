# Walkie Talkie PWA

A real-time voice communication Progressive Web App built with PHP, designed to be easily embeddable in third-party websites.

## Features

- **Real-time Voice Communication**: Push-to-talk functionality with instant audio transmission
- **WebAuthn/Passkey Authentication**: Passwordless login with biometrics or security keys
  - Multi-device support (Windows Hello, Touch ID, Android, YubiKey, etc.)
  - Optional anonymous mode with auto-generated screen names
  - Secure JWT-based session management
- **User Identity**: Screen names displayed in messages and history
  - Unique screen names enforced across authenticated and anonymous users
  - Persistent screen names for registered users
  - Auto-generated random names for anonymous users (e.g., "BoldEagle742")
- **Message History**: Automatic recording and playback of recent transmissions
  - Configurable retention (message count and age limits)
  - Individual and sequential playback controls
  - Visual history panel with timestamps and user identities
  - Live updates as users speak
- **Channel Support**: Multiple channels with isolated message histories
- **CLI Audio Tools**: Powerful command-line utilities for automation and management
  - **Audio Sender**: Programmatically send pre-recorded audio to any channel
  - **Welcome Messages**: Automated greetings on user connect or channel join
  - **Bot Integration**: Create audio bots for announcements and automated responses
  - **Multiple Formats**: Support for WAV files and raw PCM16 audio
  - **Example Audio**: Sample files included for testing (tones, notifications, welcome messages)
- **Progressive Web App**: Installable, offline-capable, responsive design
- **Embeddable**: Can be embedded in iframes or linked directly
- **Cross-platform**: Works on desktop and mobile devices

### Demonstration

- [Live Demo](https://html5-walkie-talkie.demo.asham.ca/)
  - **Note**: If you don't see the message history panel, perform a hard refresh (Ctrl+Shift+R on Windows/Linux, Cmd+Shift+R on Mac) or clear your browser cache to load the latest version.

## Prerequisites

Before installation, ensure you have:

- **PHP 8.1 or higher** with the following extensions:
  - `pdo_sqlite` - Required for message history storage
  - `mbstring` - For string handling
  - `sockets` - For WebSocket server
- **Composer** - For PHP dependency management

**To check if extensions are installed:**
```bash
php -m | grep -E 'pdo_sqlite|mbstring|sockets'
```

**To install missing extensions:**
```bash
# Ubuntu/Debian
sudo apt-get install php-sqlite3 php-mbstring

# CentOS/RHEL
sudo yum install php-pdo php-mbstring

# macOS (with Homebrew)
brew install php
```

## Installation

1. **Install Dependencies**
   ```bash
   composer install
   ```

2. **Configure Environment (Optional)**
   Copy `.env.example` to `.env` and customize as needed:
   ```bash
   cp .env.example .env
   ```
   See [Production Deployment](#production-deployment) section for details on configuration options.

3. **Start the WebSocket Server**
   ```bash
   # Start as daemon (recommended for production)
   php server.php start

   # Or run directly for development
   php server.php
   ```
   The WebSocket server will run on `ws://localhost:8080`

   **Daemon commands:**
   ```bash
   php server.php start           # Start daemon
   php server.php stop            # Stop daemon
   php server.php restart         # Restart daemon
   php server.php status          # Check status
   php server.php start --quiet   # Start with suppressed "already running" message (for cron)
   ```

4. **Serve the Web Files**
   Set up a web server pointing to the `public/` directory. For development:
   ```bash
   cd public
   php -S localhost:3000
   ```

## Usage

### Standalone Application
Visit `http://localhost:3000/` to access the full walkie talkie interface.

### Embedded Version
Embed in an iframe:
```html
<iframe
    src="http://localhost:3000/embed.php?channel=1&theme=default&width=300px&height=200px"
    width="300"
    height="200"
    frameborder="0">
</iframe>
```

### Embed Parameters
- `channel`: Channel number (default: "1")
- `theme`: UI theme ("default", "dark", "minimal")
- `width`: Embed width (default: "100%")
- `height`: Embed height (default: "300px")

## CLI Tools

The application includes powerful command-line utilities for programmatic audio management and automated announcements.

### Overview

The CLI tools enable:
- **Automated Announcements**: Send pre-recorded audio to channels (system alerts, notifications, scheduled messages)
- **Welcome Messages**: Greet users automatically when they connect or join channels
- **Bot Integration**: Create audio bots for announcements, time checks, or automated responses
- **Testing**: Inject test audio for development and quality assurance

Example audio files are provided in `examples/audio/` for testing and reference.

### Generating JWT Tokens (generate-token.php)

Generate JWT tokens for authenticated users and create service accounts for automation:

```bash
# List all registered users
php cli/generate-token.php --list

# Generate token for an existing user
php cli/generate-token.php username

# Create a service account and generate token (for bots/automation)
php cli/generate-token.php --create AudioBot
php cli/generate-token.php --create NotificationSystem
```

**Service Accounts**:
- **Purpose**: Bot accounts for automated announcements and CLI tools
- **No Passkey Required**: Service accounts don't need WebAuthn credentials
- **Same Permissions**: Can send audio and join channels like regular users
- **Persistent**: Remains in database, generate new tokens as needed

**Prerequisites**:
- `JWT_SECRET` must be configured in `.env`
- For regular users: Must be registered via `/login.html` with WebAuthn/passkey
- For service accounts: Use `--create` flag to create instantly

**Token Information**:
- Default expiration: 1 hour (configurable via `JWT_ACCESS_EXPIRATION`)
- Tokens are tied to specific user accounts
- Include username and user ID in payload
- Generate new tokens when expired

**Example Workflow**:
```bash
# Create a bot account
php cli/generate-token.php --create AudioBot

# Use the token to send announcements
php cli/walkie-cli.php send announcement.wav --channel 1 --token "eyJhbG..."

# Later, generate a new token for the same account
php cli/generate-token.php AudioBot
```

### Sending Audio (walkie-cli.php)

Send pre-recorded audio files to channels for announcements or notifications:

```bash
# Send audio with screen name
php cli/walkie-cli.php send announcement.wav --channel 1 --screen-name "AudioBot"

# Send audio with JWT token
php cli/walkie-cli.php send message.wav --channel 1 --token "eyJhbGc..."

# Send raw PCM16 from stdin
cat audio.pcm | php cli/walkie-cli.php send - --channel 1 --screen-name "Bot" --sample-rate 48000

# Specify custom WebSocket URL
php cli/walkie-cli.php send msg.wav --channel 1 --screen-name "Bot" --url "ws://example.com:8080"
```

**Supported Audio Formats**:
- WAV files (PCM, 16-bit, mono) - format detected automatically
- Raw PCM16 data (requires --sample-rate flag)

**Options**:
- `--channel <id>` - Channel to send to (required)
- `--token <jwt>` - JWT access token for authentication
- `--screen-name <name>` - Screen name for anonymous mode
- `--url <url>` - WebSocket server URL (default: ws://localhost:8080)
- `--sample-rate <rate>` - Sample rate for raw PCM16 (default: 48000)
- `--chunk-size <bytes>` - Chunk size in bytes (default: 4096)
- `--verbose` - Show detailed progress

### Managing Welcome Messages (welcome-manager.php)

Configure automated welcome messages that play when users connect or join channels:

```bash
# List all welcome messages
php cli/welcome-manager.php list

# Add server welcome message (plays on connect)
php cli/welcome-manager.php add \
    --name "Server Welcome" \
    --audio data/audio/welcome.wav \
    --trigger connect

# Add channel-specific welcome (plays on channel join)
php cli/welcome-manager.php add \
    --name "Channel 1 Welcome" \
    --audio data/audio/channel1.wav \
    --trigger channel_join \
    --channel 1

# Add message for both triggers
php cli/welcome-manager.php add \
    --name "Universal Welcome" \
    --audio data/audio/welcome-all.wav \
    --trigger both

# Delete, enable, or disable messages
php cli/welcome-manager.php delete --id 3
php cli/welcome-manager.php disable --id 2
php cli/welcome-manager.php enable --id 2

# Test a welcome message
php cli/welcome-manager.php test --id 1 --channel 1

# Show statistics
php cli/welcome-manager.php stats
```

**Setup**:
1. Run database migration: `php migrations/003_add_welcome_messages.php`
2. Add welcome messages using the CLI tool
3. Restart WebSocket server: `php server.php restart`

**Trigger Types**:
- `connect` - Plays when user authenticates or sets screen name
- `channel_join` - Plays when user joins a channel
- `both` - Plays on both events

**Features**:
- Multiple welcome messages per trigger type
- Channel-specific or server-wide messages
- Enable/disable messages without deleting
- Playback statistics and testing tools
- Automated playback on user events

**Example Audio Files**:

The `examples/audio/` directory includes sample files to get started:

```
examples/audio/
├── welcome/
│   ├── server-welcome.wav          # General server greeting
│   ├── server-welcome-tones.wav    # Tone-based welcome
│   ├── channel1-welcome.wav        # Channel 1 specific
│   └── channel2-welcome.wav        # Channel 2 specific
└── announcements/
    ├── notification.wav             # Generic notification sound
    ├── test-tone-440hz.wav         # A4 test tone
    └── test-tone-1000hz.wav        # 1kHz test tone
```

Copy these to `data/audio/` and reference them in your welcome message configuration.

**Environment Variables**:
```env
WELCOME_ENABLED=true                    # Enable/disable welcome messages
CLI_DEFAULT_WEBSOCKET_URL=ws://localhost:8080
CLI_DEFAULT_SAMPLE_RATE=48000
CLI_DEFAULT_CHUNK_SIZE=4096
```

**Use Cases**:
- Greet new users with instructions or server rules
- Play channel-specific announcements (e.g., "Welcome to Tech Support")
- Add audio branding to your walkie talkie instance
- Provide audible feedback for successful authentication

## Architecture

### Backend Components
- **WebSocket Server** (`src/WebSocketServer.php`): Handles real-time communication
- **ReactPHP**: Powers the WebSocket server
- **Audio Streaming**: Base64-encoded PCM16 audio chunks
- **Message History**: SQLite database with WAL mode for concurrent access
- **Automatic Cleanup**: Age-based and count-based message retention

### Frontend Components
- **PWA Features**: Service worker, manifest, offline support
- **Audio Pipeline**: Web Audio API → WebSocket → Broadcast to participants
- **Responsive Design**: Optimized for both standalone and embedded use

### File Structure
```
walkie-talkie/
├── public/
│   ├── index.php          # Main application
│   ├── embed.php          # Embeddable version
│   ├── manifest.json      # PWA manifest
│   ├── sw.js              # Service worker
│   └── assets/
│       ├── style.css      # Main styles
│       ├── embed.css      # Embed-specific styles
│       └── walkie-talkie.js # Core JavaScript
├── cli/
│   ├── walkie-cli.php     # CLI tool for sending audio
│   ├── welcome-manager.php # CLI tool for managing welcome messages
│   ├── generate-token.php  # JWT token generator for authenticated users
│   └── lib/
│       ├── AudioProcessor.php   # Audio format handling
│       ├── WebSocketClient.php  # WebSocket client
│       ├── AudioSender.php      # Audio transmission
│       └── WelcomeManager.php   # Welcome message DB operations
├── src/
│   └── WebSocketServer.php # WebSocket server implementation
├── data/
│   └── walkie-talkie.db   # SQLite database (auto-created)
├── migrations/
│   └── 003_add_welcome_messages.php # Welcome messages migration
├── docs/
│   └── AUDIOMANAGER.md    # CLI tools implementation plan
├── server.php             # Server startup script
├── composer.json          # PHP dependencies
├── .env.example           # Environment configuration template
└── README.md              # This file
```

## How It Works

1. **Authentication**: Users can register/login with passkeys or use anonymous mode with a screen name
2. **Connection**: Users connect to the WebSocket server with their identity (JWT token or screen name)
3. **Channel Join**: Join a channel to start communicating with others on that channel
4. **Push-to-Talk**: Hold the microphone button to start recording
5. **Audio Transmission**: Audio is captured, encoded as PCM16, converted to base64, and sent via WebSocket
6. **Broadcasting**: Server broadcasts audio to all channel participants with sender's screen name
7. **Message Storage**: Complete transmissions are saved to SQLite database with user identity and retention policies
8. **Playback**: Recipients decode and play the audio instantly, with access to message history showing who spoke

## Browser Support

- **Chrome/Edge**: Full support
- **Firefox**: Full support
- **Safari**: Full support (iOS 14.3+)
- **Mobile browsers**: Optimized for touch interfaces

## Authentication

This application now supports **WebAuthn/passkeys** for passwordless authentication with optional anonymous mode.

### Quick Setup

1. **Generate JWT Secret**:
   ```bash
   openssl rand -base64 64
   ```

2. **Update your `.env` file**:
   ```env
   # WebAuthn Configuration
   WEBAUTHN_RP_NAME="Walkie Talkie"
   WEBAUTHN_RP_ID=localhost                    # Change to your domain in production
   WEBAUTHN_ORIGIN=http://localhost:3000       # Change to your URL in production

   # JWT Configuration
   JWT_SECRET=<your-generated-secret-here>
   JWT_ACCESS_EXPIRATION=3600                  # 1 hour
   JWT_REFRESH_EXPIRATION=604800               # 7 days

   # Authentication Settings
   ANONYMOUS_MODE_ENABLED=true                 # Allow unauthenticated users
   REGISTRATION_ENABLED=true                   # Allow new user registration
   ```

3. **Run database migration**:
   ```bash
   php migrations/001_add_authentication.php
   ```

### Features

- **Passwordless Login**: Use biometrics (fingerprint, face ID) or security keys
- **Multi-device Support**: Register multiple passkeys per account (phone, laptop, YubiKey, etc.)
- **Anonymous Mode**: Optional guest access with temporary screen names
- **Unique Screen Names**: Enforced across all users (registered + anonymous)
- **JWT Tokens**: Secure, stateless session management
- **Automatic Refresh**: Tokens refresh automatically before expiration

### User Experience

**Registered Users**:
- Visit `/login.html` to create account or login
- Use biometrics or security key for authentication
- Persistent screen name across sessions
- Manage multiple passkeys from `/passkeys.html`

**Anonymous Users** (if enabled):
- Prompted to choose a screen name on first visit (or auto-generated if cancelled)
- Auto-generated names follow pattern: AdjectiveNoun### (e.g., "BoldEagle742")
- Screen name validated for uniqueness across all users
- Persists for browser session, lost on disconnect

### Configuration Options

```env
# Disable anonymous mode (require login)
ANONYMOUS_MODE_ENABLED=false

# Disable new registrations (existing users only)
REGISTRATION_ENABLED=false

# Screen name rules
SCREEN_NAME_MIN_LENGTH=2
SCREEN_NAME_MAX_LENGTH=20
SCREEN_NAME_PATTERN=^[a-zA-Z0-9_-]+$
```

### Important Notes

- **HTTPS Required**: WebAuthn only works over HTTPS (except localhost)
- **RP ID**: Must match your domain exactly (no port, no protocol)
  - For `example.com` → use `WEBAUTHN_RP_ID=example.com`
  - For `sub.example.com` → use `WEBAUTHN_RP_ID=sub.example.com`
- **Origin**: Must include protocol and port
  - For `https://example.com` → use `WEBAUTHN_ORIGIN=https://example.com`
  - For `https://example.com:8443` → use `WEBAUTHN_ORIGIN=https://example.com:8443`

For detailed implementation guide, see [docs/WEBAUTHN.md](docs/WEBAUTHN.md)

## Security Considerations

- **HTTPS required** for microphone access and WebAuthn in production
- **JWT Secret**: Generate strong secret, never commit to version control
- **Passkeys**: Phishing-resistant, private keys never leave device
- **Token Security**: Access tokens in localStorage, refresh tokens in HTTP-only cookies
- Configure CORS headers for cross-origin embedding
- Rate limiting on authentication endpoints (5 attempts per 5 minutes)
- All database queries use prepared statements (SQL injection protection)

## Production Deployment

### Running as a System Service

For production environments, set up the WebSocket server to run automatically:

1. **Add to crontab** (Linux/macOS):
   ```bash
   crontab -e
   ```
   Add these lines (update path to your installation):
   ```cron
   # Start daemon at system reboot
   @reboot cd /path/to/walkie-talkie && php server.php start --quiet

   # Check every 5 minutes and start if not running (quiet mode suppresses "already running" spam)
   */5 * * * * cd /path/to/walkie-talkie && php server.php start --quiet
   ```

   **Note**: The `--quiet` flag suppresses console output when the daemon is already running, preventing cron email spam while still logging to `walkie-talkie.log`.

2. **Log files**:
   - Daemon logs: `walkie-talkie.log`
   - PID file: `walkie-talkie.pid`

3. **Environment Configuration**:
   Create a `.env` file to customize settings (or copy `.env.example`):
   ```env
   # Server listening configuration (where the server binds)
   WEBSOCKET_HOST=0.0.0.0      # IP address the server listens on
   WEBSOCKET_PORT=8080          # Port the server listens on

   # Client connection URL (what browsers use to connect)
   WEBSOCKET_URL=ws://localhost:8080

   # Message History Configuration
   MESSAGE_HISTORY_MAX_COUNT=10    # Maximum messages per channel
   MESSAGE_HISTORY_MAX_AGE=300     # Maximum age in seconds (5 minutes)

   # Optional settings
   DEBUG=false
   ```

   **Important**: The distinction between server and client settings:
   - `WEBSOCKET_HOST` and `WEBSOCKET_PORT`: Define where the WebSocket server **listens** for connections
     - Use `0.0.0.0` to listen on all network interfaces (recommended for production)
     - Use `127.0.0.1` to listen only on localhost (more secure for development)
   - `WEBSOCKET_URL`: Defines the actual URL that client browsers use to **connect** to the server
     - This is embedded in the web interface and used by JavaScript to establish connections
     - Can differ from the server settings when using proxies, load balancers, or public domains
     - Use `ws://` for development or `wss://` for secure production connections
     - Examples:
       - Local development: `ws://localhost:8080`
       - Behind proxy: `wss://your-domain.com/ws`
       - Public IP: `ws://203.0.113.45:8080`

   **Message History Configuration**:
   - `MESSAGE_HISTORY_MAX_COUNT`: Maximum number of messages to keep per channel (default: 10)
   - `MESSAGE_HISTORY_MAX_AGE`: Maximum message age in seconds (default: 300 = 5 minutes)
   - Messages are deleted when EITHER limit is exceeded
   - Examples:
     - Short-term: `MESSAGE_HISTORY_MAX_COUNT=5` and `MESSAGE_HISTORY_MAX_AGE=60` (1 minute)
     - Long-term: `MESSAGE_HISTORY_MAX_COUNT=100` and `MESSAGE_HISTORY_MAX_AGE=86400` (24 hours)
   - Database stored in `data/walkie-talkie.db` (auto-created)

## Customization

### Custom Templates

You can inject custom HTML into the header and footer of all pages:

1. **Create template files:**
   ```bash
   # Copy example templates
   cp templates/header.php.example templates/header.php
   cp templates/footer.php.example templates/footer.php
   ```

2. **Customize the templates:**
   - `templates/header.php` - Included right after `</head>` (top of body)
   - `templates/footer.php` - Included right before `</body>` (bottom of body)

3. **Example use cases:**
   - Analytics (Google Analytics, Plausible, etc.)
   - Chat widgets (Intercom, Drift, etc.)
   - Custom banners or notifications
   - Additional tracking scripts
   - Custom CSS or JavaScript

**Note:** These files are excluded from git, so they won't be overwritten during updates.

## Development

### Adding New Channels
1. Update the WebSocket server to handle dynamic channels
2. Modify the frontend to allow channel selection
3. Update the embed.php to accept channel parameters

### Extending Features
- **User authentication**: Add login system
- **Channel management**: Create/join/leave channels
- **Per-channel configuration**: Different retention settings per channel
- **Video support**: Add webcam functionality
- **Export functionality**: Download message history

## Troubleshooting

**Microphone access denied**: Ensure HTTPS in production, allow microphone permissions

**WebSocket connection failed**: Check if server.php is running on port 8080

**No audio playback**: Verify browser autoplay policies and volume settings

**Embed not loading**: Check CORS headers and iframe permissions

**Message history not saving**:
- Ensure `pdo_sqlite` PHP extension is installed: `php -m | grep pdo_sqlite`
- Ensure `data/` directory exists and is writable (chmod 755 or 777)
- Check server logs: `tail -f walkie-talkie.log`

**Database locked errors**: WAL mode should prevent this, but check file permissions on `data/` directory

**Message history panel not visible on live demo**: Clear browser cache or perform hard refresh (Ctrl+Shift+R / Cmd+Shift+R)

## License

Dual licensed. AGPL-3.0 License for Open Source. Commercial/Non Open Source usage requires a separate licensing agreement.

See [LICENSE.md](LICENSE.md) for details.
