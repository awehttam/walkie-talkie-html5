# Audio Management CLI Tool

## Overview

This document outlines the design and implementation plan for a command-line utility that sends PCM16 audio to the WebSocket server. The tool supports:

- **Periodic Announcements**: Send pre-recorded audio messages to channels on demand
- **Welcome Messages**: Automatically play messages when users join the server or channels
- **Multiple Audio Formats**: Support for WAV files and raw PCM16 with auto-detection
- **Flexible Authentication**: Use JWT tokens or anonymous screen names

## Architecture

### Components

1. **walkie-cli.php** - Main CLI entry point for sending audio
2. **welcome-manager.php** - CLI tool for managing welcome messages
3. **lib/AudioProcessor.php** - Shared library for audio format handling
4. **lib/WebSocketClient.php** - Shared WebSocket communication library
5. **lib/AudioSender.php** - Shared audio transmission logic
6. **lib/WelcomeManager.php** - Database operations for welcome messages
7. **Server Modifications** - Extend WebSocketServer.php to handle welcome messages

### File Structure

```
walkie-talkie/
├── cli/
│   ├── walkie-cli.php           # Main CLI tool for sending audio
│   ├── welcome-manager.php      # Welcome message management
│   └── lib/
│       ├── AudioProcessor.php   # Audio format handling
│       ├── WebSocketClient.php  # WebSocket client
│       ├── AudioSender.php      # Audio transmission
│       └── WelcomeManager.php   # Welcome message DB operations
├── src/
│   └── WebSocketServer.php      # (Modified) Server with welcome support
└── data/
    └── walkie-talkie.db         # (Extended) Database with welcome_messages table
```

## Detailed Design

### 1. AudioProcessor Library

**Purpose**: Handle multiple audio formats with auto-detection

**Features**:
- Detect file format (WAV vs raw PCM16)
- Parse WAV headers and validate format (PCM, 16-bit, mono)
- Extract audio sample rate
- Convert audio data to base64-encoded PCM16 chunks
- Support both file paths and stdin input

**Key Methods**:
```php
class AudioProcessor {
    public static function loadAudio(string $filePath): array
    public static function detectFormat(string $data): string
    public static function parseWav(string $data): array
    public static function chunkAudio(string $pcm16Data, int $chunkSize): array
    public static function toBase64(string $pcm16Chunk): string
}
```

**Return Format**:
```php
[
    'sample_rate' => 48000,
    'format' => 'pcm16',
    'duration_ms' => 5000,
    'chunks' => ['base64chunk1', 'base64chunk2', ...]
]
```

### 2. WebSocketClient Library

**Purpose**: Establish and maintain WebSocket connections

**Features**:
- Connect to WebSocket server
- Handle authentication (JWT or anonymous)
- Send and receive JSON messages
- Automatic reconnection with exponential backoff
- Heartbeat/ping support

**Key Methods**:
```php
class WebSocketClient {
    public function __construct(string $url)
    public function connect(array $auth = []): bool
    public function send(array $message): bool
    public function receive(float $timeout = 1.0): ?array
    public function close(): void
}
```

**Authentication Formats**:
```php
// JWT token
['token' => 'eyJhbGc...']

// Anonymous screen name
['screen_name' => 'AudioBot123']
```

### 3. AudioSender Library

**Purpose**: High-level audio transmission orchestration

**Features**:
- Combine AudioProcessor and WebSocketClient
- Send audio with proper message protocol
- Handle transmission state (start, chunks, end)
- Report progress to console

**Key Methods**:
```php
class AudioSender {
    public function __construct(WebSocketClient $client)
    public function sendAudio(string $audioFile, string $channel, array $options = []): bool
    public function sendChunk(string $base64Audio, int $sampleRate): bool
}
```

**Message Protocol**:
```json
// Start transmission
{
    "type": "audio_start",
    "channel": "1",
    "screen_name": "AudioBot",
    "sample_rate": 48000
}

// Audio chunks
{
    "type": "audio",
    "channel": "1",
    "audio": "base64_encoded_pcm16...",
    "screen_name": "AudioBot"
}

// End transmission
{
    "type": "audio_end",
    "channel": "1",
    "screen_name": "AudioBot"
}
```

### 4. WelcomeManager Library

**Purpose**: Database operations for welcome messages

**Features**:
- CRUD operations for welcome messages
- Query messages by trigger type
- Validate audio file references
- Track message metadata (created, last played)

