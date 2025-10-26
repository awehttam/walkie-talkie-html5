# Walkie Talkie PWA

A real-time voice communication Progressive Web App built with PHP, designed to be easily embeddable in third-party websites.

## Features

- **Real-time Voice Communication**: Push-to-talk functionality with instant audio transmission
- **Channel Support**: Currently supports multiple channels
- **Progressive Web App**: Installable, offline-capable, responsive design
- **Embeddable**: Can be embedded in iframes or linked directly
- **Cross-platform**: Works on desktop and mobile devices
- **WebRTC Integration**: High-quality audio with noise suppression and echo cancellation

### Demonstration

- [Live Demo](https://html5-walkie-talkie.demo.asham.ca/)
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
   php server.php start    # Start daemon
   php server.php stop     # Stop daemon
   php server.php restart  # Restart daemon
   php server.php status   # Check status
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

## Architecture

### Backend Components
- **WebSocket Server** (`src/WebSocketServer.php`): Handles real-time communication
- **ReactPHP**: Powers the WebSocket server
- **Audio Streaming**: Base64-encoded WebM audio chunks

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
├── src/
│   └── WebSocketServer.php # WebSocket server implementation
├── server.php             # Server startup script
├── composer.json          # PHP dependencies
└── README.md             # This file
```

## How It Works

1. **Connection**: Users connect to the WebSocket server and join Channel 1
2. **Push-to-Talk**: Hold the microphone button to start recording
3. **Audio Transmission**: Audio is captured, encoded as WebM, converted to base64, and sent via WebSocket
4. **Broadcasting**: Server broadcasts audio to all channel participants except the sender
5. **Playback**: Recipients decode and play the audio instantly

## Browser Support

- **Chrome/Edge**: Full support
- **Firefox**: Full support
- **Safari**: Full support (iOS 14.3+)
- **Mobile browsers**: Optimized for touch interfaces

## Security Considerations

- HTTPS required for microphone access in production
- Configure CORS headers for cross-origin embedding
- Consider implementing user authentication for production use

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
   @reboot cd /path/to/walkie-talkie && php server.php start

   # Check every 5 minutes and start if not running
   */5 * * * * cd /path/to/walkie-talkie && php server.php start
   ```

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

## Development

### Adding New Channels
1. Update the WebSocket server to handle dynamic channels
2. Modify the frontend to allow channel selection
3. Update the embed.php to accept channel parameters

### Extending Features
- **User authentication**: Add login system
- **Channel management**: Create/join/leave channels
- **Audio recording**: Save conversations
- **Video support**: Add webcam functionality

## Troubleshooting

**Microphone access denied**: Ensure HTTPS in production, allow microphone permissions
**WebSocket connection failed**: Check if server.php is running on port 8080
**No audio playback**: Verify browser autoplay policies and volume settings
**Embed not loading**: Check CORS headers and iframe permissions

## License

MIT License - feel free to use and modify for your projects.
