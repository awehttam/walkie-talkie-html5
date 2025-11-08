/**
 * Opus Codec Wrapper for Web Audio API
 * Provides Opus encoding/decoding capabilities for walkie-talkie application
 */

class OpusCodec {
    constructor() {
        this.isSupported = this.checkSupport();
        this.encoder = null;
        this.decoder = null;
        this.encoderReady = false;
        this.decoderReady = false;
    }

    /**
     * Check if Opus codec is supported in this browser
     */
    checkSupport() {
        // Check MediaRecorder support for Opus
        if (typeof MediaRecorder !== 'undefined') {
            const types = [
                'audio/ogg;codecs=opus',
                'audio/webm;codecs=opus',
                'audio/ogg'
            ];

            for (const type of types) {
                if (MediaRecorder.isTypeSupported(type)) {
                    console.log(`Opus support detected: ${type}`);
                    this.supportedMimeType = type;
                    return true;
                }
            }
        }

        console.warn('Opus codec not supported in this browser');
        return false;
    }

    /**
     * Initialize Opus encoder
     * @param {Object} options - Encoder options
     * @param {number} options.sampleRate - Sample rate (default: 48000)
     * @param {number} options.channels - Number of channels (default: 1)
     * @param {number} options.bitrate - Bitrate in bps (default: 24000)
     */
    async initEncoder(options = {}) {
        const config = {
            sampleRate: options.sampleRate || 48000,
            channels: options.channels || 1,
            bitrate: options.bitrate || 24000,
            ...options
        };

        this.encoderConfig = config;
        this.encoderReady = true;

        console.log('Opus encoder initialized:', config);
        return true;
    }

    /**
     * Initialize Opus decoder
     * @param {Object} options - Decoder options
     */
    async initDecoder(options = {}) {
        this.decoderConfig = {
            sampleRate: options.sampleRate || 48000,
            channels: options.channels || 1,
            ...options
        };

        this.decoderReady = true;

        console.log('Opus decoder initialized:', this.decoderConfig);
        return true;
    }

    /**
     * Encode audio using MediaRecorder approach
     * This is used for real-time streaming encoding
     *
     * @param {MediaStream} stream - Audio stream to encode
     * @param {Function} onDataCallback - Called with encoded chunks
     * @returns {MediaRecorder} - The MediaRecorder instance
     */
    createStreamEncoder(stream, onDataCallback) {
        if (!this.isSupported) {
            throw new Error('Opus encoding not supported in this browser');
        }

        const mimeType = this.supportedMimeType;
        const bitrate = this.encoderConfig?.bitrate || 24000;

        // Create MediaRecorder with Opus codec
        const mediaRecorder = new MediaRecorder(stream, {
            mimeType: mimeType,
            audioBitsPerSecond: bitrate
        });

        // Buffer to collect encoded data
        let encodedChunks = [];

        mediaRecorder.ondataavailable = (event) => {
            if (event.data && event.data.size > 0) {
                encodedChunks.push(event.data);

                // Convert to Base64 for transmission
                const reader = new FileReader();
                reader.onloadend = () => {
                    const base64data = reader.result.split(',')[1];
                    if (onDataCallback) {
                        onDataCallback({
                            data: base64data,
                            mimeType: mimeType,
                            timestamp: Date.now()
                        });
                    }
                };
                reader.readAsDataURL(event.data);
            }
        };

        mediaRecorder.onerror = (event) => {
            console.error('MediaRecorder error:', event.error);
        };

        return mediaRecorder;
    }

    /**
     * Encode PCM16 audio data to Opus
     * Note: This uses MediaRecorder approach since direct Opus encoding
     * requires WASM libraries which we're avoiding for now
     *
     * @param {Int16Array} pcm16Data - PCM16 audio data
     * @returns {Promise<Uint8Array>} - Opus encoded data
     */
    async encodePCM16(pcm16Data) {
        // This is a placeholder for direct PCM16 -> Opus encoding
        // In practice, we use MediaRecorder for streaming which handles this
        throw new Error('Direct PCM16 encoding not implemented - use createStreamEncoder instead');
    }

    /**
     * Decode Opus audio data to PCM
     * Uses Web Audio API decodeAudioData which supports Opus
     *
     * @param {Uint8Array|String} opusData - Opus encoded data (Uint8Array or base64)
     * @param {AudioContext} audioContext - Web Audio context
     * @returns {Promise<AudioBuffer>} - Decoded audio buffer
     */
    async decode(opusData, audioContext) {
        if (!this.decoderReady) {
            await this.initDecoder();
        }

        // Convert base64 to ArrayBuffer if needed
        let arrayBuffer;
        if (typeof opusData === 'string') {
            arrayBuffer = this.base64ToArrayBuffer(opusData);
            console.log('Decoding Opus data:', {
                base64Length: opusData.length,
                arrayBufferSize: arrayBuffer.byteLength,
                firstBytes: new Uint8Array(arrayBuffer.slice(0, 4))
            });
        } else if (opusData instanceof Uint8Array) {
            arrayBuffer = opusData.buffer;
        } else {
            arrayBuffer = opusData;
        }

        // Use Web Audio API to decode Opus
        try {
            console.log('Attempting to decode audio data, size:', arrayBuffer.byteLength);
            const audioBuffer = await audioContext.decodeAudioData(arrayBuffer);
            console.log('Successfully decoded Opus audio:', {
                duration: audioBuffer.duration,
                sampleRate: audioBuffer.sampleRate,
                channels: audioBuffer.numberOfChannels
            });
            return audioBuffer;
        } catch (error) {
            console.error('Opus decode error:', error);
            console.error('ArrayBuffer size:', arrayBuffer.byteLength);
            console.error('First 16 bytes:', new Uint8Array(arrayBuffer.slice(0, 16)));
            throw error;
        }
    }

    /**
     * Convert base64 string to ArrayBuffer
     */
    base64ToArrayBuffer(base64) {
        const binaryString = atob(base64);
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }
        return bytes.buffer;
    }

    /**
     * Convert ArrayBuffer to base64 string
     */
    arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary);
    }

    /**
     * Clean up resources
     */
    destroy() {
        this.encoder = null;
        this.decoder = null;
        this.encoderReady = false;
        this.decoderReady = false;
    }
}

// Make available globally
if (typeof window !== 'undefined') {
    window.OpusCodec = OpusCodec;
}
