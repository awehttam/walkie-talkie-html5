# Alternative Audio Codecs for Real-Time Streaming

## Executive Summary

After discovering that Opus via MediaRecorder API cannot support real-time chunk streaming (due to WebM container limitations), this document evaluates alternative codecs and approaches for the walkie-talkie application.

**Key Requirement**: Real-time PTT (Push-to-Talk) audio streaming with <150ms latency and broad browser compatibility.

---

## Current Situation

### What Works
- **PCM16**: Uncompressed audio, proven working, universal browser support
- Bandwidth: ~768 kbps (11.5 MB/minute)
- Latency: ~10ms
- Browser support: 100%

### What Doesn't Work
- **Opus via MediaRecorder**: Cannot stream chunks in real-time
- Issue: Chrome/Edge only support `audio/webm;codecs=opus`
- WebM chunks require complete file structure (init segment + media segments)
- Individual 100ms chunks cannot be decoded independently

---

## Alternative Codecs Evaluation

### 1. AAC (Advanced Audio Coding)

#### Overview
- Industry standard for audio compression (MPEG-4 Part 3)
- Used by YouTube, Apple Music, Android, streaming services
- Good compression: 48-128 kbps typical for voice

#### Browser Support (2025)

| Browser | MediaRecorder Support | Container Format |
|---------|----------------------|------------------|
| **Chrome/Edge** | ✓ Yes | MP4 (`audio/mp4;codecs=mp4a.40.2`) |
| **Firefox** | ⚠ OS-dependent | MP4 (requires system codec) |
| **Safari** | ✓ Yes | MP4 (native support) |
| **Mobile** | ✓ Excellent | MP4 |

**AAC-LC codec string**: `mp4a.40.2` (Low Complexity profile)

#### Streaming Capability

**MP4 Container**:
- ✓ Supports fragmented MP4 (fMP4) for streaming
- ✓ Each fragment is independently decodable
- ✓ Used by DASH and HLS streaming protocols
- ✓ Can use `timeslice` parameter in MediaRecorder for chunks

**Potential for Real-Time**:
```javascript
const recorder = new MediaRecorder(stream, {
    mimeType: 'audio/mp4;codecs=mp4a.40.2',
    audioBitsPerSecond: 64000
});

// Request 100ms chunks
recorder.start(100);

recorder.ondataavailable = (event) => {
    // Each chunk should be a valid fMP4 fragment
    sendAudioChunk(event.data);
};
```

#### Pros
- ✓ **Excellent browser support** (especially Safari/iOS)
- ✓ **Good compression** (64-96 kbps for voice = 92% bandwidth reduction)
- ✓ **Mature standard** (established since 1997)
- ✓ **Low latency** encoding (~20-40ms)
- ✓ **MP4 fragments** may support chunk streaming
- ✓ **Hardware acceleration** available on most devices

#### Cons
- ✗ **Patent-encumbered** (royalty-free for streaming, but licensing complexity)
- ✗ **Firefox support** depends on OS codecs
- ✗ **Need to test** if MP4 fragments work for real-time chunks
- ✗ **Slightly lower quality** than Opus at same bitrate

#### Recommendation
**HIGH PRIORITY - Test immediately**. If MP4 fragments support independent chunk decoding, AAC could be the ideal solution.

---

### 2. WebCodecs API (Low-Level Codec Access)

#### Overview
- New W3C API (2021+) for direct codec access
- Low-level encoding/decoding without container overhead
- Full control over codec parameters and frame processing

#### Browser Support (2025)

| Browser | AudioEncoder | AudioDecoder | Status |
|---------|-------------|--------------|--------|
| **Chrome/Edge** | ✓ v94+ | ✓ v94+ | Stable |
| **Firefox** | ✓ v130+ | ✓ v130+ | Stable |
| **Safari** | ⚠ Tech Preview | ✓ 17.4+ | Partial |
| **Safari Mobile** | ⚠ Not yet | ✓ iOS 17.4+ | Decode only |

**Current Status**: ~85% browser support, Safari encoder coming soon

#### Supported Codecs via WebCodecs

Available codecs depend on browser and platform:
- **Opus**: Widely supported
- **AAC**: Supported (platform-dependent)
- **PCM**: Always available
- **Vorbis**: Some browsers
- **FLAC**: Some browsers

#### Implementation Example

