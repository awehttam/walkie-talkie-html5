class WalkieTalkie {
    constructor(options = {}) {
        this.serverUrl = options.serverUrl || null; // Will be fetched from config if not provided
        this.channel = options.channel || '1';
        this.embedMode = options.embedMode || false;
        this.configUrl = options.configUrl || 'config.php';

        this.ws = null;
        this.audioContext = null;
        this.mediaRecorder = null;
        this.audioStream = null;
        this.isRecording = false;

        // Generate unique client ID to prevent hearing own audio
        this.clientId = 'client_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        this.isConnected = false;
        this.participants = 0;

        // Authentication
        this.accessToken = null;
        this.currentUser = null;
        this.isAnonymous = false;
        this.screenName = null;
        this.tokenRefreshTimer = null;
        this.config = null;

        // Track notification rate limiting (1 hour = 3600000ms)
        this.lastNotificationTime = 0;
        this.notificationCooldown = 300; // 5 minutes

        // Track when we're speaking to prevent self-notifications
        this.isSpeaking = false;

        // Track app visibility for notifications
        this.isAppActive = true;
        this.setupVisibilityTracking();

        this.eventListeners = {};
        this.audioChunks = [];
        this.playbackQueue = [];
        this.isPlaying = false;
        this.courtesyBeepEnabled = true;

        // Message history
        this.messageHistory = [];
        this.isPlayingHistory = false;
        this.currentHistoryIndex = -1;
        this.currentPlayingHistoryIndex = -1;
        this.singlePlaybackTimeout = null;
        this.currentAudioSource = null;
    }

    async init() {
        this.setupUI();
        await this.loadConfig();
        await this.checkAuthentication();
        this.connectWebSocket();
        this.requestMicrophoneAccess();
        this.setupNotifications();
        this.trackAppVisibility();
    }

    async loadConfig() {
        if (this.serverUrl && this.config) {
            // Server URL and config were provided in constructor options
            return;
        }

        try {
            const response = await fetch(this.configUrl);
            if (!response.ok) {
                throw new Error(`Config fetch failed: ${response.status}`);
            }

            this.config = await response.json();
            this.serverUrl = this.config.websocketUrl;

            if (this.config.debug) {
                console.log('Debug mode enabled, loaded config:', this.config);
            }
        } catch (error) {
            console.warn('Failed to load config, using defaults:', error);
            this.serverUrl = 'ws://localhost:8080';
            this.config = {
                websocketUrl: this.serverUrl,
                anonymousModeEnabled: true,
                registrationEnabled: true
            };
        }
    }

    async checkAuthentication() {
        // Check for existing access token
        this.accessToken = localStorage.getItem('access_token');

        if (this.accessToken) {
            // Validate and get user info
            try {
                const response = await fetch('/auth/user-info.php', {
                    headers: {
                        'Authorization': `Bearer ${this.accessToken}`
                    }
                });

                const result = await response.json();
                if (result.success) {
                    this.currentUser = result.user;
                    this.screenName = result.user.username;
                    this.showAuthenticatedUI();
                    this.scheduleTokenRefresh();
                    console.log('Authenticated as:', this.screenName);
                } else {
                    // Token invalid, clear it
                    localStorage.removeItem('access_token');
                    this.accessToken = null;
                }
            } catch (error) {
                console.error('Failed to validate token:', error);
                localStorage.removeItem('access_token');
                this.accessToken = null;
            }
        }

        // Check if anonymous mode allowed
        if (!this.accessToken && !this.config.anonymousModeEnabled) {
            // Redirect to login
            window.location.href = '/login.html';
            return;
        }

        if (!this.accessToken && this.config.anonymousModeEnabled) {
            // Prompt for screen name
            this.promptForScreenName();
        }
    }

    promptForScreenName() {
        const screenName = prompt('Choose a screen name (2-20 characters, letters/numbers/underscore/hyphen):');
        if (!screenName) {
            alert('Screen name is required');
            this.promptForScreenName();
            return;
        }

        // Basic validation
        const pattern = new RegExp(this.config.screenNamePattern || '^[a-zA-Z0-9_-]+$');
        const minLength = this.config.screenNameMinLength || 2;
        const maxLength = this.config.screenNameMaxLength || 20;

        if (screenName.length < minLength || screenName.length > maxLength || !pattern.test(screenName)) {
            alert(`Invalid screen name. Use ${minLength}-${maxLength} characters (letters, numbers, underscore, hyphen only)`);
            this.promptForScreenName();
            return;
        }

        this.screenName = screenName;
        this.isAnonymous = true;
        console.log('Anonymous user:', this.screenName);
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

    setupVisibilityTracking() {
        // Track when the app becomes visible/hidden
        document.addEventListener('visibilitychange', () => {
            this.isAppActive = !document.hidden;

            // Notify service worker of app state change
            this.sendToServiceWorker('APP_STATE_CHANGED', {
                isActive: this.isAppActive
            });
        });

        // Also track focus/blur events
        window.addEventListener('focus', () => {
            this.isAppActive = true;
            this.sendToServiceWorker('APP_STATE_CHANGED', {
                isActive: true
            });
        });

        window.addEventListener('blur', () => {
            this.isAppActive = false;
            this.sendToServiceWorker('APP_STATE_CHANGED', {
                isActive: false
            });
        });
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

        // Setup history panel
        const historyToggle = document.getElementById('history-toggle');
        const historyPanel = document.getElementById('history-panel');
        const playAllBtn = document.getElementById('play-all-btn');

        if (historyToggle && historyPanel) {
            historyToggle.addEventListener('click', () => {
                historyPanel.classList.toggle('collapsed');
                const isCollapsed = historyPanel.classList.contains('collapsed');
                historyToggle.textContent = isCollapsed ? 'Show History' : 'Hide History';
            });
        }

        if (playAllBtn) {
            playAllBtn.addEventListener('click', () => {
                this.playAllHistory();
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

                // Send authentication or screen name
                if (this.accessToken) {
                    this.ws.send(JSON.stringify({
                        type: 'authenticate',
                        token: this.accessToken
                    }));
                } else if (this.isAnonymous && this.screenName) {
                    this.ws.send(JSON.stringify({
                        type: 'set_screen_name',
                        screen_name: this.screenName
                    }));
                }

                // Join channel after authentication (will be handled in message handler)
                // this.joinChannel() will be called after authentication confirmation
                this.emit('connected');
            };

            this.ws.onmessage = (event) => {
                const data = JSON.parse(event.data);

                // Log all incoming messages for debugging
                // if (data.type === 'audio_data') {
                //     console.log('Received audio_data:', {
                //         format: data.format,
                //         mimeType: data.mimeType,
                //         sampleRate: data.sampleRate,
                //         dataLength: data.data ? data.data.length : 0,
                //         channel: data.channel
                //     });
                // } else {
                //     console.log('Received WebSocket message:', data.type, data);
                // }

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
            case 'authenticated':
                console.log('Authenticated as:', data.user.username);
                this.currentUser = data.user;
                this.screenName = data.user.username;
                this.joinChannel();
                break;

            case 'screen_name_set':
                console.log('Screen name set to:', data.screen_name);
                this.screenName = data.screen_name;
                this.joinChannel();
                break;

            case 'authentication_required':
                console.log('Authentication required');
                if (!this.accessToken) {
                    window.location.href = '/login.html';
                }
                break;

            case 'channel_joined':
                this.participants = data.participants;
                this.updateParticipantsCount();
                console.log(`Joined channel ${data.channel}`);
                break;

            case 'participant_joined':
                this.participants = data.participants;
                this.updateParticipantsCount();
                if (data.screen_name) {
                    console.log(`${data.screen_name} joined the channel`);
                }
                break;

            case 'participant_left':
                this.participants = data.participants;
                this.updateParticipantsCount();
                if (data.screen_name) {
                    console.log(`${data.screen_name} left the channel`);
                }
                break;

            case 'audio_data':
                // Don't play our own audio back to ourselves
                if (data.clientId && data.clientId === this.clientId) {
                    // console.log('Ignoring own audio message');
                    break;
                }

                if (data.format === 'encoded') {
                    this.playEncodedAudio(data.data, data.mimeType || 'audio/webm');
                } else if (data.format === 'pcm16') {
                    this.playPCMAudio(data.data, data.sampleRate || 44100, data.channels || 1);
                } else {
                    this.playEncodedAudio(data.data, data.mimeType || 'audio/webm');
                }
                break;

            case 'user_speaking':
                this.updateSpeakingIndicator(data.speaking, data.screen_name);
                this.emit('speaking', { speaking: data.speaking, screen_name: data.screen_name });

                // Notify service worker when someone else starts speaking (not ourselves)
                // Only send notifications when app is in background or not active
                if (data.speaking && !this.isSpeaking && !this.isAppActive) {
                    // Rate limit notifications to once per hour
                    const now = Date.now();
                    if (now - this.lastNotificationTime > this.notificationCooldown) {
                        this.sendToServiceWorker('TRANSMISSION_STARTED', {
                            channel: this.channel
                        });
                        this.lastNotificationTime = now;
                    }
                }

                // When someone stops speaking, refresh the message history
                if (!data.speaking) {
                    // Small delay to allow server to save the message
                    setTimeout(() => {
                        this.requestHistory();
                    }, 500);
                }
                break;

            case 'history_response':
                this.handleHistoryResponse(data.messages);
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

            // Request message history for this channel
            this.requestHistory();

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
                    autoGainControl: true
                    // Let the browser choose the optimal sample rate
                }
            });

            // Create audio context without forcing sample rate
            if (!this.audioContext) {
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            }

            console.log(`Audio context sample rate: ${this.audioContext.sampleRate}Hz`);

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

    async startTalking() {
        if (!this.audioStream || !this.isConnected || this.isRecording) return;

        try {
            // Use simple PCM audio processing
            await this.setupSimplePCMStreaming();

            this.isRecording = true;
            this.isSpeaking = true;
            this.pttButton.classList.add('recording');

            this.ws.send(JSON.stringify({
                type: 'push_to_talk_start',
                channel: this.channel,
                clientId: this.clientId
            }));

            console.log('Started simple PCM audio streaming');
        } catch (error) {
            console.error('Failed to start streaming:', error);
        }
    }

    async setupSimplePCMStreaming() {
        // Create audio context with default settings
        if (!this.audioContext) {
            this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
        }

        if (this.audioContext.state === 'suspended') {
            await this.audioContext.resume();
        }

        // Create source from microphone stream
        this.microphoneSource = this.audioContext.createMediaStreamSource(this.audioStream);

        // Create script processor with larger buffer to reduce overhead
        this.scriptProcessor = this.audioContext.createScriptProcessor(4096, 1, 1);

        this.scriptProcessor.onaudioprocess = (event) => {
            if (!this.isRecording) return;

            const inputBuffer = event.inputBuffer;
            const audioData = inputBuffer.getChannelData(0);

            if (!audioData || audioData.length === 0) return;

            // Simple, direct PCM16 conversion - no processing
            const pcm16 = new Int16Array(audioData.length);
            for (let i = 0; i < audioData.length; i++) {
                const sample = Math.max(-1, Math.min(1, audioData[i]));
                pcm16[i] = sample * 32767;
            }

            // Send directly
            this.sendSimplePCM(pcm16);
        };

        // Connect audio nodes - ScriptProcessorNode needs to be connected to destination to work
        this.microphoneSource.connect(this.scriptProcessor);

        // Create a gain node set to 0 to avoid feedback but keep the processing chain active
        this.muteGain = this.audioContext.createGain();
        this.muteGain.gain.value = 0; // Mute the output

        this.scriptProcessor.connect(this.muteGain);
        this.muteGain.connect(this.audioContext.destination);
    }

    sendSimplePCM(pcm16Data) {
        try {
            const uint8Array = new Uint8Array(pcm16Data.buffer);
            const base64Audio = btoa(String.fromCharCode.apply(null, uint8Array));

            const audioMessage = {
                type: 'audio_data',
                channel: this.channel,
                data: base64Audio,
                format: 'pcm16',
                sampleRate: this.audioContext.sampleRate,
                channels: 1,
                clientId: this.clientId,
                excludeSender: true // Hint for server to not echo back
            };

            // console.log('Sending audio_data:', {
            //     format: audioMessage.format,
            //     sampleRate: audioMessage.sampleRate,
            //     dataLength: audioMessage.data.length,
            //     channel: audioMessage.channel,
            //     pcm16Length: pcm16Data.length
            // });

            this.ws.send(JSON.stringify(audioMessage));
        } catch (error) {
            console.error('Failed to send simple PCM:', error);
        }
    }

    setupMediaRecorder() {
        // Use MediaRecorder for clean, native audio encoding
        const options = {
            mimeType: 'audio/webm;codecs=opus',
            audioBitsPerSecond: 128000
        };

        // Fallback options if webm/opus not supported
        if (!MediaRecorder.isTypeSupported(options.mimeType)) {
            if (MediaRecorder.isTypeSupported('audio/webm')) {
                options.mimeType = 'audio/webm';
            } else if (MediaRecorder.isTypeSupported('audio/mp4')) {
                options.mimeType = 'audio/mp4';
            } else {
                options.mimeType = ''; // Use default
            }
        }

        this.mediaRecorder = new MediaRecorder(this.audioStream, options);
        this.recordedChunks = [];

        this.mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) {
                this.sendAudio(event.data);
            }
        };

        this.mediaRecorder.onerror = (event) => {
            console.error('MediaRecorder error:', event.error);
        };

        // Start recording with small chunks for real-time streaming
        this.mediaRecorder.start(100); // 100ms chunks
        console.log('MediaRecorder started with type:', options.mimeType);
    }

    async setupWebAudioStreaming() {
        // Create audio context with default sample rate (let browser decide)
        if (!this.audioContext) {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            this.audioContext = new AudioContextClass();
            console.log('Audio context created with sample rate:', this.audioContext.sampleRate);
        }

        // Resume audio context if suspended
        if (this.audioContext.state === 'suspended') {
            await this.audioContext.resume();
        }

        try {
            // Load audio worklet if not already loaded
            if (!this.audioWorkletLoaded) {
                await this.audioContext.audioWorklet.addModule('assets/audio-processor.js');
                this.audioWorkletLoaded = true;
            }

            // Create source from microphone stream
            this.microphoneSource = this.audioContext.createMediaStreamSource(this.audioStream);

            // Create AudioWorkletNode for audio processing
            this.audioProcessor = new AudioWorkletNode(this.audioContext, 'audio-processor');

            // Listen for audio data from the worklet
            this.audioProcessor.port.onmessage = (event) => {
                if (event.data.type === 'audioData') {
                    this.sendPCMAudio(event.data.data);
                }
            };

            // Connect audio nodes
            this.microphoneSource.connect(this.audioProcessor);
            // Don't connect to destination to avoid feedback
            // this.audioProcessor.connect(this.audioContext.destination);

            // Start recording in the worklet
            this.audioProcessor.port.postMessage({ type: 'start' });

        } catch (error) {
            console.warn('AudioWorklet not supported, falling back to ScriptProcessorNode:', error);
            this.setupLegacyAudioStreaming();
        }
    }

    setupLegacyAudioStreaming() {
        // Create source from microphone stream
        this.microphoneSource = this.audioContext.createMediaStreamSource(this.audioStream);

        // Create script processor for audio processing (legacy fallback)
        // Use larger buffer to reduce processing overhead
        this.scriptProcessor = this.audioContext.createScriptProcessor(4096, 1, 1);

        this.scriptProcessor.onaudioprocess = (event) => {
            if (!this.isRecording) return;

            const inputBuffer = event.inputBuffer;
            const audioData = inputBuffer.getChannelData(0);

            // Validate audio data
            if (!audioData || audioData.length === 0) return;

            // Minimal processing - convert directly to PCM16
            const pcm16 = new Int16Array(audioData.length);
            for (let i = 0; i < audioData.length; i++) {
                // Simple conversion without extra processing
                const sample = Math.max(-1.0, Math.min(1.0, audioData[i]));
                pcm16[i] = Math.round(sample * 32767);
            }

            // Send PCM data
            this.sendPCMAudio(pcm16);
        };

        // Connect audio nodes (don't connect to destination to avoid feedback)
        this.microphoneSource.connect(this.scriptProcessor);
        // this.scriptProcessor.connect(this.audioContext.destination);
    }

    stopTalking() {
        if (!this.isRecording) return;

        this.isRecording = false;
        this.isSpeaking = false;
        this.pttButton.classList.remove('recording');

        // Disconnect script processor
        if (this.scriptProcessor) {
            this.scriptProcessor.disconnect();
            this.scriptProcessor = null;
        }

        // Disconnect mute gain node
        if (this.muteGain) {
            this.muteGain.disconnect();
            this.muteGain = null;
        }

        // Disconnect microphone source
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
            channel: this.channel,
            clientId: this.clientId
        }));

        console.log('Stopped simple PCM streaming');
    }

    processAudioQuality(audioData) {
        // Minimal processing - just return the data as-is to test
        return audioData;
    }

    sendPCMAudio(pcmData) {
        try {
            // Validate PCM data before sending
            if (!pcmData || pcmData.length === 0) {
                console.warn('Empty PCM data, skipping send');
                return;
            }

            // Convert PCM16 to base64 with proper chunking to avoid call stack issues
            const uint8Array = new Uint8Array(pcmData.buffer);
            let binaryString = '';
            const chunkSize = 8192; // Process in chunks to avoid stack overflow

            for (let i = 0; i < uint8Array.length; i += chunkSize) {
                const chunk = uint8Array.subarray(i, i + chunkSize);
                binaryString += String.fromCharCode.apply(null, chunk);
            }

            const base64Audio = btoa(binaryString);

            // Use actual sample rate from audio context
            const actualSampleRate = this.audioContext ? this.audioContext.sampleRate : 44100;

            this.ws.send(JSON.stringify({
                type: 'audio_data',
                channel: this.channel,
                data: base64Audio,
                format: 'pcm16',
                sampleRate: actualSampleRate,
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
                    format: 'encoded', // Mark as pre-encoded audio
                    mimeType: audioBlob.type,
                    size: audioBlob.size,
                    clientId: this.clientId,
                    excludeSender: true
                }));

                console.log('Sent encoded audio chunk:', audioBlob.type, audioBlob.size, 'bytes');
            };
            reader.readAsBinaryString(audioBlob);
        } catch (error) {
            console.error('Failed to send audio:', error);
        }
    }

    async playEncodedAudio(base64Data, mimeType) {
        try {
            if (!this.audioContext) {
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            }

            if (this.audioContext.state === 'suspended') {
                await this.audioContext.resume();
            }

            // Decode base64 to binary data
            const binaryString = atob(base64Data);
            const uint8Array = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                uint8Array[i] = binaryString.charCodeAt(i);
            }

            // Use Web Audio API to decode and play
            const audioBuffer = await this.audioContext.decodeAudioData(uint8Array.buffer.slice());

            // Create buffer source
            const source = this.audioContext.createBufferSource();
            source.buffer = audioBuffer;

            // Apply volume
            const gainNode = this.audioContext.createGain();
            const volume = this.volumeControl ? this.volumeControl.value / 100 : 0.5;
            gainNode.gain.setValueAtTime(volume, this.audioContext.currentTime);

            // Connect and play
            source.connect(gainNode);
            gainNode.connect(this.audioContext.destination);
            source.start();

            console.log('Playing encoded audio via Web Audio API:', mimeType);

        } catch (error) {
            console.error('Failed to play encoded audio:', error);
            console.log('Falling back to HTML audio element');

            // Fallback to HTML audio element
            try {
                const binaryString = atob(base64Data);
                const uint8Array = new Uint8Array(binaryString.length);
                for (let i = 0; i < binaryString.length; i++) {
                    uint8Array[i] = binaryString.charCodeAt(i);
                }

                const audioBlob = new Blob([uint8Array], { type: mimeType });
                const audioUrl = URL.createObjectURL(audioBlob);

                const audio = new Audio(audioUrl);
                const volume = this.volumeControl ? this.volumeControl.value / 100 : 0.5;
                audio.volume = volume;

                await audio.play();

                audio.addEventListener('ended', () => {
                    URL.revokeObjectURL(audioUrl);
                });
            } catch (fallbackError) {
                console.error('Fallback audio playback also failed:', fallbackError);
            }
        }
    }

    async playPCMAudio(base64Data, sampleRate, channels) {
        try {
            if (!base64Data || base64Data.length === 0) return;

            if (!this.audioContext) {
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            }

            if (this.audioContext.state === 'suspended') {
                await this.audioContext.resume();
            }

            // Decode base64 to PCM data
            const binaryString = atob(base64Data);
            const uint8Array = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                uint8Array[i] = binaryString.charCodeAt(i);
            }

            const pcm16 = new Int16Array(uint8Array.buffer);

            // Convert to float32 for Web Audio
            const float32Array = new Float32Array(pcm16.length);
            for (let i = 0; i < pcm16.length; i++) {
                float32Array[i] = pcm16[i] / 32768.0;
            }

            // Create audio buffer
            const audioBuffer = this.audioContext.createBuffer(1, float32Array.length, sampleRate || 44100);
            audioBuffer.getChannelData(0).set(float32Array);

            // Play it
            const source = this.audioContext.createBufferSource();
            source.buffer = audioBuffer;

            const gainNode = this.audioContext.createGain();
            const volume = this.volumeControl ? this.volumeControl.value / 100 : 0.5;
            gainNode.gain.value = volume;

            source.connect(gainNode);
            gainNode.connect(this.audioContext.destination);
            source.start();

            // Store the source so we can stop it later
            this.currentAudioSource = source;

            // Clear the reference when it ends naturally
            source.onended = () => {
                if (this.currentAudioSource === source) {
                    this.currentAudioSource = null;
                }
            };

            console.log('Playing simple PCM audio');

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

    updateSpeakingIndicator(speaking, screenName = null) {
        if (!this.speakingIndicator) return;

        if (speaking) {
            this.speakingIndicator.classList.add('active');
            if (screenName) {
                this.speakingIndicator.textContent = `${screenName} is speaking...`;
            }
        } else {
            this.speakingIndicator.classList.remove('active');
            this.speakingIndicator.textContent = '';
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

    // Message History Methods

    requestHistory() {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify({
                type: 'history_request',
                channel: this.channel
            }));
            console.log('Requested history for channel', this.channel);
        }
    }

    handleHistoryResponse(messages) {
        this.messageHistory = messages || [];
        console.log('Received', this.messageHistory.length, 'historical messages');
        this.updateHistoryPanel();
    }

    updateHistoryPanel() {
        const historyList = document.getElementById('history-list');
        if (!historyList) return;

        historyList.innerHTML = '';

        if (this.messageHistory.length === 0) {
            historyList.innerHTML = '<div class="history-empty">No messages yet</div>';
            return;
        }

        this.messageHistory.forEach((message, index) => {
            const messageEl = document.createElement('div');
            messageEl.className = 'history-message';
            messageEl.dataset.index = index;

            const timestamp = this.formatTimestamp(parseInt(message.timestamp));
            const duration = this.formatDuration(parseInt(message.duration));
            const userId = this.formatUserId(message.client_id);

            messageEl.innerHTML = `
                <div class="history-message-info">
                    <span class="history-user">${userId}</span>
                    <span class="history-timestamp">${timestamp}</span>
                    <span class="history-duration">${duration}</span>
                </div>
                <button class="history-play-btn" data-index="${index}">
                    <svg width="12" height="12" viewBox="0 0 12 12">
                        <path d="M2 1 L2 11 L10 6 Z" fill="currentColor"/>
                    </svg>
                </button>
            `;

            const playBtn = messageEl.querySelector('.history-play-btn');
            playBtn.addEventListener('click', () => this.playHistoryMessage(index));

            historyList.appendChild(messageEl);
        });
    }

    formatTimestamp(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);

        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins}m ago`;
        if (diffMins < 1440) return `${Math.floor(diffMins / 60)}h ago`;

        // Show time for older messages
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    formatDuration(durationMs) {
        const seconds = Math.round(durationMs / 1000 * 10) / 10;
        return `${seconds}s`;
    }

    formatUserId(clientId) {
        // Extract last 4 characters of client ID
        const shortId = clientId.slice(-4);
        return `User #${shortId}`;
    }

    playHistoryMessage(index) {
        if (index < 0 || index >= this.messageHistory.length) return;

        // If this message is already playing, stop it
        if (this.currentPlayingHistoryIndex === index) {
            this.stopSingleHistoryPlayback(index);
            return;
        }

        // Stop any currently playing message
        if (this.currentPlayingHistoryIndex !== -1) {
            this.stopSingleHistoryPlayback(this.currentPlayingHistoryIndex);
        }

        const message = this.messageHistory[index];
        console.log('Playing history message', index);

        // Mark as playing
        this.currentPlayingHistoryIndex = index;

        // Highlight the playing message
        this.highlightHistoryMessage(index);

        // Update button to pause icon
        this.updatePlayButtonIcon(index, 'pause');

        // Play the audio
        this.playPCMAudio(
            message.audio_data,
            parseInt(message.sample_rate),
            1
        );

        // Calculate when audio will finish and auto-reset button
        const duration = parseInt(message.duration);
        if (this.singlePlaybackTimeout) {
            clearTimeout(this.singlePlaybackTimeout);
        }
        this.singlePlaybackTimeout = setTimeout(() => {
            this.stopSingleHistoryPlayback(index);
        }, duration);
    }

    stopSingleHistoryPlayback(index) {
        // Stop the audio source if it's playing
        if (this.currentAudioSource) {
            try {
                this.currentAudioSource.stop();
            } catch (e) {
                // Already stopped
            }
            this.currentAudioSource = null;
        }

        this.currentPlayingHistoryIndex = -1;
        this.updatePlayButtonIcon(index, 'play');
        this.removeHistoryHighlight();
        if (this.singlePlaybackTimeout) {
            clearTimeout(this.singlePlaybackTimeout);
            this.singlePlaybackTimeout = null;
        }
    }

    updatePlayButtonIcon(index, state) {
        const messageEl = document.querySelector(`[data-index="${index}"]`);
        if (!messageEl) return;

        const playBtn = messageEl.querySelector('.history-play-btn');
        if (!playBtn) return;

        if (state === 'pause') {
            // Stop icon (square)
            playBtn.innerHTML = `
                <svg width="12" height="12" viewBox="0 0 12 12">
                    <rect x="2" y="2" width="8" height="8" fill="currentColor"/>
                </svg>
            `;
            playBtn.classList.add('playing');
        } else {
            // Play icon (triangle)
            playBtn.innerHTML = `
                <svg width="12" height="12" viewBox="0 0 12 12">
                    <path d="M2 1 L2 11 L10 6 Z" fill="currentColor"/>
                </svg>
            `;
            playBtn.classList.remove('playing');
        }
    }

    playAllHistory() {
        if (this.messageHistory.length === 0) {
            console.log('No messages to play');
            return;
        }

        if (this.isPlayingHistory) {
            console.log('Already playing history');
            return;
        }

        console.log('Playing all history messages');
        this.isPlayingHistory = true;
        this.currentHistoryIndex = 0;
        this.playNextHistoryMessage();
    }

    playNextHistoryMessage() {
        if (!this.isPlayingHistory || this.currentHistoryIndex >= this.messageHistory.length) {
            this.stopHistoryPlayback();
            return;
        }

        const message = this.messageHistory[this.currentHistoryIndex];
        console.log('Playing history message', this.currentHistoryIndex);

        // Highlight current message
        this.highlightHistoryMessage(this.currentHistoryIndex);

        // Play the audio
        const audioData = message.audio_data;
        const sampleRate = parseInt(message.sample_rate);

        // Calculate duration to wait before playing next
        const duration = parseInt(message.duration);

        this.playPCMAudio(audioData, sampleRate, 1);

        // Move to next message after this one finishes
        setTimeout(() => {
            this.currentHistoryIndex++;
            this.playNextHistoryMessage();
        }, duration + 100); // Add 100ms gap between messages
    }

    stopHistoryPlayback() {
        this.isPlayingHistory = false;
        this.currentHistoryIndex = -1;
        this.removeHistoryHighlight();
        console.log('Finished playing history');
    }

    highlightHistoryMessage(index) {
        this.removeHistoryHighlight();
        const messageEl = document.querySelector(`[data-index="${index}"]`);
        if (messageEl) {
            messageEl.classList.add('playing');
        }
    }

    removeHistoryHighlight() {
        const playingElements = document.querySelectorAll('.history-message.playing');
        playingElements.forEach(el => el.classList.remove('playing'));
    }

    // Authentication helper methods

    scheduleTokenRefresh() {
        // Refresh token 5 minutes before expiration
        const expiresIn = 3600; // 1 hour in seconds
        const refreshIn = (expiresIn - 300) * 1000; // 55 minutes in ms

        if (this.tokenRefreshTimer) {
            clearTimeout(this.tokenRefreshTimer);
        }

        this.tokenRefreshTimer = setTimeout(async () => {
            await this.refreshAccessToken();
        }, refreshIn);
    }

    async refreshAccessToken() {
        try {
            const response = await fetch('/auth/refresh.php', {
                method: 'POST',
                credentials: 'include' // Send refresh token cookie
            });

            const result = await response.json();
            if (result.success) {
                this.accessToken = result.tokens.access_token;
                localStorage.setItem('access_token', this.accessToken);
                this.scheduleTokenRefresh();
                console.log('Access token refreshed');
            } else {
                // Refresh failed, redirect to login
                console.error('Token refresh failed');
                localStorage.removeItem('access_token');
                window.location.href = '/login.html';
            }
        } catch (error) {
            console.error('Token refresh error:', error);
            localStorage.removeItem('access_token');
            window.location.href = '/login.html';
        }
    }

    showAuthenticatedUI() {
        // Add user menu/controls to UI
        const container = document.querySelector('.container') || document.body;

        let userMenu = document.getElementById('userMenu');
        if (!userMenu) {
            userMenu = document.createElement('div');
            userMenu.id = 'userMenu';
            userMenu.className = 'user-menu';
            userMenu.style.cssText = 'position: fixed; top: 10px; right: 10px; background: rgba(0,0,0,0.8); padding: 10px; border-radius: 6px; z-index: 1000;';
            container.appendChild(userMenu);
        }

        userMenu.innerHTML = `
            <span style="color: #fff; margin-right: 10px;">👤 ${this.currentUser.username}</span>
            <button onclick="window.location.href='/passkeys.html'" style="padding: 5px 10px; margin-right: 5px; cursor: pointer;">Passkeys</button>
            <button onclick="walkieTalkie.logout()" style="padding: 5px 10px; cursor: pointer;">Logout</button>
        `;
    }

    async logout() {
        try {
            await fetch('/auth/logout.php', {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${this.accessToken}`
                },
                credentials: 'include'
            });
        } catch (error) {
            console.error('Logout error:', error);
        }

        // Clear local storage
        localStorage.removeItem('access_token');

        // Clear refresh timer
        if (this.tokenRefreshTimer) {
            clearTimeout(this.tokenRefreshTimer);
        }

        // Reload page
        window.location.href = '/';
    }
}

// Global access for embed mode
window.WalkieTalkie = WalkieTalkie;