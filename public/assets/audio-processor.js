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

            // Convert Float32Array to PCM16
            const pcm16 = new Int16Array(inputChannel.length);
            for (let i = 0; i < inputChannel.length; i++) {
                pcm16[i] = Math.max(-32768, Math.min(32767, inputChannel[i] * 32768));
            }

            // Send PCM data to main thread
            this.port.postMessage({
                type: 'audioData',
                data: pcm16
            });
        }

        return true;
    }
}

registerProcessor('audio-processor', AudioProcessor);