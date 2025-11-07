# Mobile App WebAuthn Integration Plan

## Executive Summary

This document outlines how to integrate WebAuthn/Passkeys authentication into the standalone Flutter mobile application for the Walkie-Talkie system. The server already has a complete WebAuthn implementation - this plan focuses on adapting it for mobile app authentication.

**Current State:**
- ✅ Server: Full WebAuthn backend implemented (PHP)
- ✅ HTML5 Client: WebAuthn authentication working
- ✅ Mobile App: Anonymous WebSocket connection working
- ❌ Mobile App: WebAuthn authentication not yet implemented

**Goal:** Enable mobile app users to register and authenticate using biometric authentication (fingerprint, Face ID) via WebAuthn/Passkeys.

---

## Architecture Overview

### Current Server Implementation

The server has a complete WebAuthn/JWT authentication system:

**Authentication Endpoints:**
- `POST /auth/register-options.php` - Generate registration challenge
- `POST /auth/register-verify.php` - Verify registration and create account
- `POST /auth/login-options.php` - Generate login challenge
- `POST /auth/login-verify.php` - Verify login and issue tokens
- `POST /auth/refresh.php` - Refresh access token
- `POST /auth/logout.php` - Revoke refresh token
- `GET /auth/user-info.php` - Get current user info
- `POST /auth/passkeys.php` - Manage passkeys (list/add/delete)

**Token System:**
- Access tokens: Short-lived (1 hour), sent as Bearer token
- Refresh tokens: Long-lived (7 days), HTTP-only cookie
- JWT-based, stateless authentication

**WebSocket Authentication:**
- Client sends `authenticate` message with JWT access token
- Server validates token and associates connection with user
- All messages include user's screen name

### Mobile App Integration Strategy

The mobile app will use native biometric APIs that implement the WebAuthn standard:

**Platform Support:**
- **Android**: Uses `androidx.biometric` + FIDO2 API
- **iOS**: Uses `AuthenticationServices` framework (ASWebAuthenticationSession) or native Passkeys API

**Authentication Flow:**
1. User initiates registration/login in mobile app
2. App calls server endpoint to get WebAuthn challenge
3. App invokes platform biometric API with challenge
4. Platform returns credential/assertion
5. App sends result to server for verification
6. Server issues JWT tokens
7. App stores tokens securely and connects to WebSocket

---

## Implementation Plan

### Phase 1: Flutter Dependencies & Setup

**Add Flutter packages to `pubspec.yaml`:**

```yaml
dependencies:
  # Existing dependencies
  web_socket_channel: ^2.4.0
  record: ^5.0.0
  audioplayers: ^5.2.0
  provider: ^6.1.0

  # NEW: Authentication dependencies
  http: ^1.1.0                      # HTTP client for API calls
  flutter_secure_storage: ^9.0.0    # Secure token storage
  local_auth: ^2.1.0                 # Biometric authentication
  passkeys: ^2.0.0                   # WebAuthn/Passkeys support
  jose: ^0.3.4                       # JWT handling (optional)
```

**Platform Configuration:**

**iOS (ios/Runner/Info.plist):**
```xml
<key>NSFaceIDUsageDescription</key>
<string>Use Face ID to securely authenticate</string>

<!-- For Associated Domains (optional, for platform authenticator) -->
<key>com.apple.developer.associated-domains</key>
<array>
    <string>webcredentials:yourdomain.com</string>
</array>
```

**Android (android/app/build.gradle):**
```gradle
android {
    defaultConfig {
        minSdkVersion 23  // Required for FIDO2
    }
}

dependencies {
    implementation 'androidx.biometric:biometric:1.2.0-alpha05'
    implementation 'com.google.android.gms:play-services-fido:20.1.0'
}
```

---

### Phase 2: Mobile Authentication Service

Create `lib/services/auth_service.dart`:

