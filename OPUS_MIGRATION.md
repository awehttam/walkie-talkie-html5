# Opus Codec Migration Plan

## Executive Summary

This document outlines the plan to add Opus codec support to the Walkie Talkie PWA application as an alternative to the current PCM16 (16-bit Linear PCM) codec. The migration will enable significant bandwidth reduction (~98% compression) while maintaining voice quality suitable for VoIP applications.

**Current State**: PCM16 only (uncompressed audio)
**Target State**: Multi-codec support (PCM16 + Opus)
**Estimated Effort**: 12-18 days of development

---

## Table of Contents

1. [Current Architecture](#current-architecture)
2. [Why Opus?](#why-opus)
3. [Key Changes Required](#key-changes-required)
4. [Implementation Phases](#implementation-phases)
5. [Technical Details](#technical-details)
6. [Testing Strategy](#testing-strategy)
7. [Backward Compatibility](#backward-compatibility)
8. [Performance Considerations](#performance-considerations)

---

## Current Architecture

### Technology Stack
- **Server**: PHP 8.1+ with Ratchet WebSocket (ReactPHP-based)
- **Client**: JavaScript (ES6+) with Web Audio API
- **Database**: SQLite with PDO
- **Audio**: PCM16 (16-bit Linear PCM) at 44.1-48kHz, mono

### Current Audio Flow

```
┌─────────────────────────────────────────────────────────────────┐
│ CLIENT: Capture                                                 │
│ Microphone → ScriptProcessor → Float32 → Int16 (PCM16)         │
│            → Uint8Array → Base64 → WebSocket JSON              │
└───────────────────────────────┬─────────────────────────────────┘
                                │
                                ↓
┌─────────────────────────────────────────────────────────────────┐
│ SERVER: Relay & Storage                                         │
│ Receive JSON → Buffer chunks → Concatenate on PTT end          │
│            → Store Base64 PCM16 in SQLite → Broadcast          │
└───────────────────────────────┬─────────────────────────────────┘
                                │
                                ↓
┌─────────────────────────────────────────────────────────────────┐
│ CLIENT: Playback                                                │
│ Base64 decode → Int16Array → Float32 → AudioBuffer → Play      │
└─────────────────────────────────────────────────────────────────┘
```

### Current Limitations
1. **No codec abstraction** - PCM16 conversion hardcoded everywhere
2. **No codec negotiation** - Implicit PCM16 assumption
3. **High bandwidth** - ~96 KB/sec (11.5 MB/minute) per transmission
4. **No compression** - Raw audio data stored in database
5. **Limited scalability** - Bandwidth constraints limit concurrent users

---

## Why Opus?

### Benefits

| Feature | PCM16 | Opus | Improvement |
|---------|-------|------|-------------|
| **Bitrate** | ~768 kbps | 16-24 kbps | 97-98% reduction |
| **Bandwidth/min** | 11.5 MB | 240 KB | 98% reduction |
| **Storage/message** | ~1 MB | ~20 KB | 98% reduction |
| **Latency** | ~10ms | ~20-50ms | Acceptable for VoIP |
| **Quality** | Perfect | Excellent for voice | Good tradeoff |
| **Browser support** | Universal | Very good | Chrome, Firefox, Safari (limited) |

### Use Cases

1. **Bandwidth-constrained environments** - Mobile networks, remote users
2. **Storage optimization** - Reduce database size by 98%
3. **Scalability** - Support 30-50x more concurrent users
4. **Cost reduction** - Lower bandwidth costs for hosting

### Industry Standard

Opus is the **IETF standard** (RFC 6716) for interactive audio, used by:
- Discord, Zoom, Microsoft Teams (VoIP)
- WebRTC (default codec)
- WhatsApp, Signal (voice messages)

---

## Key Changes Required

### 1. Message Protocol Enhancement

**Current** (`public/assets/walkie-talkie.js:731-759`):
```javascript
{
    type: 'audio_data',
    format: 'pcm16',
    sampleRate: 44100,
    channels: 1,
    data: base64_pcm16_data
}
```

**Proposed**:
```javascript
{
    type: 'audio_data',
    codec: 'opus',              // NEW: Explicit codec field
    format: 'opus',             // Keep for backward compat
    sampleRate: 48000,          // Opus prefers 48kHz
    channels: 1,
    bitrate: 24000,             // NEW: Opus bitrate in bps
    frameSize: 960,             // NEW: Samples per frame (20ms @ 48kHz)
    data: base64_opus_data
}
```

**Impact**:
- 2 client methods to update: `sendSimplePCM()`, `sendPCMAudio()`
- Server message validation in `broadcastAudio()`
- Database storage schema change

---

### 2. Client-Side Encoding

**File**: `public/assets/walkie-talkie.js:685-729`

**Current**:
```javascript
setupSimplePCMStreaming() {
    // Direct Float32 → Int16 conversion
    const int16Array = new Int16Array(float32Array.length);
    for (let i = 0; i < float32Array.length; i++) {
        int16Array[i] = Math.max(-32768, Math.min(32767,
            Math.floor(float32Array[i] * 32768)));
    }
    this.sendSimplePCM(int16Array);
}
```

**Proposed**:
```javascript
setupAudioStreaming(codec = 'pcm16') {
    if (codec === 'opus') {
        this.opusEncoder = new OpusEncoder({
            sampleRate: 48000,
            channels: 1,
            bitrate: 24000,
            frameSize: 960  // 20ms frames
        });
    }

    scriptProcessor.onaudioprocess = (event) => {
        const float32Array = event.inputBuffer.getChannelData(0);

        if (codec === 'opus') {
            const opusData = this.opusEncoder.encode(float32Array);
            this.sendOpusAudio(opusData);
        } else {
            const pcm16Data = this.convertToPCM16(float32Array);
            this.sendSimplePCM(pcm16Data);
        }
    };
}

sendOpusAudio(opusBytes) {
    const base64 = btoa(String.fromCharCode(...opusBytes));
    this.ws.send(JSON.stringify({
        type: 'audio_data',
        codec: 'opus',
        format: 'opus',
        sampleRate: 48000,
        channels: 1,
        bitrate: 24000,
        data: base64,
        channel: this.currentChannel,
        clientId: this.clientId
    }));
}
```

**Dependencies**:
- `opus-media-recorder` (npm package) - 500KB WASM library
- Alternative: `libopus.js` or `opus.js`

**Files Modified**:
- `public/assets/walkie-talkie.js`
- `public/index.php` (include library script)

---

### 3. Server-Side Broadcasting

**File**: `src/WebSocketServer.php:593-636`

**Current**:
```php
public function broadcastAudio($from, array $data): void
{
    // Buffer PCM16 chunks for concatenation
    if (isset($data['format']) && $data['format'] === 'pcm16') {
        $this->activeTransmissions[$key]['chunks'][] = $data['data'];
    }

    // Broadcast to all clients
    $this->broadcast($from, $data);
}
```

**Proposed**:
```php
public function broadcastAudio($from, array $data): void
{
    $codec = $data['codec'] ?? $data['format'] ?? 'pcm16';  // Backward compat

    // Handle codec-specific buffering
    switch ($codec) {
        case 'pcm16':
            // Buffer raw chunks for concatenation
            $this->activeTransmissions[$key]['chunks'][] = $data['data'];
            break;

        case 'opus':
            // Opus frames are independent - store separately
            $this->activeTransmissions[$key]['frames'][] = [
                'data' => $data['data'],
                'sequence' => $this->activeTransmissions[$key]['frameCount']++,
                'timestamp' => microtime(true)
            ];
            break;
    }

    // Broadcast with codec info
    $message = [
        'type' => 'audio_data',
        'codec' => $codec,
        'format' => $data['format'],
        'sampleRate' => $data['sampleRate'] ?? 48000,
        'channels' => $data['channels'] ?? 1,
        'data' => $data['data']
    ];

    $this->broadcast($from, $message);
}
```

**Files Modified**:
- `src/WebSocketServer.php:broadcastAudio()`
- `src/WebSocketServer.php:handlePushToTalkEnd()` (storage logic)

---

### 4. Database Schema Migration

**File**: `migrations/004_add_opus_support.sql` (new file)

```sql
-- Add codec tracking to message history
ALTER TABLE message_history ADD COLUMN codec TEXT DEFAULT 'pcm16';
ALTER TABLE message_history ADD COLUMN bitrate INTEGER;  -- In bps

-- Create index for codec queries
CREATE INDEX idx_codec_channel ON message_history(codec, channel, timestamp);

-- Update existing rows to mark as PCM16
UPDATE message_history SET codec = 'pcm16' WHERE codec IS NULL;

-- Optional: Add codec preferences table
CREATE TABLE IF NOT EXISTS user_codec_preferences (
    user_id TEXT PRIMARY KEY,
    preferred_codec TEXT NOT NULL DEFAULT 'pcm16',
    fallback_codec TEXT NOT NULL DEFAULT 'pcm16',
    opus_bitrate INTEGER DEFAULT 24000,
    updated_at INTEGER NOT NULL
);
```

**Migration Script**: `cli/migrate.php` (new file)

```php
<?php
// Run migration safely
$db = new PDO('sqlite:data/app.db');
$db->exec(file_get_contents('migrations/004_add_opus_support.sql'));
echo "Migration complete\n";
```

**Files Modified/Created**:
- `migrations/004_add_opus_support.sql` (new)
- `cli/migrate.php` (new)
- `src/WebSocketServer.php:saveMessage()` (update INSERT)

---

### 5. Client-Side Playback

**File**: `public/assets/walkie-talkie.js:1054-1112`

**Current**:
```javascript
playPCMAudio(base64Data, sampleRate, channels) {
    // Decode Base64
    const binaryString = atob(base64Data);
    const uint8Array = new Uint8Array(binaryString.length);

    // Convert to Int16
    const int16Array = new Int16Array(uint8Array.buffer);

    // Convert to Float32
    const float32Array = new Float32Array(int16Array.length);
    for (let i = 0; i < int16Array.length; i++) {
        float32Array[i] = int16Array[i] / 32768.0;
    }

    // Play
    const audioBuffer = this.audioContext.createBuffer(channels,
        float32Array.length, sampleRate);
    audioBuffer.getChannelData(0).set(float32Array);

    const source = this.audioContext.createBufferSource();
    source.buffer = audioBuffer;
    source.connect(this.audioContext.destination);
    source.start();
}
```

**Proposed**:
```javascript
async playAudio(base64Data, codec, sampleRate, channels) {
    // Decode Base64
    const binaryString = atob(base64Data);
    const uint8Array = new Uint8Array(binaryString.length);
    for (let i = 0; i < binaryString.length; i++) {
        uint8Array[i] = binaryString.charCodeAt(i);
    }

    let float32Array;

    if (codec === 'opus') {
        // Decode Opus
        if (!this.opusDecoder) {
            this.opusDecoder = new OpusDecoder({
                sampleRate: sampleRate,
                channels: channels
            });
        }
        float32Array = await this.opusDecoder.decode(uint8Array);
    } else {
        // PCM16 path (unchanged)
        const int16Array = new Int16Array(uint8Array.buffer);
        float32Array = new Float32Array(int16Array.length);
        for (let i = 0; i < int16Array.length; i++) {
            float32Array[i] = int16Array[i] / 32768.0;
        }
    }

    // Create and play buffer
    const audioBuffer = this.audioContext.createBuffer(
        channels,
        float32Array.length,
        sampleRate
    );
    audioBuffer.getChannelData(0).set(float32Array);

    const source = this.audioContext.createBufferSource();
    source.buffer = audioBuffer;
    source.connect(this.audioContext.destination);
    source.start();
}

// Update handleWebSocketMessage
handleWebSocketMessage(data) {
    if (data.type === 'audio_data') {
        const codec = data.codec || data.format || 'pcm16';
        this.playAudio(
            data.data,
            codec,
            data.sampleRate || 44100,
            data.channels || 1
        );
    }
}
```

**Files Modified**:
- `public/assets/walkie-talkie.js:playPCMAudio()` → `playAudio()`
- `public/assets/walkie-talkie.js:handleWebSocketMessage()`

---

### 6. Configuration System

**File**: `.env.example`

```env
# Audio Codec Configuration
AUDIO_CODEC_PRIMARY=opus           # Primary codec: 'pcm16' or 'opus'
AUDIO_CODEC_FALLBACK=pcm16         # Fallback if primary not supported
OPUS_BITRATE_KBPS=24               # Opus bitrate: 16-64 kbps
OPUS_FRAME_DURATION_MS=20          # Frame duration: 10, 20, 40, 60 ms
OPUS_APPLICATION=voip              # Application: voip, audio, lowdelay
OPUS_COMPLEXITY=5                  # Encoder complexity: 0-10
```

**File**: `public/index.php` (add UI controls)

```html
<!-- Add to settings panel -->
<div class="codec-settings">
    <h3>Audio Settings</h3>

    <div class="form-group">
        <label for="codec-select">Audio Codec</label>
        <select id="codec-select">
            <option value="opus">Opus (Compressed)</option>
            <option value="pcm16">PCM16 (Uncompressed)</option>
            <option value="auto">Auto-select</option>
        </select>
        <small>Opus uses 98% less bandwidth</small>
    </div>

    <div class="form-group" id="opus-settings">
        <label for="opus-bitrate">Opus Bitrate</label>
        <select id="opus-bitrate">
            <option value="16">16 kbps (Low)</option>
            <option value="24" selected>24 kbps (Recommended)</option>
            <option value="32">32 kbps (High)</option>
            <option value="64">64 kbps (Maximum)</option>
        </select>
    </div>
</div>
```

**Files Modified/Created**:
- `.env.example` (add codec config)
- `public/index.php` (add UI controls)
- `public/assets/walkie-talkie.js` (read preferences)

---

### 7. CLI Audio Tools

**File**: `cli/lib/AudioProcessor.php:51-103`

**Challenge**: PHP has no native Opus support. Options:

**Option 1: System Call Wrapper** (Recommended)
```php
class AudioProcessor
{
    public function encodeToOpus(string $pcm16FilePath, int $bitrate = 24000): array
    {
        $tempOpusFile = tempnam(sys_get_temp_dir(), 'opus_');

        // Use system opusenc command
        $cmd = sprintf(
            'opusenc --raw --raw-rate 48000 --raw-chan 1 --bitrate %d %s %s 2>&1',
            $bitrate,
            escapeshellarg($pcm16FilePath),
            escapeshellarg($tempOpusFile)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception("Opus encoding failed: " . implode("\n", $output));
        }

        $opusData = file_get_contents($tempOpusFile);
        unlink($tempOpusFile);

        return [
            'codec' => 'opus',
            'sample_rate' => 48000,
            'channels' => 1,
            'bitrate' => $bitrate,
            'data' => base64_encode($opusData),
            'size_bytes' => strlen($opusData)
        ];
    }

    public function loadAudio(string $filePath): array
    {
        // Detect format
        $format = $this->detectFormat($filePath);

        if ($format === 'opus') {
            // Already Opus - just load
            $opusData = file_get_contents($filePath);
            return [
                'codec' => 'opus',
                'sample_rate' => 48000,  // Default for CLI
                'data' => base64_encode($opusData),
                'chunks' => [$opusData]  // No chunking needed
            ];
        } else {
            // PCM16 or WAV - existing logic
            $pcmData = $this->extractPCM16($filePath);

            // Optional: Convert to Opus
            if (getenv('AUDIO_CODEC_PRIMARY') === 'opus') {
                return $this->encodeToOpus($filePath);
            }

            return [
                'codec' => 'pcm16',
                'sample_rate' => $this->sampleRate,
                'data' => base64_encode($pcmData),
                'chunks' => $this->chunkAudio($pcmData)
            ];
        }
    }
}
```

**Option 2: Send PCM16, Server Transcodes** (Simpler)
```php
// CLI sends PCM16 as-is
// Server converts to Opus if needed (requires PHP Opus library)
```

**Dependencies**:
- `opusenc` binary installed on system
- Or: PHP FFI extension + libopus.so

**Files Modified**:
- `cli/lib/AudioProcessor.php`
- `cli/lib/AudioSender.php` (update message format)

---

## Implementation Phases

### Phase 1: Protocol & Infrastructure (2-3 days)

**Goals**:
- Add codec field to message protocol
- Update database schema
- Add configuration system

**Tasks**:
1. Create database migration script
2. Add `codec` field to WebSocket messages
3. Update `.env.example` with codec config
4. Update server message validation
5. Add backward compatibility checks

**Files Modified**:
- `migrations/004_add_opus_support.sql` (new)
- `src/WebSocketServer.php`
- `.env.example`

**Testing**:
- Verify existing PCM16 messages still work
- Test database migration on test data
- Verify config loading

---

### Phase 2: Client-Side Opus Encoding (3-5 days)

**Goals**:
- Add Opus encoder library
- Update audio capture to encode Opus
- Add UI codec selector

**Tasks**:
1. Integrate `opus-media-recorder` library
2. Create `OpusEncoder` wrapper class
3. Update `setupSimplePCMStreaming()` for codec selection
4. Add `sendOpusAudio()` method
5. Add UI controls for codec selection
6. Store user codec preference in localStorage

**Files Modified**:
- `public/assets/walkie-talkie.js`
- `public/index.php`
- `package.json` (if using npm)

**Testing**:
- Test Opus encoding in Chrome, Firefox, Safari
- Verify encoded data size (~98% smaller)
- Test codec switching without page reload
- Test microphone permissions with Opus

---

### Phase 3: Client-Side Opus Decoding (2-3 days)

**Goals**:
- Add Opus decoder library
- Update playback to handle Opus

**Tasks**:
1. Integrate Opus decoder (same library as encoder)
2. Update `playPCMAudio()` → `playAudio()`
3. Add codec detection in `handleWebSocketMessage()`
4. Test playback quality

**Files Modified**:
- `public/assets/walkie-talkie.js`

**Testing**:
- Test Opus playback from live transmission
- Test Opus playback from message history
- Verify audio quality matches PCM16
- Test latency (should be <150ms total)

---

### Phase 4: Server-Side Storage (2-3 days)

**Goals**:
- Update buffering logic for Opus
- Store codec information in database
- Update message history

**Tasks**:
1. Update `broadcastAudio()` buffering logic
2. Update `handlePushToTalkEnd()` storage
3. Update `saveMessage()` to store codec field
4. Update `getRecentMessages()` to return codec
5. Test storage efficiency

**Files Modified**:
- `src/WebSocketServer.php`

**Testing**:
- Verify Opus messages stored correctly
- Check database size reduction
- Test message history playback
- Test mixed PCM16/Opus history

---

### Phase 5: CLI Tools (2-3 days)

**Goals**:
- Add Opus support to CLI audio tools
- Update welcome message system

**Tasks**:
1. Add `opusenc` system call wrapper
2. Update `AudioProcessor::loadAudio()`
3. Update `AudioSender` to send Opus
4. Test CLI audio sending with Opus

**Files Modified**:
- `cli/lib/AudioProcessor.php`
- `cli/lib/AudioSender.php`

**Testing**:
- Test sending Opus files via CLI
- Test WAV → Opus conversion
- Verify CLI-sent Opus plays in browser

---

### Phase 6: Testing & Optimization (3-4 days)

**Goals**:
- End-to-end testing
- Performance optimization
- Browser compatibility testing

**Tasks**:
1. Test all codec combinations:
   - Opus sender → Opus receiver
   - Opus sender → PCM16 receiver (transcoding)
   - PCM16 sender → Opus receiver (transcoding)
   - Mixed codec channels
2. Performance testing:
   - CPU usage during encoding
   - Latency measurements
   - Bandwidth measurements
3. Browser testing:
   - Chrome, Firefox, Safari
   - Mobile browsers
   - Fallback behavior
4. Load testing:
   - 10 concurrent Opus transmissions
   - 50 concurrent users
5. Documentation

**Deliverables**:
- Test report
- Performance benchmarks
- Browser compatibility matrix
- Updated README

---

## Technical Details

### Opus Encoder Configuration

**Recommended Settings for VoIP**:
```javascript
{
    sampleRate: 48000,        // Opus native sample rate
    channels: 1,              // Mono for voice
    bitrate: 24000,           // 24 kbps (good quality)
    application: 'voip',      // Optimized for voice
    frameSize: 960,           // 20ms frames @ 48kHz
    complexity: 5,            // Encoding complexity (0-10)
    useDTX: false,            // Discontinuous transmission (optional)
    useInbandFEC: true        // Forward error correction (recommended)
}
```

**Frame Size Options**:
| Frame Duration | Samples @ 48kHz | Use Case |
|---------------|-----------------|----------|
| 10ms | 480 | Ultra-low latency |
| 20ms | 960 | **Recommended for VoIP** |
| 40ms | 1920 | Lower overhead |
| 60ms | 2880 | Maximum efficiency |

**Bitrate Recommendations**:
| Bitrate | Quality | Use Case |
|---------|---------|----------|
| 8-12 kbps | Narrow-band | Extremely limited bandwidth |
| 16 kbps | Good | Mobile networks |
| **24 kbps** | **Very good** | **Recommended** |
| 32 kbps | Excellent | High-quality voice |
| 64 kbps | Transparent | Music/high-fidelity |

---

### Opus Packet Structure

**Opus OGG Container** (for storage):
```
OGG Page Header (27 bytes)
├─ Capture pattern: "OggS"
├─ Version: 0
├─ Header type: 0x02 (beginning of stream)
├─ Granule position: 0
├─ Serial number: random
├─ Page sequence: 0
├─ Checksum: CRC32
└─ Segment table

Opus Header (19 bytes)
├─ Magic: "OpusHead"
├─ Version: 1
├─ Channel count: 1
├─ Pre-skip: 312
├─ Input sample rate: 48000
├─ Output gain: 0
└─ Mapping family: 0

Opus Data Packets (variable)
└─ Compressed audio frames
```

**Raw Opus Frame** (for transmission):
```
TOC Byte (1 byte)
├─ Config: 5 bits (frame size, mode)
├─ Stereo flag: 1 bit
└─ Frame count: 2 bits

Frame Data (variable)
└─ Compressed audio samples
```

---

### Browser Compatibility

| Browser | Opus Encoding | Opus Decoding | Notes |
|---------|--------------|---------------|-------|
| **Chrome 91+** | ✓ Native | ✓ Native | Full support via MediaRecorder |
| **Firefox 88+** | ✓ Native | ✓ Native | Full support |
| **Safari 14.1+** | ✓ Via WASM | ✓ Via WASM | No native MediaRecorder Opus |
| **Edge 91+** | ✓ Native | ✓ Native | Chromium-based |
| **Mobile Chrome** | ✓ Native | ✓ Native | Full support |
| **Mobile Safari** | ✓ Via WASM | ✓ Via WASM | Limited |

**Fallback Strategy**:
```javascript
function selectCodec() {
    // Check MediaRecorder Opus support
    if (MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')) {
        return 'opus-native';
    }

    // Check Web Audio API Opus decoding
    if (typeof OpusDecoder !== 'undefined') {
        return 'opus-wasm';
    }

    // Fallback to PCM16
    return 'pcm16';
}
```

---

### JavaScript Libraries

**Option 1: opus-media-recorder** (Recommended)
```javascript
// npm install opus-media-recorder
import { OpusMediaRecorder } from 'opus-media-recorder';

const recorder = new OpusMediaRecorder(stream, {
    mimeType: 'audio/ogg;codecs=opus',
    audioBitsPerSecond: 24000
});

recorder.ondataavailable = (event) => {
    // event.data is Blob containing Opus OGG
    sendOpusData(event.data);
};

recorder.start(1000); // Get data every 1 second
```

**Option 2: libopus.js** (Pure WASM)
```javascript
// Direct Opus encoder/decoder via WebAssembly
import OpusEncoder from 'libopus.js/encoder';
import OpusDecoder from 'libopus.js/decoder';

const encoder = new OpusEncoder({
    sampleRate: 48000,
    channels: 1,
    bitrate: 24000
});

// Encode Float32 samples
const opusPacket = encoder.encode(float32Array);
```

**Option 3: opus.js** (Emscripten)
```javascript
// Larger but mature library
import Opus from 'opus.js';

const encoder = new Opus.Encoder({
    frequency: 48000,
    channels: 1,
    bitrate: 24000
});
```

**Size Comparison**:
| Library | Size (gzipped) | Performance | Recommendation |
|---------|----------------|-------------|----------------|
| opus-media-recorder | ~450 KB | Excellent | **Best for production** |
| libopus.js | ~200 KB | Very good | Good for custom workflows |
| opus.js | ~550 KB | Good | Mature but larger |

---

### PHP Opus Integration

**No native PHP support** - Must use external tools:

**Option 1: opusenc CLI** (Recommended)
```php
// Install: apt-get install opus-tools
exec('opusenc --bitrate 24 input.wav output.opus', $output, $return);
```

**Option 2: FFmpeg**
```php
// Install: apt-get install ffmpeg
exec('ffmpeg -i input.wav -c:a libopus -b:a 24k output.opus', $output, $return);
```

**Option 3: PHP FFI + libopus**
```php
// Requires PHP 7.4+ FFI extension
$ffi = FFI::cdef('
    typedef struct OpusEncoder OpusEncoder;
    OpusEncoder *opus_encoder_create(int Fs, int channels, int application, int *error);
    int opus_encode(OpusEncoder *st, const short *pcm, int frame_size, unsigned char *data, int max_data_bytes);
', 'libopus.so');

$encoder = $ffi->opus_encoder_create(48000, 1, 2048, null);
$encoded = $ffi->opus_encode($encoder, $pcm, 960, $buffer, 4000);
```

**Recommendation**: Use **Option 1 (opusenc CLI)** for simplicity unless high performance needed.

---

## Testing Strategy

### Unit Tests

**Client-Side**:
```javascript
// Test Opus encoding
test('Opus encoder produces valid data', async () => {
    const encoder = new OpusEncoder({ sampleRate: 48000, channels: 1, bitrate: 24000 });
    const samples = new Float32Array(960).fill(0.5);
    const encoded = encoder.encode(samples);

    expect(encoded).toBeInstanceOf(Uint8Array);
    expect(encoded.length).toBeGreaterThan(0);
    expect(encoded.length).toBeLessThan(200); // Compressed
});

// Test Opus decoding
test('Opus decoder produces valid audio', async () => {
    const decoder = new OpusDecoder({ sampleRate: 48000, channels: 1 });
    const decoded = await decoder.decode(opusPacket);

    expect(decoded).toBeInstanceOf(Float32Array);
    expect(decoded.length).toBe(960);
});

// Test codec selection
test('Codec selector falls back to PCM16', () => {
    const codec = selectCodec({ opusSupported: false });
    expect(codec).toBe('pcm16');
});
```

**Server-Side**:
```php
// Test codec field storage
public function testOpusMessageStorage(): void
{
    $server = new WebSocketServer();
    $data = [
        'type' => 'audio_data',
        'codec' => 'opus',
        'data' => base64_encode('test opus data'),
        'sampleRate' => 48000,
        'channels' => 1
    ];

    $server->broadcastAudio($mockConnection, $data);

    $this->assertDatabaseHas('message_history', [
        'codec' => 'opus',
        'sample_rate' => 48000
    ]);
}
```

---

### Integration Tests

**End-to-End Transmission**:
```javascript
test('Opus transmission and playback', async () => {
    // 1. Capture audio
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    const talkie = new WalkieTalkie();
    talkie.init();

    // 2. Select Opus codec
    talkie.setCodec('opus');

    // 3. Start talking
    await talkie.startTalking();

    // Wait for audio capture
    await sleep(2000);

    // 4. Stop talking
    await talkie.stopTalking();

    // 5. Verify message stored
    const messages = await talkie.getRecentMessages('test-channel');
    expect(messages[0].codec).toBe('opus');

    // 6. Playback
    await talkie.playMessage(messages[0]);

    // Verify audio played
    expect(talkie.isPlaying()).toBe(true);
});
```

**Codec Compatibility Matrix**:
```javascript
const testCases = [
    { sender: 'opus', receiver: 'opus', expected: 'direct' },
    { sender: 'opus', receiver: 'pcm16', expected: 'transcode' },
    { sender: 'pcm16', receiver: 'opus', expected: 'transcode' },
    { sender: 'pcm16', receiver: 'pcm16', expected: 'direct' }
];

testCases.forEach(({ sender, receiver, expected }) => {
    test(`${sender} → ${receiver}: ${expected}`, async () => {
        const result = await testTransmission(sender, receiver);
        expect(result.path).toBe(expected);
        expect(result.audioPlayed).toBe(true);
    });
});
```

---

### Performance Tests

**Bandwidth Measurement**:
```javascript
test('Opus reduces bandwidth by 95%+', async () => {
    const pcm16Size = await transmitAudio('pcm16', 10000); // 10s
    const opusSize = await transmitAudio('opus', 10000);

    const reduction = (pcm16Size - opusSize) / pcm16Size;
    expect(reduction).toBeGreaterThan(0.95); // 95%+
});
```

**Latency Measurement**:
```javascript
test('Opus latency under 150ms', async () => {
    const startTime = performance.now();

    // Capture → Encode → Send → Receive → Decode → Play
    await fullTransmissionCycle('opus');

    const latency = performance.now() - startTime;
    expect(latency).toBeLessThan(150); // ms
});
```

**CPU Usage**:
```javascript
test('Opus encoding CPU usage acceptable', async () => {
    const cpuBefore = await getCPUUsage();

    // Encode 1 minute of audio
    await encodeAudioStream('opus', 60000);

    const cpuAfter = await getCPUUsage();
    const cpuIncrease = cpuAfter - cpuBefore;

    expect(cpuIncrease).toBeLessThan(20); // <20% CPU
});
```

---

### Browser Compatibility Tests

**Automated Testing** (Playwright):
```javascript
const browsers = ['chromium', 'firefox', 'webkit'];

browsers.forEach(browserType => {
    test(`Opus works on ${browserType}`, async () => {
        const browser = await playwright[browserType].launch();
        const page = await browser.newPage();

        await page.goto('http://localhost:8080');

        // Test Opus capability detection
        const hasOpus = await page.evaluate(() => {
            return window.walkieTalkie.detectOpusSupport();
        });

        if (browserType === 'webkit') {
            // Safari might use WASM fallback
            expect(hasOpus).toBeTruthy();
        } else {
            // Chrome/Firefox have native support
            expect(hasOpus).toBe('native');
        }

        await browser.close();
    });
});
```

---

## Backward Compatibility

### Strategy

1. **Default to PCM16** for existing clients
2. **Opus is opt-in** via UI or config
3. **Server supports both** codecs simultaneously
4. **Database migration** marks existing data as PCM16
5. **Graceful degradation** if Opus unavailable

### Compatibility Rules

**Message Protocol**:
```javascript
// Server receives message without codec field
if (!isset($data['codec'])) {
    // Assume legacy PCM16
    $data['codec'] = 'pcm16';
}

// Client receives message without codec field
const codec = data.codec || data.format || 'pcm16';
```

**Database Migration**:
```sql
-- Mark existing records as PCM16
UPDATE message_history
SET codec = 'pcm16'
WHERE codec IS NULL OR codec = '';
```

**Client Detection**:
```javascript
// Detect if client supports Opus
const supportsOpus =
    MediaRecorder.isTypeSupported('audio/ogg;codecs=opus') ||
    typeof OpusEncoder !== 'undefined';

if (!supportsOpus) {
    console.warn('Opus not supported, falling back to PCM16');
    this.codec = 'pcm16';
}
```

### Version Compatibility Matrix

| Server Version | Client Version | PCM16 | Opus | Notes |
|---------------|----------------|-------|------|-------|
| Pre-Opus | Pre-Opus | ✓ | ✗ | Current state |
| **Post-Opus** | Pre-Opus | ✓ | ✗ | Old client works |
| **Post-Opus** | **Post-Opus** | ✓ | ✓ | Full support |
| Pre-Opus | **Post-Opus** | ✓ | ✗ | Client auto-downgrades |

---

## Performance Considerations

### Bandwidth Comparison

**10-second voice message**:
| Codec | Bitrate | Size | Storage/1000 msgs | Bandwidth/hour |
|-------|---------|------|------------------|----------------|
| PCM16 | 768 kbps | 960 KB | 960 MB | 2.7 GB |
| Opus (16k) | 16 kbps | 20 KB | 20 MB | 57 MB |
| Opus (24k) | 24 kbps | 30 KB | 30 MB | 86 MB |
| Opus (32k) | 32 kbps | 40 KB | 40 MB | 115 MB |

**Savings**: 96-98% reduction

---

### Latency Budget

| Component | PCM16 | Opus | Target |
|-----------|-------|------|--------|
| Capture | 10-20ms | 10-20ms | <20ms |
| Encode | 1-5ms | 10-30ms | <30ms |
| Network | 20-50ms | 20-50ms | <50ms |
| Decode | 1-5ms | 10-30ms | <30ms |
| Buffer | 10-20ms | 10-20ms | <20ms |
| **Total** | **42-100ms** | **60-150ms** | **<150ms** |

**Opus adds 20-50ms latency** - acceptable for VoIP.

---

### CPU Usage

**Encoding** (per stream):
| Codec | CPU % (Mobile) | CPU % (Desktop) |
|-------|---------------|----------------|
| PCM16 | 1-2% | <1% |
| Opus (complexity 5) | 5-10% | 2-5% |
| Opus (complexity 10) | 15-25% | 8-12% |

**Recommendation**: Use complexity 5 for mobile, 8 for desktop.

---

### Memory Usage

**Per stream**:
| Component | PCM16 | Opus |
|-----------|-------|------|
| Encoder | 50 KB | 200 KB |
| Decoder | 50 KB | 200 KB |
| Buffers | 100 KB | 150 KB |
| **Total** | **200 KB** | **550 KB** |

**Impact**: Negligible on modern devices.

---

### Scalability

**Server capacity** (8-core, 16GB RAM):
| Codec | Concurrent Streams | Bandwidth | Notes |
|-------|-------------------|-----------|-------|
| PCM16 | 50-100 | 400 Mbps | Bandwidth-limited |
| Opus | 1000-2000 | 48 Mbps | CPU/memory-limited |

**Opus enables 10-20x more concurrent users**.

---

## Risk Assessment

### High Risks

1. **Browser compatibility issues**
   - Mitigation: Comprehensive fallback to PCM16
   - Testing: Cross-browser automated tests

2. **Audio quality degradation**
   - Mitigation: Use 24kbps bitrate, enable FEC
   - Testing: Subjective listening tests

3. **Latency increase**
   - Mitigation: Use 20ms frames, optimize buffering
   - Testing: Latency measurement suite

### Medium Risks

4. **CLI Opus encoding failures**
   - Mitigation: Check `opusenc` availability, fallback to PCM16
   - Testing: Test on multiple OS platforms

5. **Database migration failures**
   - Mitigation: Backup database before migration, rollback plan
   - Testing: Test migration on copy of production data

6. **Increased client-side CPU**
   - Mitigation: Use lower complexity on mobile detection
   - Testing: Mobile device performance testing

### Low Risks

7. **Library bloat (500KB+)**
   - Mitigation: Lazy-load Opus library only when needed
   - Testing: Bundle size analysis

8. **Opus decoder initialization lag**
   - Mitigation: Pre-initialize decoder on page load
   - Testing: Time-to-first-audio measurement

---

## Success Metrics

### Performance Goals

- **Bandwidth reduction**: >95% for Opus transmissions
- **Audio quality**: MOS score >4.0 (very good)
- **Latency**: <150ms end-to-end
- **CPU usage**: <10% on desktop, <20% on mobile
- **Encoding success rate**: >99%

### Adoption Goals

- **Opt-in rate**: >50% of users within 1 month
- **Codec usage**: >80% Opus traffic after 3 months
- **Error rate**: <1% transcoding failures

### Storage Goals

- **Database size reduction**: >90% for new messages
- **Cost savings**: 10x more messages per storage unit

---

## Rollout Plan

### Stage 1: Alpha (Internal Testing)
- **Duration**: 1 week
- **Users**: Development team only
- **Goals**: Identify major bugs, verify basic functionality

### Stage 2: Beta (Limited Release)
- **Duration**: 2 weeks
- **Users**: 10-20% of users (opt-in feature flag)
- **Goals**: Performance validation, gather feedback

### Stage 3: General Availability
- **Duration**: Ongoing
- **Users**: All users
- **Default**: Opus (with PCM16 fallback)

---

## Rollback Plan

If critical issues arise:

1. **Quick rollback**: Disable Opus via environment variable
   ```env
   AUDIO_CODEC_PRIMARY=pcm16
   AUDIO_CODEC_OPUS_ENABLED=false
   ```

2. **Client-side disable**: Update config to force PCM16
   ```javascript
   const FORCE_PCM16 = true;
   ```

3. **Database consistency**: All PCM16 data still playable
4. **No data loss**: Opus-encoded messages remain in DB for later

---

## Open Questions

1. **Should we transcode Opus → PCM16 on server for legacy clients?**
   - Pro: Better compatibility
   - Con: Server CPU cost
   - **Recommendation**: No, client-side fallback only

2. **Should we re-encode stored PCM16 messages to Opus?**
   - Pro: Immediate storage savings
   - Con: Lossy conversion, CPU intensive
   - **Recommendation**: No, only new messages

3. **Should CLI default to Opus or PCM16?**
   - **Recommendation**: Follow server config default

4. **Should we support multiple bitrates simultaneously?**
   - **Recommendation**: Yes, via user preference

---

## Resources

### Documentation
- [Opus Codec Specification (RFC 6716)](https://datatracker.ietf.org/doc/html/rfc6716)
- [Opus Recommended Settings](https://wiki.xiph.org/Opus_Recommended_Settings)
- [Web Audio API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Audio_API)
- [MediaRecorder API](https://developer.mozilla.org/en-US/docs/Web/API/MediaRecorder)

### Libraries
- [opus-media-recorder](https://github.com/kbumsik/opus-media-recorder)
- [libopus.js](https://github.com/chris-rudmin/opus-recorder)
- [opus-tools](https://opus-codec.org/downloads/)

### Tools
- [Opus Bitrate Calculator](https://opus-codec.org/comparison/)
- [Audio Quality Testing](https://www.webrtc.org/testing/)

---

## Conclusion

Adding Opus codec support will provide:
- **98% bandwidth reduction** (768 kbps → 24 kbps)
- **10-20x scalability** improvement
- **Minimal latency increase** (<50ms)
- **Industry-standard** voice quality

**Estimated effort**: 12-18 days
**Recommended approach**: Phased rollout with PCM16 fallback
**Primary risk**: Browser compatibility (mitigated by fallback strategy)

This migration positions the Walkie Talkie application for significant bandwidth savings and improved scalability while maintaining excellent voice quality and backward compatibility.
