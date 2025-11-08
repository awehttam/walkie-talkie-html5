# Opus Codec Testing Checklist

## Pre-Testing Setup

- [x] Run database migration: `php cli/migrate.php`
- [ ] Start WebSocket server: `php server.php`
- [ ] Open application in browser: `http://localhost:3000` (or your configured URL)

## Test Cases

### 1. Database Migration

**Test**: Verify database schema updated correctly

```bash
php cli/migrate.php
```

**Expected**:
- ✅ Migration completes without errors
- ✅ `message_history` table has `codec` and `bitrate` columns
- ✅ Existing records have `codec = 'pcm16'`

**Verify**:
```bash
sqlite3 data/app.db ".schema message_history"
```

---

### 2. Codec Detection (Chrome/Firefox)

**Test**: Open app and check codec support detection

**Expected**:
- ✅ Console shows "Opus codec support detected"
- ✅ Codec dropdown shows both options enabled
- ✅ Status shows "Opus: Supported! (98% bandwidth reduction)"

**Console Commands**:
```javascript
console.log('Codec support:', window.walkieTalkie.codecSupport);
// Should show: { pcm16: true, opus: true }
```

---

### 3. PCM16 Transmission (Baseline)

**Test**: Send audio using PCM16 codec

**Steps**:
1. Select "PCM16 (Uncompressed)" from dropdown
2. Press and hold PTT button
3. Speak for 3-5 seconds
4. Release PTT button

**Expected**:
- ✅ Console shows "Started PCM16 audio streaming"
- ✅ WebSocket messages contain `codec: 'pcm16'`
- ✅ Audio plays back correctly on other clients
- ✅ Message saved in history with `codec = 'pcm16'`

**Verify**:
```sql
SELECT codec, length(audio_data), sample_rate FROM message_history ORDER BY id DESC LIMIT 1;
-- Should show: pcm16 | ~960000 | 44100-48000
```

---

### 4. Opus Transmission

**Test**: Send audio using Opus codec

**Steps**:
1. Select "Opus (Compressed - 98% smaller)" from dropdown
2. Press and hold PTT button
3. Speak for 3-5 seconds
4. Release PTT button

**Expected**:
- ✅ Console shows "Started Opus audio streaming"
- ✅ WebSocket messages contain `codec: 'opus'`
- ✅ Audio plays back correctly on other clients
- ✅ Message saved in history with `codec = 'opus'`
- ✅ Data size ~98% smaller than PCM16

**Verify**:
```sql
SELECT codec, length(audio_data), bitrate FROM message_history ORDER BY id DESC LIMIT 1;
-- Should show: opus | ~30000 | 24000
```

**Console Commands**:
```javascript
// Check last sent message
console.log('Selected codec:', window.walkieTalkie.selectedCodec);
// Should show: "opus"
```

---

### 5. Opus Playback

**Test**: Receive and play Opus audio

**Setup**:
- Client A: Select Opus codec
- Client B: Join same channel

**Steps**:
1. Client A sends Opus audio
2. Client B receives audio

**Expected**:
- ✅ Client B console shows "Playing Opus audio"
- ✅ Audio quality is good (no distortion)
- ✅ Latency is acceptable (<150ms total)
- ✅ No errors in console

---

### 6. Message History Playback

**Test**: Play back Opus messages from history

**Steps**:
1. Send 2-3 Opus messages
2. Open History panel
3. Click play button on Opus messages
4. Try "Play All" button

**Expected**:
- ✅ Individual Opus messages play correctly
- ✅ "Play All" plays all messages in sequence
- ✅ Codec field shows in message metadata
- ✅ No playback errors

**Verify History Response**:
```javascript
// Check history data structure
console.log(window.walkieTalkie.messageHistory[0]);
// Should include: codec: 'opus', bitrate: 24000
```

---

### 7. Codec Switching

**Test**: Switch between PCM16 and Opus

**Steps**:
1. Start with PCM16, send a message
2. Switch to Opus, send a message
3. Switch back to PCM16, send a message
4. Check all messages play correctly

**Expected**:
- ✅ Codec switch is instant (no page reload needed)
- ✅ All messages stored with correct codec
- ✅ Mixed codec history plays correctly
- ✅ Preference persists after page reload

**Verify**:
```javascript
localStorage.getItem('preferred_codec'); // Should match selected codec
```

---

### 8. Browser Compatibility

**Test**: Safari/Edge support detection

**Safari Expected**:
- ⚠️ May show "Opus: Not supported" or limited support
- ✅ Falls back to PCM16 correctly
- ✅ Opus option disabled if not supported

**Edge Expected**:
- ✅ Full Opus support (Chromium-based)
- ✅ Same behavior as Chrome

