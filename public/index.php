<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walkie Talkie</title>
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/style.css">
    <meta name="theme-color" content="#2196F3">
    <link rel="icon" type="image/png" href="assets/icon-192.png">
</head>
<body>
    <div id="app">
        <header class="header">
            <h1>🗣️ Walkie Talkie</h1>
            <div class="channel-info">
                <span>Channel: <strong id="channel-display">1</strong></span>
                <span id="participants-count">0 participants</span>
            </div>
        </header>

        <main class="main">
            <div class="channel-selector">
                <label for="channel-input">Channel:</label>
                <input type="number" id="channel-input" min="1" max="999" value="1" class="channel-input">
                <button id="join-channel-btn" class="join-btn">Join</button>
            </div>

            <div class="status-panel">
                <div id="connection-status" class="status disconnected">
                    <span class="status-dot"></span>
                    <span class="status-text">Disconnected</span>
                </div>

                <div id="speaking-indicator" class="speaking-indicator">
                    <span class="speaking-text">Someone is speaking...</span>
                </div>
            </div>

            <div class="controls">
                <button id="ptt-button" class="ptt-button" disabled>
                    <span class="ptt-icon">🎤</span>
                    <span class="ptt-text">Hold to Talk</span>
                </button>

                <div class="volume-control">
                    <label for="volume">Volume:</label>
                    <input type="range" id="volume" min="0" max="100" value="50">
                </div>

                <div class="beep-control">
                    <label for="courtesy-beep" class="checkbox-label">
                        <input type="checkbox" id="courtesy-beep" checked>
                        <span class="checkmark"></span>
                        Courtesy Beep
                    </label>
                </div>
            </div>

            <div class="instructions">
                <p>Press and hold the microphone button to talk on Channel 1</p>
                <p>Make sure to allow microphone access when prompted</p>
            </div>
        </main>

        <footer class="footer">
            <p>Walkie Talkie PWA - Ready for embedding</p>
        </footer>
    </div>

    <script src="assets/walkie-talkie.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js');
        }

        // Get channel from URL parameter or default to 1
        const urlParams = new URLSearchParams(window.location.search);
        const initialChannel = urlParams.get('channel') || '1';

        const app = new WalkieTalkie({
            serverUrl: 'ws://localhost:8080',
            channel: initialChannel
        });

        app.init();

        // Update URL when channel changes (optional)
        app.on('channel_changed', (data) => {
            const newUrl = new URL(window.location);
            newUrl.searchParams.set('channel', data.channel);
            window.history.replaceState({}, '', newUrl);
        });
    </script>
</body>
</html>