```dart
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:flutter_secure_storage:flutter_secure_storage.dart';
import 'package:passkeys/passkeys.dart';

class AuthService {
  final String baseUrl;
  final FlutterSecureStorage _storage = const FlutterSecureStorage();
  final PasskeysClient _passkeysClient = PasskeysClient();

  String? _accessToken;
  String? _refreshToken;
  Map<String, dynamic>? _currentUser;

  AuthService(this.baseUrl);

  // ==================== Registration ====================

  /// Step 1: Get registration options from server
  Future<Map<String, dynamic>> getRegistrationOptions(String username) async {
    final response = await http.post(
      Uri.parse('$baseUrl/auth/register-options.php'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({'username': username}),
    );

    if (response.statusCode != 200) {
      final error = jsonDecode(response.body);
      throw Exception(error['error'] ?? 'Registration failed');
    }

    final data = jsonDecode(response.body);
    if (!data['success']) {
      throw Exception(data['error'] ?? 'Registration failed');
    }

    return data['options'];
  }

  /// Step 2: Register with passkey
  Future<void> registerWithPasskey(String username) async {
    try {
      // Get options from server
      final options = await getRegistrationOptions(username);

      // Create passkey using platform API
      final credential = await _passkeysClient.register(
        RegisterRequestOptions(
          challenge: options['challenge'],
          rp: RelyingParty(
            name: options['rp']['name'],
            id: options['rp']['id'],
          ),
          user: UserInfo(
            id: options['user']['id'],
            name: options['user']['name'],
            displayName: options['user']['displayName'],
          ),
          pubKeyCredParams: (options['pubKeyCredParams'] as List)
              .map((p) => PubKeyCredParam(
                    type: p['type'],
                    alg: p['alg'],
                  ))
              .toList(),
          timeout: options['timeout'],
          authenticatorSelection: AuthenticatorSelectionCriteria(
            userVerification: options['authenticatorSelection']
                ['userVerification'],
          ),
        ),
      );

      // Send credential to server for verification
      await verifyRegistration(credential);

    } on PasskeyException catch (e) {
      throw Exception('Passkey creation failed: ${e.message}');
    }
  }

  /// Step 3: Verify registration with server
  Future<void> verifyRegistration(RegisterResponseType credential) async {
    final response = await http.post(
      Uri.parse('$baseUrl/auth/register-verify.php'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({
        'credential': {
          'id': credential.id,
          'rawId': credential.rawId,
          'response': {
            'clientDataJSON': credential.response.clientDataJSON,
            'attestationObject': credential.response.attestationObject,
          },
          'type': credential.type,
        },
        'nickname': await _getDeviceNickname(),
      }),
    );

    if (response.statusCode != 200) {
      final error = jsonDecode(response.body);
      throw Exception(error['error'] ?? 'Registration verification failed');
    }

    final data = jsonDecode(response.body);
    if (!data['success']) {
      throw Exception(data['error'] ?? 'Registration verification failed');
    }

    // Store tokens
    await _storeTokens(data['tokens']);
    _currentUser = data['user'];
  }

  // ==================== Login ====================

  /// Step 1: Get login options from server
  Future<Map<String, dynamic>> getLoginOptions(String username) async {
    final response = await http.post(
      Uri.parse('$baseUrl/auth/login-options.php'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({'username': username}),
    );

    if (response.statusCode != 200) {
      final error = jsonDecode(response.body);
      throw Exception(error['error'] ?? 'Login failed');
    }

    final data = jsonDecode(response.body);
    if (!data['success']) {
      throw Exception(data['error'] ?? 'Login failed');
    }

    return data['options'];
  }

  /// Step 2: Login with passkey
  Future<void> loginWithPasskey(String username) async {
    try {
      // Get options from server
      final options = await getLoginOptions(username);

      // Authenticate with passkey
      final assertion = await _passkeysClient.authenticate(
        AuthenticateRequestOptions(
          challenge: options['challenge'],
          rpId: options['rpId'],
          timeout: options['timeout'],
          allowCredentials: (options['allowCredentials'] as List?)
              ?.map((c) => PublicKeyCredentialDescriptor(
                    type: c['type'],
                    id: c['id'],
                    transports: (c['transports'] as List?)
                        ?.cast<String>(),
                  ))
              .toList(),
          userVerification: options['userVerification'],
        ),
      );

      // Send assertion to server for verification
      await verifyLogin(assertion);

    } on PasskeyException catch (e) {
      throw Exception('Passkey authentication failed: ${e.message}');
    }
  }

  /// Step 3: Verify login with server
  Future<void> verifyLogin(AuthenticateResponseType assertion) async {
    final response = await http.post(
      Uri.parse('$baseUrl/auth/login-verify.php'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({
        'credential': {
          'id': assertion.id,
          'rawId': assertion.rawId,
          'response': {
            'clientDataJSON': assertion.response.clientDataJSON,
            'authenticatorData': assertion.response.authenticatorData,
            'signature': assertion.response.signature,
            'userHandle': assertion.response.userHandle,
          },
          'type': assertion.type,
        },
      }),
    );

    if (response.statusCode != 200) {
      final error = jsonDecode(response.body);
      throw Exception(error['error'] ?? 'Login verification failed');
    }

    final data = jsonDecode(response.body);
    if (!data['success']) {
      throw Exception(data['error'] ?? 'Login verification failed');
    }

    // Store tokens
    await _storeTokens(data['tokens']);
    _currentUser = data['user'];
  }

  // ==================== Token Management ====================

  /// Store tokens securely
  Future<void> _storeTokens(Map<String, dynamic> tokens) async {
    _accessToken = tokens['access_token'];
    _refreshToken = tokens['refresh_token'];

    await _storage.write(key: 'access_token', value: _accessToken);
    await _storage.write(key: 'refresh_token', value: _refreshToken);
  }

  /// Load tokens from storage
  Future<bool> loadStoredTokens() async {
    _accessToken = await _storage.read(key: 'access_token');
    _refreshToken = await _storage.read(key: 'refresh_token');

    if (_accessToken != null) {
      // Validate token and get user info
      try {
        await getCurrentUser();
        return true;
      } catch (e) {
        // Token invalid, clear it
        await clearTokens();
        return false;
      }
    }

    return false;
  }

  /// Refresh access token
  Future<void> refreshAccessToken() async {
    if (_refreshToken == null) {
      throw Exception('No refresh token available');
    }

    final response = await http.post(
      Uri.parse('$baseUrl/auth/refresh.php'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({'refresh_token': _refreshToken}),
    );

    if (response.statusCode != 200) {
      throw Exception('Token refresh failed');
    }

    final data = jsonDecode(response.body);
    if (!data['success']) {
      throw Exception('Token refresh failed');
    }

    _accessToken = data['tokens']['access_token'];
    await _storage.write(key: 'access_token', value: _accessToken);
  }

  /// Get current user info
  Future<Map<String, dynamic>> getCurrentUser() async {
    if (_accessToken == null) {
      throw Exception('Not authenticated');
    }

    final response = await http.get(
      Uri.parse('$baseUrl/auth/user-info.php'),
      headers: {
        'Authorization': 'Bearer $_accessToken',
      },
    );

    if (response.statusCode == 401) {
      // Token expired, try to refresh
      await refreshAccessToken();
      return getCurrentUser(); // Retry
    }

    if (response.statusCode != 200) {
      throw Exception('Failed to get user info');
    }

    final data = jsonDecode(response.body);
    if (!data['success']) {
      throw Exception('Failed to get user info');
    }

    _currentUser = data['user'];
    return _currentUser!;
  }

  /// Logout
  Future<void> logout() async {
    if (_accessToken != null) {
      try {
        await http.post(
          Uri.parse('$baseUrl/auth/logout.php'),
          headers: {
            'Authorization': 'Bearer $_accessToken',
            'Content-Type': 'application/json',
          },
          body: jsonEncode({'refresh_token': _refreshToken}),
        );
      } catch (e) {
        // Ignore errors, clear tokens anyway
      }
    }

    await clearTokens();
  }

  /// Clear all stored tokens
  Future<void> clearTokens() async {
    _accessToken = null;
    _refreshToken = null;
    _currentUser = null;

    await _storage.delete(key: 'access_token');
    await _storage.delete(key: 'refresh_token');
  }

  // ==================== Helpers ====================

  bool get isAuthenticated => _accessToken != null;

  String? get accessToken => _accessToken;

  Map<String, dynamic>? get currentUser => _currentUser;

  String? get screenName => _currentUser?['username'];

  /// Get device nickname for passkey registration
  Future<String> _getDeviceNickname() async {
    // Get device model name
    // You can use device_info_plus package for this
    return 'Mobile Device'; // Placeholder
  }

  /// HTTP client with automatic token refresh
  Future<http.Response> authenticatedRequest(
    String method,
    String url, {
    Map<String, String>? headers,
    Object? body,
  }) async {
    if (_accessToken == null) {
      throw Exception('Not authenticated');
    }

    final authHeaders = {
      'Authorization': 'Bearer $_accessToken',
      if (headers != null) ...headers,
    };

    http.Response response;

    switch (method.toUpperCase()) {
      case 'GET':
        response = await http.get(Uri.parse(url), headers: authHeaders);
        break;
      case 'POST':
        response = await http.post(
          Uri.parse(url),
          headers: authHeaders,
          body: body,
        );
        break;
      default:
        throw Exception('Unsupported method');
    }

    // If unauthorized, try to refresh token and retry
    if (response.statusCode == 401) {
      await refreshAccessToken();
      return authenticatedRequest(method, url, headers: headers, body: body);
    }

    return response;
  }
}
```

