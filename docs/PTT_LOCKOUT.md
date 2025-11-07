# PTT (Push-To-Talk) Lockout Configuration

## Overview

The PTT lockout feature ensures that only one user can transmit at a time per channel. When enabled, if someone is already speaking on a channel, other users who try to transmit will receive an error message asking them to wait until the current speaker has finished.

## Global Configuration

### Enable/Disable PTT Lockout

Add this setting to your `.env` file:

```bash
# PTT (Push-To-Talk) Lockout
# When enabled, only one user can transmit at a time per channel
# Set to 'true' to enable, 'false' to allow simultaneous transmissions
PTT_LOCKOUT_ENABLED=true
```

**Values:**
- `true` or `1` - Enables PTT lockout (default)
- `false` or `0` - Disables PTT lockout, allows simultaneous transmissions

### Behavior

**When Enabled (`PTT_LOCKOUT_ENABLED=true`):**
- Only one user can transmit per channel at any given time
- If User A is transmitting on channel 5, User B cannot transmit on channel 5 until User A finishes
- User B will see a warning notification: "Please wait - [User A's name] is currently speaking"
- Users on different channels can transmit simultaneously (e.g., User A on channel 5, User C on channel 10)

**When Disabled (`PTT_LOCKOUT_ENABLED=false`):**
- Multiple users can transmit simultaneously on the same channel
- Audio streams will overlap and mix together
- This mode is useful for open discussion/conference scenarios

## Channel-Specific Configuration

### Current Implementation

The current implementation applies the PTT lockout setting **globally** across all channels. The lockout is enforced per-channel (User A on channel 5 doesn't block User B on channel 10), but the enable/disable setting affects all channels equally.

### Making It Channel-Specific

If you want to enable PTT lockout for specific channels only, here's what would be involved:

#### 1. Configuration Approach

**Option A: Environment Variable with Channel List**
```bash
# Comma-separated list of channels with PTT lockout enabled
PTT_LOCKOUT_CHANNELS=1,2,3,5,10
```

**Option B: Configuration File (JSON/YAML)**
```json
{
  "channels": {
    "1": { "ptt_lockout": true },
    "2": { "ptt_lockout": true },
    "5": { "ptt_lockout": false },
    "default": { "ptt_lockout": true }
  }
}
```

**Option C: Database Table**
```sql
CREATE TABLE channel_settings (
    channel_id TEXT PRIMARY KEY,
    ptt_lockout_enabled BOOLEAN DEFAULT 1,
    created_at INTEGER,
    updated_at INTEGER
);
```

#### 2. Code Changes Required

**In `WebSocketServer.php`:**

1. Change `$pttLockoutEnabled` from a boolean to an array or configuration object:
```php
protected $pttLockoutConfig = []; // Map: channelId => boolean
```

2. Update `loadConfiguration()` to parse channel-specific settings:
```php
private function loadConfiguration()
{
    // Load channel-specific PTT lockout settings
    $pttChannels = $_ENV['PTT_LOCKOUT_CHANNELS'] ?? '';
    if (!empty($pttChannels)) {
        $enabledChannels = array_map('trim', explode(',', $pttChannels));
        foreach ($enabledChannels as $channel) {
            $this->pttLockoutConfig[$channel] = true;
        }
    }

    // Set default for all channels
    $defaultEnabled = ($_ENV['PTT_LOCKOUT_ENABLED'] ?? 'true') === 'true';
    $this->pttLockoutConfig['default'] = $defaultEnabled;
}
```

3. Add a helper method to check if lockout is enabled for a specific channel:
```php
private function isPttLockoutEnabled(string $channel): bool
{
    // Check for channel-specific setting
    if (isset($this->pttLockoutConfig[$channel])) {
        return $this->pttLockoutConfig[$channel];
    }

    // Fall back to default
    return $this->pttLockoutConfig['default'] ?? true;
}
```

4. Update `handlePushToTalkStart()` to use the new method:
```php
if ($this->isPttLockoutEnabled($channel) && isset($this->activeTransmitters[$channel]) && ...) {
    // Block transmission
}
```

5. Update the transmitter tracking to only happen when enabled:
```php
if ($this->isPttLockoutEnabled($channel)) {
    $this->activeTransmitters[$channel] = $conn->resourceId;
}
```

#### 3. Example Implementation

For a quick channel-specific implementation, you could:

1. Add to `.env.example`:
```bash
# PTT lockout for all channels (default)
PTT_LOCKOUT_ENABLED=true

# Optional: Specific channels where PTT lockout should be disabled
# Comma-separated list of channel numbers
PTT_LOCKOUT_DISABLED_CHANNELS=
```

2. Update the configuration loading to parse disabled channels:
```php
// In loadConfiguration()
$this->pttLockoutEnabled = ($_ENV['PTT_LOCKOUT_ENABLED'] ?? 'true') === 'true';

// Load channels where PTT lockout should be disabled
$disabledChannels = $_ENV['PTT_LOCKOUT_DISABLED_CHANNELS'] ?? '';
if (!empty($disabledChannels)) {
    $this->pttLockoutDisabledChannels = array_map('trim', explode(',', $disabledChannels));
} else {
    $this->pttLockoutDisabledChannels = [];
}
```

3. Check both settings in `handlePushToTalkStart()`:
```php
$lockoutActive = $this->pttLockoutEnabled && !in_array($channel, $this->pttLockoutDisabledChannels);
if ($lockoutActive && isset($this->activeTransmitters[$channel]) && ...) {
    // Block transmission
}
```

### Recommendation

For most use cases, the **global configuration** (current implementation) is sufficient. Channel-specific configuration adds complexity and should only be implemented if you have a specific need, such as:

- Some channels are for open discussion (no lockout)
- Other channels are for official announcements (strict lockout)
- Emergency channels that need priority/different behavior

If you need this feature, **Option A** (Environment Variable with disabled channels list) is the simplest to implement and maintain.

## Plugin System Alternative

Alternatively, you could implement channel-specific PTT lockout rules using the plugin system:

```php
// In a custom plugin
$this->addHook('plugin.audio.transmit.start', function($conn, $channel, $identity, &$allowTransmission) {
    // Disable PTT lockout for channel 999 (open discussion channel)
    if ($channel === '999') {
        return; // Allow transmission regardless of active transmitters
    }

    // Your custom logic for other channels
});
```

This approach gives you maximum flexibility without modifying core code.
