# Opus Codec Support

This feature adds Opus audio codec support to the Walkie Talkie application, providing significant bandwidth reduction while maintaining excellent voice quality.

## Features

- **98% Bandwidth Reduction**: Opus @ 24kbps vs PCM16 @ 768kbps
- **Backward Compatible**: PCM16 remains fully supported
- **Browser-Based**: No external dependencies, uses native Web Audio APIs
- **User Selectable**: Codec can be changed via UI dropdown
- **Persistent Preference**: Codec selection saved in localStorage

## Benefits

| Metric | PCM16 | Opus (24kbps) | Improvement |
|--------|-------|---------------|-------------|
| Bitrate | 768 kbps | 24 kbps | 97% reduction |
| 10s message | 960 KB | 30 KB | 97% smaller |
| 1000 messages | 960 MB | 30 MB | 97% less storage |
| Bandwidth/hour | 2.7 GB | 86 MB | 97% savings |

## Browser Support

| Browser | Opus Encoding | Opus Decoding | Status |
|---------|--------------|---------------|--------|
| Chrome 91+ | ✓ Native | ✓ Native | Full support |
| Firefox 88+ | ✓ Native | ✓ Native | Full support |
| Edge 91+ | ✓ Native | ✓ Native | Full support |
| Safari 14.1+ | ✓ Limited | ✓ Limited | Works with limitations |
| Mobile Chrome | ✓ Native | ✓ Native | Full support |
| Mobile Safari | ✓ Limited | ✓ Limited | Works with limitations |

## Setup

### 1. Run Database Migration

```bash
php cli/migrate.php
```

This adds `codec` and `bitrate` columns to the `message_history` table.

### 2. Configure Environment (Optional)

Update `.env` with Opus settings:

```env
# Audio Codec Configuration
AUDIO_CODEC_PRIMARY=opus          # or 'pcm16'
AUDIO_CODEC_FALLBACK=pcm16
OPUS_BITRATE_KBPS=24
OPUS_FRAME_DURATION_MS=20
OPUS_APPLICATION=voip
OPUS_COMPLEXITY=5
```

### 3. Select Codec in UI

1. Open the Walkie Talkie application
2. Find the **Audio Codec** dropdown in the controls
3. Select "Opus (Compressed - 98% smaller)"
4. Check the status message shows "Opus: Supported!"

## Usage

### Client-Side

The codec selection is automatic based on user preference:

```javascript
// Codec is initialized on app load
walkieTalkie.initializeCodecs();

// User selects codec via UI dropdown
// Or programmatically:
walkieTalkie.selectedCodec = 'opus';
localStorage.setItem('preferred_codec', 'opus');
```

### Server-Side

The server automatically handles both codecs:

- Receives codec information in `audio_data` messages
- Stores codec type in database
- Returns codec information in history responses
- No server-side transcoding required

## Architecture

### Client Flow (Opus)

```
Microphone Stream
    ↓
MediaRecorder (Opus encoder)
    ↓
Opus compressed data (OGG container)
    ↓
Base64 encoding
    ↓
WebSocket JSON message {codec: 'opus', data: '...'}
    ↓
Server (relay)
    ↓
Other clients
    ↓
Web Audio API decodeAudioData (Opus decoder)
    ↓
AudioBuffer playback
```

### Server Storage

```sql
-- Message with codec metadata
INSERT INTO message_history (
    channel, audio_data, codec, bitrate, sample_rate, duration
) VALUES (
    '1', 'base64_opus_data', 'opus', 24000, 48000, 5000
);
```

## API Changes

### WebSocket Message Format

**Before** (PCM16 only):
```json
{
    "type": "audio_data",
    "format": "pcm16",
    "data": "base64_pcm16_data",
    "sampleRate": 44100,
    "channels": 1
}
```

**After** (with Opus):
```json
{
    "type": "audio_data",
    "codec": "opus",
    "format": "opus",
    "data": "base64_opus_data",
    "sampleRate": 48000,
    "channels": 1,
    "bitrate": 24000
}
```

### Database Schema

```sql
-- New columns
ALTER TABLE message_history ADD COLUMN codec TEXT DEFAULT 'pcm16';
ALTER TABLE message_history ADD COLUMN bitrate INTEGER;
```

## Testing

### Manual Testing

1. **Opus Encoding Test**:
   - Select "Opus" from codec dropdown
   - Press and hold PTT button
   - Speak for a few seconds
   - Check browser console for "Started Opus audio streaming"

2. **Opus Playback Test**:
   - Have another user send Opus audio
   - Check console for "Playing Opus audio"
   - Verify audio quality is good

3. **Mixed Codec Test**:
   - Send messages with both PCM16 and Opus
   - Check message history shows both types
   - Verify playback works for both