---

### Phase 3: Update WebSocket Service

Update `lib/services/websocket_service.dart` to use authentication:

```dart
class WebSocketService {
  final String url;
  final AuthService authService;

  WebChannel? _channel;
  StreamController<Map<String, dynamic>>? _messageController;

  WebSocketService(this.url, this.authService);

  Future<void> connect() async {
    _channel = WebSocketChannel.connect(Uri.parse(url));
    _messageController = StreamController<Map<String, dynamic>>.broadcast();

    // Listen for messages
    _channel!.stream.listen(
      (message) {
        final data = jsonDecode(message);
        _messageController!.add(data);
        _handleMessage(data);
      },
      onError: (error) {
        print('WebSocket error: $error');
        _reconnect();
      },
      onDone: () {
        print('WebSocket closed');
        _reconnect();
      },
    );

    // Send authentication after connection
    await _authenticate();
  }

  Future<void> _authenticate() async {
    if (authService.isAuthenticated) {
      // Send JWT token for authenticated users
      send({
        'type': 'authenticate',
        'token': authService.accessToken,
      });
    } else {
      // Send screen name for anonymous users
      // (fallback if anonymous mode enabled)
      final screenName = await _promptForScreenName();
      send({
        'type': 'set_screen_name',
        'screen_name': screenName,
      });
    }
  }

  void _handleMessage(Map<String, dynamic> data) {
    switch (data['type']) {
      case 'authenticated':
        print('Authenticated as ${data['user']['username']}');
        break;

      case 'authentication_required':
        // Server requires authentication
        // Navigate to login screen
        break;

      case 'error':
        if (data['code'] == 'screen_name_taken') {
          // Handle screen name conflict
        }
        break;
    }
  }

  void send(Map<String, dynamic> message) {
    _channel?.sink.add(jsonEncode(message));
  }

  void _reconnect() {
    Future.delayed(const Duration(seconds: 3), () {
      connect();
    });
  }

  Stream<Map<String, dynamic>> get messages => _messageController!.stream;

  void dispose() {
    _channel?.sink.close();
    _messageController?.close();
  }
}
```

