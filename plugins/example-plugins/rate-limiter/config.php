<?php
/**
 * Rate Limiter Plugin Configuration
 */

return [
    'enabled' => true,
    'max_transmissions_per_minute' => 10,
    'cooldown_seconds' => 2,
    'exempt_users' => [
        // Add usernames to exempt from rate limiting
        // 'AdminUser',
        // 'BotAccount',
    ],
];
