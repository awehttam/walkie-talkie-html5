/**
 * WebCodecs Opus Codec Implementation
 *
 * Uses the WebCodecs API for low-latency, direct Opus encoding/decoding
 * without container overhead. Much faster than MediaRecorder approach.
 *
 * Browser Support (2025):
 * - Chrome/Edge: Full support (AudioEncoder + AudioDecoder)
 * - Firefox: Full support (130+)
 * - Safari: Decoder only (no encoder yet)
 */

class WebCodecsOpus {
    constructor() {
        this.isSupported = this.checkSupport();
        this.encoder = null;
        this.decoder = null;
        this.encoderReady = false;
        this.decoderReady = false;
        this.audioData = null;
        this.sampleRate = 48000;
        this.channels = 1;
    }

    /**
     * Check WebCodecs support
     */
    checkSupport() {
        const hasAudioEncoder = typeof AudioEncoder !== 'undefined';
        const hasAudioDecoder = typeof AudioDecoder !== 'undefined';
        const hasAudioData = typeof AudioData !== 'undefined';

        console.log('WebCodecs support check:', {
            AudioEncoder: hasAudioEncoder,
            AudioDecoder: hasAudioDecoder,
            AudioData: hasAudioData
        });

        this.hasEncoder = hasAudioEncoder;
        this.hasDecoder = hasAudioDecoder;

        return hasAudioEncoder && hasAudioDecoder && hasAudioData;
    }

    /**
     * Initialize Opus encoder
     */
    async initEncoder(options = {}) {
        if (!this.hasEncoder) {
            throw new Error('AudioEncoder not supported in this browser');
        }

        const config = {
            codec: 'opus',
            sampleRate: options.sampleRate || 48000,
            numberOfChannels: options.channels || 1,
            bitrate: options.bitrate || 24000
        };

        // Check if config is supported
        const support = await AudioEncoder.isConfigSupported(config);

        if (!support.supported) {
            console.error('Opus encoding not supported with config:', config);
            throw new Error('Opus encoding not supported');
        }

        console.log('✓ Opus encoding supported:', support.config);

        this.encoderConfig = support.config;
        this.sampleRate = config.sampleRate;
        this.channels = config.numberOfChannels;
        this.encoderReady = true;

        return true;
    }

    /**
     * Initialize Opus decoder
     */
    async initDecoder(options = {}) {
        if (!this.hasDecoder) {
            throw new Error('AudioDecoder not supported in this browser');
        }

        const config = {
            codec: 'opus',
            sampleRate: options.sampleRate || 48000,
            numberOfChannels: options.channels || 1
        };

        // Check if config is supported
        const support = await AudioDecoder.isConfigSupported(config);

        if (!support.supported) {
            console.error('Opus decoding not supported with config:', config);
            throw new Error('Opus decoding not supported');
        }

        console.log('✓ Opus decoding supported:', support.config);

        this.decoderConfig = support.config;
        this.decoderReady = true;

        return true;
    }

    /**
     * Create encoder for audio stream
     */
    createEncoder(onEncodedChunk) {
        if (!this.encoderReady) {
            throw new Error('Encoder not initialized');
        }

        this.encoder = new AudioEncoder({
            output: (chunk, metadata) => {
                // Extract encoded data
                const buffer = new ArrayBuffer(chunk.byteLength);
                chunk.copyTo(buffer);

                // Convert to base64 for transmission
                const uint8Array = new Uint8Array(buffer);
                let binary = '';
                for (let i = 0; i < uint8Array.length; i++) {
                    binary += String.fromCharCode(uint8Array[i]);
                }
                const base64 = btoa(binary);

                onEncodedChunk({
                    data: base64,
                    timestamp: chunk.timestamp,
                    duration: chunk.duration,
                    type: chunk.type,
                    byteLength: chunk.byteLength
                });
            },
            error: (error) => {
                console.error('WebCodecs encoder error:', error);
            }
        });

        this.encoder.configure(this.encoderConfig);

        console.log('WebCodecs Opus encoder created');
        return this.encoder;
    }

    /**
     * Encode audio data from microphone
     */
    encodeAudioData(audioData) {
        if (!this.encoder || this.encoder.state !== 'configured') {
            console.warn('Encoder not ready');
            return;
        }

        // Opus encoder can handle variable frame sizes
        // Just encode the audio data directly
        this.encoder.encode(audioData);
        audioData.close();
    }

    /**
     * Create decoder
     */
    createDecoder(onDecodedAudio) {
        if (!this.decoderReady) {
            throw new Error('Decoder not initialized');
        }

        this.decoder = new AudioDecoder({
            output: (audioData) => {
                onDecodedAudio(audioData);
            },
            error: (error) => {
                console.error('WebCodecs decoder error:', error);
            }
        });

        this.decoder.configure(this.decoderConfig);

        console.log('WebCodecs Opus decoder created');
        return this.decoder;
    }

    /**
     * Decode Opus data to AudioData
     */
    decode(base64Data, timestamp = 0) {
        if (!this.decoder || this.decoder.state !== 'configured') {
            console.warn('Decoder not ready');
            return;
        }

        // Convert base64 to ArrayBuffer
        const binaryString = atob(base64Data);
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }

        // Create EncodedAudioChunk
        const chunk = new EncodedAudioChunk({
            type: 'key', // Opus frames are always keyframes
            timestamp: timestamp,
            data: bytes.buffer
        });

        this.decoder.decode(chunk);
    }

    /**
     * Flush encoder/decoder
     */
    async flush() {
        const promises = [];

        if (this.encoder && this.encoder.state === 'configured') {
            promises.push(this.encoder.flush());
        }

        if (this.decoder && this.decoder.state === 'configured') {
            promises.push(this.decoder.flush());
        }

        await Promise.all(promises);
    }

    /**
     * Clean up
     */
    destroy() {
        if (this.encoder) {
            this.encoder.close();
            this.encoder = null;
        }

        if (this.decoder) {
            this.decoder.close();
            this.decoder = null;
        }

        this.encoderReady = false;
        this.decoderReady = false;
    }
}

// Make available globally
if (typeof window !== 'undefined') {
    window.WebCodecsOpus = WebCodecsOpus;
}