---

### Phase 4: UI Screens

#### Registration Screen

`lib/screens/register_screen.dart`:

```dart
class RegisterScreen extends StatefulWidget {
  @override
  _RegisterScreenState createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _usernameController = TextEditingController();
  final _authService = AuthService('https://yourdomain.com');
  bool _isLoading = false;

  Future<void> _register() async {
    final username = _usernameController.text.trim();

    if (username.isEmpty) {
      _showError('Please enter a username');
      return;
    }

    setState(() => _isLoading = true);

    try {
      await _authService.registerWithPasskey(username);

      // Navigate to main app
      Navigator.pushReplacementNamed(context, '/home');

    } catch (e) {
      _showError(e.toString());
    } finally {
      setState(() => _isLoading = false);
    }
  }

  void _showError(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(message)),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Register')),
      body: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            TextField(
              controller: _usernameController,
              decoration: const InputDecoration(
                labelText: 'Choose a screen name',
                hintText: '2-20 characters',
              ),
              enabled: !_isLoading,
            ),
            const SizedBox(height: 24),
            ElevatedButton(
              onPressed: _isLoading ? null : _register,
              child: _isLoading
                  ? const CircularProgressIndicator()
                  : const Text('Register with Biometrics'),
            ),
            TextButton(
              onPressed: () {
                Navigator.pushReplacementNamed(context, '/login');
              },
              child: const Text('Already have an account? Login'),
            ),
          ],
        ),
      ),
    );
  }
}
```

