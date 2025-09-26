<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walkie Talkie</title>
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/style.css">
    <meta name="theme-color" content="#2196F3">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
    <link rel="apple-touch-icon" sizes="192x192" href="assets/icon-192.png">
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
            <p>Walkie Talkie by <a href="https://github.com/awehttam/walkie-talkie-html5" target="_blank" rel="noopener noreferrer">awehttam</a></p>
        </footer>
    </div>

    <script src="assets/walkie-talkie.js"></script>
    <script>
        let deferredPrompt;
        let installButton;

        // Service Worker registration
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js');
        }

        // PWA Install prompt handling
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            showInstallButton();
        });

        function showInstallButton() {
            if (!installButton) {
                installButton = document.createElement('button');
                installButton.textContent = '📱 Install App';
                installButton.className = 'install-btn';
                installButton.style.cssText = `
                    position: fixed;
                    top: 10px;
                    right: 10px;
                    background: #2196F3;
                    color: white;
                    border: none;
                    padding: 8px 16px;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 14px;
                    z-index: 1000;
                `;
                installButton.addEventListener('click', installApp);
                document.body.appendChild(installButton);
            }
        }

        async function installApp() {
            if (!deferredPrompt) return;

            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;

            if (outcome === 'accepted') {
                console.log('PWA installed');
            }

            deferredPrompt = null;
            if (installButton) {
                installButton.remove();
                installButton = null;
            }
        }

        // Hide install button if already installed
        window.addEventListener('appinstalled', () => {
            if (installButton) {
                installButton.remove();
                installButton = null;
            }
            console.log('PWA was installed');
        });

        // Get channel from URL parameter or default to 1
        const urlParams = new URLSearchParams(window.location.search);
        const initialChannel = urlParams.get('channel') || '1';

        const app = new WalkieTalkie({
            channel: initialChannel,
            configUrl: 'config.php'
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