**Key Methods**:
```php
class WelcomeManager {
    public function __construct(PDO $db)
    public function addMessage(string $name, string $audioFile, string $trigger, array $options = []): int
    public function getMessages(string $trigger = null): array
    public function deleteMessage(int $id): bool
    public function updateLastPlayed(int $id): void
}
```

**Database Schema**:
```sql
CREATE TABLE welcome_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    audio_file TEXT NOT NULL,
    trigger_type TEXT NOT NULL, -- 'connect', 'channel_join', 'both'
    channel TEXT,               -- NULL for all channels, or specific channel
    enabled INTEGER DEFAULT 1,
    created_at INTEGER NOT NULL,
    last_played_at INTEGER,
    play_count INTEGER DEFAULT 0
);
```

### 5. Main CLI Tool: walkie-cli.php

**Purpose**: Command-line interface for sending audio

**Usage**:
```bash
# Send audio with JWT token
php cli/walkie-cli.php send announcement.wav \
    --channel 1 \
    --token "eyJhbGc..."

# Send audio with screen name
php cli/walkie-cli.php send welcome.wav \
    --channel 1 \
    --screen-name "AudioBot"

# Send raw PCM16 from stdin
cat audio.pcm | php cli/walkie-cli.php send - \
    --channel 1 \
    --screen-name "AudioBot" \
    --sample-rate 48000

# Specify WebSocket URL
php cli/walkie-cli.php send message.wav \
    --channel 1 \
    --screen-name "Bot" \
    --url "ws://example.com:8080"
```

**Command Structure**:
```
walkie-cli.php send <audio-file> [options]

Arguments:
    audio-file          Path to audio file (WAV or PCM16), or "-" for stdin

Options:
    --channel <id>      Channel to send to (required)
    --token <jwt>       JWT access token for authentication
    --screen-name <n>   Screen name for anonymous mode
    --url <url>         WebSocket server URL (default: ws://localhost:8080)
    --sample-rate <r>   Sample rate for raw PCM16 (default: 48000)
    --chunk-size <b>    Chunk size in bytes (default: 4096)
    --verbose           Show detailed progress
```

**Output**:
```
Connecting to ws://localhost:8080...
Connected as: AudioBot
Loading audio: announcement.wav
Format: WAV, 48kHz, 5.2s
Sending to channel: 1
Progress: [##########] 100% (52/52 chunks)
Transmission complete.
```

### 6. Welcome Manager CLI: welcome-manager.php

**Purpose**: Manage automated welcome messages

**Usage**:
```bash
# List all welcome messages
php cli/welcome-manager.php list

# Add welcome message for server connection
php cli/welcome-manager.php add \
    --name "Server Welcome" \
    --audio welcome-server.wav \
    --trigger connect

# Add welcome message for channel join
php cli/welcome-manager.php add \
    --name "Channel 1 Welcome" \
    --audio welcome-channel1.wav \
    --trigger channel_join \
    --channel 1

# Add message for both triggers
php cli/welcome-manager.php add \
    --name "Universal Welcome" \
    --audio welcome-universal.wav \
    --trigger both

# Delete welcome message
php cli/welcome-manager.php delete --id 3

# Disable/enable message
php cli/welcome-manager.php disable --id 2
php cli/welcome-manager.php enable --id 2

# Test a welcome message
php cli/welcome-manager.php test --id 1 --channel 1
```

**Command Structure**:
```
welcome-manager.php <command> [options]

Commands:
    list                List all welcome messages
    add                 Add new welcome message
    delete              Delete welcome message
    enable              Enable welcome message
    disable             Disable welcome message
    test                Test a welcome message

Add Options:
    --name <name>       Friendly name for the message
    --audio <file>      Path to audio file
    --trigger <type>    Trigger type: connect, channel_join, both
    --channel <id>      Specific channel (optional, omit for all)

Delete/Enable/Disable Options:
    --id <id>           Message ID

Test Options:
    --id <id>           Message ID to test
    --channel <id>      Channel to test on
```

**Output Example**:
```
Welcome Messages:
--------------------------------------------------------------------------------
ID  Name                Trigger        Channel  Enabled  Played  File
--------------------------------------------------------------------------------
1   Server Welcome      connect        all      yes      45      welcome-server.wav
2   Channel 1 Welcome   channel_join   1        yes      12      welcome-ch1.wav
3   Universal Welcome   both           all      no       0       welcome-all.wav
```

### 7. Server Modifications

**File**: `src/WebSocketServer.php`