#### Login Screen

`lib/screens/login_screen.dart`:

```dart
class LoginScreen extends StatefulWidget {
  @override
  _LoginScreenState createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _usernameController = TextEditingController();
  final _authService = AuthService('https://yourdomain.com');
  bool _isLoading = false;

  Future<void> _login() async {
    final username = _usernameController.text.trim();

    if (username.isEmpty) {
      _showError('Please enter your username');
      return;
    }

    setState(() => _isLoading = true);

    try {
      await _authService.loginWithPasskey(username);

      // Navigate to main app
      Navigator.pushReplacementNamed(context, '/home');

    } catch (e) {
      _showError(e.toString());
    } finally {
      setState(() => _isLoading = false);
    }
  }

  void _showError(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(message)),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Login')),
      body: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            TextField(
              controller: _usernameController,
              decoration: const InputDecoration(
                labelText: 'Screen name',
              ),
              enabled: !_isLoading,
            ),
            const SizedBox(height: 24),
            ElevatedButton(
              onPressed: _isLoading ? null : _login,
              child: _isLoading
                  ? const CircularProgressIndicator()
                  : const Text('Login with Biometrics'),
            ),
            TextButton(
              onPressed: () {
                Navigator.pushReplacementNamed(context, '/register');
              },
              child: const Text('New user? Register'),
            ),
          ],
        ),
      ),
    );
  }
}
```

---

### Phase 5: Main App Integration

Update `lib/main.dart`:

```dart
void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  runApp(const WalkieTalkieApp());
}

class WalkieTalkieApp extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Walkie Talkie',
      initialRoute: '/splash',
      routes: {
        '/splash': (context) => SplashScreen(),
        '/register': (context) => RegisterScreen(),
        '/login': (context) => LoginScreen(),
        '/home': (context) => HomeScreen(),
      },
    );
  }
}

class SplashScreen extends StatefulWidget {
  @override
  _SplashScreenState createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  final _authService = AuthService('https://yourdomain.com');

  @override
  void initState() {
    super.initState();
    _checkAuthentication();
  }

  Future<void> _checkAuthentication() async {
    // Try to load stored tokens
    final hasToken = await _authService.loadStoredTokens();

    if (hasToken) {
      // Already authenticated, go to home
      Navigator.pushReplacementNamed(context, '/home');
    } else {
      // Not authenticated, check if anonymous mode allowed
      final config = await _fetchConfig();

      if (config['anonymousModeEnabled']) {
        // Allow anonymous, go to home (will prompt for screen name)
        Navigator.pushReplacementNamed(context, '/home');
      } else {
        // Require authentication, go to login
        Navigator.pushReplacementNamed(context, '/login');
      }
    }
  }

  Future<Map<String, dynamic>> _fetchConfig() async {
    final response = await http.get(
      Uri.parse('https://yourdomain.com/config.php'),
    );
    return jsonDecode(response.body);
  }

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(
        child: CircularProgressIndicator(),
      ),
    );
  }
}
```

---

## Platform-Specific Considerations

### Android Implementation

**Using FIDO2 API:**

The `passkeys` package wraps Google Play Services FIDO2 API. For Android, the flow is:

