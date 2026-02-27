<?php

/**
 * Poor man's cron — task scheduling via variables table.
 *
 * Tasks defined in config/cron.yml:
 *   cleanup_tokens:
 *     function: cleanup_expired_tokens
 *     every: 60
 *
 * Checked on every web request (near-zero overhead when nothing is due).
 * Also runnable via `php liteframe cron` for manual/crontab use.
 */

$CRONS = [];

/**
 * Check and run any due cron tasks. Called every request.
 * Returns immediately if no tasks are configured.
 */
function _lf_cron_run(): void
{
    global $CRONS;
    if (empty($CRONS)) return;

    $now = time();

    foreach ($CRONS as $name => $task) {
        $lastRun = variable_get("cron_{$name}_last_run", 0);
        $interval = (int) $task['every'] * 60;

        if (($now - $lastRun) >= $interval) {
            _lf_cron_run_task($name, $task);
        }
    }
}

/**
 * Execute a single cron task. Updates last_run, tracks errors.
 */
function _lf_cron_run_task(string $name, array $task): bool
{
    $fn = $task['function'];

    if (!function_exists($fn)) {
        variable_set("cron_{$name}_error", "Function not found: {$fn}");
        return false;
    }

    try {
        $fn();
        variable_set("cron_{$name}_last_run", time());
        variable_del("cron_{$name}_error");
        return true;
    } catch (\Throwable $e) {
        variable_set("cron_{$name}_error", $e->getMessage());
        variable_set("cron_{$name}_last_run", time());
        return false;
    }
}

/**
 * Built-in HTTP handler for /api/cron?key=...
 * Checks cron_key from settings, runs all tasks, returns JSON.
 */
function _lf_cron_handle_run(): array
{
    $key = setting('cron_key', '');
    if ($key === '') {
        http_response_code(404);
        return ['error' => 'Not found'];
    }

    $provided = $_GET['key'] ?? '';
    if (!hash_equals($key, $provided)) {
        http_response_code(403);
        return ['error' => 'Invalid cron key'];
    }

    return ['results' => _lf_cron_run_all()];
}

/**
 * Run ALL tasks ignoring timing. For CLI use.
 * Returns ['task_name' => 'OK'|'FAILED: reason'].
 */
function _lf_cron_run_all(): array
{
    global $CRONS;
    $results = [];

    foreach ($CRONS as $name => $task) {
        $ok = _lf_cron_run_task($name, $task);
        if ($ok) {
            $results[$name] = 'OK';
        } else {
            $error = variable_get("cron_{$name}_error", 'Unknown error');
            $results[$name] = "FAILED: {$error}";
        }
    }

    return $results;
}