**Changes Required**:

1. **Load Welcome Messages on Startup**:
```php
private $welcomeMessages = [];

public function __construct($host, $port) {
    // ... existing code ...
    $this->loadWelcomeMessages();
}

private function loadWelcomeMessages() {
    $manager = new WelcomeManager($this->db);
    $this->welcomeMessages = [
        'connect' => $manager->getMessages('connect'),
        'channel_join' => $manager->getMessages('channel_join'),
        'both' => $manager->getMessages('both')
    ];
}
```

2. **Play Welcome on Connection**:
```php
public function onOpen(ConnectionInterface $conn) {
    // ... existing authentication ...

    // Play connection welcome messages
    $this->playWelcomeMessages($conn, 'connect', null);
}
```

3. **Play Welcome on Channel Join**:
```php
private function handleJoinChannel($conn, $channel) {
    // ... existing join logic ...

    // Play channel welcome messages
    $this->playWelcomeMessages($conn, 'channel_join', $channel);
}
```

4. **Welcome Message Playback**:
```php
private function playWelcomeMessages(ConnectionInterface $conn, string $trigger, ?string $channel) {
    $messages = array_merge(
        $this->welcomeMessages[$trigger] ?? [],
        $this->welcomeMessages['both'] ?? []
    );

    foreach ($messages as $message) {
        // Check if message applies to this channel
        if ($message['channel'] !== null && $message['channel'] !== $channel) {
            continue;
        }

        if (!$message['enabled']) {
            continue;
        }

        // Load and send audio
        $audioFile = $message['audio_file'];
        if (!file_exists($audioFile)) {
            $this->logger->warning("Welcome audio not found: {$audioFile}");
            continue;
        }

        try {
            $audio = AudioProcessor::loadAudio($audioFile);

            // Send audio_start
            $conn->send(json_encode([
                'type' => 'audio_start',
                'channel' => $channel ?? $conn->channel,
                'screen_name' => 'Server',
                'sample_rate' => $audio['sample_rate']
            ]));

            // Send chunks
            foreach ($audio['chunks'] as $chunk) {
                $conn->send(json_encode([
                    'type' => 'audio',
                    'channel' => $channel ?? $conn->channel,
                    'audio' => $chunk,
                    'screen_name' => 'Server'
                ]));
            }

            // Send audio_end
            $conn->send(json_encode([
                'type' => 'audio_end',
                'channel' => $channel ?? $conn->channel,
                'screen_name' => 'Server'
            ]));

            // Update play count
            $manager = new WelcomeManager($this->db);
            $manager->updateLastPlayed($message['id']);

        } catch (Exception $e) {
            $this->logger->error("Failed to play welcome message: " . $e->getMessage());
        }
    }
}
```

5. **Reload Welcome Messages Command**:
```php
// Add admin command to reload welcome messages without restarting server
private function handleMessage(ConnectionInterface $from, $msg) {
    // ... existing message handling ...

    if ($data['type'] === 'reload_welcome_messages') {
        // Verify admin authentication
        if ($this->isAdmin($from)) {
            $this->loadWelcomeMessages();
            $from->send(json_encode([
                'type' => 'admin_response',
                'message' => 'Welcome messages reloaded'
            ]));
        }
    }
}
```

## Implementation Phases

### Phase 1: Core Libraries (Priority: High)

**Files to Create**:
- `cli/lib/AudioProcessor.php`
- `cli/lib/WebSocketClient.php`
- `cli/lib/AudioSender.php`

**Tasks**:
1. Implement WAV parser with format detection
2. Implement raw PCM16 handling
3. Create base64 chunking logic
4. Build WebSocket client with ReactPHP
5. Implement authentication handshake
6. Create audio transmission protocol

**Testing**:
```bash
# Test audio loading
php -r "require 'cli/lib/AudioProcessor.php';
var_dump(AudioProcessor::loadAudio('test.wav'));"

# Test WebSocket connection
php -r "require 'cli/lib/WebSocketClient.php';
\$client = new WebSocketClient('ws://localhost:8080');
\$client->connect(['screen_name' => 'Test']);
var_dump(\$client->receive());"
```

### Phase 2: Main CLI Tool (Priority: High)

**Files to Create**:
- `cli/walkie-cli.php`

**Tasks**:
1. Implement argument parsing
2. Build send command
3. Add progress reporting
4. Add error handling and validation
5. Write help documentation