1. App calls `Fido2ApiClient.getRegisterPendingIntent()` or `getSignPendingIntent()`
2. System displays biometric prompt
3. User authenticates with fingerprint/face
4. System returns credential/assertion

**Requirements:**
- Google Play Services installed
- Device with biometric hardware
- minSdkVersion 23+ (Android 6.0)

**Alternative for older devices:**
- Fall back to `local_auth` package for simple biometric check
- Store credentials using `flutter_secure_storage`
- Not true WebAuthn, but provides biometric protection

### iOS Implementation

**Using ASWebAuthenticationSession:**

For iOS, WebAuthn can be implemented using:

1. `ASWebAuthenticationSession` - Opens an in-app browser for WebAuthn
2. Native Passkeys API (iOS 16+) - True passkey support

**Requirements:**
- iOS 13.0+ for ASWebAuthenticationSession
- iOS 16.0+ for native Passkeys
- Associated Domains configured for platform authenticator

**Implementation:**

The `passkeys` package on iOS uses the Passkeys API when available, falling back to ASWebAuthenticationSession on older iOS versions.

---

## Security Considerations

### Token Storage

**Use Flutter Secure Storage:**
- Stores tokens in iOS Keychain / Android Keystore
- Encrypted at rest
- Only accessible to your app
- Survives app uninstall/reinstall (configurable)

```dart
final storage = FlutterSecureStorage(
  aOptions: AndroidOptions(
    encryptedSharedPreferences: true,
  ),
  iOptions: IOSOptions(
    accessibility: KeychainAccessibility.first_unlock_this_device,
  ),
);
```

### Network Security

**HTTPS Required:**
- WebAuthn requires HTTPS in production
- Use certificate pinning for extra security:

```dart
final client = HttpClient()
  ..badCertificateCallback = (cert, host, port) {
    // Implement certificate pinning
    return false;
  };
```

### Token Refresh Strategy

**Automatic Refresh:**
```dart
class TokenRefreshInterceptor {
  final AuthService authService;

  TokenRefreshInterceptor(this.authService);

  Future<http.Response> request(Future<http.Response> Function() request) async {
    final response = await request();

    if (response.statusCode == 401) {
      // Token expired, refresh and retry
      await authService.refreshAccessToken();
      return await request();
    }

    return response;
  }
}
```

---

## Testing Strategy

### Unit Tests

Test authentication service methods:

```dart
void main() {
  group('AuthService', () {
    late AuthService authService;
    late MockHttpClient mockHttp;

    setUp(() {
      mockHttp = MockHttpClient();
      authService = AuthService('https://test.com', httpClient: mockHttp);
    });

    test('registerWithPasskey success', () async {
      // Mock registration options response
      when(mockHttp.post(any, body: any))
          .thenAnswer((_) async => http.Response(
                jsonEncode({
                  'success': true,
                  'options': {...},
                }),
                200,
              ));

      // Test registration flow
      await authService.registerWithPasskey('testuser');

      expect(authService.isAuthenticated, true);
    });

    test('loginWithPasskey handles expired token', () async {
      // Test token refresh logic
    });
  });
}
```

### Integration Tests

Test full authentication flow:

```dart
void main() {
  testWidgets('Complete registration flow', (tester) async {
    await tester.pumpWidget(WalkieTalkieApp());

    // Navigate to register screen
    await tester.tap(find.text('Register'));
    await tester.pumpAndSettle();

    // Enter username
    await tester.enterText(find.byType(TextField), 'testuser');

    // Tap register button
    await tester.tap(find.text('Register with Biometrics'));
    await tester.pump();

    // Verify biometric prompt (mocked)
    // Verify navigation to home screen
    expect(find.text('Push to Talk'), findsOneWidget);
  });
}
```

---

## Deployment Checklist

### Server Configuration

- [ ] Ensure `WEBAUTHN_RP_ID` matches your domain (e.g., `yourdomain.com`)
- [ ] Set `WEBAUTHN_ORIGINS` to your HTTPS URL(s) (e.g., `https://yourdomain.com` or `https://yourdomain.com,https://mobile.yourdomain.com`)
- [ ] Generate strong `JWT_SECRET` (64+ bytes)
- [ ] Configure CORS headers to allow mobile app origin
- [ ] Enable HTTPS (required for WebAuthn)
- [ ] Test all authentication endpoints with Postman

