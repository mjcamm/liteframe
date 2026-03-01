<?php

require_once __DIR__ . '/bootstrap.php';

echo "=== Rate Limit Tests ===\n\n";

$db = new Database(':memory:');
TestLF::set('db', $db);
$db->exec('CREATE TABLE IF NOT EXISTS _rate_limits (key TEXT PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 0, window_start INTEGER NOT NULL)');

TestLF::set('settings', [
    'rate_limit' => [
        'enabled' => true,
        'window' => 60,
        'max_requests' => 5,
        'login_window' => 900,
        'login_max' => 2,
    ],
]);

$_SERVER['REMOTE_ADDR'] = '192.168.1.1';

$result = TestLF::call('rate_limit_check', 'my_handler');
assert($result === true);
echo "[PASS] First request allowed\n";

for ($i = 0; $i < 4; $i++) {
    $result = TestLF::call('rate_limit_check', 'my_handler');
}
assert($result === true);
echo "[PASS] Requests within limit allowed (5/5)\n";

$result = TestLF::call('rate_limit_check', 'my_handler');
assert($result === false);
echo "[PASS] Request exceeding limit blocked (6th request)\n";

$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$result = TestLF::call('rate_limit_check', 'my_handler');
assert($result === true);
echo "[PASS] Different IP has separate limit\n";

$_SERVER['REMOTE_ADDR'] = '172.16.0.1';
$result = TestLF::call('rate_limit_check', '_auth_login');
assert($result === true);
$result = TestLF::call('rate_limit_check', '_auth_login');
assert($result === true);
$result = TestLF::call('rate_limit_check', '_auth_login');
assert($result === false);
echo "[PASS] Auth endpoints use stricter limit (login_max=2)\n";

$_SERVER['REMOTE_ADDR'] = '172.16.0.1';
$result = TestLF::call('rate_limit_check', 'my_handler');
assert($result === true);
echo "[PASS] Auth and global limits are separate keys\n";

$_SERVER['REMOTE_ADDR'] = '192.168.1.1';
$db->exec('UPDATE _rate_limits SET window_start = ? WHERE key = ?', [time() - 120, 'global:192.168.1.1']);
$result = TestLF::call('rate_limit_check', 'my_handler');
assert($result === true);
echo "[PASS] Expired window resets counter\n";

$s = TestLF::get('settings');
$s['rate_limit']['enabled'] = false;
TestLF::set('settings', $s);
$_SERVER['REMOTE_ADDR'] = '192.168.1.1';
$db->exec('INSERT OR REPLACE INTO _rate_limits (key, hits, window_start) VALUES (?, ?, ?)', ['global:192.168.1.1', 999, time()]);
$result = TestLF::call('rate_limit_check', 'my_handler');
assert($result === true);
echo "[PASS] Disabled rate limiting allows all requests\n";

$s['rate_limit']['enabled'] = true;
TestLF::set('settings', $s);

$db->exec('INSERT OR REPLACE INTO _rate_limits (key, hits, window_start) VALUES (?, ?, ?)', ['global:expired.ip', 50, time() - 2000]);
TestLF::call('rate_limit_cleanup');
$row = $db->one('SELECT * FROM _rate_limits WHERE key = ?', ['global:expired.ip']);
assert($row === null);
echo "[PASS] Cleanup removes expired entries\n";

$db->exec('INSERT OR REPLACE INTO _rate_limits (key, hits, window_start) VALUES (?, ?, ?)', ['global:active.ip', 3, time()]);
TestLF::call('rate_limit_cleanup');
$row = $db->one('SELECT * FROM _rate_limits WHERE key = ?', ['global:active.ip']);
assert($row !== null);
assert((int) $row->hits === 3);
echo "[PASS] Cleanup preserves active entries\n";

echo "\n=== All tests passed ===\n";
