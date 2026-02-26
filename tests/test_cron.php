<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/variables.php';
require_once __DIR__ . '/../src/cron.php';

echo "=== Cron Tests ===\n\n";

$db = new Database(':memory:');
$db->exec('CREATE TABLE _variables (name TEXT PRIMARY KEY, value TEXT NOT NULL)');

// Helper: define a simple cron function
function cron_test_increment(): void
{
    $count = variable_get('cron_test_count', 0);
    variable_set('cron_test_count', $count + 1);
}

function cron_test_fail(): void
{
    throw new \RuntimeException('Something went wrong');
}

// Test 1: _lf_cron_run with empty $CRONS does nothing
global $CRONS;
$CRONS = [];
_lf_cron_run();
echo "[PASS] _lf_cron_run with empty config does nothing\n";

// Test 2: _lf_cron_run_task executes function and sets last_run
$CRONS = ['test_inc' => ['function' => 'cron_test_increment', 'every' => 5]];
$ok = _lf_cron_run_task('test_inc', $CRONS['test_inc']);
assert($ok === true, '_lf_cron_run_task should return true on success');
$count = variable_get('cron_test_count');
assert($count === 1, 'Function should have been called');
$lastRun = variable_get('cron_test_inc_last_run');
assert($lastRun !== null && $lastRun > 0, 'last_run should be set');
echo "[PASS] _lf_cron_run_task executes function and sets last_run\n";

// Test 3: _lf_cron_run_task clears previous error on success
variable_set('cron_test_inc_error', 'old error');
_lf_cron_run_task('test_inc', $CRONS['test_inc']);
$error = variable_get('cron_test_inc_error');
assert($error === null, 'Error should be cleared on success');
echo "[PASS] _lf_cron_run_task clears error on success\n";

// Test 4: _lf_cron_run_task catches exception and stores error
$CRONS['test_fail'] = ['function' => 'cron_test_fail', 'every' => 5];
$ok = _lf_cron_run_task('test_fail', $CRONS['test_fail']);
assert($ok === false, 'Should return false on failure');
$error = variable_get('cron_test_fail_error');
assert($error === 'Something went wrong', 'Error message should be stored');
echo "[PASS] _lf_cron_run_task catches exception and stores error\n";

// Test 5: _lf_cron_run_task with missing function stores error
$CRONS['test_missing'] = ['function' => 'nonexistent_cron_function', 'every' => 5];
$ok = _lf_cron_run_task('test_missing', $CRONS['test_missing']);
assert($ok === false, 'Should return false for missing function');
$error = variable_get('cron_test_missing_error');
assert(str_contains($error, 'Function not found'), 'Should store function-not-found error');
echo "[PASS] _lf_cron_run_task with missing function stores error\n";

// Test 6: _lf_cron_run skips tasks that are not yet due
variable_set('cron_test_count', 0);
$CRONS = ['test_inc' => ['function' => 'cron_test_increment', 'every' => 5]];
variable_set('cron_test_inc_last_run', time()); // just ran
_lf_cron_run();
$count = variable_get('cron_test_count');
assert($count === 0, 'Task should not run when not due');
echo "[PASS] _lf_cron_run skips tasks not yet due\n";

// Test 7: _lf_cron_run executes tasks that are due
variable_set('cron_test_count', 0);
variable_set('cron_test_inc_last_run', time() - 400); // 400s ago, interval is 300s (5 min)
_lf_cron_run();
$count = variable_get('cron_test_count');
assert($count === 1, 'Task should run when due');
echo "[PASS] _lf_cron_run executes tasks that are due\n";

// Test 8: _lf_cron_run executes tasks that have never run (last_run = 0)
variable_set('cron_test_count', 0);
variable_del('cron_test_inc_last_run');
_lf_cron_run();
$count = variable_get('cron_test_count');
assert($count === 1, 'Task should run when never run before');
echo "[PASS] _lf_cron_run executes tasks that have never run\n";

// Test 9: _lf_cron_run_all runs all tasks ignoring timing
variable_set('cron_test_count', 0);
$CRONS = [
    'test_inc' => ['function' => 'cron_test_increment', 'every' => 9999],
    'test_fail' => ['function' => 'cron_test_fail', 'every' => 9999],
];
variable_set('cron_test_inc_last_run', time()); // just ran — should still run
$results = _lf_cron_run_all();
assert($results['test_inc'] === 'OK', 'Successful task should be OK');
assert(str_starts_with($results['test_fail'], 'FAILED:'), 'Failed task should start with FAILED:');
$count = variable_get('cron_test_count');
assert($count === 1, 'Successful task should have run');
echo "[PASS] _lf_cron_run_all runs all tasks ignoring timing\n";

// Test 10: _lf_cron_run_all with empty config returns empty array
$CRONS = [];
$results = _lf_cron_run_all();
assert($results === [], 'Empty config should return empty results');
echo "[PASS] _lf_cron_run_all with empty config returns empty array\n";

echo "\n=== All tests passed ===\n";
