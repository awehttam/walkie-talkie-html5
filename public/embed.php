<?php
$channel = $_GET['channel'] ?? '1';
$theme = $_GET['theme'] ?? 'default';
$width = $_GET['width'] ?? '100%';
$height = $_GET['height'] ?? '300px';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walkie Talkie - Channel <?php echo htmlspecialchars($channel); ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/embed.css">
    <style>
        body {
            margin: 0;
            padding: 10px;
            height: <?php echo htmlspecialchars($height); ?>;
            width: <?php echo htmlspecialchars($width); ?>;
            overflow: hidden;
        }
    </style>
</head>
<body class="embed-mode theme-<?php echo htmlspecialchars($theme); ?>">
<?php
// Include custom header template if it exists
if (file_exists(__DIR__ . '/../templates/header.php')) {
    include __DIR__ . '/../templates/header.php';
}
?>
    <div id="embed-app">
        <div class="embed-header">
            <span class="channel-badge">Ch. <?php echo htmlspecialchars($channel); ?></span>
            <div id="connection-status" class="status disconnected">
                <span class="status-dot"></span>
            </div>
            <span id="participants-count">0</span>
        </div>

        <div class="embed-main">
            <div class="embed-channel-selector">
                <input type="number" id="channel-input" min="1" max="999" value="<?php echo htmlspecialchars($channel); ?>" class="embed-channel-input">
                <button id="join-channel-btn" class="embed-join-btn">Join</button>
            </div>

            <div id="speaking-indicator" class="speaking-indicator compact">
                <span class="speaking-pulse"></span>
            </div>

            <button id="ptt-button" class="ptt-button compact" disabled>
                <span class="ptt-icon">🎤</span>
            </button>

            <div class="volume-control compact">
                <input type="range" id="volume" min="0" max="100" value="50">
            </div>

            <div class="beep-control compact">
                <label for="courtesy-beep" class="checkbox-label compact">
                    <input type="checkbox" id="courtesy-beep" checked>
                    <span class="checkmark compact"></span>
                </label>
            </div>
        </div>
    </div>

    <script src="assets/walkie-talkie.js"></script>
    <script>
        const app = new WalkieTalkie({
            channel: '<?php echo htmlspecialchars($channel); ?>',
            embedMode: true,
            configUrl: 'config.php'
        });

        app.init();

        // Update channel badge when channel changes
        app.on('channel_changed', (data) => {
            const channelBadge = document.querySelector('.channel-badge');
            if (channelBadge) {
                channelBadge.textContent = `Ch. ${data.channel}`;
            }
        });

        // Communicate with parent window if in iframe
        if (window.parent !== window) {
            app.on('connected', () => {
                window.parent.postMessage({
                    type: 'walkie-talkie-connected',
                    channel: '<?php echo htmlspecialchars($channel); ?>'
                }, '*');
            });

            app.on('speaking', (data) => {
                window.parent.postMessage({
                    type: 'walkie-talkie-speaking',
                    speaking: data.speaking
                }, '*');
            });

            app.on('channel_changed', (data) => {
                window.parent.postMessage({
                    type: 'walkie-talkie-channel-changed',
                    channel: data.channel
                }, '*');
            });
        }
    </script>
<?php
// Include custom footer template if it exists
if (file_exists(__DIR__ . '/../templates/footer.php')) {
    include __DIR__ . '/../templates/footer.php';
}
?>
</body>
</html>