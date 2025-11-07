# Mobile App Implementation Plan

## Overview

This document outlines the comprehensive plan for implementing a cross-platform mobile application (Android & iOS) for the Walkie-Talkie system using Flutter. The app will provide native mobile experience with full feature parity to the web interface, including real-time audio transmission, authentication, and background operation.

**Framework**: Flutter
**Target Platforms**: Android (API 21+), iOS (13.0+)
**Authentication**: Biometric (WebAuthn/Passkeys) + Guest Mode
**Approach**: Phased development with iterative releases

---

## Architecture Summary

### Current System Overview

The walkie-talkie system consists of:

- **WebSocket Server**: Ratchet/ReactPHP (PHP 8.1+) on port 8080
- **Protocol**: JSON messages over WebSocket (ws:// or wss://)
- **Audio Format**: PCM16 (16-bit signed integer, mono, 48kHz)
- **Authentication**: JWT-based with WebAuthn/Passkeys or anonymous guest mode
- **Database**: SQLite for users, credentials, message history
- **Plugin System**: Extensible hooks for custom functionality

### Mobile App Architecture

```
Flutter App
├── Services Layer
│   ├── WebSocket Service (connection, messaging, reconnection)
│   ├── Audio Service (recording, playback, PCM16 encoding/decoding)
│   ├── Auth Service (JWT tokens, WebAuthn, session management)
│   └── Storage Service (secure token storage, preferences)
├── State Management (Provider/Riverpod)
│   ├── Connection State (status, channel, participants)
│   ├── Audio State (recording, speaking indicator)
│   └── Auth State (user, tokens, mode)
├── UI Layer
│   ├── Home Screen (PTT button, channel, status)
│   ├── Auth Screens (login, register, guest mode)
│   ├── History Screen (message playback)
│   └── Settings Screen (volume, beep, preferences)
└── Platform Layer
    ├── Android (AudioRecord, AudioTrack, biometrics)
    └── iOS (AVAudioEngine, biometrics)
```

---

## Phase 1: MVP Foundation (2-3 weeks)

**Goal**: Basic push-to-talk functionality with guest mode

### 1.1 Project Setup & Configuration

**Tasks**:
- Create Flutter project: `flutter create walkie_talkie_mobile`
- Configure iOS deployment target (iOS 13.0+)
- Configure Android minSdkVersion (21), targetSdkVersion (34)
- Set up project structure with proper directories
- Add core dependencies to `pubspec.yaml`

**Initial Dependencies**:
```yaml
dependencies:
  flutter:
    sdk: flutter
  web_socket_channel: ^2.4.0      # WebSocket client
  record: ^5.0.0                   # Audio recording
  audioplayers: ^5.2.0             # Audio playback
  permission_handler: ^11.0.0      # Microphone permissions
  provider: ^6.1.0                 # State management
  shared_preferences: ^2.2.0       # Local storage
```

**Platform Permissions**:
- iOS: Add `NSMicrophoneUsageDescription` to Info.plist
- Android: Add `RECORD_AUDIO`, `INTERNET`, `WAKE_LOCK` permissions

**Deliverables**:
- Flutter project scaffold
- Build succeeds on both iOS and Android
- Permissions configuration complete

---

### 1.2 Core WebSocket Communication

**Implementation Details**:

**WebSocket Service** (`lib/services/websocket_service.dart`):
- Connection management with auto-reconnection (3-second delay)
- JSON message parsing and serialization
- Connection state tracking (disconnected/connecting/connected)
- Event stream for incoming messages
- Graceful disconnect handling

**Message Protocol**:
```dart
// Message types
enum MessageType {
  authenticate,
  setScreenName,
  joinChannel,
  leaveChannel,
  pushToTalkStart,
  pushToTalkEnd,
  audioData,
  historyRequest,
  // Server messages
  authenticated,
  screenNameSet,
  channelJoined,
  participantJoined,
  participantLeft,
  userSpeaking,
  historyResponse,
  error
}

// Example message structure
class WebSocketMessage {
  final MessageType type;
  final Map<String, dynamic> data;

  Map<String, dynamic> toJson() => {
    'type': type.name,
    ...data
  };
}
```

**Connection Flow**:
1. Connect to WebSocket URL (from config or hardcoded)
2. Listen for connection open event
3. Send authentication or screen name message
4. Handle server response (authenticated/screen_name_set)
5. Join default channel
6. Start listening for messages

**Reconnection Logic**:
- Detect connection loss
- Wait 3 seconds
- Attempt reconnection
- Restore previous state (re-authenticate, re-join channel)
- Notify UI of connection status changes

**Deliverables**:
- WebSocket service with full message protocol support
- Reconnection logic tested
- Message stream for UI consumption

---

### 1.3 Guest Authentication

**Implementation Details**:

**Auth Service** (`lib/services/auth_service.dart`):
- Guest mode with screen name entry
- Screen name validation (2-20 chars, alphanumeric, `^[a-zA-Z0-9_-]+$`)
- Random screen name generation
- Session state management

**Screen Name Generator**:
```dart
List<String> adjectives = ['Bold', 'Quick', 'Silent', 'Brave', 'Swift'];
List<String> animals = ['Eagle', 'Tiger', 'Wolf', 'Hawk', 'Bear'];

String generateScreenName() {
  final adj = adjectives[Random().nextInt(adjectives.length)];
  final animal = animals[Random().nextInt(animals.length)];
  final number = Random().nextInt(1000);
  return '$adj$animal$number';
}
```

**Authentication Flow (Guest)**:
1. User enters screen name OR generates random name
2. Validate screen name format
3. Send `set_screen_name` message to server
4. Await `screen_name_set` confirmation
5. Store screen name in local state
6. Proceed to main UI

**UI Screens**:
- Welcome screen with "Enter Name" field
- "Generate Random Name" button
- "Continue as Guest" action
- Validation error messages

**Deliverables**:
- Guest authentication flow working
- Screen name validation and generation
- Welcome/login screen UI

---

### 1.4 Basic Audio Pipeline

**Implementation Details**:

**Audio Service** (`lib/services/audio_service.dart`):
- Microphone permission handling
- Audio recording in PCM16 format
- Audio playback with buffering
- Format conversion (Float32 ↔ Int16)
- Base64 encoding/decoding

**Recording Pipeline**:
```dart
// Configuration
final recordConfig = RecordConfig(
  encoder: AudioEncoder.pcm16bit,
  sampleRate: 48000,
  numChannels: 1,
  bitRate: 768000,
  autoGain: true,
  echoCancel: true,
  noiseSuppress: true,
);

// Recording flow
1. Request microphone permission
2. Start recording with config
3. Capture audio chunks (4096 samples)
4. Convert Float32 → Int16 (if needed)
5. Encode to Base64
6. Send via WebSocket
```

**Audio Data Packet**:
```dart
class AudioDataMessage {
  final String channel;
  final String data;          // Base64 PCM16
  final String format;        // "pcm16"
  final int sampleRate;       // 48000
  final int channels;         // 1
  final String clientId;      // Unique client ID
  final bool excludeSender;   // true
}
```

**Playback Pipeline**:
```dart
// Playback flow
1. Receive Base64 audio data from WebSocket
2. Decode Base64 → byte array
3. Convert Int16 → Float32 (if needed)
4. Create audio buffer
5. Queue for playback
6. Play with volume control
```

**PCM16 Conversion**:
```dart
// Float32 → Int16
Int16List float32ToInt16(Float32List input) {
  final output = Int16List(input.length);
  for (int i = 0; i < input.length; i++) {
    // Clamp to [-1.0, 1.0] and scale to Int16 range
    final clamped = input[i].clamp(-1.0, 1.0);
    output[i] = (clamped * 32767).round();
  }
  return output;
}

// Int16 → Float32
Float32List int16ToFloat32(Int16List input) {
  final output = Float32List(input.length);
  for (int i = 0; i < input.length; i++) {
    output[i] = input[i] / 32768.0;
  }
  return output;
}
```

**Chunking Strategy**:
- Chunk size: 4096 samples
- Duration: ~85ms at 48kHz
- Byte size: 8192 bytes (4096 samples × 2 bytes)
- Base64 size: ~10,923 bytes (8192 × 4/3)

**Audio Quality Settings**:
```dart
const audioConfig = {
  'echoCancellation': true,
  'noiseSuppression': true,
  'autoGainControl': true,
  'sampleRate': 48000,
  'channelCount': 1,
};
```

**Deliverables**:
- Audio recording with PCM16 format
- Audio playback functionality
- Format conversion utilities
- Base64 encoding/decoding
- Chunking logic implemented

---

### 1.5 Push-to-Talk UI

**Implementation Details**:

**PTT Button Widget** (`lib/widgets/ptt_button.dart`):
- Large, circular button (center of screen)
- Visual states: idle (gray), recording (red/animated), disabled (gray/dimmed)
- Touch handlers for press/release
- Haptic feedback on press/release
- Animated recording indicator (pulsing effect)

**Touch Handling**:
```dart
GestureDetector(
  onTapDown: (_) => _startTransmission(),
  onTapUp: (_) => _stopTransmission(),
  onTapCancel: () => _stopTransmission(),
  child: Container(
    // PTT button UI
  ),
);
```

**Transmission Flow**:
```dart
void _startTransmission() async {
  // 1. Check microphone permission
  if (!await _checkMicPermission()) return;

  // 2. Send push_to_talk_start message
  await websocketService.send(PushToTalkStartMessage(
    channel: currentChannel,
    clientId: clientId,
  ));

  // 3. Start audio recording
  await audioService.startRecording();

  // 4. Start streaming audio chunks
  audioService.audioChunks.listen((chunk) {
    final base64Data = base64Encode(chunk);
    websocketService.send(AudioDataMessage(
      channel: currentChannel,
      data: base64Data,
      format: 'pcm16',
      sampleRate: 48000,
      channels: 1,
      clientId: clientId,
      excludeSender: true,
    ));
  });

  // 5. Update UI state
  setState(() => isRecording = true);
  HapticFeedback.mediumImpact();
}

void _stopTransmission() async {
  // 1. Stop audio recording
  await audioService.stopRecording();

  // 2. Send push_to_talk_end message
  await websocketService.send(PushToTalkEndMessage(
    channel: currentChannel,
    clientId: clientId,
  ));

  // 3. Update UI state
  setState(() => isRecording = false);
  HapticFeedback.lightImpact();
}
```

**Speaking Indicator**:
- Display "Username is speaking..." when receiving `user_speaking` message
- Show visual indicator (e.g., waveform animation, pulsing icon)
- Hide when speaking ends
- Position: Top of screen or overlay on PTT button

**UI Layout**:
```
┌─────────────────────────┐
│   Connection Status     │ ← Online/Offline indicator
│   Channel: 1 (5 users)  │ ← Channel info
├─────────────────────────┤
│                         │
│   [Speaking Indicator]  │ ← When someone else talks
│                         │
│         ┌───┐           │
│         │PTT│           │ ← Large circular button
│         └───┘           │
│     Hold to Talk        │
│                         │
└─────────────────────────┘
```

**Deliverables**:
- PTT button with visual states
- Touch handling (press/release)
- Speaking indicator for other users
- Haptic feedback integration
- Main screen layout

---

### 1.6 Single Channel Support

**Implementation Details**:

**Channel Management**:
- Hard-code channel "1" for MVP
- Auto-join on connection
- Display channel info in header
- Show participant count

**Channel State**:
```dart
class ChannelState {
  final String channelId;
  final int participantCount;
  final String? currentSpeaker;
  final bool isConnected;
}
```

**Event Handling**:
```dart
// Handle channel_joined event
void _onChannelJoined(Map<String, dynamic> data) {
  setState(() {
    channelState = ChannelState(
      channelId: data['channel'],
      participantCount: data['participants'],
      isConnected: true,
    );
  });
}

// Handle participant_joined event
void _onParticipantJoined(Map<String, dynamic> data) {
  setState(() {
    channelState = channelState.copyWith(
      participantCount: data['participants'],
    );
  });
  // Show notification: "${data['screen_name']} joined"
}

// Handle participant_left event
void _onParticipantLeft(Map<String, dynamic> data) {
  setState(() {
    channelState = channelState.copyWith(
      participantCount: data['participants'],
    );
  });
}

// Handle user_speaking event
void _onUserSpeaking(Map<String, dynamic> data) {
  setState(() {
    if (data['speaking']) {
      channelState = channelState.copyWith(
        currentSpeaker: data['screen_name'],
      );
    } else {
      channelState = channelState.copyWith(
        currentSpeaker: null,
      );
    }
  });
}
```

**Connection Flow**:
1. WebSocket connects
2. Authenticate (guest mode)
3. Auto-join channel "1"
4. Display channel info
5. Ready for PTT

**Deliverables**:
- Channel state management
- Auto-join logic
- Participant count display
- Event handling for channel updates

---

### Phase 1 Deliverables Summary

**Functional App with**:
- ✅ Guest mode authentication (screen name)
- ✅ WebSocket connection with auto-reconnect
- ✅ Push-to-talk audio transmission
- ✅ Audio reception and playback
- ✅ Single channel (channel "1")
- ✅ Connection status indicator
- ✅ Participant count display
- ✅ Speaking indicator

**Testing Checklist**:
- [ ] Can set screen name and connect
- [ ] Can press PTT and record audio
- [ ] Can hear audio from other users
- [ ] Reconnects after network interruption
- [ ] Handles microphone permission denial
- [ ] Works on both iOS and Android
- [ ] Audio quality is acceptable
- [ ] UI is responsive and intuitive

---

## Phase 2: Full Feature Set (3-4 weeks)

**Goal**: Feature parity with web interface

### 2.1 Multi-Channel Support

**Implementation Details**:

**Channel Selector UI**:
- Bottom sheet or modal for channel selection
- Number input (1-999)
- List of recent/favorite channels
- Current channel highlighted

**Channel Management**:
```dart
class ChannelService {
  String currentChannel = '1';
  List<String> recentChannels = [];

  Future<void> switchChannel(String newChannel) async {
    // 1. Leave current channel
    await websocketService.send(LeaveChannelMessage(
      channel: currentChannel,
    ));

    // 2. Join new channel
    await websocketService.send(JoinChannelMessage(
      channel: newChannel,
    ));

    // 3. Wait for channel_joined confirmation
    await _waitForChannelJoined(newChannel);

    // 4. Update state
    currentChannel = newChannel;
    _addToRecentChannels(newChannel);

    // 5. Request message history
    await requestHistory(newChannel);
  }
}
```

**Channel Validation**:
- Range: 1-999
- Numeric only
- Handle invalid input gracefully

**UI Updates**:
- Add channel selector button/icon to header
- Show current channel number prominently
- Update participant count per channel

**Deliverables**:
- Channel selector UI
- Switch channel functionality
- Recent channels list
- Channel state per channel

---

### 2.2 Message History

**Implementation Details**:

**History Request**:
```dart
void requestHistory(String channel) async {
  await websocketService.send(HistoryRequestMessage(
    channel: channel,
  ));
}
```

**History Response Parsing**:
```dart
class MessageHistoryItem {
  final String clientId;
  final String screenName;
  final String audioData;      // Base64 PCM16
  final int sampleRate;
  final int duration;           // milliseconds
  final int timestamp;          // Unix timestamp ms
}

void _onHistoryResponse(Map<String, dynamic> data) {
  final messages = (data['messages'] as List)
      .map((m) => MessageHistoryItem.fromJson(m))
      .toList();

  setState(() {
    messageHistory = messages;
  });
}
```

**History UI** (`lib/screens/history_screen.dart`):
- Collapsible panel (bottom sheet or drawer)
- Scrollable list of messages
- Each item shows:
  - Screen name
  - Timestamp (formatted: "2:45 PM" or "2h ago")
  - Duration (formatted: "3.5s")
  - Play button
- "Play All" button at top
- Auto-refresh after user's own transmission

**Message Item Widget**:
```dart
class MessageHistoryItem extends StatelessWidget {
  final MessageHistory message;
  final VoidCallback onPlay;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      leading: Icon(Icons.mic),
      title: Text(message.screenName),
      subtitle: Text('${_formatTimestamp(message.timestamp)} • ${_formatDuration(message.duration)}'),
      trailing: IconButton(
        icon: Icon(Icons.play_arrow),
        onPressed: onPlay,
      ),
    );
  }
}
```

**Playback Logic**:
```dart
Future<void> playMessage(MessageHistoryItem message) async {
  // 1. Decode Base64 to PCM16
  final audioBytes = base64Decode(message.audioData);

  // 2. Convert to playable format
  final audioBuffer = await audioService.createAudioBuffer(
    audioBytes,
    sampleRate: message.sampleRate,
  );

  // 3. Play audio
  await audioService.playAudioBuffer(audioBuffer);
}

Future<void> playAllMessages() async {
  for (final message in messageHistory) {
    await playMessage(message);
    // Small delay between messages
    await Future.delayed(Duration(milliseconds: 300));
  }
}
```

**Auto-Update Logic**:
- After user's transmission ends, request updated history
- Append new messages to list
- Scroll to bottom (optional)

**Deliverables**:
- Message history UI (collapsible panel)
- History request/response handling
- Individual message playback
- "Play All" sequential playback
- Timestamp and duration formatting
- Auto-refresh after transmission

---

### 2.3 Audio Enhancements

**Implementation Details**:

**Volume Control**:
```dart
class AudioSettings {
  double volume = 1.0; // 0.0 to 1.0
  bool courtesyBeepEnabled = true;
}

// Apply volume to playback
audioPlayer.setVolume(settings.volume);
```

**Volume Control UI**:
- Slider widget (0-100%)
- In settings screen or quick access in main screen
- Live preview (optional)
- Persist setting to SharedPreferences

**Courtesy Beep**:
```dart
Future<void> playCourtesyBeep() async {
  // Generate 800Hz sine wave tone
  final sampleRate = 48000;
  final duration = 0.2; // 200ms
  final frequency = 800;

  final samples = (sampleRate * duration).toInt();
  final audioData = Float32List(samples);

  for (int i = 0; i < samples; i++) {
    final t = i / sampleRate;
    audioData[i] = 0.3 * sin(2 * pi * frequency * t);
  }

  // Play the tone
  await audioService.playAudioBuffer(audioData);
}

void _stopTransmission() async {
  await audioService.stopRecording();

  // Play courtesy beep if enabled
  if (settings.courtesyBeepEnabled) {
    await playCourtesyBeep();
  }

  await websocketService.send(PushToTalkEndMessage(...));
}
```

**Beep Toggle**:
- Checkbox in settings
- "Courtesy Beep" label with description
- Persist to SharedPreferences

**Audio Buffering Improvements**:
- Implement jitter buffer to smooth playback
- Pre-buffer incoming audio chunks
- Handle packet loss gracefully

**Buffer Logic**:
```dart
class AudioBuffer {
  final Queue<AudioChunk> buffer = Queue();
  final int minBufferSize = 3; // chunks
  bool isPlaying = false;

  void addChunk(AudioChunk chunk) {
    buffer.add(chunk);
    if (buffer.length >= minBufferSize && !isPlaying) {
      _startPlayback();
    }
  }

  Future<void> _startPlayback() async {
    isPlaying = true;
    while (buffer.isNotEmpty) {
      final chunk = buffer.removeFirst();
      await audioService.playChunk(chunk);
    }
    isPlaying = false;
  }
}
```

**Deliverables**:
- Volume control slider
- Courtesy beep generation and playback
- Beep enable/disable toggle
- Improved audio buffering
- Settings persistence

---

### 2.4 Background Operation

**Implementation Details**:

**Background Permissions**:

**iOS** (`ios/Runner/Info.plist`):
```xml
<key>UIBackgroundModes</key>
<array>
  <string>audio</string>
  <string>fetch</string>
</array>
<key>NSMicrophoneUsageDescription</key>
<string>Required for push-to-talk audio transmission</string>
```

**Android** (`android/app/src/main/AndroidManifest.xml`):
```xml
<uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
<uses-permission android:name="android.permission.WAKE_LOCK" />
<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />

<service
    android:name=".WebSocketService"
    android:enabled="true"
    android:exported="false"
    android:foregroundServiceType="mediaPlayback" />
```

**Foreground Service (Android)**:
```dart
// Use flutter_foreground_task or similar package
await FlutterForegroundTask.init(
  androidNotificationOptions: AndroidNotificationOptions(
    channelId: 'walkie_talkie_service',
    channelName: 'Walkie Talkie Service',
    channelDescription: 'Keeps connection alive',
    channelImportance: NotificationChannelImportance.LOW,
  ),
);

await FlutterForegroundTask.startService(
  notificationTitle: 'Walkie Talkie',
  notificationText: 'Connected to Channel 1',
);
```

**Background Audio Session (iOS)**:
```dart
// Configure audio session for background playback
AVAudioSession.sharedInstance().setCategory(
  AVAudioSessionCategoryPlayback,
  mode: AVAudioSessionModeVoiceChat,
  options: [
    .mixWithOthers,
    .allowBluetooth,
    .defaultToSpeaker,
  ]
);
```

**WebSocket Keep-Alive**:
```dart
class BackgroundWebSocketService {
  Timer? _keepAliveTimer;

  void startKeepAlive() {
    _keepAliveTimer = Timer.periodic(Duration(seconds: 30), (_) {
      // Send ping message to keep connection alive
      websocketService.send({'type': 'ping'});
    });
  }

  void stopKeepAlive() {
    _keepAliveTimer?.cancel();
  }
}
```

**Notification System**:
```dart
class NotificationService {
  final FlutterLocalNotificationsPlugin _notifications =
      FlutterLocalNotificationsPlugin();

  Future<void> init() async {
    const androidSettings = AndroidInitializationSettings('@mipmap/ic_launcher');
    const iosSettings = DarwinInitializationSettings(
      requestAlertPermission: true,
      requestBadgePermission: true,
      requestSoundPermission: true,
    );

    await _notifications.initialize(
      InitializationSettings(android: androidSettings, iOS: iosSettings),
      onDidReceiveNotificationResponse: _onNotificationTap,
    );
  }

  Future<void> showIncomingAudioNotification(String screenName) async {
    await _notifications.show(
      0,
      'Walkie Talkie',
      '$screenName is speaking',
      NotificationDetails(
        android: AndroidNotificationDetails(
          'incoming_audio',
          'Incoming Audio',
          importance: Importance.high,
          priority: Priority.high,
        ),
        iOS: DarwinNotificationDetails(),
      ),
    );
  }

  void _onNotificationTap(NotificationResponse response) {
    // Bring app to foreground
    // Navigate to main screen
  }
}
```

**Background Audio Handling**:
- Continue playing incoming audio when backgrounded
- Show notification when someone speaks
- Rate-limit notifications (max 1 per 5 minutes)
- Update foreground service notification with channel info

**App Lifecycle Management**:
```dart
class AppLifecycleManager extends WidgetsBindingObserver {
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.paused:
        // App backgrounded
        _onAppBackgrounded();
        break;
      case AppLifecycleState.resumed:
        // App foregrounded
        _onAppForegrounded();
        break;
      default:
        break;
    }
  }

  void _onAppBackgrounded() {
    // Start foreground service (Android)
    // Enable notification mode
    // Continue WebSocket connection
  }

  void _onAppForegrounded() {
    // Stop foreground service (Android)
    // Disable notification mode
    // Refresh UI state
  }
}
```

**Dependencies**:
- `flutter_local_notifications` - Local notifications
- `flutter_foreground_task` (Android) - Foreground service
- `wakelock` - Prevent device sleep during transmission

**Deliverables**:
- Background permissions configured
- Foreground service (Android)
- Background audio session (iOS)
- WebSocket keep-alive in background
- Notification system for incoming audio
- App lifecycle management
- Notification tap handling

---

### 2.5 Settings & Preferences

**Implementation Details**:

**Settings Screen** (`lib/screens/settings_screen.dart`):
- Accessible from menu/icon in main screen
- Organized sections

**Settings Structure**:
```dart
class AppSettings {
  // Audio
  double volume = 1.0;
  bool courtesyBeepEnabled = true;
  int sampleRate = 48000;

  // Notifications
  bool notificationsEnabled = true;

  // Appearance
  ThemeMode themeMode = ThemeMode.system;

  // Connection
  String? customWebSocketUrl;

  // Persistence
  Future<void> save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setDouble('volume', volume);
    await prefs.setBool('courtesyBeep', courtesyBeepEnabled);
    await prefs.setInt('sampleRate', sampleRate);
    await prefs.setBool('notifications', notificationsEnabled);
    await prefs.setString('themeMode', themeMode.name);
  }

  Future<void> load() async {
    final prefs = await SharedPreferences.getInstance();
    volume = prefs.getDouble('volume') ?? 1.0;
    courtesyBeepEnabled = prefs.getBool('courtesyBeep') ?? true;
    sampleRate = prefs.getInt('sampleRate') ?? 48000;
    notificationsEnabled = prefs.getBool('notifications') ?? true;
    final themeName = prefs.getString('themeMode') ?? 'system';
    themeMode = ThemeMode.values.firstWhere((e) => e.name == themeName);
  }
}
```

**Settings UI Sections**:

**Audio Settings**:
- Volume slider (0-100%)
- Courtesy beep toggle
- Sample rate selector (44100, 48000)

**Notification Settings**:
- Enable/disable notifications
- Notification sound toggle

**Appearance**:
- Theme selector (Light/Dark/System)
- Language selector (future)

**Connection**:
- WebSocket URL (for testing)
- Connection status
- Debug info (version, client ID)

**About**:
- App version
- Server version (if available)
- License information
- Privacy policy link

**Theme Support**:
```dart
class ThemeProvider extends ChangeNotifier {
  ThemeMode _themeMode = ThemeMode.system;

  ThemeMode get themeMode => _themeMode;

  void setThemeMode(ThemeMode mode) {
    _themeMode = mode;
    notifyListeners();
    _saveTheme();
  }

  ThemeData get lightTheme => ThemeData(
    brightness: Brightness.light,
    primarySwatch: Colors.blue,
    // Custom theme properties
  );

  ThemeData get darkTheme => ThemeData(
    brightness: Brightness.dark,
    primarySwatch: Colors.blue,
    // Custom theme properties
  );
}

// In main.dart
MaterialApp(
  theme: themeProvider.lightTheme,
  darkTheme: themeProvider.darkTheme,
  themeMode: themeProvider.themeMode,
  // ...
);
```

**Deliverables**:
- Settings screen UI
- Settings persistence
- Theme support (light/dark/system)
- Audio settings integration
- Notification settings integration

---

### Phase 2 Deliverables Summary

**Full-Featured App with**:
- ✅ Multi-channel support (1-999)
- ✅ Message history with playback
- ✅ Volume control
- ✅ Courtesy beep
- ✅ Background operation (iOS/Android)
- ✅ Notifications for incoming audio
- ✅ Settings screen
- ✅ Theme support (light/dark)
- ✅ Recent channels list

**Testing Checklist**:
- [ ] Can switch between channels
- [ ] Message history loads and plays correctly
- [ ] "Play All" works sequentially
- [ ] Volume control affects playback
- [ ] Courtesy beep plays when enabled
- [ ] App stays connected in background
- [ ] Notifications appear for incoming audio
- [ ] Tapping notification opens app
- [ ] Settings persist across app restarts
- [ ] Theme switching works correctly
- [ ] Works on both iOS and Android

---

## Phase 3: Authentication & Security (2-3 weeks)

**Goal**: Add biometric authentication with WebAuthn

### 3.1 WebAuthn/Passkey Integration

**Implementation Details**:

**Dependencies**:
```yaml
dependencies:
  passkeys: ^1.0.0  # or alternative WebAuthn package
  http: ^1.1.0
  flutter_secure_storage: ^9.0.0
```

**WebAuthn Flow Overview**:

WebAuthn uses public-key cryptography with platform authenticators (Face ID, Touch ID, fingerprint). The mobile app will interact with the server's WebAuthn endpoints to register and authenticate.

**Registration Flow**:

1. **Get Registration Options**:
```dart
Future<Map<String, dynamic>> getRegistrationOptions(String username) async {
  final response = await http.post(
    Uri.parse('$serverUrl/auth/register-options.php'),
    headers: {'Content-Type': 'application/json'},
    body: jsonEncode({'username': username}),
  );

  if (response.statusCode != 200) {
    throw Exception('Failed to get registration options');
  }

  return jsonDecode(response.body);
}
```

2. **Create Credential with Platform Authenticator**:
```dart
Future<void> registerWithPasskey(String username, String nickname) async {
  // 1. Get registration options from server
  final options = await getRegistrationOptions(username);

  // 2. Create credential using platform authenticator
  final credential = await Passkey.register(
    challenge: base64Decode(options['options']['challenge']),
    relyingParty: RelyingParty(
      id: options['options']['rp']['id'],
      name: options['options']['rp']['name'],
    ),
    user: PasskeyUser(
      id: base64Decode(options['options']['user']['id']),
      name: options['options']['user']['name'],
      displayName: options['options']['user']['displayName'],
    ),
    timeout: options['options']['timeout'],
  );

  // 3. Verify credential with server
  final verifyResponse = await http.post(
    Uri.parse('$serverUrl/auth/register-verify.php'),
    headers: {'Content-Type': 'application/json'},
    body: jsonEncode({
      'credential': {
        'id': credential.id,
        'rawId': base64Encode(credential.rawId),
        'type': credential.type,
        'response': {
          'clientDataJSON': base64Encode(credential.response.clientDataJSON),
          'attestationObject': base64Encode(credential.response.attestationObject),
        },
      },
      'nickname': nickname,
    }),
  );

  if (verifyResponse.statusCode != 200) {
    throw Exception('Registration failed');
  }

  // 4. Extract and store tokens
  final result = jsonDecode(verifyResponse.body);
  await _storeTokens(
    result['tokens']['access_token'],
    result['tokens']['refresh_token'],
  );

  return result['user'];
}
```

**Login Flow**:

1. **Get Login Options**:
```dart
Future<Map<String, dynamic>> getLoginOptions(String username) async {
  final response = await http.post(
    Uri.parse('$serverUrl/auth/login-options.php'),
    headers: {'Content-Type': 'application/json'},
    body: jsonEncode({'username': username}),
  );

  if (response.statusCode != 200) {
    throw Exception('Failed to get login options');
  }

  return jsonDecode(response.body);
}
```

2. **Authenticate with Platform Authenticator**:
```dart
Future<void> loginWithPasskey(String username) async {
  // 1. Get login options from server
  final options = await getLoginOptions(username);

  // 2. Authenticate using platform authenticator
  final credential = await Passkey.authenticate(
    challenge: base64Decode(options['options']['challenge']),
    relyingPartyId: options['options']['rpId'],
    timeout: options['options']['timeout'],
    allowCredentials: (options['options']['allowCredentials'] as List)
        .map((c) => AllowedCredential(
          id: base64Decode(c['id']),
          type: c['type'],
        ))
        .toList(),
  );

  // 3. Verify authentication with server
  final verifyResponse = await http.post(
    Uri.parse('$serverUrl/auth/login-verify.php'),
    headers: {'Content-Type': 'application/json'},
    body: jsonEncode({
      'credential': {
        'id': credential.id,
        'rawId': base64Encode(credential.rawId),
        'type': credential.type,
        'response': {
          'clientDataJSON': base64Encode(credential.response.clientDataJSON),
          'authenticatorData': base64Encode(credential.response.authenticatorData),
          'signature': base64Encode(credential.response.signature),
          'userHandle': base64Encode(credential.response.userHandle),
        },
      },
    }),
  );

  if (verifyResponse.statusCode != 200) {
    throw Exception('Login failed');
  }

  // 4. Extract and store tokens
  final result = jsonDecode(verifyResponse.body);
  await _storeTokens(
    result['tokens']['access_token'],
    result['tokens']['refresh_token'],
  );

  return result['user'];
}
```

**Platform Authenticator Notes**:
- **iOS**: Uses Face ID or Touch ID
- **Android**: Uses fingerprint, face unlock, or device PIN
- **Fallback**: If biometric not available, use device PIN/pattern
- **User Verification**: "preferred" allows device to decide

**Error Handling**:
- User cancels biometric prompt: Show friendly message, allow retry
- No biometric available: Prompt to set up or use guest mode
- Registration/login fails: Show specific error message
- Network error: Retry logic with user notification

**Deliverables**:
- WebAuthn registration flow
- WebAuthn login flow
- Platform authenticator integration
- Error handling for auth failures
- Biometric prompt UI

---

### 3.2 JWT Token Management

**Implementation Details**:

**Secure Storage**:
```dart
class SecureTokenStorage {
  final FlutterSecureStorage _storage = FlutterSecureStorage();

  Future<void> storeAccessToken(String token) async {
    await _storage.write(key: 'access_token', value: token);
  }

  Future<void> storeRefreshToken(String token) async {
    await _storage.write(key: 'refresh_token', value: token);
  }

  Future<String?> getAccessToken() async {
    return await _storage.read(key: 'access_token');
  }

  Future<String?> getRefreshToken() async {
    return await _storage.read(key: 'refresh_token');
  }

  Future<void> clearTokens() async {
    await _storage.delete(key: 'access_token');
    await _storage.delete(key: 'refresh_token');
  }
}
```

**Token Expiration Handling**:
```dart
class TokenManager {
  Timer? _refreshTimer;
  final SecureTokenStorage _storage = SecureTokenStorage();

  Future<void> scheduleTokenRefresh(String accessToken) async {
    // Parse JWT to get expiration
    final parts = accessToken.split('.');
    final payload = jsonDecode(
      utf8.decode(base64Url.decode(base64Url.normalize(parts[1])))
    );

    final expiresAt = DateTime.fromMillisecondsSinceEpoch(
      payload['exp'] * 1000
    );

    // Schedule refresh 5 minutes before expiration
    final refreshAt = expiresAt.subtract(Duration(minutes: 5));
    final delay = refreshAt.difference(DateTime.now());

    _refreshTimer?.cancel();
    _refreshTimer = Timer(delay, () => refreshAccessToken());
  }

  Future<void> refreshAccessToken() async {
    try {
      final refreshToken = await _storage.getRefreshToken();
      if (refreshToken == null) {
        throw Exception('No refresh token available');
      }

      // Send refresh token as cookie or in body
      final response = await http.post(
        Uri.parse('$serverUrl/auth/refresh.php'),
        headers: {
          'Cookie': 'refresh_token=$refreshToken',
        },
      );

      if (response.statusCode != 200) {
        throw Exception('Token refresh failed');
      }

      final result = jsonDecode(response.body);
      final newAccessToken = result['tokens']['access_token'];

      // Store new access token
      await _storage.storeAccessToken(newAccessToken);

      // Schedule next refresh
      await scheduleTokenRefresh(newAccessToken);

      // Re-authenticate WebSocket connection
      await _reAuthenticateWebSocket(newAccessToken);

    } catch (e) {
      // Refresh failed - require re-login
      await logout();
      throw Exception('Session expired. Please log in again.');
    }
  }

  Future<void> _reAuthenticateWebSocket(String token) async {
    await websocketService.send({
      'type': 'authenticate',
      'token': token,
    });
  }
}
```

**WebSocket Authentication**:
```dart
Future<void> authenticateWebSocket() async {
  final accessToken = await tokenStorage.getAccessToken();

  if (accessToken != null) {
    // Authenticated mode
    await websocketService.send({
      'type': 'authenticate',
      'token': accessToken,
    });

    // Wait for authenticated response
    await _waitForAuthResponse();
  } else {
    // Guest mode - use screen name
    await websocketService.send({
      'type': 'set_screen_name',
      'screen_name': guestScreenName,
    });
  }
}

void _onAuthenticatedMessage(Map<String, dynamic> data) {
  setState(() {
    currentUser = User.fromJson(data['user']);
    isAuthenticated = true;
  });
}
```

**Session Management**:
```dart
class AuthService {
  User? _currentUser;
  bool _isAuthenticated = false;

  bool get isAuthenticated => _isAuthenticated;
  User? get currentUser => _currentUser;
  bool get isGuest => !_isAuthenticated;

  Future<void> checkSession() async {
    final accessToken = await tokenStorage.getAccessToken();
    if (accessToken == null) {
      _isAuthenticated = false;
      return;
    }

    try {
      // Validate token with server
      final response = await http.get(
        Uri.parse('$serverUrl/auth/user-info.php'),
        headers: {
          'Authorization': 'Bearer $accessToken',
        },
      );

      if (response.statusCode == 200) {
        final result = jsonDecode(response.body);
        _currentUser = User.fromJson(result['user']);
        _isAuthenticated = true;

        // Schedule token refresh
        await tokenManager.scheduleTokenRefresh(accessToken);
      } else {
        // Token invalid - try refresh
        await tokenManager.refreshAccessToken();
      }
    } catch (e) {
      // Session invalid
      _isAuthenticated = false;
      await logout();
    }
  }

  Future<void> logout() async {
    // Clear tokens
    await tokenStorage.clearTokens();

    // Call logout endpoint
    await http.post(Uri.parse('$serverUrl/auth/logout.php'));

    // Clear state
    _currentUser = null;
    _isAuthenticated = false;

    // Disconnect WebSocket
    await websocketService.disconnect();
  }
}
```

**Token Refresh Strategy**:
- Automatic refresh 5 minutes before expiration
- On-demand refresh if API returns 401
- Refresh on app foreground (if expired)
- Clear tokens and require re-login if refresh fails

**Deliverables**:
- Secure token storage (Keychain/KeyStore)
- Automatic token refresh logic
- WebSocket authentication with JWT
- Session validation
- Token expiration handling
- Logout functionality

---

### 3.3 Authentication UI/UX

**Implementation Details**:

**Auth Flow UI**:

**Welcome Screen** (`lib/screens/welcome_screen.dart`):
```dart
class WelcomeScreen extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            // App logo/icon
            Icon(Icons.radio, size: 100),
            SizedBox(height: 20),

            // App name
            Text('Walkie Talkie', style: TextStyle(fontSize: 32)),
            SizedBox(height: 40),

            // Login button
            ElevatedButton.icon(
              icon: Icon(Icons.fingerprint),
              label: Text('Login with Biometrics'),
              onPressed: () => _showLoginDialog(context),
            ),
            SizedBox(height: 10),

            // Register button
            OutlinedButton(
              child: Text('Create Account'),
              onPressed: () => _showRegisterDialog(context),
            ),
            SizedBox(height: 20),

            // Guest mode
            TextButton(
              child: Text('Continue as Guest'),
              onPressed: () => _showGuestDialog(context),
            ),
          ],
        ),
      ),
    );
  }
}
```

**Login Dialog**:
```dart
Future<void> _showLoginDialog(BuildContext context) async {
  final usernameController = TextEditingController();

  final username = await showDialog<String>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text('Login'),
      content: TextField(
        controller: usernameController,
        decoration: InputDecoration(
          labelText: 'Username',
          hintText: 'Enter your username',
        ),
        autofocus: true,
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: Text('Cancel'),
        ),
        ElevatedButton(
          onPressed: () => Navigator.pop(context, usernameController.text),
          child: Text('Continue'),
        ),
      ],
    ),
  );

  if (username != null && username.isNotEmpty) {
    try {
      // Show loading
      showDialog(
        context: context,
        barrierDismissible: false,
        builder: (context) => Center(child: CircularProgressIndicator()),
      );

      // Perform login (will trigger biometric prompt)
      await authService.loginWithPasskey(username);

      // Hide loading
      Navigator.pop(context);

      // Navigate to main screen
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (context) => HomeScreen()),
      );
    } catch (e) {
      Navigator.pop(context); // Hide loading

      // Show error
      showDialog(
        context: context,
        builder: (context) => AlertDialog(
          title: Text('Login Failed'),
          content: Text(e.toString()),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: Text('OK'),
            ),
          ],
        ),
      );
    }
  }
}
```

**Registration Dialog**:
```dart
Future<void> _showRegisterDialog(BuildContext context) async {
  final usernameController = TextEditingController();
  final nicknameController = TextEditingController();

  final result = await showDialog<Map<String, String>>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text('Create Account'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          TextField(
            controller: usernameController,
            decoration: InputDecoration(
              labelText: 'Username',
              hintText: 'Choose a username',
            ),
          ),
          SizedBox(height: 10),
          TextField(
            controller: nicknameController,
            decoration: InputDecoration(
              labelText: 'Device Nickname',
              hintText: 'e.g., "My iPhone"',
            ),
          ),
          SizedBox(height: 10),
          Text(
            'You\'ll use biometrics (Face ID/Touch ID) to sign in',
            style: TextStyle(fontSize: 12, color: Colors.grey),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: Text('Cancel'),
        ),
        ElevatedButton(
          onPressed: () => Navigator.pop(context, {
            'username': usernameController.text,
            'nickname': nicknameController.text,
          }),
          child: Text('Create'),
        ),
      ],
    ),
  );

  if (result != null) {
    try {
      // Show loading
      showDialog(
        context: context,
        barrierDismissible: false,
        builder: (context) => Center(child: CircularProgressIndicator()),
      );

      // Perform registration (will trigger biometric setup)
      await authService.registerWithPasskey(
        result['username']!,
        result['nickname']!,
      );

      // Hide loading
      Navigator.pop(context);

      // Navigate to main screen
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (context) => HomeScreen()),
      );
    } catch (e) {
      Navigator.pop(context); // Hide loading

      // Show error
      showDialog(
        context: context,
        builder: (context) => AlertDialog(
          title: Text('Registration Failed'),
          content: Text(e.toString()),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: Text('OK'),
            ),
          ],
        ),
      );
    }
  }
}
```

**Guest Mode Dialog**:
```dart
Future<void> _showGuestDialog(BuildContext context) async {
  final screenNameController = TextEditingController();

  final screenName = await showDialog<String>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text('Guest Mode'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          TextField(
            controller: screenNameController,
            decoration: InputDecoration(
              labelText: 'Screen Name',
              hintText: 'Enter a name (2-20 chars)',
            ),
            maxLength: 20,
          ),
          SizedBox(height: 10),
          ElevatedButton(
            onPressed: () {
              screenNameController.text = generateRandomScreenName();
            },
            child: Text('Generate Random Name'),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: Text('Cancel'),
        ),
        ElevatedButton(
          onPressed: () => Navigator.pop(context, screenNameController.text),
          child: Text('Continue'),
        ),
      ],
    ),
  );

  if (screenName != null && screenName.isNotEmpty) {
    // Validate screen name
    if (!RegExp(r'^[a-zA-Z0-9_-]{2,20}$').hasMatch(screenName)) {
      showDialog(
        context: context,
        builder: (context) => AlertDialog(
          title: Text('Invalid Screen Name'),
          content: Text('Use 2-20 alphanumeric characters, dashes, or underscores.'),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: Text('OK'),
            ),
          ],
        ),
      );
      return;
    }

    // Set guest mode
    await authService.setGuestMode(screenName);

    // Navigate to main screen
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(builder: (context) => HomeScreen()),
    );
  }
}
```

**User Menu Widget**:
```dart
class UserMenu extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final authService = context.watch<AuthService>();

    return PopupMenuButton(
      icon: Icon(Icons.account_circle),
      itemBuilder: (context) => [
        PopupMenuItem(
          child: ListTile(
            leading: Icon(
              authService.isGuest ? Icons.person_outline : Icons.verified_user,
            ),
            title: Text(
              authService.isGuest
                ? authService.guestScreenName ?? 'Guest'
                : authService.currentUser?.username ?? 'User'
            ),
            subtitle: Text(authService.isGuest ? 'Guest Mode' : 'Authenticated'),
          ),
          enabled: false,
        ),
        PopupMenuDivider(),
        if (authService.isGuest)
          PopupMenuItem(
            child: ListTile(
              leading: Icon(Icons.login),
              title: Text('Login / Register'),
            ),
            onTap: () {
              Navigator.push(
                context,
                MaterialPageRoute(builder: (context) => WelcomeScreen()),
              );
            },
          ),
        PopupMenuItem(
          child: ListTile(
            leading: Icon(Icons.settings),
            title: Text('Settings'),
          ),
          onTap: () {
            Navigator.push(
              context,
              MaterialPageRoute(builder: (context) => SettingsScreen()),
            );
          },
        ),
        if (!authService.isGuest)
          PopupMenuItem(
            child: ListTile(
              leading: Icon(Icons.logout),
              title: Text('Logout'),
            ),
            onTap: () async {
              await authService.logout();
              Navigator.pushReplacement(
                context,
                MaterialPageRoute(builder: (context) => WelcomeScreen()),
              );
            },
          ),
      ],
    );
  }
}
```

**Biometric Prompt Customization**:
- **iOS**: System handles UI automatically (Face ID/Touch ID animation)
- **Android**: Customize prompt with app branding

**Error Messages**:
- "Biometric authentication failed. Please try again."
- "No biometric authentication available. Please enable Face ID/Touch ID in settings."
- "Registration failed: Username already taken."
- "Login failed: No account found with this username."
- "Session expired. Please log in again."

**Deliverables**:
- Welcome screen with auth options
- Login dialog with username input
- Registration dialog with username and nickname
- Guest mode dialog with screen name input
- User menu widget
- Biometric prompt integration
- Error message handling
- Navigation flow (welcome → main screen)

---

### 3.4 API Integration

**Implementation Details**:

**HTTP Service** (`lib/services/http_service.dart`):
```dart
class HttpService {
  final String baseUrl;
  final SecureTokenStorage tokenStorage;

  HttpService(this.baseUrl, this.tokenStorage);

  Future<http.Response> get(String path, {bool requiresAuth = false}) async {
    final headers = <String, String>{
      'Content-Type': 'application/json',
    };

    if (requiresAuth) {
      final token = await tokenStorage.getAccessToken();
      if (token != null) {
        headers['Authorization'] = 'Bearer $token';
      }
    }

    return await http.get(
      Uri.parse('$baseUrl$path'),
      headers: headers,
    );
  }

  Future<http.Response> post(
    String path,
    Map<String, dynamic> body, {
    bool requiresAuth = false,
  }) async {
    final headers = <String, String>{
      'Content-Type': 'application/json',
    };

    if (requiresAuth) {
      final token = await tokenStorage.getAccessToken();
      if (token != null) {
        headers['Authorization'] = 'Bearer $token';
      }
    }

    return await http.post(
      Uri.parse('$baseUrl$path'),
      headers: headers,
      body: jsonEncode(body),
    );
  }
}
```

**User Info Validation**:
```dart
Future<User?> getUserInfo() async {
  try {
    final response = await httpService.get(
      '/auth/user-info.php',
      requiresAuth: true,
    );

    if (response.statusCode == 200) {
      final data = jsonDecode(response.body);
      if (data['success']) {
        return User.fromJson(data['user']);
      }
    } else if (response.statusCode == 401) {
      // Token expired - try refresh
      await tokenManager.refreshAccessToken();
      return await getUserInfo(); // Retry
    }
  } catch (e) {
    print('Error getting user info: $e');
  }

  return null;
}
```

**Token Refresh Endpoint**:
```dart
Future<String?> refreshToken() async {
  try {
    final refreshToken = await tokenStorage.getRefreshToken();
    if (refreshToken == null) return null;

    final response = await http.post(
      Uri.parse('$baseUrl/auth/refresh.php'),
      headers: {
        'Cookie': 'refresh_token=$refreshToken',
      },
    );

    if (response.statusCode == 200) {
      final data = jsonDecode(response.body);
      if (data['success']) {
        final newAccessToken = data['tokens']['access_token'];
        await tokenStorage.storeAccessToken(newAccessToken);
        return newAccessToken;
      }
    }
  } catch (e) {
    print('Error refreshing token: $e');
  }

  return null;
}
```

**Logout Endpoint**:
```dart
Future<void> logout() async {
  try {
    await httpService.post('/auth/logout.php', {}, requiresAuth: true);
  } catch (e) {
    print('Error logging out: $e');
  } finally {
    // Clear local tokens regardless
    await tokenStorage.clearTokens();
    await websocketService.disconnect();
  }
}
```

**Configuration Endpoint**:
```dart
Future<AppConfig> getConfig() async {
  try {
    final response = await httpService.get('/config.php');

    if (response.statusCode == 200) {
      final data = jsonDecode(response.body);
      return AppConfig(
        websocketUrl: data['websocket_url'],
        anonymousModeEnabled: data['anonymous_mode_enabled'],
        registrationEnabled: data['registration_enabled'],
        screenNameMinLength: data['screen_name_min_length'],
        screenNameMaxLength: data['screen_name_max_length'],
      );
    }
  } catch (e) {
    print('Error getting config: $e');
  }

  // Return defaults if config unavailable
  return AppConfig.defaults();
}
```

**Error Handling**:
```dart
class ApiException implements Exception {
  final String message;
  final int? statusCode;

  ApiException(this.message, [this.statusCode]);

  @override
  String toString() => message;
}

Future<T> handleApiCall<T>(Future<http.Response> Function() call) async {
  try {
    final response = await call();

    if (response.statusCode >= 200 && response.statusCode < 300) {
      return jsonDecode(response.body) as T;
    } else if (response.statusCode == 401) {
      throw ApiException('Unauthorized', 401);
    } else if (response.statusCode == 429) {
      throw ApiException('Too many requests. Please try again later.', 429);
    } else {
      final data = jsonDecode(response.body);
      throw ApiException(data['message'] ?? 'Unknown error', response.statusCode);
    }
  } on SocketException {
    throw ApiException('No internet connection');
  } on TimeoutException {
    throw ApiException('Request timed out');
  } catch (e) {
    throw ApiException('Network error: $e');
  }
}
```

**Deliverables**:
- HTTP service wrapper
- GET `/auth/user-info.php` integration
- POST `/auth/refresh.php` integration
- POST `/auth/logout.php` integration
- GET `/config.php` integration
- Error handling for API calls
- Automatic token refresh on 401

---

### Phase 3 Deliverables Summary

**Secure Authentication System with**:
- ✅ WebAuthn/Passkey registration
- ✅ WebAuthn/Passkey login
- ✅ Biometric authentication (Face ID/Touch ID/Fingerprint)
- ✅ JWT token management
- ✅ Secure token storage
- ✅ Automatic token refresh
- ✅ Guest mode fallback
- ✅ Auth UI/UX flows
- ✅ User menu with logout
- ✅ Session validation

**Testing Checklist**:
- [ ] Can register new account with biometrics
- [ ] Can login with biometrics
- [ ] Tokens stored securely
- [ ] Tokens refresh automatically before expiration
- [ ] WebSocket authenticates with JWT
- [ ] Can switch between authenticated and guest mode
- [ ] Logout clears tokens and disconnects
- [ ] Session persists across app restarts
- [ ] Handles biometric failures gracefully
- [ ] Works on both iOS and Android

---

## Phase 4: Polish & Production (2-3 weeks)

**Goal**: App store readiness

### 4.1 Error Handling & Resilience

**Implementation Details**:

**Error Types & Handling**:

**1. Network Errors**:
```dart
class ErrorHandler {
  static void handleNetworkError(dynamic error, BuildContext context) {
    String message;

    if (error is SocketException) {
      message = 'No internet connection. Please check your network.';
    } else if (error is TimeoutException) {
      message = 'Request timed out. Please try again.';
    } else if (error is WebSocketException) {
      message = 'Connection lost. Reconnecting...';
    } else {
      message = 'Network error: ${error.toString()}';
    }

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        action: SnackBarAction(
          label: 'Retry',
          onPressed: () => _retry(),
        ),
      ),
    );
  }
}
```

**2. Server Errors**:
```dart
void handleServerError(Map<String, dynamic> errorMessage) {
  final message = errorMessage['message'] ?? 'Unknown error';
  final code = errorMessage['code'];

  if (code == 'RATE_LIMIT_EXCEEDED') {
    showRateLimitDialog(message);
  } else if (code == 'CHANNEL_FULL') {
    showChannelFullDialog(message);
  } else {
    showErrorSnackbar(message);
  }
}
```

**3. Audio Errors**:
```dart
class AudioErrorHandler {
  static void handleAudioError(dynamic error, BuildContext context) {
    String message;

    if (error is PermissionDeniedException) {
      message = 'Microphone permission denied. Please enable in settings.';
      showPermissionDialog(context);
    } else if (error is AudioDeviceException) {
      message = 'Audio device error. Please check your microphone/speaker.';
    } else {
      message = 'Audio error: ${error.toString()}';
    }

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(message), duration: Duration(seconds: 5)),
    );
  }

  static void showPermissionDialog(BuildContext context) {
    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Microphone Permission Required'),
        content: Text(
          'Walkie Talkie needs microphone access to transmit audio. '
          'Please enable microphone permission in your device settings.'
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: Text('Cancel'),
          ),
          ElevatedButton(
            onPressed: () {
              openAppSettings();
              Navigator.pop(context);
            },
            child: Text('Open Settings'),
          ),
        ],
      ),
    );
  }
}
```

**4. Rate Limiting**:
```dart
class RateLimitHandler {
  DateTime? _lastTransmission;
  int _transmissionCount = 0;

  bool canTransmit() {
    final now = DateTime.now();

    // Reset counter if minute elapsed
    if (_lastTransmission == null ||
        now.difference(_lastTransmission!) > Duration(minutes: 1)) {
      _transmissionCount = 0;
    }

    // Check rate limit (10 per minute from server plugin)
    if (_transmissionCount >= 10) {
      return false;
    }

    _transmissionCount++;
    _lastTransmission = now;
    return true;
  }

  void showRateLimitError(BuildContext context) {
    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('Rate Limit Exceeded'),
        content: Text(
          'You\'re transmitting too frequently. '
          'Please wait a moment before trying again.'
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: Text('OK'),
          ),
        ],
      ),
    );
  }
}
```

**5. Edge Cases**:
```dart
// Microphone permission denied
if (await Permission.microphone.isDenied) {
  showPermissionDialog();
  return;
}

// Speaker/audio output unavailable
if (!await audioService.isAudioOutputAvailable()) {
  showErrorDialog('No audio output device available');
  return;
}

// WebSocket disconnected during transmission
if (!websocketService.isConnected) {
  stopRecording();
  showErrorSnackbar('Connection lost. Transmission cancelled.');
  return;
}

// Low battery warning
if (await Battery().batteryLevel < 10) {
  showWarningDialog('Low battery. Background mode may be restricted.');
}
```

**Retry Logic**:
```dart
class RetryManager {
  static Future<T> withRetry<T>({
    required Future<T> Function() action,
    int maxAttempts = 3,
    Duration delay = const Duration(seconds: 2),
  }) async {
    int attempt = 0;

    while (true) {
      try {
        return await action();
      } catch (e) {
        attempt++;
        if (attempt >= maxAttempts) {
          rethrow;
        }
        await Future.delayed(delay * attempt);
      }
    }
  }
}

// Usage
await RetryManager.withRetry(
  action: () => websocketService.connect(),
  maxAttempts: 3,
);
```

**Global Error Handler**:
```dart
void main() {
  FlutterError.onError = (details) {
    // Log to crash reporting service
    crashReportingService.logError(details.exception, details.stack);

    // Show user-friendly error in debug mode
    if (kDebugMode) {
      FlutterError.presentError(details);
    }
  };

  runZonedGuarded(() {
    runApp(MyApp());
  }, (error, stack) {
    // Catch async errors
    crashReportingService.logError(error, stack);
  });
}
```

**Deliverables**:
- Comprehensive error handling for all scenarios
- User-friendly error messages
- Permission denied handling with settings redirect
- Rate limit detection and user notification
- Edge case handling (mic, speaker, battery, etc.)
- Retry logic for transient failures
- Global error handler

---

### 4.2 Performance Optimization

**Implementation Details**:

**Audio Latency Optimization**:

**1. Reduce Recording Buffer**:
```dart
// Smaller buffer = lower latency but more CPU usage
const recordConfig = RecordConfig(
  encoder: AudioEncoder.pcm16bit,
  sampleRate: 48000,
  numChannels: 1,
  bufferSize: 2048,  // Reduced from 4096
);
```

**2. Optimize Base64 Encoding**:
```dart
// Use fast Base64 encoding
String encodeAudioFast(Uint8List bytes) {
  return base64Encode(bytes);  // Native implementation is optimized
}

// Batch encode if possible
List<String> encodeBatch(List<Uint8List> chunks) {
  return chunks.map((chunk) => base64Encode(chunk)).toList();
}
```

**3. Playback Buffering**:
```dart
class OptimizedAudioPlayer {
  final Queue<AudioChunk> _buffer = Queue();
  final int _minBufferSize = 2;  // Minimum chunks before playback
  final int _maxBufferSize = 10; // Maximum chunks to buffer

  void addChunk(AudioChunk chunk) {
    if (_buffer.length >= _maxBufferSize) {
      _buffer.removeFirst(); // Drop oldest to prevent overflow
    }

    _buffer.add(chunk);

    if (_buffer.length >= _minBufferSize && !_isPlaying) {
      _startPlayback();
    }
  }

  Future<void> _startPlayback() async {
    _isPlaying = true;

    while (_buffer.isNotEmpty) {
      final chunk = _buffer.removeFirst();
      await _playChunk(chunk);
    }

    _isPlaying = false;
  }
}
```

**WebSocket Message Optimization**:

**1. Reduce Message Size**:
```dart
// Use short keys to reduce JSON overhead
final message = {
  't': 'audio_data',        // type
  'c': channelId,           // channel
  'd': base64Data,          // data
  'f': 'pcm16',            // format
  's': 48000,              // sampleRate
  'ch': 1,                 // channels
  'id': clientId,          // clientId
  'e': true,               // excludeSender
};
```

**2. Batch Send (if protocol allows)**:
```dart
// Instead of sending each chunk immediately
// Batch multiple chunks into single message
List<String> _pendingChunks = [];

void sendAudioChunk(String base64Data) {
  _pendingChunks.add(base64Data);

  // Send every N chunks or after timeout
  if (_pendingChunks.length >= 3) {
    _flushChunks();
  }
}

void _flushChunks() {
  if (_pendingChunks.isEmpty) return;

  websocketService.send({
    'type': 'audio_batch',
    'channel': channelId,
    'chunks': _pendingChunks,
  });

  _pendingChunks.clear();
}
```

**Memory Optimization**:

**1. Dispose Resources**:
```dart
class AudioService {
  @override
  void dispose() {
    _recorder?.dispose();
    _player?.dispose();
    _audioChunkController.close();
    super.dispose();
  }
}
```

**2. Limit Message History**:
```dart
class MessageHistory {
  static const maxMessages = 50;
  final List<MessageHistoryItem> _messages = [];

  void addMessage(MessageHistoryItem message) {
    _messages.add(message);

    // Keep only recent messages
    if (_messages.length > maxMessages) {
      _messages.removeAt(0);
    }
  }
}
```

**Battery Optimization**:

**1. Reduce Wake Locks**:
```dart
class BatteryManager {
  bool _isTransmitting = false;

  void startTransmission() {
    if (!_isTransmitting) {
      Wakelock.enable();
      _isTransmitting = true;
    }
  }

  void stopTransmission() {
    if (_isTransmitting) {
      Wakelock.disable();
      _isTransmitting = false;
    }
  }
}
```

**2. Adjust Background Frequency**:
```dart
// Reduce WebSocket ping frequency in background
class BackgroundManager {
  Duration _pingInterval = Duration(seconds: 30);

  void onAppBackgrounded() {
    _pingInterval = Duration(minutes: 2);  // Less frequent in background
    _restartPingTimer();
  }

  void onAppForegrounded() {
    _pingInterval = Duration(seconds: 30);
    _restartPingTimer();
  }
}
```

**Profiling & Monitoring**:
```dart
class PerformanceMonitor {
  final Stopwatch _stopwatch = Stopwatch();

  Future<T> measureAsync<T>(String operation, Future<T> Function() action) async {
    _stopwatch.reset();
    _stopwatch.start();

    final result = await action();

    _stopwatch.stop();
    final elapsed = _stopwatch.elapsedMilliseconds;

    if (elapsed > 100) {
      print('⚠️ Slow operation: $operation took ${elapsed}ms');
    }

    return result;
  }
}

// Usage
await performanceMonitor.measureAsync(
  'Audio encoding',
  () => encodeAudio(audioData),
);
```

**Deliverables**:
- Optimized audio pipeline (reduced latency)
- Efficient Base64 encoding
- Playback buffering optimization
- WebSocket message optimization
- Memory management (dispose, limits)
- Battery optimization (wake locks, background)
- Performance profiling tools

---

### 4.3 UI/UX Polish

**Implementation Details**:

**Animations**:

**1. PTT Button Animation**:
```dart
class AnimatedPTTButton extends StatefulWidget {
  @override
  _AnimatedPTTButtonState createState() => _AnimatedPTTButtonState();
}

class _AnimatedPTTButtonState extends State<AnimatedPTTButton>
    with SingleTickerProviderStateMixin {
  late AnimationController _controller;
  late Animation<double> _scaleAnimation;
  late Animation<double> _pulseAnimation;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      duration: Duration(milliseconds: 1000),
      vsync: this,
    )..repeat(reverse: true);

    _scaleAnimation = Tween<double>(begin: 0.95, end: 1.05)
        .animate(CurvedAnimation(parent: _controller, curve: Curves.easeInOut));

    _pulseAnimation = Tween<double>(begin: 0.8, end: 1.0)
        .animate(CurvedAnimation(parent: _controller, curve: Curves.easeInOut));
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTapDown: (_) => _onPressDown(),
      onTapUp: (_) => _onPressUp(),
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, child) {
          return Transform.scale(
            scale: isRecording ? _scaleAnimation.value : 1.0,
            child: Container(
              width: 200,
              height: 200,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: isRecording ? Colors.red : Colors.grey,
                boxShadow: [
                  if (isRecording)
                    BoxShadow(
                      color: Colors.red.withOpacity(_pulseAnimation.value),
                      blurRadius: 30,
                      spreadRadius: 10,
                    ),
                ],
              ),
              child: Icon(
                Icons.mic,
                size: 80,
                color: Colors.white,
              ),
            ),
          );
        },
      ),
    );
  }
}
```

**2. Speaking Indicator Animation**:
```dart
class SpeakingIndicator extends StatelessWidget {
  final String screenName;

  @override
  Widget build(BuildContext context) {
    return AnimatedContainer(
      duration: Duration(milliseconds: 300),
      curve: Curves.easeInOut,
      padding: EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.yellow.withOpacity(0.2),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          _WaveformAnimation(),
          SizedBox(width: 8),
          Text('$screenName is speaking...',
              style: TextStyle(fontWeight: FontWeight.bold)),
        ],
      ),
    );
  }
}

class _WaveformAnimation extends StatefulWidget {
  @override
  _WaveformAnimationState createState() => _WaveformAnimationState();
}

class _WaveformAnimationState extends State<_WaveformAnimation>
    with SingleTickerProviderStateMixin {
  late AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      duration: Duration(milliseconds: 800),
      vsync: this,
    )..repeat();
  }

  @override
  Widget build(BuildContext context) {
    return Row(
      children: List.generate(3, (index) {
        return AnimatedBuilder(
          animation: _controller,
          builder: (context, child) {
            final height = 10 +
                15 * sin((_controller.value * 2 * pi) + (index * pi / 3));
            return Container(
              width: 3,
              height: height,
              margin: EdgeInsets.symmetric(horizontal: 1),
              decoration: BoxDecoration(
                color: Colors.yellow,
                borderRadius: BorderRadius.circular(2),
              ),
            );
          },
        );
      }),
    );
  }
}
```

**3. Screen Transitions**:
```dart
// Smooth page transitions
Navigator.push(
  context,
  PageRouteBuilder(
    pageBuilder: (context, animation, secondaryAnimation) => SettingsScreen(),
    transitionsBuilder: (context, animation, secondaryAnimation, child) {
      return SlideTransition(
        position: Tween<Offset>(
          begin: Offset(1.0, 0.0),
          end: Offset.zero,
        ).animate(CurvedAnimation(
          parent: animation,
          curve: Curves.easeInOut,
        )),
        child: child,
      );
    },
  ),
);
```

**Haptic Feedback**:
```dart
class HapticManager {
  static void onPTTPress() {
    HapticFeedback.mediumImpact();
  }

  static void onPTTRelease() {
    HapticFeedback.lightImpact();
  }

  static void onError() {
    HapticFeedback.heavyImpact();
  }

  static void onSuccess() {
    HapticFeedback.selectionClick();
  }

  static void onChannelSwitch() {
    HapticFeedback.selectionClick();
  }
}
```

**Onboarding Flow**:
```dart
class OnboardingScreen extends StatefulWidget {
  @override
  _OnboardingScreenState createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  final PageController _pageController = PageController();
  int _currentPage = 0;

  final List<OnboardingPage> _pages = [
    OnboardingPage(
      title: 'Welcome to Walkie Talkie',
      description: 'Push-to-talk voice communication',
      icon: Icons.radio,
    ),
    OnboardingPage(
      title: 'Hold to Talk',
      description: 'Press and hold the button to transmit',
      icon: Icons.mic,
    ),
    OnboardingPage(
      title: 'Join Channels',
      description: 'Connect with others on different channels',
      icon: Icons.groups,
    ),
    OnboardingPage(
      title: 'Stay Connected',
      description: 'Receive messages even when the app is in background',
      icon: Icons.notifications,
    ),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Column(
        children: [
          Expanded(
            child: PageView.builder(
              controller: _pageController,
              onPageChanged: (page) => setState(() => _currentPage = page),
              itemCount: _pages.length,
              itemBuilder: (context, index) {
                return _buildPage(_pages[index]);
              },
            ),
          ),
          _buildPageIndicator(),
          _buildNavigationButtons(),
        ],
      ),
    );
  }

  Widget _buildPage(OnboardingPage page) {
    return Padding(
      padding: EdgeInsets.all(40),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(page.icon, size: 100, color: Colors.blue),
          SizedBox(height: 40),
          Text(page.title, style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold)),
          SizedBox(height: 20),
          Text(page.description, textAlign: TextAlign.center, style: TextStyle(fontSize: 16)),
        ],
      ),
    );
  }

  Widget _buildNavigationButtons() {
    return Padding(
      padding: EdgeInsets.all(20),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          if (_currentPage > 0)
            TextButton(
              onPressed: () => _pageController.previousPage(
                duration: Duration(milliseconds: 300),
                curve: Curves.easeInOut,
              ),
              child: Text('Back'),
            )
          else
            SizedBox(width: 80),

          if (_currentPage < _pages.length - 1)
            ElevatedButton(
              onPressed: () => _pageController.nextPage(
                duration: Duration(milliseconds: 300),
                curve: Curves.easeInOut,
              ),
              child: Text('Next'),
            )
          else
            ElevatedButton(
              onPressed: _completeOnboarding,
              child: Text('Get Started'),
            ),
        ],
      ),
    );
  }

  void _completeOnboarding() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool('onboarding_completed', true);

    Navigator.pushReplacement(
      context,
      MaterialPageRoute(builder: (context) => WelcomeScreen()),
    );
  }
}
```

**Help/Tutorial**:
```dart
class HelpScreen extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Help')),
      body: ListView(
        padding: EdgeInsets.all(16),
        children: [
          _buildHelpSection(
            title: 'How to Use Push-to-Talk',
            content: '1. Press and hold the microphone button\n'
                    '2. Speak your message\n'
                    '3. Release the button to send',
            icon: Icons.mic,
          ),
          _buildHelpSection(
            title: 'Switching Channels',
            content: 'Tap the channel number at the top to join a different channel (1-999)',
            icon: Icons.swap_horiz,
          ),
          _buildHelpSection(
            title: 'Message History',
            content: 'Swipe up from the bottom to view and replay recent messages',
            icon: Icons.history,
          ),
          _buildHelpSection(
            title: 'Permissions',
            content: 'Walkie Talkie requires microphone access to transmit audio. '
                    'You can manage permissions in your device settings.',
            icon: Icons.security,
          ),
        ],
      ),
    );
  }

  Widget _buildHelpSection({
    required String title,
    required String content,
    required IconData icon,
  }) {
    return Card(
      margin: EdgeInsets.only(bottom: 16),
      child: ListTile(
        leading: Icon(icon, size: 40),
        title: Text(title, style: TextStyle(fontWeight: FontWeight.bold)),
        subtitle: Text(content),
      ),
    );
  }
}
```

**Deliverables**:
- Animated PTT button with pulsing effect
- Speaking indicator with waveform animation
- Smooth screen transitions
- Haptic feedback for all interactions
- Onboarding flow for new users
- Help/tutorial screens
- Polished UI with consistent styling

---

### 4.4 Testing & QA

**Implementation Details**:

**Unit Tests**:
```dart
// test/services/websocket_service_test.dart
void main() {
  group('WebSocketService', () {
    late WebSocketService service;
    late MockWebSocketChannel mockChannel;

    setUp(() {
      mockChannel = MockWebSocketChannel();
      service = WebSocketService(mockChannel);
    });

    test('connects successfully', () async {
      await service.connect();
      expect(service.isConnected, true);
    });

    test('sends message correctly', () async {
      final message = {'type': 'test', 'data': 'hello'};
      await service.send(message);

      verify(mockChannel.sink.add(jsonEncode(message))).called(1);
    });

    test('reconnects after disconnect', () async {
      await service.connect();
      service.disconnect();

      await Future.delayed(Duration(seconds: 4));
      expect(service.isConnected, true);
    });
  });
}

// test/services/audio_service_test.dart
void main() {
  group('AudioService', () {
    test('converts Float32 to Int16 correctly', () {
      final input = Float32List.fromList([0.0, 0.5, -0.5, 1.0, -1.0]);
      final output = float32ToInt16(input);

      expect(output[0], 0);
      expect(output[1], 16383);
      expect(output[2], -16384);
      expect(output[3], 32767);
      expect(output[4], -32767);
    });

    test('encodes audio to Base64', () {
      final audioData = Uint8List.fromList([1, 2, 3, 4]);
      final encoded = base64Encode(audioData);

      expect(encoded, isNotEmpty);
      expect(base64Decode(encoded), audioData);
    });
  });
}
```

**Integration Tests**:
```dart
// integration_test/app_test.dart
void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  group('Full App Flow', () {
    testWidgets('Guest mode flow', (tester) async {
      await tester.pumpWidget(MyApp());

      // 1. Enter guest mode
      await tester.tap(find.text('Continue as Guest'));
      await tester.pumpAndSettle();

      // 2. Enter screen name
      await tester.enterText(find.byType(TextField), 'TestUser');
      await tester.tap(find.text('Continue'));
      await tester.pumpAndSettle();

      // 3. Verify main screen appears
      expect(find.byType(PTTButton), findsOneWidget);
      expect(find.text('Channel: 1'), findsOneWidget);
    });

    testWidgets('Push-to-talk flow', (tester) async {
      await tester.pumpWidget(MyApp());

      // Setup: Login as guest
      await _loginAsGuest(tester);

      // 1. Press PTT button
      await tester.press(find.byType(PTTButton));
      await tester.pumpAndSettle();

      // 2. Verify recording started
      expect(find.text('Recording...'), findsOneWidget);

      // 3. Release PTT button
      await tester.release(find.byType(PTTButton));
      await tester.pumpAndSettle();

      // 4. Verify recording stopped
      expect(find.text('Recording...'), findsNothing);
    });
  });
}
```

**Cross-Device Testing Matrix**:

**iOS Devices**:
- iPhone SE (iOS 13) - Minimum supported version
- iPhone 12 (iOS 15)
- iPhone 14 Pro (iOS 16)
- iPhone 15 (iOS 17)
- iPad Air (iOS 16)

**Android Devices**:
- Samsung Galaxy S10 (Android 10 / API 29)
- Google Pixel 5 (Android 11 / API 30)
- Samsung Galaxy S21 (Android 12 / API 31)
- Google Pixel 7 (Android 13 / API 33)
- OnePlus 11 (Android 14 / API 34)

**Test Scenarios**:

**1. Audio Quality Tests**:
- [ ] Audio transmission is clear and understandable
- [ ] No distortion or clipping
- [ ] Volume is appropriate
- [ ] Background noise suppression works
- [ ] Echo cancellation works

**2. Network Tests**:
- [ ] Connects over WiFi
- [ ] Connects over 4G/5G
- [ ] Handles network switch (WiFi ↔ cellular)
- [ ] Reconnects after airplane mode toggle
- [ ] Handles poor network conditions (high latency, packet loss)

**3. Background Mode Tests**:
- [ ] Stays connected when backgrounded
- [ ] Receives audio when backgrounded
- [ ] Shows notifications for incoming audio
- [ ] Tapping notification opens app
- [ ] Foreground service (Android) keeps connection alive
- [ ] Battery usage is reasonable

**4. Permission Tests**:
- [ ] Handles microphone permission grant
- [ ] Handles microphone permission denial
- [ ] Redirects to settings when needed
- [ ] Handles notification permission (Android 13+)

**5. Authentication Tests**:
- [ ] Can register with biometrics
- [ ] Can login with biometrics
- [ ] Tokens persist across app restarts
- [ ] Tokens refresh automatically
- [ ] Logout clears tokens
- [ ] Can switch between auth and guest mode

**6. Channel Tests**:
- [ ] Can join different channels
- [ ] Participant count updates correctly
- [ ] Audio only heard in current channel
- [ ] Channel state persists during session

**7. Message History Tests**:
- [ ] History loads on channel join
- [ ] Can play individual messages
- [ ] "Play All" works sequentially
- [ ] History updates after transmission
- [ ] Timestamps are correct

**8. Edge Cases**:
- [ ] Multiple rapid PTT presses
- [ ] Very long transmission (>30 seconds)
- [ ] App killed while transmitting
- [ ] Server disconnects during transmission
- [ ] Low battery mode
- [ ] Do Not Disturb mode

**Automated CI/CD Testing**:
```yaml
# .github/workflows/test.yml
name: Test

on: [push, pull_request]

jobs:
  test:
    runs-on: macos-latest

    steps:
      - uses: actions/checkout@v3

      - uses: subosito/flutter-action@v2
        with:
          flutter-version: '3.16.0'

      - name: Install dependencies
        run: flutter pub get

      - name: Run unit tests
        run: flutter test

      - name: Run integration tests (iOS)
        run: flutter test integration_test --device-id=<ios-simulator-id>

      - name: Run integration tests (Android)
        run: flutter test integration_test --device-id=<android-emulator-id>
```

**Deliverables**:
- Comprehensive unit tests
- Integration tests for key flows
- Cross-device testing completed
- All test scenarios passed
- CI/CD pipeline configured
- Test coverage report

---

### 4.5 App Store Preparation

**Implementation Details**:

**App Icons & Assets**:

**iOS** (`ios/Runner/Assets.xcassets/AppIcon.appiconset/`):
- 20x20 @2x, @3x (iPhone Notification)
- 29x29 @2x, @3x (iPhone Settings)
- 40x40 @2x, @3x (iPhone Spotlight)
- 60x60 @2x, @3x (iPhone App)
- 1024x1024 (App Store)

**Android** (`android/app/src/main/res/`):
- mipmap-mdpi: 48x48
- mipmap-hdpi: 72x72
- mipmap-xhdpi: 96x96
- mipmap-xxhdpi: 144x144
- mipmap-xxxhdpi: 192x192

**Splash Screen**:
```yaml
# pubspec.yaml
flutter_native_splash:
  color: "#ffffff"
  image: assets/splash_logo.png
  android: true
  ios: true
```

**Privacy Policy**:

**Required Disclosures**:
- Microphone access: "We use your microphone to transmit voice messages in real-time"
- Network usage: "We connect to our servers to facilitate voice communication"
- Data storage: "Message history is stored locally and on our servers for 5 minutes"
- Authentication: "Biometric data is processed locally on your device and never sent to our servers"
- Optional analytics: "We collect anonymous usage data to improve the app" (if implemented)

**Privacy Policy Hosting**:
- Host at `https://yourdomain.com/privacy-policy.html`
- Link in app settings
- Link in app store listings

**Terms of Service**:
- Host at `https://yourdomain.com/terms-of-service.html`
- Acceptable use policy
- Content restrictions (no illegal content, harassment, etc.)
- Liability limitations

**App Store Listings**:

**iOS App Store** (App Store Connect):

**App Information**:
- Name: Walkie Talkie
- Subtitle: Push-to-Talk Voice Chat
- Category: Social Networking
- Age Rating: 4+ (or appropriate based on content policy)

**Description**:
```
Walkie Talkie - Real-time Push-to-Talk Voice Communication

Stay connected with friends, family, or team members using instant push-to-talk voice messages.

Features:
• Push-to-talk instant voice transmission
• Multiple channels (1-999)
• Message history and playback
• Background mode - stay connected even when the app is closed
• Secure authentication with Face ID/Touch ID
• Guest mode for quick access
• Adjustable volume and courtesy beep
• Low latency audio streaming

Perfect for:
• Team coordination
• Event communication
• Family stay-in-touch
• Gaming groups
• Emergency response teams

Privacy & Security:
• Optional biometric authentication
• End-to-end audio transmission
• Minimal data collection
• No ads or tracking

Download now and start talking!
```

**Keywords**: walkie talkie, push to talk, PTT, voice chat, team communication, voice messaging

**Screenshots**:
- 6.5" Display (iPhone 14 Pro Max): 5-10 screenshots
  1. Main PTT screen
  2. Speaking indicator
  3. Channel selector
  4. Message history
  5. Settings screen
  6. Login/biometric screen
- 5.5" Display (iPhone 8 Plus): Same screenshots scaled

**Google Play Store**:

**App Information**:
- App name: Walkie Talkie
- Short description: Push-to-talk voice communication
- Category: Communication
- Content rating: Everyone (or appropriate)

**Description**: (Same as iOS with formatting for Google Play)

**Screenshots**:
- Phone: 5-8 screenshots (similar to iOS)
- 7" Tablet: Optional
- 10" Tablet: Optional

**Feature Graphic**: 1024x500 banner image

**Promo Video**: Optional 30-60 second demo

**App Signing**:

**iOS**:
```bash
# Generate certificates in Xcode
# 1. Open Xcode → Preferences → Accounts
# 2. Add Apple ID
# 3. Manage Certificates → Create iOS Distribution certificate
# 4. In Runner target → Signing & Capabilities
# 5. Select team and provisioning profile
```

**Android**:
```bash
# Generate keystore
keytool -genkey -v -keystore walkie-talkie-release.jks \
  -keyalg RSA -keysize 2048 -validity 10000 \
  -alias walkie-talkie

# Configure in android/key.properties
storePassword=<password>
keyPassword=<password>
keyAlias=walkie-talkie
storeFile=walkie-talkie-release.jks

# Update android/app/build.gradle
signingConfigs {
    release {
        keyAlias keystoreProperties['keyAlias']
        keyPassword keystoreProperties['keyPassword']
        storeFile file(keystoreProperties['storeFile'])
        storePassword keystoreProperties['storePassword']
    }
}
```

**Build Release**:
```bash
# iOS
flutter build ios --release

# Android
flutter build appbundle --release
```

**Crash Reporting** (Optional):
```yaml
# pubspec.yaml
dependencies:
  sentry_flutter: ^7.0.0

# lib/main.dart
await SentryFlutter.init(
  (options) {
    options.dsn = 'YOUR_SENTRY_DSN';
    options.tracesSampleRate = 0.1;
  },
  appRunner: () => runApp(MyApp()),
);
```

**Analytics** (Optional):
```yaml
# pubspec.yaml
dependencies:
  firebase_analytics: ^10.0.0

# Track key events
analytics.logEvent(name: 'ptt_transmission', parameters: {
  'duration': duration,
  'channel': channelId,
});
```

**App Review Preparation**:

**iOS App Review Notes**:
- Test account: Provide guest mode or test credentials
- Special instructions: "Tap 'Continue as Guest' to test without authentication"
- Demo video: Optional video showing key features

**Google Play Review Notes**:
- Similar information as iOS
- Privacy declarations filled out correctly

**Release Checklist**:
- [ ] App icons created for all sizes
- [ ] Splash screen configured
- [ ] Privacy policy published and linked
- [ ] Terms of service published
- [ ] App store listings written
- [ ] Screenshots captured
- [ ] Signing configured
- [ ] Release builds tested
- [ ] Crash reporting configured (optional)
- [ ] Analytics configured (optional)
- [ ] App review information prepared
- [ ] Beta testing completed (TestFlight/Internal Testing)

**Deliverables**:
- Complete app store assets (icons, screenshots, graphics)
- Privacy policy and terms of service
- App store listings written
- Release builds generated (iOS .ipa, Android .aab)
- Signing configured
- Optional: Crash reporting and analytics
- Submitted to App Store and Google Play

---

### Phase 4 Deliverables Summary

**Production-Ready App with**:
- ✅ Comprehensive error handling
- ✅ Performance optimization
- ✅ Polished UI/UX with animations
- ✅ Haptic feedback
- ✅ Onboarding flow
- ✅ Help screens
- ✅ Extensive testing (unit, integration, cross-device)
- ✅ App store assets
- ✅ Privacy policy and terms
- ✅ Release builds
- ✅ Optional crash reporting and analytics

**Final Testing Checklist**:
- [ ] All Phase 1-3 features work correctly
- [ ] Error handling covers all scenarios
- [ ] Performance meets targets (latency, battery)
- [ ] UI is polished and intuitive
- [ ] Onboarding guides new users
- [ ] Unit tests pass
- [ ] Integration tests pass
- [ ] Cross-device testing complete
- [ ] Beta testers provide positive feedback
- [ ] App store review guidelines met
- [ ] Privacy policy and terms accessible
- [ ] Release builds install and run correctly

---

## Project Structure (Final)

```
walkie_talkie_mobile/
├── android/                        # Android native code
│   ├── app/
│   │   ├── src/main/
│   │   │   ├── AndroidManifest.xml
│   │   │   └── res/                # Icons, splash, etc.
│   │   └── build.gradle
│   └── key.properties              # Signing config
├── ios/                            # iOS native code
│   ├── Runner/
│   │   ├── Info.plist
│   │   ├── Assets.xcassets/        # Icons, splash, etc.
│   │   └── AppDelegate.swift
│   └── Runner.xcodeproj
├── lib/
│   ├── main.dart                   # App entry point
│   ├── models/
│   │   ├── user.dart
│   │   ├── channel.dart
│   │   ├── message.dart
│   │   └── app_config.dart
│   ├── providers/
│   │   ├── auth_provider.dart
│   │   ├── connection_provider.dart
│   │   ├── audio_provider.dart
│   │   └── settings_provider.dart
│   ├── screens/
│   │   ├── welcome_screen.dart
│   │   ├── home_screen.dart
│   │   ├── settings_screen.dart
│   │   ├── history_screen.dart
│   │   ├── help_screen.dart
│   │   └── onboarding_screen.dart
│   ├── services/
│   │   ├── websocket_service.dart
│   │   ├── audio_service.dart
│   │   ├── auth_service.dart
│   │   ├── http_service.dart
│   │   ├── storage_service.dart
│   │   ├── notification_service.dart
│   │   └── background_service.dart
│   ├── utils/
│   │   ├── audio_utils.dart
│   │   ├── error_handler.dart
│   │   ├── performance_monitor.dart
│   │   └── constants.dart
│   └── widgets/
│       ├── ptt_button.dart
│       ├── speaking_indicator.dart
│       ├── channel_selector.dart
│       ├── message_history_item.dart
│       └── user_menu.dart
├── test/                           # Unit tests
│   ├── services/
│   ├── models/
│   └── utils/
├── integration_test/               # Integration tests
│   └── app_test.dart
├── assets/                         # Static assets
│   ├── images/
│   ├── sounds/
│   └── fonts/
├── pubspec.yaml                    # Dependencies
└── README.md
```

---

## Dependencies Summary

```yaml
# pubspec.yaml
name: walkie_talkie_mobile
description: Push-to-talk voice communication app
version: 1.0.0+1

environment:
  sdk: ">=3.0.0 <4.0.0"

dependencies:
  flutter:
    sdk: flutter

  # WebSocket & HTTP
  web_socket_channel: ^2.4.0
  http: ^1.1.0

  # Audio
  record: ^5.0.0
  audioplayers: ^5.2.0

  # Authentication
  passkeys: ^1.0.0                  # or webauthn package
  flutter_secure_storage: ^9.0.0

  # State Management
  provider: ^6.1.0                  # or riverpod

  # Storage
  shared_preferences: ^2.2.0

  # Permissions
  permission_handler: ^11.0.0

  # Background & Notifications
  flutter_local_notifications: ^16.0.0
  flutter_foreground_task: ^6.0.0   # Android only
  wakelock: ^0.6.2

  # UI/UX
  flutter_native_splash: ^2.3.0

  # Optional: Analytics & Crash Reporting
  # firebase_analytics: ^10.0.0
  # sentry_flutter: ^7.0.0

dev_dependencies:
  flutter_test:
    sdk: flutter
  integration_test:
    sdk: flutter
  flutter_lints: ^3.0.0
  mockito: ^5.4.0

flutter:
  uses-material-design: true
  assets:
    - assets/images/
    - assets/sounds/
```

---

## Timeline Summary

**Total Duration**: 9-13 weeks

- **Phase 1** (MVP Foundation): 2-3 weeks
- **Phase 2** (Full Features): 3-4 weeks
- **Phase 3** (Authentication): 2-3 weeks
- **Phase 4** (Polish & Production): 2-3 weeks

**Milestones**:
1. Week 3: MVP demo ready (PTT, guest mode, single channel)
2. Week 7: Feature-complete internal release (all features working)
3. Week 10: Beta release to TestFlight/Internal Testing
4. Week 12: Production release to App Store & Google Play

---

## Risk Assessment & Mitigation

**High Priority Risks**:

1. **Audio Latency Too High**
   - **Risk**: End-to-end latency > 1 second
   - **Mitigation**: Test early, optimize buffer sizes, use native audio APIs

2. **Background Mode Restrictions**
   - **Risk**: iOS/Android kill app in background
   - **Mitigation**: Foreground service (Android), background audio session (iOS), extensive testing

3. **WebAuthn Implementation Complexity**
   - **Risk**: Biometric auth doesn't work on some devices
   - **Mitigation**: Start with guest mode, thorough testing, fallback to PIN

4. **App Store Rejection**
   - **Risk**: Privacy policy, permissions, content issues
   - **Mitigation**: Follow guidelines, clear privacy policy, test submission process

**Medium Priority Risks**:

5. **Battery Drain in Background**
   - **Mitigation**: Optimize WebSocket ping frequency, test battery usage

6. **Network Reliability**
   - **Mitigation**: Robust reconnection logic, offline indicators

7. **Device Compatibility Issues**
   - **Mitigation**: Test on wide range of devices, handle edge cases

---

## Success Metrics

**Technical Metrics**:
- Audio latency: < 500ms end-to-end
- Connection success rate: > 95%
- App crash rate: < 1%
- Battery usage: < 5% per hour in background

**User Experience Metrics**:
- Onboarding completion: > 80%
- Daily active users retention: > 40%
- Average session duration: > 10 minutes
- App store rating: > 4.0 stars

**Business Metrics**:
- App store approval: First submission
- Beta tester satisfaction: > 80% positive feedback
- Feature parity with web: 100%

---

## Next Steps

1. **Confirm Plan**: Review and approve this implementation plan
2. **Set Up Project**: Create Flutter project and repository
3. **Start Phase 1**: Begin with MVP foundation
4. **Regular Check-ins**: Weekly progress reviews and adjustments
5. **Iterative Development**: Release MVP internally, gather feedback, iterate
6. **Beta Testing**: TestFlight (iOS) and Internal Testing (Google Play)
7. **Production Release**: Submit to app stores

---

## Support & Maintenance

**Post-Launch**:
- Monitor crash reports and analytics
- Respond to user feedback and reviews
- Fix critical bugs within 48 hours
- Release updates every 2-4 weeks (bug fixes, improvements)
- Major feature releases quarterly

**Server-Side Compatibility**:
- App must remain compatible with current WebSocket protocol
- Test thoroughly when server updates are deployed
- Version negotiation if protocol changes in future

---

## Conclusion

This comprehensive plan provides a clear roadmap for developing a production-ready cross-platform mobile app for the Walkie-Talkie system using Flutter. The phased approach allows for iterative development, early validation, and incremental feature releases.

**Key Strengths**:
- Clear milestones and deliverables
- Comprehensive feature coverage
- Robust error handling and testing
- Production-ready polish
- Compatible with existing server infrastructure

**Recommended Approach**:
1. Start with Phase 1 MVP to validate core functionality
2. Gather feedback from internal users
3. Iterate and add Phase 2 features
4. Add authentication in Phase 3
5. Polish and release in Phase 4

This plan positions the mobile app for success in both app stores while providing excellent user experience and seamless integration with the existing walkie-talkie server.