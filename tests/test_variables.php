<?php

require_once __DIR__ . '/bootstrap.php';

echo "=== Variables Tests ===\n\n";

$db = new Database(':memory:');
TestLF::set('db', $db);
$db->exec('CREATE TABLE _variables (name TEXT PRIMARY KEY, value TEXT NOT NULL)');

$result = LF::variable_get('missing');
assert($result === null, 'Missing variable should return null');
echo "[PASS] Missing variable returns null\n";

$result = LF::variable_get('missing', 'fallback');
assert($result === 'fallback', 'Should return custom default');
echo "[PASS] Missing variable returns custom default\n";

LF::variable_set('site_name', 'My Site');
$result = LF::variable_get('site_name');
assert($result === 'My Site', 'Should return stored string');
echo "[PASS] Set and get string\n";

LF::variable_set('counter', 42);
$result = LF::variable_get('counter');
assert($result === 42, 'Should return stored integer');
echo "[PASS] Set and get integer\n";

LF::variable_set('maintenance', true);
$result = LF::variable_get('maintenance');
assert($result === true, 'Should return stored boolean');
echo "[PASS] Set and get boolean\n";

LF::variable_set('allowed_ips', ['127.0.0.1', '10.0.0.1']);
$result = LF::variable_get('allowed_ips');
assert($result === ['127.0.0.1', '10.0.0.1'], 'Should return stored array');
echo "[PASS] Set and get array\n";

LF::variable_set('smtp', ['host' => 'mail.example.com', 'port' => 587]);
$result = LF::variable_get('smtp');
assert($result['host'] === 'mail.example.com', 'Should return stored assoc array');
assert($result['port'] === 587, 'Should preserve integer in assoc array');
echo "[PASS] Set and get associative array\n";

LF::variable_set('site_name', 'New Name');
$result = LF::variable_get('site_name');
assert($result === 'New Name', 'Should return overwritten value');
echo "[PASS] Overwrite existing variable\n";

LF::variable_del('site_name');
$result = LF::variable_get('site_name');
assert($result === null, 'Deleted variable should return null');
echo "[PASS] Delete variable\n";

LF::variable_del('nonexistent');
echo "[PASS] Delete non-existent variable (no error)\n";

$result = LF::variable_get('counter');
assert($result === 42, 'Other variables should be unaffected');
echo "[PASS] Other variables unaffected by delete\n";

LF::variable_set('nullable', null);
$result = LF::variable_get('nullable');
assert($result === null, 'Should store and return null');
echo "[PASS] Set and get null value\n";

LF::variable_set('nullable', null);
$result = LF::variable_get('nullable', 'was_missing');
assert($result === null, 'Stored null should return null, not default');
echo "[PASS] Stored null distinguished from missing key\n";

echo "\n=== All tests passed ===\n";
