<?php
/**
 * AudioProcessor - Audio Format Handling Library
 *
 * Copyright (C) 2025 Matthew Asham
 *
 * This program is dual-licensed:
 *
 * 1. GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later)
 *    For open source use, you can redistribute it and/or modify it under
 *    the terms of the GNU Affero General Public License as published by
 *    the Free Software Foundation, either version 3 of the License, or
 *    (at your option) any later version.
 *
 * 2. Commercial License
 *    For commercial or proprietary use without AGPL-3.0 obligations,
 *    contact Matthew Asham at https://www.asham.ca/
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * ---
 *
 * Handles multiple audio formats with auto-detection:
 * - WAV files (PCM, 16-bit, mono)
 * - Raw PCM16 data
 *
 * Usage:
 *   $audio = AudioProcessor::loadAudio('file.wav');
 *   // Returns: ['sample_rate' => 48000, 'format' => 'pcm16', 'duration_ms' => 5000, 'chunks' => [...]]
 */

namespace WalkieTalkie\CLI;

class AudioProcessor
{
    /**
     * Load audio from file or stdin
     *
     * @param string $filePath Path to audio file, or "-" for stdin
     * @param int $sampleRate Sample rate for raw PCM16 (ignored for WAV)
     * @param int $chunkSize Chunk size in bytes
     * @return array Audio data with metadata
     * @throws \Exception On invalid format or file error
     */
    public static function loadAudio(string $filePath, int $sampleRate = 48000, int $chunkSize = 4096): array
    {
        // Read file data
        if ($filePath === '-') {
            $data = stream_get_contents(STDIN);
            if ($data === false) {
                throw new \Exception('Failed to read from stdin');
            }
        } else {
            if (!file_exists($filePath)) {
                throw new \Exception("Audio file not found: {$filePath}");
            }
            $data = file_get_contents($filePath);
            if ($data === false) {
                throw new \Exception("Failed to read file: {$filePath}");
            }
        }

        if (empty($data)) {
            throw new \Exception('Audio data is empty');
        }

        // Detect format
        $format = self::detectFormat($data);

        // Parse based on format
        if ($format === 'wav') {
            $parsed = self::parseWav($data);
            $pcm16Data = $parsed['pcm_data'];
            $sampleRate = $parsed['sample_rate'];
        } elseif ($format === 'pcm16') {
            $pcm16Data = $data;
            // Use provided sample rate
        } else {
            throw new \Exception("Unsupported audio format: {$format}");
        }

        // Calculate duration
        $totalSamples = strlen($pcm16Data) / 2; // 16-bit = 2 bytes per sample
        $durationMs = (int)(($totalSamples / $sampleRate) * 1000);

        // Chunk the audio
        $chunks = self::chunkAudio($pcm16Data, $chunkSize);

        return [
            'sample_rate' => $sampleRate,
            'format' => 'pcm16',
            'duration_ms' => $durationMs,
            'chunks' => $chunks,
            'total_samples' => (int)$totalSamples,
            'total_bytes' => strlen($pcm16Data)
        ];
    }

    /**
     * Detect audio format
     *
     * @param string $data Binary audio data
     * @return string Format: 'wav' or 'pcm16'
     */
    public static function detectFormat(string $data): string
    {
        // Check for WAV header (RIFF....WAVE)
        if (strlen($data) >= 12) {
            $riff = substr($data, 0, 4);
            $wave = substr($data, 8, 4);
            if ($riff === 'RIFF' && $wave === 'WAVE') {
                return 'wav';
            }
        }

        // Assume raw PCM16
        return 'pcm16';
    }

