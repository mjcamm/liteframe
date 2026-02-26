<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/variables.php';

echo "=== Variables Tests ===\n\n";

$db = new Database(':memory:');
$db->exec('CREATE TABLE _variables (name TEXT PRIMARY KEY, value TEXT NOT NULL)');

// Test 1: Get missing variable returns default
$result = variable_get('missing');
assert($result === null, 'Missing variable should return null');
echo "[PASS] Missing variable returns null\n";

// Test 2: Get missing variable with custom default
$result = variable_get('missing', 'fallback');
assert($result === 'fallback', 'Should return custom default');
echo "[PASS] Missing variable returns custom default\n";

// Test 3: Set and get a string
variable_set('site_name', 'My Site');
$result = variable_get('site_name');
assert($result === 'My Site', 'Should return stored string');
echo "[PASS] Set and get string\n";

// Test 4: Set and get an integer
variable_set('counter', 42);
$result = variable_get('counter');
assert($result === 42, 'Should return stored integer');
echo "[PASS] Set and get integer\n";

// Test 5: Set and get a boolean
variable_set('maintenance', true);
$result = variable_get('maintenance');
assert($result === true, 'Should return stored boolean');
echo "[PASS] Set and get boolean\n";

// Test 6: Set and get an array
variable_set('allowed_ips', ['127.0.0.1', '10.0.0.1']);
$result = variable_get('allowed_ips');
assert($result === ['127.0.0.1', '10.0.0.1'], 'Should return stored array');
echo "[PASS] Set and get array\n";

// Test 7: Set and get an associative array
variable_set('smtp', ['host' => 'mail.example.com', 'port' => 587]);
$result = variable_get('smtp');
assert($result['host'] === 'mail.example.com', 'Should return stored assoc array');
assert($result['port'] === 587, 'Should preserve integer in assoc array');
echo "[PASS] Set and get associative array\n";

// Test 8: Overwrite existing variable
variable_set('site_name', 'New Name');
$result = variable_get('site_name');
assert($result === 'New Name', 'Should return overwritten value');
echo "[PASS] Overwrite existing variable\n";

// Test 9: Delete a variable
variable_del('site_name');
$result = variable_get('site_name');
assert($result === null, 'Deleted variable should return null');
echo "[PASS] Delete variable\n";

// Test 10: Delete non-existent variable (no error)
variable_del('nonexistent');
echo "[PASS] Delete non-existent variable (no error)\n";

// Test 11: Other variables unaffected by delete
$result = variable_get('counter');
assert($result === 42, 'Other variables should be unaffected');
echo "[PASS] Other variables unaffected by delete\n";

// Test 12: Set null value
variable_set('nullable', null);
$result = variable_get('nullable');
assert($result === null, 'Should store and return null');
echo "[PASS] Set and get null value\n";

// Test 13: Distinguish null value from missing key
variable_set('nullable', null);
$result = variable_get('nullable', 'was_missing');
// null stored via JSON becomes "null" string, decoded back to null
// So this should return null, not 'was_missing'
assert($result === null, 'Stored null should return null, not default');
echo "[PASS] Stored null distinguished from missing key\n";

echo "\n=== All tests passed ===\n";