### Mobile App Configuration

- [ ] Update `baseUrl` in AuthService to production server
- [ ] Configure associated domains (iOS)
- [ ] Add FIDO2 dependency (Android)
- [ ] Test on physical devices (simulators may not support biometrics)
- [ ] Test with different biometric types (fingerprint, Face ID, etc.)
- [ ] Test offline behavior (token expiration, reconnection)
- [ ] Test token refresh logic
- [ ] Implement error handling for all edge cases

### Platform-Specific

**iOS:**
- [ ] Configure Associated Domains entitlement
- [ ] Test on iOS 13+ and iOS 16+ (different Passkey APIs)
- [ ] Test with Face ID and Touch ID devices
- [ ] Verify Info.plist permissions

**Android:**
- [ ] Test on devices with Google Play Services
- [ ] Test on devices without biometric hardware (graceful fallback)
- [ ] Verify minSdkVersion compatibility
- [ ] Test with different Android versions (6.0+)

---

## Troubleshooting Guide

### Common Issues

**Issue: "Passkeys not supported" error**
- **Cause**: Device doesn't support WebAuthn/FIDO2
- **Solution**: Check device compatibility, ensure Google Play Services updated (Android), iOS 13+ (iOS)

**Issue: "Invalid challenge" error**
- **Cause**: Challenge expired or mismatched
- **Solution**: Ensure server and client time are synchronized, implement challenge timeout handling

**Issue: "Token refresh failed"**
- **Cause**: Refresh token expired or revoked
- **Solution**: Redirect to login screen, clear stored tokens

**Issue: "Network error" during authentication**
- **Cause**: No internet connection, server down, CORS issue
- **Solution**: Implement network connectivity check, retry logic, proper error messages

**Issue: "Biometric authentication cancelled"**
- **Cause**: User cancelled biometric prompt
- **Solution**: Handle `PasskeyException` gracefully, allow retry

---

## Migration Path for Existing Anonymous Users

If you have existing users with the mobile app connecting anonymously:

### Option 1: In-App Registration Prompt

Show a banner encouraging registration:
```dart
if (!authService.isAuthenticated) {
  showDialog(
    context: context,
    builder: (context) => AlertDialog(
      title: const Text('Create Account'),
      content: const Text(
        'Register to save your screen name and use it across devices!',
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Maybe Later'),
        ),
        ElevatedButton(
          onPressed: () {
            Navigator.pop(context);
            Navigator.pushNamed(context, '/register');
          },
          child: const Text('Register'),
        ),
      ],
    ),
  );
}
```

### Option 2: Force Registration

If `ANONYMOUS_MODE_ENABLED=false` on server:
```dart
// In splash screen
if (!authService.isAuthenticated && !config['anonymousModeEnabled']) {
  Navigator.pushReplacementNamed(context, '/login');
}
```

---

## Summary

This plan provides a complete integration of WebAuthn authentication into your Flutter mobile app. The server already has all the necessary endpoints - the mobile app just needs to:

1. **Call HTTP APIs** to get WebAuthn challenges
2. **Invoke platform biometric APIs** using the `passkeys` package
3. **Send credentials/assertions** back to server for verification
4. **Store JWT tokens** securely using `flutter_secure_storage`
5. **Authenticate WebSocket** connection with access token

**Key Benefits:**
- ✅ Passwordless authentication (no passwords to remember/manage)
- ✅ Native biometric experience (Face ID, Touch ID, fingerprint)
- ✅ Secure token storage (iOS Keychain, Android Keystore)
- ✅ Multi-device support (users can register multiple devices)
- ✅ Backward compatible (anonymous mode still works)
- ✅ Phishing-resistant (FIDO2 standard)

**Next Steps:**
1. Add dependencies to `pubspec.yaml`
2. Implement `AuthService` class
3. Create registration/login screens
4. Update WebSocket service for authentication
5. Test on physical devices
6. Deploy to production
