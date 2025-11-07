-- Migration 004: Add Opus Codec Support
-- Adds codec tracking to message_history table

-- Add codec column (default to 'pcm16' for backward compatibility)
ALTER TABLE message_history ADD COLUMN codec TEXT DEFAULT 'pcm16';

-- Add bitrate column for Opus metadata (in bits per second)
ALTER TABLE message_history ADD COLUMN bitrate INTEGER;

-- Create index for codec queries
CREATE INDEX IF NOT EXISTS idx_codec_channel ON message_history(codec, channel, timestamp);

-- Update existing rows to explicitly mark as PCM16
UPDATE message_history SET codec = 'pcm16' WHERE codec IS NULL OR codec = '';

-- Optional: Create user codec preferences table for future use
CREATE TABLE IF NOT EXISTS user_codec_preferences (
    user_id TEXT PRIMARY KEY,
    preferred_codec TEXT NOT NULL DEFAULT 'pcm16',
    fallback_codec TEXT NOT NULL DEFAULT 'pcm16',
    opus_bitrate INTEGER DEFAULT 24000,
    updated_at INTEGER NOT NULL,
    CHECK (preferred_codec IN ('pcm16', 'opus')),
    CHECK (fallback_codec IN ('pcm16', 'opus')),
    CHECK (opus_bitrate >= 6000 AND opus_bitrate <= 510000)
);
