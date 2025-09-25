class WalkieTalkie {
    constructor(options = {}) {
        this.serverUrl = options.serverUrl || null; // Will be fetched from config if not provided
        this.channel = options.channel || '1';
        this.embedMode = options.embedMode || false;
        this.configUrl = options.configUrl || '../config.php';

        this.ws = null;
        this.audioContext = null;
        this.mediaRecorder = null;
        this.audioStream = null;
        this.isRecording = false;
        this.isConnected = false;
        this.participants = 0;

        this.eventListeners = {};
        this.audioChunks = [];
        this.playbackQueue = [];
        this.isPlaying = false;
        this.courtesyBeepEnabled = true;
    }

    async init() {
        this.setupUI();
        await this.loadConfig();
        this.connectWebSocket();
        this.requestMicrophoneAccess();
        this.setupNotifications();
        this.trackAppVisibility();
    }

    async loadConfig() {
        if (this.serverUrl) {
            // Server URL was provided in constructor options
            return;
        }

        try {
            const response = await fetch(this.configUrl);
            if (!response.ok) {
                throw new Error(`Config fetch failed: ${response.status}`);
            }

            const config = await response.json();
            this.serverUrl = config.websocketUrl;

            if (config.debug) {
                console.log('Debug mode enabled, loaded config:', config);
            }
        } catch (error) {
            console.warn('Failed to load config, using default WebSocket URL:', error);
            this.serverUrl = 'ws://localhost:8080';
        }
    }

    on(event, callback) {
        if (!this.eventListeners[event]) {
            this.eventListeners[event] = [];
        }
        this.eventListeners[event].push(callback);
    }

    emit(event, data) {
        if (this.eventListeners[event]) {
            this.eventListeners[event].forEach(callback => callback(data));
        }
    }

    setupUI() {
        this.pttButton = document.getElementById('ptt-button');
        this.connectionStatus = document.getElementById('connection-status');
        this.speakingIndicator = document.getElementById('speaking-indicator');
        this.participantsCount = document.getElementById('participants-count');
        this.volumeControl = document.getElementById('volume');
        this.channelInput = document.getElementById('channel-input');
        this.joinChannelBtn = document.getElementById('join-channel-btn');
        this.channelDisplay = document.getElementById('channel-display');
        this.courtesyBeepToggle = document.getElementById('courtesy-beep');

        // Setup channel switching
        if (this.channelInput && this.joinChannelBtn) {
            this.channelInput.value = this.channel;

            this.joinChannelBtn.addEventListener('click', () => {
                this.switchChannel();
            });

            this.channelInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.switchChannel();
                }
            });

            // Validate channel input
            this.channelInput.addEventListener('input', (e) => {
                const value = parseInt(e.target.value);
                if (value < 1 || value > 999 || isNaN(value)) {
                    e.target.setCustomValidity('Channel must be between 1 and 999');
                    this.joinChannelBtn.disabled = true;
                } else {
                    e.target.setCustomValidity('');
                    this.joinChannelBtn.disabled = false;
                }
            });
        }

        if (this.pttButton) {
            this.pttButton.addEventListener('mousedown', () => this.startTalking());
            this.pttButton.addEventListener('mouseup', () => this.stopTalking());
            this.pttButton.addEventListener('touchstart', (e) => {
                e.preventDefault();
                this.startTalking();
            });
            this.pttButton.addEventListener('touchend', (e) => {
                e.preventDefault();
                this.stopTalking();
            });

            // Keyboard support
            this.pttButton.addEventListener('keydown', (e) => {
                if (e.code === 'Space' || e.code === 'Enter') {
                    e.preventDefault();
                    if (!this.isRecording) this.startTalking();
                }
            });
            this.pttButton.addEventListener('keyup', (e) => {
                if (e.code === 'Space' || e.code === 'Enter') {
                    e.preventDefault();
                    this.stopTalking();
                }
            });
        }

        if (this.volumeControl) {
            this.volumeControl.addEventListener('input', (e) => {
                this.setVolume(e.target.value / 100);
            });
        }

        // Setup courtesy beep toggle
        if (this.courtesyBeepToggle) {
            this.courtesyBeepToggle.checked = this.courtesyBeepEnabled;
            this.courtesyBeepToggle.addEventListener('change', (e) => {
                this.courtesyBeepEnabled = e.target.checked;
                console.log('Courtesy beep', this.courtesyBeepEnabled ? 'enabled' : 'disabled');

                // Play test beep when enabled
                if (this.courtesyBeepEnabled) {
                    setTimeout(() => {
                        this.generateCourtesyBeep();
                    }, 100);
                }
            });
        }
    }

    connectWebSocket() {
        try {
            this.ws = new WebSocket(this.serverUrl);

            this.ws.onopen = () => {
                console.log('WebSocket connected');
                this.isConnected = true;
                this.updateConnectionStatus('connected');
                this.joinChannel();
                this.emit('connected');
            };

            this.ws.onmessage = (event) => {
                const data = JSON.parse(event.data);
                this.handleWebSocketMessage(data);
            };

            this.ws.onclose = () => {
                console.log('WebSocket disconnected');
                this.isConnected = false;
                this.updateConnectionStatus('disconnected');
                setTimeout(() => this.connectWebSocket(), 3000);
            };

            this.ws.onerror = (error) => {
                console.error('WebSocket error:', error);
                this.updateConnectionStatus('error');
            };
        } catch (error) {
            console.error('Failed to connect WebSocket:', error);
            this.updateConnectionStatus('error');
        }
    }

    handleWebSocketMessage(data) {
        switch (data.type) {
            case 'channel_joined':
                this.participants = data.participants;
                this.updateParticipantsCount();
                console.log(`Joined channel ${data.channel}`);
                break;

            case 'participant_joined':
                this.participants = data.participants;
                this.updateParticipantsCount();
                break;

            case 'audio_data':
                if (data.format === 'pcm16') {
                    this.playPCMAudio(data.data, data.sampleRate || 44100, data.channels || 1);
                } else {
                    this.playAudio(data.data, data.mimeType || 'audio/webm');
                }
                break;

            case 'user_speaking':
                this.updateSpeakingIndicator(data.speaking);
                this.emit('speaking', { speaking: data.speaking });

                // Notify service worker when someone starts speaking
                if (data.speaking) {
                    this.sendToServiceWorker('TRANSMISSION_STARTED', {
                        channel: this.channel
                    });
                }
                break;
        }
    }

    switchChannel() {
        if (!this.channelInput || !this.isConnected) return;

        const newChannel = this.channelInput.value.trim();
        const channelNum = parseInt(newChannel);

        if (channelNum < 1 || channelNum > 999 || isNaN(channelNum)) {
            alert('Please enter a valid channel number between 1 and 999');
            return;
        }

        if (newChannel === this.channel) {
            console.log('Already on channel', newChannel);
            return;
        }

        // Stop any current recording
        if (this.isRecording) {
            this.stopTalking();
        }

        // Leave current channel
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify({
                type: 'leave_channel',
                channel: this.channel
            }));
        }

        // Update channel
        this.channel = newChannel;
        this.updateChannelDisplay();

        // Notify service worker of channel change
        this.sendToServiceWorker('CHANNEL_CHANGED', { channel: newChannel });

        // Join new channel
        this.joinChannel();

        console.log('Switched to channel', newChannel);
        this.emit('channel_changed', { channel: newChannel });
    }

    joinChannel() {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify({
                type: 'join_channel',
                channel: this.channel
            }));

            // Also notify service worker of current channel
            this.sendToServiceWorker('CHANNEL_CHANGED', { channel: this.channel });
        }
    }

    updateChannelDisplay() {
        if (this.channelDisplay) {
            this.channelDisplay.textContent = this.channel;
        }
        if (this.channelInput) {
            this.channelInput.value = this.channel;
        }
    }

    async requestMicrophoneAccess() {
        try {
            this.audioStream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                    sampleRate: 44100
                }
            });

            this.audioContext = new (window.AudioContext || window.webkitAudioContext)({
                sampleRate: 44100
            });

            if (this.pttButton) {
                this.pttButton.disabled = false;
                this.pttButton.querySelector('.ptt-text').textContent = 'Hold to Talk';
            }

            console.log('Microphone access granted');
        } catch (error) {
            console.error('Microphone access denied:', error);
            if (this.pttButton) {
                this.pttButton.querySelector('.ptt-text').textContent = 'Mic Access Denied';
            }
        }
    }

    startTalking() {
        if (!this.audioStream || !this.isConnected || this.isRecording) return;

        try {
            // Use Web Audio API for real-time audio processing
            this.setupWebAudioStreaming();

            this.isRecording = true;
            this.pttButton.classList.add('recording');

            this.ws.send(JSON.stringify({
                type: 'push_to_talk_start',
                channel: this.channel
            }));

            console.log('Started real-time audio streaming');
        } catch (error) {
            console.error('Failed to start streaming:', error);
        }
    }

    setupWebAudioStreaming() {
        // Create audio context if not exists
        if (!this.audioContext) {
            this.audioContext = new (window.AudioContext || window.webkitAudioContext)({
                sampleRate: 44100
            });
        }

        // Resume audio context if suspended
        if (this.audioContext.state === 'suspended') {
            this.audioContext.resume();
        }

        // Create source from microphone stream
        this.microphoneSource = this.audioContext.createMediaStreamSource(this.audioStream);

        // Create script processor for audio processing
        this.scriptProcessor = this.audioContext.createScriptProcessor(4096, 1, 1);

        this.scriptProcessor.onaudioprocess = (event) => {
            if (!this.isRecording) return;

            const inputBuffer = event.inputBuffer;
            const audioData = inputBuffer.getChannelData(0);

            // Convert Float32Array to PCM16
            const pcm16 = new Int16Array(audioData.length);
            for (let i = 0; i < audioData.length; i++) {
                pcm16[i] = Math.max(-32768, Math.min(32767, audioData[i] * 32768));
            }

            // Send PCM data
            this.sendPCMAudio(pcm16);
        };

        // Connect audio nodes
        this.microphoneSource.connect(this.scriptProcessor);
        this.scriptProcessor.connect(this.audioContext.destination);
    }

    stopTalking() {
        if (!this.isRecording) return;

        this.isRecording = false;
        this.pttButton.classList.remove('recording');

        // Disconnect Web Audio nodes
        if (this.scriptProcessor) {
            this.scriptProcessor.disconnect();
            this.scriptProcessor = null;
        }
        if (this.microphoneSource) {
            this.microphoneSource.disconnect();
            this.microphoneSource = null;
        }

        // Play courtesy beep after a short delay
        setTimeout(() => {
            this.generateCourtesyBeep();
        }, 100);

        this.ws.send(JSON.stringify({
            type: 'push_to_talk_end',
            channel: this.channel
        }));

        console.log('Stopped audio streaming');
    }

    sendPCMAudio(pcmData) {
        try {
            // Convert PCM16 to base64
            const uint8Array = new Uint8Array(pcmData.buffer);
            let binaryString = '';
            for (let i = 0; i < uint8Array.length; i++) {
                binaryString += String.fromCharCode(uint8Array[i]);
            }
            const base64Audio = btoa(binaryString);

            this.ws.send(JSON.stringify({
                type: 'audio_data',
                channel: this.channel,
                data: base64Audio,
                format: 'pcm16',
                sampleRate: 44100,
                channels: 1
            }));
        } catch (error) {
            console.error('Failed to send PCM audio:', error);
        }
    }

    async sendAudio(audioBlob) {
        try {
            const reader = new FileReader();
            reader.onload = () => {
                const base64Audio = btoa(reader.result);
                this.ws.send(JSON.stringify({
                    type: 'audio_data',
                    channel: this.channel,
                    data: base64Audio,
                    mimeType: audioBlob.type // Send the MIME type with the audio
                }));
            };
            reader.readAsBinaryString(audioBlob);
        } catch (error) {
            console.error('Failed to send audio:', error);
        }
    }

    async playPCMAudio(base64Data, sampleRate, channels) {
        try {
            if (!this.audioContext) {
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)({
                    sampleRate: sampleRate
                });
            }

            // Resume audio context if suspended
            if (this.audioContext.state === 'suspended') {
                await this.audioContext.resume();
            }

            // Decode base64 to PCM data
            const binaryString = atob(base64Data);
            const uint8Array = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                uint8Array[i] = binaryString.charCodeAt(i);
            }

            // Convert to Int16Array (PCM16)
            const pcm16 = new Int16Array(uint8Array.buffer);

            // Convert PCM16 to Float32Array for Web Audio API
            const float32Array = new Float32Array(pcm16.length);
            for (let i = 0; i < pcm16.length; i++) {
                float32Array[i] = pcm16[i] / 32768.0;
            }

            // Create audio buffer
            const audioBuffer = this.audioContext.createBuffer(channels, float32Array.length, sampleRate);
            audioBuffer.getChannelData(0).set(float32Array);

            // Create buffer source
            const source = this.audioContext.createBufferSource();
            source.buffer = audioBuffer;

            // Apply volume
            const gainNode = this.audioContext.createGain();
            const volume = this.volumeControl ? this.volumeControl.value / 100 : 0.5;
            gainNode.gain.value = volume;

            // Connect and play
            source.connect(gainNode);
            gainNode.connect(this.audioContext.destination);
            source.start();

            console.log('Playing PCM audio chunk:', float32Array.length, 'samples');

        } catch (error) {
            console.error('Failed to play PCM audio:', error);
        }
    }

    async playAudio(base64Data, mimeType = 'audio/webm') {
        try {
            // Decode base64 to binary data
            const binaryString = atob(base64Data);
            const bytes = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }

            // Create audio blob with the correct MIME type
            const audioBlob = new Blob([bytes], { type: mimeType });
            console.log('Received audio chunk:', audioBlob.size, 'bytes, type:', mimeType);

            // Queue audio for sequential playback to avoid overlapping
            this.playbackQueue.push(audioBlob);

            if (!this.isPlaying) {
                this.processPlaybackQueue();
            }

        } catch (error) {
            console.error('Failed to decode audio:', error);
        }
    }

    async processPlaybackQueue() {
        if (this.playbackQueue.length === 0) {
            this.isPlaying = false;
            return;
        }

        this.isPlaying = true;
        const audioBlob = this.playbackQueue.shift();

        try {
            const audioUrl = URL.createObjectURL(audioBlob);
            const audio = new Audio(audioUrl);

            // Set volume
            audio.volume = this.volumeControl ? this.volumeControl.value / 100 : 0.5;

            // Add debug logging
            console.log('Playing audio chunk, size:', audioBlob.size, 'bytes');

            audio.addEventListener('ended', () => {
                URL.revokeObjectURL(audioUrl);
                // Process next audio chunk
                this.processPlaybackQueue();
            });

            audio.addEventListener('error', (e) => {
                console.error('Audio playback error:', e);
                URL.revokeObjectURL(audioUrl);
                // Continue with next chunk even if this one failed
                this.processPlaybackQueue();
            });

            // Attempt to play
            await audio.play();

        } catch (error) {
            console.error('Failed to play audio chunk:', error);
            // Continue with next chunk
            this.processPlaybackQueue();
        }
    }

    updateConnectionStatus(status) {
        if (!this.connectionStatus) return;

        this.connectionStatus.className = `status ${status}`;
        const statusText = this.connectionStatus.querySelector('.status-text');

        if (statusText) {
            switch (status) {
                case 'connected':
                    statusText.textContent = 'Connected';
                    break;
                case 'disconnected':
                    statusText.textContent = 'Disconnected';
                    break;
                case 'error':
                    statusText.textContent = 'Connection Error';
                    break;
            }
        }
    }

    updateSpeakingIndicator(speaking) {
        if (!this.speakingIndicator) return;

        if (speaking) {
            this.speakingIndicator.classList.add('active');
        } else {
            this.speakingIndicator.classList.remove('active');
        }
    }

    updateParticipantsCount() {
        if (!this.participantsCount) return;

        if (this.embedMode) {
            this.participantsCount.textContent = this.participants;
        } else {
            this.participantsCount.textContent = `${this.participants} participants`;
        }
    }

    setVolume(volume) {
        // Volume is handled per-audio element during playback
        console.log('Volume set to:', volume);
    }

    generateCourtesyBeep() {
        if (!this.courtesyBeepEnabled || !this.audioContext) return;

        try {
            // Create a short beep sound (800Hz for 150ms)
            const duration = 0.15; // 150ms
            const frequency = 800; // 800Hz
            const sampleRate = this.audioContext.sampleRate;
            const frameCount = sampleRate * duration;

            // Create audio buffer
            const audioBuffer = this.audioContext.createBuffer(1, frameCount, sampleRate);
            const channelData = audioBuffer.getChannelData(0);

            // Generate sine wave with envelope
            for (let i = 0; i < frameCount; i++) {
                const t = i / sampleRate;
                // Sine wave
                const sine = Math.sin(2 * Math.PI * frequency * t);
                // Envelope (fade in/out to avoid clicks)
                const envelope = Math.sin(Math.PI * t / duration);
                channelData[i] = sine * envelope * 0.3; // 30% volume
            }

            // Play the beep
            const source = this.audioContext.createBufferSource();
            source.buffer = audioBuffer;

            const gainNode = this.audioContext.createGain();
            const volume = this.volumeControl ? this.volumeControl.value / 100 : 0.5;
            gainNode.gain.value = volume * 0.5; // Beep at half user volume

            source.connect(gainNode);
            gainNode.connect(this.audioContext.destination);
            source.start();

            console.log('Playing courtesy beep');

        } catch (error) {
            console.error('Failed to generate courtesy beep:', error);
        }
    }

    async setupNotifications() {
        if (!('Notification' in window) || !('serviceWorker' in navigator)) {
            console.log('Notifications not supported');
            return;
        }

        try {
            // Request notification permission
            let permission = Notification.permission;

            if (permission === 'default') {
                permission = await Notification.requestPermission();
            }

            const granted = permission === 'granted';
            console.log('Notification permission:', granted ? 'granted' : 'denied');

            // Send permission status to service worker
            this.sendToServiceWorker('NOTIFICATION_PERMISSION', { granted });

        } catch (error) {
            console.error('Failed to setup notifications:', error);
        }
    }

    trackAppVisibility() {
        // Track if app is visible/focused
        const updateAppState = () => {
            const isActive = !document.hidden && document.hasFocus();
            this.sendToServiceWorker('APP_STATE_CHANGED', { isActive });
        };

        // Listen for visibility changes
        document.addEventListener('visibilitychange', updateAppState);
        window.addEventListener('focus', updateAppState);
        window.addEventListener('blur', updateAppState);

        // Initial state
        updateAppState();
    }

    sendToServiceWorker(type, data) {
        if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({ type, data });
        }
    }
}

// Global access for embed mode
window.WalkieTalkie = WalkieTalkie;