    /**
     * Parse WAV file
     *
     * @param string $data WAV file data
     * @return array ['sample_rate' => int, 'pcm_data' => string]
     * @throws \Exception On invalid WAV format
     */
    public static function parseWav(string $data): array
    {
        $dataLen = strlen($data);

        if ($dataLen < 44) {
            throw new \Exception('Invalid WAV file: too short (minimum 44 bytes for header)');
        }

        // Parse RIFF header
        $riff = substr($data, 0, 4);
        if ($riff !== 'RIFF') {
            throw new \Exception('Invalid WAV file: missing RIFF header');
        }

        $wave = substr($data, 8, 4);
        if ($wave !== 'WAVE') {
            throw new \Exception('Invalid WAV file: missing WAVE header');
        }

        // Find fmt chunk
        $offset = 12;
        $fmtChunk = null;
        $dataChunk = null;

        while ($offset < $dataLen - 8) {
            $chunkId = substr($data, $offset, 4);
            $chunkSize = unpack('V', substr($data, $offset + 4, 4))[1];

            if ($chunkId === 'fmt ') {
                $fmtChunk = [
                    'offset' => $offset + 8,
                    'size' => $chunkSize
                ];
            } elseif ($chunkId === 'data') {
                $dataChunk = [
                    'offset' => $offset + 8,
                    'size' => $chunkSize
                ];
            }

            $offset += 8 + $chunkSize;

            // Stop if we have both chunks
            if ($fmtChunk && $dataChunk) {
                break;
            }
        }

        if (!$fmtChunk) {
            throw new \Exception('Invalid WAV file: missing fmt chunk');
        }

        if (!$dataChunk) {
            throw new \Exception('Invalid WAV file: missing data chunk');
        }

        // Parse fmt chunk
        $fmtData = substr($data, $fmtChunk['offset'], $fmtChunk['size']);
        if (strlen($fmtData) < 16) {
            throw new \Exception('Invalid WAV file: fmt chunk too short');
        }

        $audioFormat = unpack('v', substr($fmtData, 0, 2))[1];
        $numChannels = unpack('v', substr($fmtData, 2, 2))[1];
        $sampleRate = unpack('V', substr($fmtData, 4, 4))[1];
        $bitsPerSample = unpack('v', substr($fmtData, 14, 2))[1];

        // Validate format
        if ($audioFormat !== 1) {
            throw new \Exception("Unsupported WAV format: {$audioFormat} (only PCM format [1] is supported)");
        }

        if ($numChannels !== 1) {
            throw new \Exception("Unsupported channel count: {$numChannels} (only mono [1] is supported)");
        }

        if ($bitsPerSample !== 16) {
            throw new \Exception("Unsupported bit depth: {$bitsPerSample} (only 16-bit is supported)");
        }

        // Extract PCM data
        $pcmData = substr($data, $dataChunk['offset'], $dataChunk['size']);

        return [
            'sample_rate' => $sampleRate,
            'pcm_data' => $pcmData
        ];
    }

    /**
     * Chunk audio data into smaller pieces
     *
     * @param string $pcm16Data Raw PCM16 data
     * @param int $chunkSize Chunk size in bytes
     * @return array Array of base64-encoded chunks
     */
    public static function chunkAudio(string $pcm16Data, int $chunkSize): array
    {
        $chunks = [];
        $dataLen = strlen($pcm16Data);
        $offset = 0;

        while ($offset < $dataLen) {
            $chunk = substr($pcm16Data, $offset, $chunkSize);
            $chunks[] = self::toBase64($chunk);
            $offset += $chunkSize;
        }

        return $chunks;
    }

    /**
     * Encode PCM16 chunk to base64
     *
     * @param string $pcm16Chunk Raw PCM16 data
     * @return string Base64-encoded data
     */
    public static function toBase64(string $pcm16Chunk): string
    {
        return base64_encode($pcm16Chunk);
    }

    /**
     * Get audio format info as human-readable string
     *
     * @param array $audioData Audio data from loadAudio()
     * @return string Format info (e.g., "WAV, 48kHz, 5.2s")
     */
    public static function formatInfo(array $audioData): string
    {
        $sampleRate = $audioData['sample_rate'];
        $durationSec = $audioData['duration_ms'] / 1000;

        // Format sample rate (e.g., 48000 -> 48kHz)
        $rateKhz = $sampleRate / 1000;

        return sprintf(
            "%s, %.0fkHz, %.1fs",
            strtoupper($audioData['format']),
            $rateKhz,
            $durationSec
        );
    }
}
