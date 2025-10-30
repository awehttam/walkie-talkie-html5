# Implementation Plan: Last Message Support with SQLite

## Overview
Add message history feature that records the last 10 transmissions per channel using SQLite with proper concurrent write handling.

## Concurrent Write Strategy (SQLite)

### Approach: WAL Mode + Connection Pooling
- **WAL Mode**: Enables concurrent reads during writes, reduces lock contention
- **Busy Timeout**: Set `busyTimeout()` to retry locks automatically (3-5 seconds)
- **Single Connection**: Maintain one persistent PDO connection in WebSocketServer
- **Transaction Batching**: Wrap inserts in transactions where possible
- **Error Handling**: Catch `SQLITE_BUSY` exceptions and retry with exponential backoff

### Why This Works
Ratchet/ReactPHP is single-threaded, so writes are naturally serialized. WAL mode allows reads during writes for client queries.

## Database Schema

```sql
CREATE TABLE message_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel TEXT NOT NULL,
    client_id TEXT NOT NULL,
    audio_data TEXT NOT NULL,  -- Base64 encoded PCM16
    sample_rate INTEGER NOT NULL,
    duration INTEGER NOT NULL,  -- milliseconds
    timestamp INTEGER NOT NULL
);

CREATE INDEX idx_channel_timestamp ON message_history(channel, timestamp DESC);
```

## Files to Modify

### 1. src/WebSocketServer.php
- Add SQLite PDO connection property with WAL mode
- Add `$activeTransmissions` property: buffer for audio chunks during transmission
- Create `initDatabase()` method: setup DB file, enable WAL, set busy timeout
- Create `saveMessage()` method: insert with auto-cleanup (keep last 10 per channel)
- Create `getChannelHistory()` method: query last 10 messages
- Modify `broadcastAudio()`: buffer audio chunks during transmission
- Modify `handlePushToTalkEnd()`: concatenate buffered chunks, save complete transmission
- Modify `onClose()`: clean up active transmission buffers
- Add `history_request` message handler: return channel history
- Add error handling for SQLITE_BUSY with retry logic

### 2. public/assets/walkie-talkie.js
- Add `messageHistory` array property
- Add `requestHistory()` method: send history_request on channel join
- Add `handleHistoryResponse()`: populate history array, update UI
- Add `playHistoryMessage(index)`: play individual message
- Add `playAllHistory()`: sequential playback of all messages
- Add `updateHistoryPanel()`: render history list with timestamp/duration/user
- Add `calculateDuration()`: estimate audio duration from data length
- Modify `switchChannel()`: request history after joining

### 3. public/index.php
- Add collapsible history panel HTML structure
- Add history message list container
- Add "Play All" button and individual play buttons (template)
- Add toggle button for collapsing/expanding panel

### 4. public/assets/style.css
- Style collapsible history panel (hidden by default)
- Style message list items (timestamp, duration, user, play button)
- Add collapsed/expanded states with smooth transitions
- Style play buttons and playback indicators
- Responsive layout adjustments

## Implementation Steps

1. **Database Setup**: Create SQLite initialization in WebSocketServer with WAL mode
2. **Server Methods**: Implement save/retrieve methods with proper error handling
3. **Message Persistence**: Hook into audio broadcast to save messages
4. **Client Request**: Add history request on channel join
5. **UI Components**: Build collapsible panel with message list
6. **Playback Logic**: Implement individual and sequential playback
7. **Testing**: Test concurrent access, verify 10-message limit, test playback

## Key Technical Details

### Storage
- **Database Location**: `data/walkie-talkie.db` (create data/ directory)
- **Message Limit**: Automatic cleanup keeping newest 10 per channel

### Display Information
- **Timestamp**: "X minutes ago" format for recent, time for older
- **Duration**: Calculated as `(audioDataLength / 2) / sampleRate * 1000` ms
- **User Identifier**: Last 4 chars of client_id (e.g., "User #a3f9")

### SQLite Configuration
```sql
PRAGMA journal_mode=WAL;
PRAGMA busy_timeout=5000;
```

### UI Behavior
- **Collapsible Panel**: Hidden by default, toggle to show/hide
- **Message List**: Shows last 10 messages with timestamp, duration, user ID
- **Individual Playback**: Click play button on any message to hear it
- **Play All**: Button to play all messages sequentially from oldest to newest

## Concurrency Safety

- Single-threaded event loop = no true concurrency
- WAL mode prevents read blocking
- Busy timeout handles any transient locks
- Retry logic for edge cases
- Connection kept open for lifetime of server process

## Message Data Structure

### Stored in Database
```json
{
  "id": 1,
  "channel": "1",
  "client_id": "client_1234567890_a3f9b2c1d",
  "audio_data": "base64_encoded_pcm16_data...",
  "sample_rate": 48000,
  "duration": 3500,
  "timestamp": 1698765432000
}
```

### Sent to Client
```json
{
  "type": "history_response",
  "channel": "1",
  "messages": [
    {
      "client_id": "client_1234567890_a3f9b2c1d",
      "audio_data": "base64_encoded_pcm16_data...",
      "sample_rate": 48000,
      "duration": 3500,
      "timestamp": 1698765432000
    }
  ]
}
```