**Testing**:
```bash
# Test basic send
php cli/walkie-cli.php send test.wav --channel 1 --screen-name "TestBot"

# Test with JWT
php cli/walkie-cli.php send test.wav --channel 1 --token "$ACCESS_TOKEN"

# Test stdin input
cat test.pcm | php cli/walkie-cli.php send - --channel 1 --screen-name "Bot"

# Test error handling
php cli/walkie-cli.php send nonexistent.wav --channel 1 --screen-name "Bot"
```

### Phase 3: Welcome Message System (Priority: Medium)

**Files to Create**:
- `cli/lib/WelcomeManager.php`
- `cli/welcome-manager.php`
- `migrations/003_add_welcome_messages.php`

**Files to Modify**:
- `src/WebSocketServer.php`

**Tasks**:
1. Create database migration for welcome_messages table
2. Implement WelcomeManager library
3. Build welcome-manager.php CLI tool
4. Add server-side welcome message loading
5. Implement welcome playback on connect/join
6. Add admin reload command

**Testing**:
```bash
# Run migration
php migrations/003_add_welcome_messages.php

# Add welcome messages
php cli/welcome-manager.php add \
    --name "Test Welcome" \
    --audio test-welcome.wav \
    --trigger connect

# List messages
php cli/welcome-manager.php list

# Test server connection (should hear welcome)
# Connect with browser and verify welcome message plays
```

## Audio Protocol Details

### Message Flow

**Client to Server**:
1. Client sends `audio_start` with metadata
2. Client sends multiple `audio` chunks
3. Client sends `audio_end` to finalize

**Server to Clients**:
- Server broadcasts all messages to channel participants
- Each message includes `screen_name` for identification
- Server stores complete transmission in message history

### Chunking Strategy

**Chunk Size**: 4096 bytes (default)
- At 48kHz, 16-bit mono: ~42ms of audio per chunk
- Balances latency vs overhead
- Configurable via `--chunk-size` option

**Base64 Encoding**:
- PCM16 is raw binary data
- Base64 ensures JSON compatibility
- ~33% size increase (acceptable for real-time transmission)

### Sample Rate Handling

**Supported Rates**:
- 8kHz (telephone quality)
- 16kHz (wideband)
- 24kHz (super wideband)
- 48kHz (full band, recommended)

**Auto-detection**:
- WAV files: Read from header
- Raw PCM16: Specify via `--sample-rate` flag

## Configuration

### Environment Variables

Add to `.env`:
```env
# CLI Audio Settings
CLI_DEFAULT_WEBSOCKET_URL=ws://localhost:8080
CLI_DEFAULT_CHANNEL=1
CLI_DEFAULT_CHUNK_SIZE=4096
CLI_AUDIO_STORAGE_PATH=/path/to/audio/files

# Welcome Message Settings
WELCOME_ENABLED=true
WELCOME_MAX_SIZE_MB=10
WELCOME_ALLOWED_FORMATS=wav,pcm
```

### Audio File Storage

**Recommended Structure**:
```
walkie-talkie/
└── data/
    └── audio/
        ├── welcome/
        │   ├── server-welcome.wav
        │   ├── channel1-welcome.wav
        │   └── channel2-welcome.wav
        └── announcements/
            ├── maintenance.wav
            ├── event-start.wav
            └── event-end.wav
```

**Permissions**:
```bash
chmod 755 data/audio
chmod 644 data/audio/**/*.wav
```

## Usage Examples

### Periodic Announcements

**Scenario**: Send hourly announcements via cron

```bash
# crontab -e
0 * * * * cd /path/to/walkie-talkie && \
    php cli/walkie-cli.php send data/audio/announcements/hourly.wav \
    --channel 1 --screen-name "Announcer" \
    >> logs/announcements.log 2>&1
```

### Event Notifications

**Scenario**: Announce event start

```bash
#!/bin/bash
# event-notify.sh

EVENT_NAME="$1"
CHANNEL="$2"

php cli/walkie-cli.php send "data/audio/announcements/${EVENT_NAME}.wav" \
    --channel "$CHANNEL" \
    --screen-name "EventBot" \
    --verbose
```

```bash
./event-notify.sh "tournament-start" 1
```

### Welcome Messages

**Scenario**: Setup welcome messages for all channels

