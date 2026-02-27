<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/variables.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/rate_limit.php';

echo "=== Rate Limit Tests ===\n\n";

// Bootstrap
$db = new Database(':memory:');
$db->exec('CREATE TABLE IF NOT EXISTS _rate_limits (key TEXT PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 0, window_start INTEGER NOT NULL)');

// Load settings with rate limiting enabled (low limits for testing)
$_settings = [
    'rate_limit' => [
        'enabled' => true,
        'window' => 60,
        'max_requests' => 5,
        'login_window' => 900,
        'login_max' => 2,
    ],
];

$_SERVER['REMOTE_ADDR'] = '192.168.1.1';

// --- Test 1: First request allowed ---
$result = _lf_rate_limit_check('my_handler');
assert($result === true, 'First request should be allowed');
echo "[PASS] First request allowed\n";

// --- Test 2: Requests within limit are allowed ---
for ($i = 0; $i < 4; $i++) {
    $result = _lf_rate_limit_check('my_handler');
}
assert($result === true, 'Requests within limit should be allowed');
echo "[PASS] Requests within limit allowed (5/5)\n";

// --- Test 3: Request exceeding limit is blocked ---
$result = _lf_rate_limit_check('my_handler');
assert($result === false, 'Request exceeding limit should be blocked');
echo "[PASS] Request exceeding limit blocked (6th request)\n";

// --- Test 4: Different IP has separate limit ---
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$result = _lf_rate_limit_check('my_handler');
assert($result === true, 'Different IP should have its own limit');
echo "[PASS] Different IP has separate limit\n";

// --- Test 5: Auth endpoints use stricter limit ---
$_SERVER['REMOTE_ADDR'] = '172.16.0.1';
$result = _lf_rate_limit_check('_auth_login');
assert($result === true, 'First auth request allowed');
$result = _lf_rate_limit_check('_auth_login');
assert($result === true, 'Second auth request allowed');
$result = _lf_rate_limit_check('_auth_login');
assert($result === false, 'Third auth request blocked (login_max=2)');
echo "[PASS] Auth endpoints use stricter limit (login_max=2)\n";

// --- Test 6: Auth and global limits are separate ---
$_SERVER['REMOTE_ADDR'] = '172.16.0.1';
$result = _lf_rate_limit_check('my_handler');
assert($result === true, 'Global limit separate from auth limit for same IP');
echo "[PASS] Auth and global limits are separate keys\n";

// --- Test 7: Expired window resets counter ---
$_SERVER['REMOTE_ADDR'] = '192.168.1.1';
$db->exec(
    'UPDATE _rate_limits SET window_start = ? WHERE key = ?',
    [time() - 120, 'global:192.168.1.1']
);
$result = _lf_rate_limit_check('my_handler');
assert($result === true, 'Expired window should reset counter');
echo "[PASS] Expired window resets counter\n";

// --- Test 8: Disabled rate limiting allows everything ---
$_settings['rate_limit']['enabled'] = false;
$_SERVER['REMOTE_ADDR'] = '192.168.1.1';
$db->exec(
    'INSERT OR REPLACE INTO _rate_limits (key, hits, window_start) VALUES (?, ?, ?)',
    ['global:192.168.1.1', 999, time()]
);
$result = _lf_rate_limit_check('my_handler');
assert($result === true, 'Disabled rate limiting should allow all requests');
echo "[PASS] Disabled rate limiting allows all requests\n";

// Re-enable for cleanup tests
$_settings['rate_limit']['enabled'] = true;

// --- Test 9: Cleanup removes expired entries ---
$db->exec(
    'INSERT OR REPLACE INTO _rate_limits (key, hits, window_start) VALUES (?, ?, ?)',
    ['global:expired.ip', 50, time() - 2000]
);
_lf_rate_limit_cleanup();
$row = $db->one('SELECT * FROM _rate_limits WHERE key = ?', ['global:expired.ip']);
assert($row === null, 'Expired entry should be cleaned up');
echo "[PASS] Cleanup removes expired entries\n";

// --- Test 10: Cleanup preserves active entries ---
$db->exec(
    'INSERT OR REPLACE INTO _rate_limits (key, hits, window_start) VALUES (?, ?, ?)',
    ['global:active.ip', 3, time()]
);
_lf_rate_limit_cleanup();
$row = $db->one('SELECT * FROM _rate_limits WHERE key = ?', ['global:active.ip']);
assert($row !== null, 'Active entry should be preserved');
assert((int) $row->hits === 3, 'Active entry hits should be unchanged');
echo "[PASS] Cleanup preserves active entries\n";

echo "\n=== All tests passed ===\n";