```javascript
// Encoder
const encoder = new AudioEncoder({
    output: (chunk, metadata) => {
        // Send raw encoded chunk (no container!)
        sendToServer(chunk.data);
    },
    error: (e) => console.error(e)
});

encoder.configure({
    codec: 'opus',          // or 'mp4a.40.2' for AAC
    sampleRate: 48000,
    numberOfChannels: 1,
    bitrate: 24000
});

// Feed audio data
encoder.encode(audioData);

// Decoder
const decoder = new AudioDecoder({
    output: (audioData) => {
        // Play decoded audio
        playAudioData(audioData);
    },
    error: (e) => console.error(e)
});

decoder.configure({
    codec: 'opus',
    sampleRate: 48000,
    numberOfChannels: 1
});

// Decode received chunk
decoder.decode(chunk);
```

#### Streaming Capability

- ✓ **Perfect for streaming**: No container overhead
- ✓ **Raw codec packets**: Each chunk is independently decodable
- ✓ **Low latency**: Direct codec access, minimal buffering
- ✓ **Full control**: Bitrate, complexity, frame size

#### Pros
- ✓ **Designed for streaming**: No container dependencies
- ✓ **Best latency**: Direct codec access (<20ms)
- ✓ **Opus support**: Can use Opus codec without WebM container
- ✓ **AAC support**: Can use AAC on supported platforms
- ✓ **Hardware acceleration**: Native codec access
- ✓ **Future-proof**: Modern web standard
- ✓ **No licensing issues**: API itself is free

#### Cons
- ✗ **Safari encoder support** not yet in production (decode only)
- ✗ **~85% browser coverage** (missing Safari encoder)
- ✗ **More complex**: Lower-level API requires more code
- ✗ **Need fallback**: Must implement PCM16 fallback for unsupported browsers
- ✗ **Audio worklet integration**: More complex than MediaRecorder

#### Recommendation
**MEDIUM-HIGH PRIORITY - Implement with fallback**. WebCodecs is the future of web audio/video processing, but needs Safari encoder support. Could implement with automatic PCM16 fallback for Safari until support arrives.

---

### 3. G.722 / G.711 (VoIP Codecs)

#### Overview
- ITU-T standard codecs for telephony
- G.711: 64 kbps (µ-law/A-law PCM)
- G.722: 64 kbps wideband
- Used in traditional VoIP systems

#### Browser Support
- ✗ **No native browser support** in MediaRecorder or WebCodecs
- ✗ Would require JavaScript or WASM implementation
- ✗ Not suitable for web applications

#### Recommendation
**NOT RECOMMENDED** - No browser support, no advantage over other options.

---

### 4. Vorbis

#### Overview
- Open-source codec (Xiph.Org Foundation)
- Predecessor to Opus
- Good quality at 64-128 kbps

#### Browser Support (2025)

| Browser | MediaRecorder | Container |
|---------|--------------|-----------|
| **Chrome/Edge** | ✗ No | N/A |
| **Firefox** | ✓ Yes | OGG |
| **Safari** | ✗ No | N/A |

#### Streaming Capability
- ✓ OGG container supports streaming chunks
- ✗ Very limited browser support (Firefox only)

#### Recommendation
**NOT RECOMMENDED** - Limited browser support, Opus is better in every way.

---

### 5. FLAC (Free Lossless Audio Codec)

#### Overview
- Lossless compression (~50% size reduction)
- Higher quality than needed for voice
- Larger than lossy codecs

#### Browser Support
- ⚠ Limited support in MediaRecorder
- ✓ Playback support better than recording

#### Recommendation
**NOT RECOMMENDED** - Overkill for voice, still larger than lossy codecs, limited recording support.

---

### 6. Keep PCM16 (Current Solution)

#### Overview
Stay with current working implementation.

#### Pros
- ✓ **Works perfectly** - proven, stable
- ✓ **100% browser support**
- ✓ **Lowest latency** (~10ms)
- ✓ **No encoding overhead**
- ✓ **Simple implementation**

#### Cons
- ✗ **High bandwidth**: 768 kbps
- ✗ **Large storage**: 11.5 MB/minute
- ✗ **Scalability limits**: Bandwidth constrains concurrent users

#### Use Cases
- Real-time PTT transmission (proven working)
- Fallback when compressed codecs fail
- Low-latency critical applications

---

## Comparison Matrix

| Codec | Bitrate | Bandwidth Savings | Browser Support | Streaming | Latency | Complexity |
|-------|---------|-------------------|-----------------|-----------|---------|------------|
| **PCM16** | 768 kbps | 0% (baseline) | 100% ✓ | ✓ | ~10ms | Low |
| **AAC** | 64-96 kbps | 88-92% | ~90% ⚠ | ? (test needed) | ~30ms | Low |
| **Opus (WebCodecs)** | 24-32 kbps | 96-97% | ~85% ⚠ | ✓ | ~20ms | Medium |
| **Opus (MediaRecorder)** | 24-32 kbps | 96-97% | 100% ✓ | ✗ | N/A | Low |
| **Vorbis** | 64-128 kbps | 83-92% | ~30% ✗ | ✓ | ~30ms | Low |