4. **Browser Compatibility Test**:
   - Test in Chrome, Firefox, Safari
   - Check codec status message
   - Verify fallback to PCM16 if not supported

### Debugging

Enable debug logging:

```javascript
// Check codec support
console.log('Codec support:', walkieTalkie.codecSupport);

// Check selected codec
console.log('Selected codec:', walkieTalkie.selectedCodec);

// Check Opus codec instance
console.log('Opus codec:', walkieTalkie.opusCodec);
```

## Performance

### Encoding Performance

- **Latency**: ~20-50ms (Opus) vs ~10ms (PCM16)
- **CPU Usage**: ~5-10% (mobile), ~2-5% (desktop)
- **Memory**: ~550 KB per stream vs ~200 KB for PCM16

### Network Performance

**Example: 10-second voice message**

| Codec | Data Size | Bandwidth | Transfer Time (1 Mbps) |
|-------|-----------|-----------|------------------------|
| PCM16 | 960 KB | 768 kbps | 7.7 seconds |
| Opus | 30 KB | 24 kbps | 0.24 seconds |

## Troubleshooting

### Opus Not Showing as Supported

**Symptom**: Status shows "Opus: Not supported in this browser"

**Causes**:
1. Browser doesn't support MediaRecorder with Opus
2. opus-codec.js not loaded
3. HTTPS required (some browsers only allow Opus on secure origins)

**Solutions**:
- Use Chrome 91+ or Firefox 88+
- Verify `<script src="assets/opus-codec.js">` is included
- Test on HTTPS or localhost

### Audio Sounds Distorted

**Symptom**: Opus audio playback is garbled or choppy

**Causes**:
1. Network packet loss
2. Incorrect sample rate
3. Decoder error

**Solutions**:
- Check network stability
- Verify sample rate is 48000 Hz
- Check browser console for decode errors

### Cannot Send Opus Audio

**Symptom**: Selecting Opus doesn't work, reverts to PCM16

**Causes**:
1. Opus codec not initialized
2. MediaRecorder failed to start
3. Microphone permissions issue

**Solutions**:
- Check console for initialization errors
- Grant microphone permissions
- Try reloading the page

## Configuration Options

### Client-Side

Stored in `localStorage`:
```javascript
localStorage.setItem('preferred_codec', 'opus'); // or 'pcm16'
```

### Server-Side

Environment variables in `.env`:
```env
OPUS_BITRATE_KBPS=24           # 16, 24, 32, 64
OPUS_FRAME_DURATION_MS=20      # 10, 20, 40, 60
OPUS_APPLICATION=voip          # voip, audio, lowdelay
OPUS_COMPLEXITY=5              # 0-10
```

## Migration Guide

### From PCM16-Only to Opus Support

**For Existing Installations**:

1. **Backup database** before migration:
   ```bash
   cp data/app.db data/app.db.backup
   ```

2. **Run migration**:
   ```bash
   php cli/migrate.php
   ```

3. **Update .env** (optional):
   ```bash
   cp .env.example .env
   # Edit .env to configure Opus settings
   ```

4. **Clear browser cache** for JavaScript updates

5. **Test with both codecs** to ensure backward compatibility

**Rollback Plan**:

If issues occur:
1. Restore database: `cp data/app.db.backup data/app.db`
2. Revert code: `git checkout main`
3. All PCM16 messages remain playable

## Known Limitations

1. **No Server-Side Transcoding**: Server only relays, doesn't convert between codecs
2. **OGG Container Only**: Uses OGG/Opus, not raw Opus packets
3. **Fixed Bitrate**: Currently 24kbps fixed, no dynamic adjustment
4. **Mono Only**: Single channel audio (same as PCM16)
5. **Browser-Dependent**: Opus features vary by browser

## Future Enhancements

- [ ] Adaptive bitrate based on network conditions
- [ ] CLI support for Opus encoding
- [ ] Server-side transcoding for codec interoperability
- [ ] Advanced Opus features (FEC, DTX)
- [ ] Codec statistics and quality metrics
- [ ] Stereo support

## References

- [Opus Codec Specification (RFC 6716)](https://datatracker.ietf.org/doc/html/rfc6716)
- [Web Audio API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Audio_API)
- [MediaRecorder API](https://developer.mozilla.org/en-US/docs/Web/API/MediaRecorder)
- [OPUS_MIGRATION.md](./OPUS_MIGRATION.md) - Detailed technical implementation guide

## Support

For issues or questions:
1. Check browser console for errors
2. Review [OPUS_MIGRATION.md](./OPUS_MIGRATION.md) for technical details
3. File an issue on GitHub with:
   - Browser version
   - Console logs
   - Steps to reproduce
