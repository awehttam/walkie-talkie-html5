# Opus Codec Debugging Guide

## Issue: No audio when using Opus codec

### Quick Test Steps

1. **Open the application in Chrome** (best Opus support)

2. **Open Browser Console** (F12)

3. **Select Opus codec** from dropdown

4. **Press and hold PTT button**, speak for 2-3 seconds

5. **Check console output** - you should see:

### Expected Console Output (Sending)

```
Started Opus audio streaming
Sending Opus audio chunk: {dataLength: XXXX, samplePreview: "data:audio/ogg;..."}
Sending Opus audio chunk: {dataLength: XXXX, samplePreview: "data:audio/ogg;..."}
...
```

### Expected Console Output (Receiving - on another client)

```
Decoding Opus data: {base64Length: XXXX, arrayBufferSize: YYYY, firstBytes: Uint8Array(4)}
Attempting to decode audio data, size: YYYY
Successfully decoded Opus audio: {duration: X.XX, sampleRate: 48000, channels: 1}
Playing Opus audio
```

---

## Diagnostic Checklist

### 1. Is Opus Supported?

**Console command:**
```javascript
console.log('Opus support:', window.walkieTalkie.codecSupport);
// Should show: {pcm16: true, opus: true}

console.log('Opus codec:', window.walkieTalkie.opusCodec);
// Should show: OpusCodec object

console.log('MediaRecorder Opus:', MediaRecorder.isTypeSupported('audio/ogg;codecs=opus'));
// Should show: true
```

**If false**: Opus not supported in browser, use PCM16

---

### 2. Is Audio Being Captured?

**Check**: Console should show "Sending Opus audio chunk" messages every 100ms while talking

**If no messages**: MediaRecorder not starting

**Fix**: Check for errors:
```javascript
// Look for this in console:
MediaRecorder error: ...
```

---

### 3. What Format is Being Sent?

**Console command** (after sending a message):
```javascript
// Check WebSocket messages in Network tab
// Look for audio_data messages
// Should contain:
{
  type: "audio_data",
  codec: "opus",
  format: "opus",
  data: "data:audio/ogg;base64,..." or just base64 string
}
```

**Issue Check**: If `data` starts with `data:audio/ogg;base64,` we're sending the data URL, not just base64

**This is likely the problem!**

---

### 4. Is the Base64 Data Correct?

The MediaRecorder outputs a **data URL** like:
```
data:audio/ogg;base64,T2dnUwACAAAAAAAAAAB0...
```

But we're sending the whole string, when we should only send the Base64 part after the comma!

**Fix Required**: Extract only the base64 part

---

### 5. Test Decoding Manually

**Console command:**
```javascript
// Get a test Opus message from history
let testMessage = window.walkieTalkie.messageHistory.find(m => m.codec === 'opus');

if (testMessage) {
    console.log('Test Opus data length:', testMessage.audio_data.length);
    console.log('First 50 chars:', testMessage.audio_data.substring(0, 50));

    // Try to decode it
    window.walkieTalkie.playOpusAudio(testMessage.audio_data, 48000);
}
```

**Check console** for decode errors

---

## Likely Root Cause

Based on the code review, I believe the issue is in `opus-codec.js` line 111:

```javascript
const base64data = reader.result.split(',')[1];  // Gets base64 part
```

This correctly extracts the base64 part, **but** check if the `sendOpusAudio` function is receiving:
- Just base64: `T2dnUwACAAAAAAAAAAB0...`
- Or data URL: `data:audio/ogg;base64,T2dn...`

---

## Most Likely Fix Needed

### Option 1: Encoder sends data URL (current issue)

If console shows `data:audio/ogg;base64,` in the sent data, we need to fix the decoding:

**File**: `public/assets/walkie-talkie.js` (line ~1157)

**Current** `playOpusAudio()`:
```javascript
const audioBuffer = await this.opusCodec.decode(base64Data, this.audioContext);
```

**Should strip data URL prefix if present**:
```javascript
async playOpusAudio(base64Data, sampleRate) {
    // Strip data URL prefix if present
    if (base64Data.startsWith('data:')) {
        const commaIndex = base64Data.indexOf(',');
        if (commaIndex !== -1) {
            base64Data = base64Data.substring(commaIndex + 1);
            console.log('Stripped data URL prefix, new length:', base64Data.length);
        }
    }

    const audioBuffer = await this.opusCodec.decode(base64Data, this.audioContext);
    // ... rest of method
}
```

---

### Option 2: Encoder sends just base64 (already correct)

If the base64 is clean (no `data:` prefix), then the issue might be:

1. **Wrong MIME type in container**
2. **Corrupted data during transmission**
3. **Browser doesn't support this specific Opus variant**

---

## Testing Steps After Fix

1. Reload page (clear cache: Ctrl+Shift+R)
2. Select Opus codec
3. Send test message
4. Check console for successful decode message
5. Verify audio plays

---

## Alternative: Simpler Approach

If Opus continues to have issues, we can fall back to using WebM/Opus which has better browser support:

**File**: `public/assets/opus-codec.js` (line ~20)

**Change**:
```javascript
const types = [
    'audio/webm;codecs=opus',  // Try WebM first (better support)
    'audio/ogg;codecs=opus',
    'audio/ogg'
];
```

This might work better because WebM is more widely supported than OGG in browsers.

---

## Need More Help?

Run this complete diagnostic:

```javascript
// Complete Opus diagnostic
console.log('=== OPUS DIAGNOSTIC ===');
console.log('1. Codec support:', window.walkieTalkie.codecSupport);
console.log('2. Selected codec:', window.walkieTalkie.selectedCodec);
console.log('3. Opus instance:', window.walkieTalkie.opusCodec);
console.log('4. MediaRecorder Opus:', MediaRecorder.isTypeSupported('audio/ogg;codecs=opus'));
console.log('5. MediaRecorder WebM:', MediaRecorder.isTypeSupported('audio/webm;codecs=opus'));
console.log('6. Message history count:', window.walkieTalkie.messageHistory.length);

// Try to find an Opus message
let opusMsg = window.walkieTalkie.messageHistory.find(m => m.codec === 'opus');
if (opusMsg) {
    console.log('7. Sample Opus message:', {
        codec: opusMsg.codec,
        dataLength: opusMsg.audio_data.length,
        dataStart: opusMsg.audio_data.substring(0, 50),
        hasDataUrlPrefix: opusMsg.audio_data.startsWith('data:')
    });
}
```

Copy the output and we can diagnose further!