**Legend**: ✓ = Good, ⚠ = Partial/Needs Testing, ✗ = Poor/Not Supported, ? = Unknown

---

## Recommended Approach

### Phase 1: Test AAC with Fragmented MP4 (Immediate - 2-3 days)

**Goal**: Determine if AAC in MP4 container supports real-time chunk streaming.

**Test Plan**:
1. Create simple test page with MediaRecorder
2. Use `audio/mp4;codecs=mp4a.40.2` MIME type
3. Set `timeslice` to 100ms for chunks
4. Test chunk decoding with Web Audio API `decodeAudioData()`
5. Test in Chrome, Safari, Firefox

**Success Criteria**:
- ✓ Individual MP4 chunks decode successfully
- ✓ Playback latency <150ms acceptable
- ✓ Works in Chrome and Safari (minimum)

**If Successful**: Implement AAC codec option (best browser coverage)

**If Failed**: Proceed to Phase 2

---

### Phase 2: Implement WebCodecs with Opus (1-2 weeks)

**Goal**: Use WebCodecs API for raw Opus encoding/decoding.

**Implementation**:
```javascript
class WebCodecsAudio {
    constructor() {
        this.encoder = null;
        this.decoder = null;
        this.supported = this.checkSupport();
    }

    checkSupport() {
        return 'AudioEncoder' in window && 'AudioDecoder' in window;
    }

    async initEncoder() {
        const config = {
            codec: 'opus',
            sampleRate: 48000,
            numberOfChannels: 1,
            bitrate: 24000
        };

        // Check if config supported
        const support = await AudioEncoder.isConfigSupported(config);
        if (!support.supported) {
            throw new Error('Opus not supported');
        }

        this.encoder = new AudioEncoder({
            output: (chunk, metadata) => {
                this.onEncodedChunk(chunk, metadata);
            },
            error: (e) => console.error('Encode error:', e)
        });

        this.encoder.configure(config);
    }

    async initDecoder() {
        const config = {
            codec: 'opus',
            sampleRate: 48000,
            numberOfChannels: 1
        };

        this.decoder = new AudioDecoder({
            output: (audioData) => {
                this.playAudioData(audioData);
            },
            error: (e) => console.error('Decode error:', e)
        });

        this.decoder.configure(config);
    }

    encode(audioData) {
        if (this.encoder.state === 'configured') {
            this.encoder.encode(audioData);
        }
    }

    decode(chunk) {
        if (this.decoder.state === 'configured') {
            this.decoder.decode(chunk);
        }
    }
}
```

**Browser Fallback Strategy**:
- Chrome/Edge/Firefox: Use WebCodecs with Opus
- Safari (no encoder): Automatic fallback to PCM16
- Progressive enhancement: Detect support, use best available

**Benefits**:
- 96% bandwidth reduction (24 kbps Opus vs 768 kbps PCM16)
- True real-time streaming (no container overhead)
- Future-proof (Safari support coming soon)

---

### Phase 3: Hybrid Storage Strategy (Parallel Implementation)

**Goal**: Reduce storage costs even if real-time transmission stays PCM16.

**Strategy**:
- **Live transmission**: Use best available codec (AAC or WebCodecs or PCM16)
- **Message storage**: Server transcodes to Opus for database
- **History playback**: Decode complete Opus files (no streaming needed)

**Implementation**:
```php
// Server-side (WebSocketServer.php)
private function saveMessage($conn, $channel, $clientId, $audioData,
                            $sampleRate, $duration, $liveCodec = 'pcm16') {

    // If live codec is PCM16, transcode to Opus for storage
    if ($liveCodec === 'pcm16') {
        $opusData = $this->transcodePCM16ToOpus($audioData, $sampleRate);
        $storageCodec = 'opus';
        $storageBitrate = 24000;
    } else {
        // Already compressed, store as-is
        $opusData = $audioData;
        $storageCodec = $liveCodec;
        $storageBitrate = $this->getBitrateForCodec($liveCodec);
    }

    // Store compressed version
    $stmt = $this->db->prepare('
        INSERT INTO message_history
        (channel, audio_data, codec, bitrate, sample_rate, duration, timestamp)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $channel, $opusData, $storageCodec,
        $storageBitrate, $sampleRate, $duration, time()
    ]);
}

private function transcodePCM16ToOpus($base64PCM16, $sampleRate) {
    // Option 1: Use opusenc binary via shell
    // Option 2: Use PHP-FFI with libopus
    // Option 3: Use PHP exec with ffmpeg

    // Example using ffmpeg:
    $pcmData = base64_decode($base64PCM16);

    // Write PCM to temp file
    $pcmFile = tempnam(sys_get_temp_dir(), 'pcm_');
    file_put_contents($pcmFile, $pcmData);

    // Encode to Opus
    $opusFile = tempnam(sys_get_temp_dir(), 'opus_');
    $cmd = sprintf(
        'ffmpeg -f s16le -ar %d -ac 1 -i %s -c:a libopus -b:a 24k %s 2>&1',
        $sampleRate,
        escapeshellarg($pcmFile),
        escapeshellarg($opusFile)
    );

    exec($cmd, $output, $returnCode);

    if ($returnCode === 0 && file_exists($opusFile)) {
        $opusData = file_get_contents($opusFile);
        $base64Opus = base64_encode($opusData);

        // Cleanup
        unlink($pcmFile);
        unlink($opusFile);

        return $base64Opus;
    }

    // Fallback: store as PCM16
    unlink($pcmFile);
    return $base64PCM16;
}
```