```bash
# Server welcome (all users on connect)
php cli/welcome-manager.php add \
    --name "Server Welcome" \
    --audio data/audio/welcome/server.wav \
    --trigger connect

# Channel-specific welcomes
for channel in 1 2 3; do
    php cli/welcome-manager.php add \
        --name "Channel $channel Welcome" \
        --audio "data/audio/welcome/channel${channel}.wav" \
        --trigger channel_join \
        --channel $channel
done
```

### Testing & Development

**Scenario**: Test audio transmission during development

```bash
# Generate test tone (requires sox)
sox -n -r 48000 -c 1 -b 16 test-tone.wav synth 2 sine 440

# Send test tone
php cli/walkie-cli.php send test-tone.wav \
    --channel 1 \
    --screen-name "TestBot" \
    --verbose

# Or use raw PCM16
sox -n -r 48000 -c 1 -b 16 -t raw test-tone.pcm synth 2 sine 440
cat test-tone.pcm | php cli/walkie-cli.php send - \
    --channel 1 \
    --screen-name "TestBot" \
    --sample-rate 48000
```

## Error Handling

### Common Issues

**Connection Failed**:
```
Error: Failed to connect to ws://localhost:8080
Possible causes:
- WebSocket server is not running
- Incorrect URL or port
- Firewall blocking connection

Try: php server.php status
```

**Authentication Failed**:
```
Error: Authentication failed: Invalid token
Possible causes:
- JWT token expired
- Token from wrong server
- Screen name already in use

Try: Get a fresh token or use a different screen name
```

**Invalid Audio Format**:
```
Error: Unsupported audio format
Expected: WAV (PCM, 16-bit, mono) or raw PCM16
Got: MP3

Try: Convert to WAV first:
ffmpeg -i input.mp3 -ar 48000 -ac 1 -sample_fmt s16 output.wav
```

**File Too Large**:
```
Error: Audio file too large (25MB > 10MB limit)
Suggestion: Split into smaller files or compress audio
```

### Exit Codes

```
0  - Success
1  - Invalid arguments
2  - Connection failed
3  - Authentication failed
4  - Audio processing error
5  - Transmission failed
10 - Database error (welcome-manager only)
```

## Performance Considerations

### Audio Processing

**Memory Usage**:
- Load entire file into memory before transmission
- For large files (>50MB), consider streaming implementation
- Current design prioritizes simplicity over memory efficiency

**Chunking**:
- Smaller chunks = lower latency, higher overhead
- Larger chunks = higher latency, lower overhead
- 4KB default balances both

### WebSocket Connection

**Connection Reuse**:
- Current design: Connect, send, disconnect
- Future optimization: Keep connection alive for multiple sends
- Trade-off: Simplicity vs efficiency

**Concurrent Sends**:
- Multiple CLI instances can send simultaneously
- Server broadcasts to all channel participants
- No locking required (WebSocket is full-duplex)

## Future Enhancements

### Phase 4 (Optional)

1. **Text-to-Speech Integration**:
   ```bash
   php cli/walkie-cli.php tts "Welcome to the server" \
       --channel 1 --voice en-US
   ```

2. **Streaming Mode**:
   ```bash
   # Keep connection alive for multiple sends
   php cli/walkie-cli.php stream --channel 1 --screen-name "DJ"
   # Then pipe audio continuously
   ```

3. **Audio Effects**:
   ```bash
   php cli/walkie-cli.php send audio.wav \
       --channel 1 --screen-name "Bot" \
       --effect "reverb" --effect "compress"
   ```

4. **Scheduled Playback**:
   ```bash
   php cli/welcome-manager.php schedule \
       --audio announcement.wav \
       --time "2024-12-01 14:00:00" \
       --channel 1
   ```

5. **Web UI for Welcome Management**:
   - Admin panel to manage welcome messages
   - Upload audio files via browser
   - Test playback in browser
   - View statistics (play count, last played)

## Dependencies

### PHP Extensions

Required (already installed):
- `sockets` - WebSocket communication
- `pdo_sqlite` - Database access
- `mbstring` - String handling
- `json` - JSON encoding/decoding

### Composer Packages

Required (already installed):
- `react/socket` - WebSocket client
- `react/event-loop` - Async event loop

New dependencies (to add):
```bash
composer require react/socket
```

## Security Considerations

### Authentication

**JWT Tokens**:
- Store securely (environment variables, not in scripts)
- Rotate regularly
- Never commit to version control

**Anonymous Mode**:
- Screen names can be spoofed
- Consider rate limiting for anonymous sends
- Log all transmissions with IP addresses

### Audio Files

