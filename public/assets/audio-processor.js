class AudioProcessor extends AudioWorkletProcessor {
    constructor() {
        super();
        this.isRecording = false;

        // Listen for messages from the main thread
        this.port.onmessage = (event) => {
            if (event.data.type === 'start') {
                this.isRecording = true;
            } else if (event.data.type === 'stop') {
                this.isRecording = false;
            }
        };
    }

    process(inputs, outputs, parameters) {
        if (!this.isRecording) {
            return true;
        }

        const input = inputs[0];
        if (input && input.length > 0) {
            const inputChannel = input[0];

            // Validate input data
            if (!inputChannel || inputChannel.length === 0) {
                return true;
            }

            // Convert Float32Array to PCM16 with improved precision
            const pcm16 = new Int16Array(inputChannel.length);
            for (let i = 0; i < inputChannel.length; i++) {
                // Clamp input to valid range first
                const sample = Math.max(-1.0, Math.min(1.0, inputChannel[i]));
                // Convert to PCM16 with proper rounding
                pcm16[i] = Math.round(sample * 32767);
            }

            // Only send if we have valid data
            if (pcm16.length > 0) {
                this.port.postMessage({
                    type: 'audioData',
                    data: pcm16
                });
            }
        }

        return true;
    }
}

registerProcessor('audio-processor', AudioProcessor);