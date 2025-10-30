<?php
// Prevent caching during development
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walkie Talkie</title>
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="assets/style.css?v=<?php echo time(); ?>">
    <meta name="theme-color" content="#2196F3">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
    <link rel="apple-touch-icon" sizes="192x192" href="assets/icon-192.png">
</head>
<body>
<?php
// Include custom header template if it exists
if (file_exists(__DIR__ . '/../templates/header.php')) {
    include __DIR__ . '/../templates/header.php';
}
?>
    <div id="app">
        <header class="header">
            <h1>🗣️ Walkie Talkie</h1>
            <div class="channel-info">
                <span>Channel: <strong id="channel-display">1</strong></span>
                <span id="participants-count">0 participants</span>
            </div>
            <div id="auth-link" class="auth-link" style="display: none;">
                <a href="/login.html" class="auth-link-btn">🔐 Login / Register</a>
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
                <p>Press and hold the microphone button to talk on the selected Channel</p>
                <p>Make sure to allow microphone access when prompted</p>
            </div>

            <div id="history-panel" class="history-panel">
                <div class="history-header">
                    <h3>Message History</h3>
                    <div class="history-controls">
                        <button id="play-all-btn" class="play-all-btn">Play All</button>
                        <button id="history-toggle" class="history-toggle-btn">Hide History</button>
                    </div>
                </div>
                <div id="history-list" class="history-list">
                    <div class="history-empty">No messages yet</div>
                </div>
            </div>
        </main>

        <footer class="footer">
            <p><a href="https://github.com/awehttam/walkie-talkie-html5" target="_blank" rel="noopener noreferrer">Walkie Talkie</a> by awehttam</p>
        </footer>
    </div>

    <script src="assets/walkie-talkie.js?v=<?php echo time(); ?>"></script>
    <script>
        let deferredPrompt;
        let installButton;

        // Service Worker registration with update handling
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js')
                .then(registration => {
                    console.log('Service Worker registered:', registration);

                    // Check for updates every time the page loads
                    registration.update();

                    // Listen for waiting service worker
                    registration.addEventListener('updatefound', () => {
                        const newWorker = registration.installing;
                        newWorker.addEventListener('statechange', () => {
                            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                // New service worker is available
                                showUpdateNotification(registration);
                            }
                        });
                    });
                })
                .catch(error => {
                    console.error('Service Worker registration failed:', error);
                });

            // Listen for controller change (when new SW takes control)
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                console.log('Service Worker updated, reloading page');
                window.location.reload();
            });
        }

        function showUpdateNotification(registration) {
            const updateButton = document.createElement('button');
            updateButton.textContent = '🔄 Update Available';
            updateButton.className = 'update-btn';
            updateButton.style.cssText = `
                position: fixed;
                top: 10px;
                left: 10px;
                background: #4CAF50;
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
                z-index: 1000;
                animation: pulse 2s infinite;
            `;
            updateButton.addEventListener('click', () => {
                if (registration.waiting) {
                    registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                    updateButton.remove();
                }
            });
            document.body.appendChild(updateButton);

            // Auto-remove after 10 seconds if not clicked
            setTimeout(() => {
                if (document.body.contains(updateButton)) {
                    updateButton.remove();
                }
            }, 10000);
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
<?php
// Include custom footer template if it exists
if (file_exists(__DIR__ . '/../templates/footer.php')) {
    include __DIR__ . '/../templates/footer.php';
}
?>
</body>
</html>