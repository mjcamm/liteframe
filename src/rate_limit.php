<?php

/**
 * Rate limiting.
 *
 * IP-based sliding window using the _rate_limits table.
 * Two tiers: general API (per-IP) and stricter auth-endpoint limits.
 * Configurable via settings.yml under `rate_limit:` section.
 */

/**
 * Check rate limit for the current request.
 * Returns true if allowed, false if rate limited (429 headers already set).
 *
 * Called after route matching, before auth middleware.
 */
function _lf_rate_limit_check(string $handler_name): bool
{
    global $db;

    if (!setting('rate_limit.enabled', true)) {
        return true;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $now = time();

    // Auth endpoints get a stricter limit
    $is_auth = str_starts_with($handler_name, '_auth_');
    if ($is_auth) {
        $key = "auth:{$ip}";
        $window = (int) setting('rate_limit.login_window', 900);
        $max = (int) setting('rate_limit.login_max', 5);
    } else {
        $key = "global:{$ip}";
        $window = (int) setting('rate_limit.window', 60);
        $max = (int) setting('rate_limit.max_requests', 100);
    }

    $window_start = $now - $window;

    $row = $db->one(
        'SELECT hits, window_start FROM _rate_limits WHERE key = ?',
        [$key]
    );

    if (!$row || $row->window_start < $window_start) {
        // No record or window expired — start fresh
        $db->exec(
            'INSERT OR REPLACE INTO _rate_limits (key, hits, window_start) VALUES (?, 1, ?)',
            [$key, $now]
        );
        $hits = 1;
        $reset = $now + $window;
    } else {
        // Within window — increment
        $hits = $row->hits + 1;
        $db->exec(
            'UPDATE _rate_limits SET hits = ? WHERE key = ?',
            [$hits, $key]
        );
        $reset = $row->window_start + $window;
    }

    // Rate limit headers on every API response
    header("X-RateLimit-Limit: {$max}");
    header('X-RateLimit-Remaining: ' . max(0, $max - $hits));
    header("X-RateLimit-Reset: {$reset}");

    // Probabilistic cleanup (~1% of requests)
    if (mt_rand(1, 100) === 1) {
        _lf_rate_limit_cleanup();
    }

    if ($hits > $max) {
        $retry_after = max(1, $reset - $now);
        header("Retry-After: {$retry_after}");
        return false;
    }

    return true;
}

/**
 * Delete expired rate limit entries.
 */
function _lf_rate_limit_cleanup(): void
{
    global $db;

    $max_window = max(
        (int) setting('rate_limit.window', 60),
        (int) setting('rate_limit.login_window', 900)
    );
    $cutoff = time() - $max_window;

    $db->exec('DELETE FROM _rate_limits WHERE window_start < ?', [$cutoff]);
}