**Validation**:
- Check file size limits
- Verify audio format before transmission
- Sanitize file paths (prevent directory traversal)

**Storage**:
- Store audio files outside public web root
- Restrict file permissions (644 for files, 755 for dirs)
- Consider disk quotas for user-uploaded audio

### Welcome Messages

**Admin Only**:
- Restrict welcome message management to admins
- Require authentication for welcome-manager.php
- Log all welcome message changes

**Resource Limits**:
- Limit welcome message duration (e.g., 10 seconds max)
- Limit number of welcome messages per channel
- Prevent welcome message spam on rapid connects

## Testing Strategy

### Unit Tests

**AudioProcessor**:
```php
// Test WAV parsing
testWavParsing()
testInvalidWavFormat()
testRawPcm16()

// Test chunking
testChunkSize()
testBase64Encoding()
```

**WebSocketClient**:
```php
// Test connection
testConnect()
testAuthenticationWithToken()
testAuthenticationWithScreenName()
testReconnection()

// Test messaging
testSendMessage()
testReceiveMessage()
```

### Integration Tests

**End-to-End Send**:
```bash
# Start server
php server.php start

# Send test audio
php cli/walkie-cli.php send test.wav --channel 1 --screen-name "TestBot"

# Verify in browser (open browser, join channel 1, should hear audio)
```

**Welcome Message Flow**:
```bash
# Add welcome message
php cli/welcome-manager.php add \
    --name "Test" --audio test.wav --trigger connect

# Restart server
php server.php restart

# Connect with browser (should hear welcome)
```

## Documentation

### User Documentation

**Files to Create**:
- `docs/CLI_USAGE.md` - Detailed CLI usage guide
- `docs/WELCOME_MESSAGES.md` - Welcome message setup guide
- `docs/AUDIO_FORMATS.md` - Supported formats and conversion

**README Updates**:
- Add CLI tool section
- Document welcome message feature
- Add usage examples

### Developer Documentation

**Files to Create**:
- `docs/AUDIO_PROTOCOL.md` - WebSocket audio protocol specification
- `docs/CLI_ARCHITECTURE.md` - CLI tool architecture and design decisions

**Code Documentation**:
- PHPDoc comments for all classes and methods
- Inline comments for complex logic
- Example usage in class headers

## Migration Guide

### Database Migration

**File**: `migrations/003_add_welcome_messages.php`

```php
<?php
/**
 * Migration: Add Welcome Messages Support
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Connect to database
$dbPath = __DIR__ . '/../data/walkie-talkie.db';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create welcome_messages table
$db->exec('
    CREATE TABLE IF NOT EXISTS welcome_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        audio_file TEXT NOT NULL,
        trigger_type TEXT NOT NULL CHECK(trigger_type IN ("connect", "channel_join", "both")),
        channel TEXT,
        enabled INTEGER DEFAULT 1,
        created_at INTEGER NOT NULL,
        last_played_at INTEGER,
        play_count INTEGER DEFAULT 0
    )
');

// Create indexes
$db->exec('CREATE INDEX IF NOT EXISTS idx_welcome_trigger ON welcome_messages(trigger_type, enabled)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_welcome_channel ON welcome_messages(channel, enabled)');

echo "Migration completed successfully.\n";
echo "Welcome messages table created.\n";
```

**Run Migration**:
```bash
php migrations/003_add_welcome_messages.php
```

### Deployment Checklist

**Before Deployment**:
- [ ] Run database migration
- [ ] Create audio storage directories
- [ ] Set correct file permissions
- [ ] Test CLI tool with sample audio
- [ ] Add welcome messages (if using)
- [ ] Update .env with CLI settings
- [ ] Restart WebSocket server

**After Deployment**:
- [ ] Verify welcome messages play on connect
- [ ] Test CLI audio send from production
- [ ] Monitor server logs for errors
- [ ] Check audio file storage usage
- [ ] Test with multiple concurrent clients

## Conclusion

This plan provides a comprehensive foundation for implementing audio management capabilities in the Walkie Talkie application. The modular design with shared libraries ensures code reusability, while the phased implementation approach allows for incremental development and testing.

**Next Steps**:
1. Begin Phase 1 implementation (Core Libraries)
2. Test thoroughly with various audio formats
3. Proceed to Phase 2 (Main CLI Tool)
4. Implement Phase 3 (Welcome Messages) based on requirements

The system is designed to be extensible, allowing for future enhancements like text-to-speech, audio effects, and scheduled playback without major architectural changes.