**Benefits**:
- 98% storage reduction even with PCM16 live transmission
- No impact on real-time UX
- Works regardless of browser codec support
- Can be implemented independently

---

## Final Recommendations

### Immediate Actions (Week 1)

1. **Test AAC streaming** (2-3 days)
   - Quick proof-of-concept
   - If successful, best immediate solution
   - Excellent Safari support

2. **Create WebCodecs prototype** (2-3 days)
   - Test in Chrome/Firefox
   - Measure latency
   - Test browser detection and fallback

3. **Implement codec detection** (1 day)
   ```javascript
   async function detectBestCodec() {
       // Priority order:
       // 1. WebCodecs Opus (best compression + streaming)
       // 2. AAC MP4 (good compression + great compatibility)
       // 3. PCM16 (fallback, always works)

       if (window.AudioEncoder && window.AudioDecoder) {
           const opusSupport = await AudioEncoder.isConfigSupported({
               codec: 'opus',
               sampleRate: 48000,
               numberOfChannels: 1,
               bitrate: 24000
           });

           if (opusSupport.supported) {
               return 'webcodecs-opus';
           }
       }

       if (MediaRecorder.isTypeSupported('audio/mp4;codecs=mp4a.40.2')) {
           return 'aac';
       }

       return 'pcm16';
   }
   ```

### Short Term (Weeks 2-3)

1. **Implement chosen codec** based on test results
2. **Add server-side transcoding** for storage optimization
3. **Update UI** with codec selector and auto-detection
4. **Comprehensive testing** across browsers

### Medium Term (Month 2)

1. **Monitor Safari WebCodecs** encoder support
2. **Optimize transcoding** performance (consider background workers)
3. **Add codec analytics** (track usage, bandwidth savings)

---

## Risk Mitigation

### Technical Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| AAC chunks don't stream | High | Fall back to WebCodecs approach |
| Safari lacks encoder support | Medium | Automatic PCM16 fallback, monitor Safari updates |
| WebCodecs adds complexity | Medium | Good abstraction layer, comprehensive testing |
| Server transcoding CPU cost | Medium | Rate limiting, async processing, optional feature |

### Browser Compatibility Strategy

```javascript
// Progressive enhancement with fallback chain
const codecStrategy = {
    tier1: 'webcodecs-opus',  // Best: 96% savings, real-time
    tier2: 'aac',              // Good: 90% savings, broad support
    tier3: 'pcm16'             // Fallback: 0% savings, 100% compatible
};

// Auto-select best available
async function selectCodec() {
    for (const tier of Object.values(codecStrategy)) {
        if (await isCodecAvailable(tier)) {
            return tier;
        }
    }
    return 'pcm16'; // Ultimate fallback
}
```

---

## Success Metrics

### Target Goals

1. **Bandwidth Reduction**: 85-95% for majority of users
2. **Browser Coverage**: 95%+ (including fallback)
3. **Latency**: <150ms end-to-end (real-time requirement)
4. **Quality**: MOS >4.0 for voice (toll quality)
5. **Storage Savings**: 95%+ (via server transcoding)

### Monitoring

- Codec usage distribution (analytics)
- Bandwidth per transmission (before/after)
- Database size growth rate
- User-reported quality issues
- Browser compatibility reports

---

## Conclusion

**Top Recommendation**: **Test AAC first, implement WebCodecs with Opus as primary solution**

1. **AAC** offers the quickest path if MP4 fragments support streaming
2. **WebCodecs + Opus** provides the best long-term solution with 96% bandwidth savings
3. **Server-side transcoding** provides storage benefits regardless of live codec
4. **Progressive enhancement** ensures 100% browser coverage via PCM16 fallback

This multi-tiered approach balances immediate bandwidth savings with broad compatibility while maintaining the critical <150ms latency requirement for real-time PTT operation.