---

### 9. Bandwidth Verification

**Test**: Verify actual bandwidth reduction

**Setup**: Use browser DevTools Network tab

**Steps**:
1. Clear network log
2. Send 10-second PCM16 message
3. Note WebSocket message size
4. Clear network log
5. Send 10-second Opus message
6. Note WebSocket message size
7. Compare sizes

**Expected**:
- PCM16: ~960 KB (768 kbps)
- Opus: ~30 KB (24 kbps)
- Ratio: ~97-98% reduction

---

### 10. Error Handling

**Test**: Handle edge cases

**Test Cases**:

#### A. Opus not supported
1. Simulate by disabling MediaRecorder
2. Try to select Opus

**Expected**:
- ✅ Alert shows "OPUS codec is not supported"
- ✅ Reverts to PCM16
- ✅ Status message shows not supported

#### B. Network interruption
1. Start Opus transmission
2. Disconnect network mid-transmission
3. Reconnect

**Expected**:
- ✅ Transmission stops gracefully
- ✅ No crashes or hung state
- ✅ Can resume after reconnection

#### C. Concurrent transmissions
1. Two clients send simultaneously

**Expected**:
- ✅ Server handles both correctly
- ✅ Both stored with correct codec
- ✅ No data corruption

---

### 11. Server Logging

**Test**: Verify server logs codec information

**Expected Server Console Output**:
```
[TALK END] Channel 1 - username (Client ID: client_xxx, Connection: 123, IP: 127.0.0.1), Codec: opus, Duration: 5000ms
```

**Verify**:
- ✅ Codec field logged
- ✅ Duration calculated correctly for Opus
- ✅ No errors during storage

---

### 12. Database Storage

**Test**: Verify database integrity

**Query**:
```sql
SELECT
    id,
    channel,
    codec,
    bitrate,
    sample_rate,
    duration,
    length(audio_data) as data_size
FROM message_history
ORDER BY id DESC
LIMIT 10;
```

**Expected**:
- ✅ Opus messages have `codec = 'opus'`
- ✅ Opus messages have `bitrate = 24000`
- ✅ Opus messages have `sample_rate = 48000`
- ✅ Opus data_size much smaller than PCM16
- ✅ No NULL values in codec column

---

## Performance Testing

### Latency Test

**Measure end-to-end latency**:

1. Open DevTools Performance tab
2. Start recording
3. Press PTT → Speak → Release
4. Measure time from release to playback end

**Expected**:
- PCM16: 50-100ms total
- Opus: 70-150ms total
- Acceptable difference: <50ms

### CPU Usage Test

**Monitor CPU during transmission**:

1. Open Task Manager / Activity Monitor
2. Send continuous 30-second message
3. Note CPU usage

**Expected**:
- Desktop: <10% CPU for Opus encoding
- Mobile: <20% CPU for Opus encoding
- No sustained high CPU after transmission

### Memory Test

**Check for memory leaks**:

1. Send 50 consecutive messages
2. Check browser memory usage
3. Should remain stable

**Expected**:
- Memory usage stable
- No continuous growth
- Garbage collection working

---

## Known Issues / Limitations

- [ ] Safari: Limited Opus support (MediaRecorder API restrictions)
- [ ] Mobile Safari: May require user gesture for first audio playback
- [ ] Opus OGG container overhead: ~1-2 KB per frame
- [ ] No server-side transcoding (clients must support same codec)

---

## Rollback Procedure

If major issues found:

```bash
# Stop server
# Restore database
cp data/app.db.backup data/app.db

# Revert code
git checkout main

# Restart server
php server.php
```

---

## Success Criteria

✅ **All must pass**:
1. Database migration completes successfully
2. PCM16 transmission still works (backward compatibility)
3. Opus transmission works in Chrome/Firefox
4. Opus playback works correctly
5. Message history shows both codecs
6. Codec selection persists
7. No console errors during normal operation
8. Bandwidth reduction confirmed (>90%)
9. Server logs codec information
10. Database stores codec metadata

---

## Test Results Template

```
Date: ___________
Tester: ___________
Browser: Chrome ___ / Firefox ___ / Safari ___ / Edge ___
OS: Windows / Mac / Linux

[ ] Database Migration
[ ] Codec Detection
[ ] PCM16 Transmission
[ ] Opus Transmission
[ ] Opus Playback
[ ] History Playback
[ ] Codec Switching
[ ] Browser Compatibility
[ ] Bandwidth Verification
[ ] Error Handling
[ ] Server Logging
[ ] Database Storage

Issues Found:
___________________________________________
___________________________________________
___________________________________________

Overall Result: PASS / FAIL / PARTIAL
```
