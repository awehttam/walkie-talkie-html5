class AudioProcessor extends AudioWorkletProcessor {
    constructor() {
        super();
        this.isRecording = false;

        // Log the actual sample rate being used
        console.log('AudioProcessor sample rate:', sampleRate);

        // Listen for messages from the main thread
        this.port.onmessage = (event) => {
            if (event.data.type === 'start') {
                this.isRecording = true;
                console.log('AudioProcessor recording started at', sampleRate, 'Hz');
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

            // Apply audio quality processing
            const processedAudio = this.processAudioQuality(inputChannel);

            // Convert Float32Array to PCM16 with improved precision
            const pcm16 = new Int16Array(processedAudio.length);
            for (let i = 0; i < processedAudio.length; i++) {
                // Clamp input to valid range first
                const sample = Math.max(-1.0, Math.min(1.0, processedAudio[i]));
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

    processAudioQuality(audioData) {
        // Simple noise gate and normalization
        const processedData = new Float32Array(audioData.length);
        const noiseGateThreshold = 0.01; // Adjust based on testing
        let maxAmplitude = 0;

        // Find peak amplitude for normalization
        for (let i = 0; i < audioData.length; i++) {
            const abs = Math.abs(audioData[i]);
            if (abs > maxAmplitude) {
                maxAmplitude = abs;
            }
        }

        // Apply noise gate and normalize
        const normalizationGain = maxAmplitude > 0 ? Math.min(1.0, 0.8 / maxAmplitude) : 1.0;

        for (let i = 0; i < audioData.length; i++) {
            const sample = audioData[i];
            if (Math.abs(sample) < noiseGateThreshold) {
                processedData[i] = 0; // Gate out noise
            } else {
                processedData[i] = sample * normalizationGain;
            }
        }

        return processedData;
    }
}

registerProcessor('audio-processor', AudioProcessor);