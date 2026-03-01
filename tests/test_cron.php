<?php

require_once __DIR__ . '/bootstrap.php';

echo "=== Cron Tests ===\n\n";

$db = new Database(':memory:');
TestLF::set('db', $db);
$db->exec('CREATE TABLE _variables (name TEXT PRIMARY KEY, value TEXT NOT NULL)');

function cron_test_increment(): void
{
    $count = LF::variable_get('cron_test_count', 0);
    LF::variable_set('cron_test_count', $count + 1);
}

function cron_test_fail(): void
{
    throw new \RuntimeException('Something went wrong');
}

TestLF::set('crons', []);
TestLF::call('cron_run');
echo "[PASS] cron_run with empty config does nothing\n";

TestLF::set('crons', ['test_inc' => ['function' => 'cron_test_increment', 'every' => 5]]);
$ok = TestLF::call('cron_run_task', 'test_inc', ['function' => 'cron_test_increment', 'every' => 5]);
assert($ok === true);
$count = LF::variable_get('cron_test_count');
assert($count === 1);
$lastRun = LF::variable_get('cron_test_inc_last_run');
assert($lastRun !== null && $lastRun > 0);
echo "[PASS] cron_run_task executes function and sets last_run\n";

LF::variable_set('cron_test_inc_error', 'old error');
TestLF::call('cron_run_task', 'test_inc', ['function' => 'cron_test_increment', 'every' => 5]);
$error = LF::variable_get('cron_test_inc_error');
assert($error === null);
echo "[PASS] cron_run_task clears error on success\n";

TestLF::set('crons', array_merge(TestLF::get('crons'), ['test_fail' => ['function' => 'cron_test_fail', 'every' => 5]]));
$ok = TestLF::call('cron_run_task', 'test_fail', ['function' => 'cron_test_fail', 'every' => 5]);
assert($ok === false);
$error = LF::variable_get('cron_test_fail_error');
assert($error === 'Something went wrong');
echo "[PASS] cron_run_task catches exception and stores error\n";

$ok = TestLF::call('cron_run_task', 'test_missing', ['function' => 'nonexistent_cron_function', 'every' => 5]);
assert($ok === false);
$error = LF::variable_get('cron_test_missing_error');
assert(str_contains($error, 'Function not found'));
echo "[PASS] cron_run_task with missing function stores error\n";

LF::variable_set('cron_test_count', 0);
TestLF::set('crons', ['test_inc' => ['function' => 'cron_test_increment', 'every' => 5]]);
LF::variable_set('cron_test_inc_last_run', time());
TestLF::call('cron_run');
$count = LF::variable_get('cron_test_count');
assert($count === 0);
echo "[PASS] cron_run skips tasks not yet due\n";

LF::variable_set('cron_test_count', 0);
LF::variable_set('cron_test_inc_last_run', time() - 400);
TestLF::call('cron_run');
$count = LF::variable_get('cron_test_count');
assert($count === 1);
echo "[PASS] cron_run executes tasks that are due\n";

LF::variable_set('cron_test_count', 0);
LF::variable_del('cron_test_inc_last_run');
TestLF::call('cron_run');
$count = LF::variable_get('cron_test_count');
assert($count === 1);
echo "[PASS] cron_run executes tasks that have never run\n";

LF::variable_set('cron_test_count', 0);
TestLF::set('crons', [
    'test_inc' => ['function' => 'cron_test_increment', 'every' => 9999],
    'test_fail' => ['function' => 'cron_test_fail', 'every' => 9999],
]);
LF::variable_set('cron_test_inc_last_run', time());
$results = TestLF::call('cron_run_all');
assert($results['test_inc'] === 'OK');
assert(str_starts_with($results['test_fail'], 'FAILED:'));
$count = LF::variable_get('cron_test_count');
assert($count === 1);
echo "[PASS] cron_run_all runs all tasks ignoring timing\n";

TestLF::set('crons', []);
$results = TestLF::call('cron_run_all');
assert($results === []);
echo "[PASS] cron_run_all with empty config returns empty array\n";

echo "\n=== All tests passed ===\n";
