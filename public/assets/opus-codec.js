/**
 * Opus Codec Wrapper for Web Audio API
 * Provides Opus encoding/decoding capabilities for walkie-talkie application
 *
 * Uses header prepending technique to make WebM/Opus chunks independently decodable:
 * - Extracts initialization segment from first chunk
 * - Prepends init segment to subsequent chunks
 * - Enables real-time streaming without container dependencies
 */

class OpusCodec {
    constructor() {
        this.isSupported = this.checkSupport();
        this.encoder = null;
        this.decoder = null;
        this.encoderReady = false;
        this.decoderReady = false;
        this.initSegment = null;
        this.isFirstChunk = true;
        this.chunkCount = 0;
    }

    /**
     * Check if Opus codec is supported in this browser
     * Header prepending works best with WebM/Opus in Chrome/Edge
     */
    checkSupport() {
        if (typeof MediaRecorder === 'undefined') {
            console.warn('MediaRecorder not supported in this browser');
            return false;
        }

        // Detect browser for compatibility
        const isChrome = /Chrome/.test(navigator.userAgent) && /Google Inc/.test(navigator.vendor);
        const isEdge = /Edg/.test(navigator.userAgent);
        const isFirefox = /Firefox/.test(navigator.userAgent);

        // Header prepending works reliably in Chrome/Edge with WebM
        // Firefox has issues with prepended WebM chunks
        if (isFirefox) {
            console.warn('Opus header prepending not reliable in Firefox - use PCM16 fallback');
            this.browserSupportsHeaderPrepending = false;
        } else {
            this.browserSupportsHeaderPrepending = true;
        }

        // Check for WebM/Opus support (required for header prepending)
        const types = [
            'audio/webm;codecs=opus',   // Primary target for header prepending
            'audio/ogg;codecs=opus',    // Fallback (doesn't need prepending but not widely supported)
            'audio/ogg'
        ];

        for (const type of types) {
            if (MediaRecorder.isTypeSupported(type)) {
                console.log(`Opus support detected: ${type}`);
                this.supportedMimeType = type;
                this.needsHeaderPrepending = type.includes('webm');
                return true;
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
     * Parse WebM structure to find Cluster element offset
     * Returns offset where Cluster starts (init segment ends)
     */
    findWebMClusterOffset(buffer) {
        const view = new DataView(buffer);
        let offset = 0;

        const readVInt = () => {
            const byte = view.getUint8(offset);
            let mask = 0x80;
            let length = 0;

            while (length < 8 && !(byte & mask)) {
                mask >>= 1;
                length++;
            }

            let value = byte & (mask - 1);
            offset++;

            for (let i = 1; i <= length; i++) {
                value = (value << 8) | view.getUint8(offset++);
            }

            return value;
        };

        const readElementId = () => {
            const byte = view.getUint8(offset);
            let mask = 0x80;
            let length = 1;

            while (length < 4 && !(byte & mask)) {
                mask >>= 1;
                length++;
            }

            let id = byte;
            offset++;

            for (let i = 1; i < length; i++) {
                id = (id << 8) | view.getUint8(offset++);
            }

            return id;
        };

        // Parse EBML elements
        while (offset < buffer.byteLength) {
            const startOffset = offset;
            const id = readElementId();
            const size = readVInt();

            // Cluster ID is 0x1F43B675
            if (id === 0x1F43B675) {
                return startOffset;
            }

            // Handle unknown size (streaming)
            const isUnknownSize = size === 0xFFFFFFFFFFFFFF || size > buffer.byteLength;

            if (!isUnknownSize && size > 0) {
                offset += size;
            }
        }

        return -1;
    }

    /**
     * Extract initialization segment from first WebM chunk
     */
    async extractInitSegment(blob) {
        const buffer = await blob.arrayBuffer();
        const clusterOffset = this.findWebMClusterOffset(buffer);

        if (clusterOffset > 0) {
            this.initSegment = buffer.slice(0, clusterOffset);
            console.log(`Extracted WebM init segment: ${this.initSegment.byteLength} bytes`);
            return this.initSegment;
        } else {
            console.error('Could not find Cluster element in WebM');
            return null;
        }
    }

    /**
     * Prepend init segment to media chunk
     */
    async prependInitSegment(blob) {
        if (!this.initSegment) {
            console.warn('No init segment available for prepending');
            return blob;
        }

        const mediaBuffer = await blob.arrayBuffer();
        const combined = new Uint8Array(this.initSegment.byteLength + mediaBuffer.byteLength);
        combined.set(new Uint8Array(this.initSegment), 0);
        combined.set(new Uint8Array(mediaBuffer), this.initSegment.byteLength);

        return new Blob([combined], { type: blob.type });
    }

    /**
     * Encode audio using MediaRecorder approach with header prepending
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

        console.log('Creating MediaRecorder with MIME type:', mimeType, 'bitrate:', bitrate);
        console.log('Header prepending:', this.needsHeaderPrepending ? 'enabled' : 'disabled');

        // Reset state for new recording
        this.initSegment = null;
        this.isFirstChunk = true;
        this.chunkCount = 0;

        // Create MediaRecorder with Opus codec
        const mediaRecorder = new MediaRecorder(stream, {
            mimeType: mimeType,
            audioBitsPerSecond: bitrate
        });

        mediaRecorder.ondataavailable = async (event) => {
            if (event.data && event.data.size > 0) {
                this.chunkCount++;
                const chunkNumber = this.chunkCount;

                console.log(`Chunk #${chunkNumber}: ${event.data.size} bytes`);

                let processedBlob = event.data;

                // Handle header prepending for WebM
                if (this.needsHeaderPrepending && this.browserSupportsHeaderPrepending) {
                    if (this.isFirstChunk) {
                        // Extract init segment from first chunk
                        await this.extractInitSegment(event.data);
                        this.isFirstChunk = false;
                        // First chunk is already complete
                        processedBlob = event.data;
                        console.log(`Chunk #${chunkNumber}: First chunk (has init segment)`);
                    } else {
                        // Prepend init segment to subsequent chunks
                        processedBlob = await this.prependInitSegment(event.data);
                        console.log(`Chunk #${chunkNumber}: Prepended init segment (+${this.initSegment.byteLength} bytes)`);
                    }
                }

                // Convert to Base64 for transmission
                const reader = new FileReader();
                reader.onloadend = () => {
                    const base64data = reader.result.split(',')[1];
                    if (onDataCallback) {
                        onDataCallback({
                            data: base64data,
                            mimeType: mediaRecorder.mimeType || mimeType,
                            timestamp: Date.now(),
                            chunkNumber: chunkNumber,
                            hasPrependedHeader: !this.isFirstChunk && this.needsHeaderPrepending
                        });
                    }
                };
                reader.readAsDataURL(processedBlob);
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
            const binaryString = atob(opusData);
            const bytes = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }

            // Create a copy of the buffer to prevent detachment
            arrayBuffer = bytes.buffer.slice(0);

            const first16 = new Uint8Array(arrayBuffer.slice(0, 16));
            const firstBytesHex = Array.from(first16).map(b => b.toString(16).padStart(2, '0')).join(' ');
            const firstBytesAscii = Array.from(first16).map(b => (b >= 32 && b < 127) ? String.fromCharCode(b) : '.').join('');

            console.log('Decoding Opus data:', {
                base64Length: opusData.length,
                arrayBufferSize: arrayBuffer.byteLength,
                firstBytes: first16,
                firstBytesHex: firstBytesHex,
                firstBytesAscii: firstBytesAscii
            });
        } else if (opusData instanceof Uint8Array) {
            arrayBuffer = opusData.buffer.slice(0);
        } else if (opusData instanceof ArrayBuffer) {
            arrayBuffer = opusData.slice(0);
        } else {
            throw new Error('Invalid opusData type: ' + typeof opusData);
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
            if (arrayBuffer.byteLength > 0) {
                console.error('First 16 bytes:', new Uint8Array(arrayBuffer.slice(0, 16)));
            }
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
