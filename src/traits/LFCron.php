<?php

trait LFCron
{
    protected static function cron_run(): void
    {
        if (empty(self::$crons)) return;

        $now = time();

        foreach (self::$crons as $name => $task) {
            $lastRun = self::variable_get("cron_{$name}_last_run", 0);
            $interval = (int) $task['every'] * 60;

            if (($now - $lastRun) >= $interval) {
                self::cron_run_task($name, $task);
            }
        }
    }

    protected static function cron_run_task(string $name, array $task): bool
    {
        $fn = $task['function'];

        if (!function_exists($fn)) {
            self::variable_set("cron_{$name}_error", "Function not found: {$fn}");
            return false;
        }

        try {
            $fn();
            self::variable_set("cron_{$name}_last_run", time());
            self::variable_del("cron_{$name}_error");
            return true;
        } catch (\Throwable $e) {
            self::variable_set("cron_{$name}_error", $e->getMessage());
            self::variable_set("cron_{$name}_last_run", time());
            return false;
        }
    }

    protected static function cron_handle_run(): array
    {
        $key = self::setting('cron_key', '');
        if ($key === '') {
            http_response_code(404);
            return ['error' => 'Not found'];
        }

        $provided = $_GET['key'] ?? '';
        if (!hash_equals($key, $provided)) {
            http_response_code(403);
            return ['error' => 'Invalid cron key'];
        }

        return ['results' => self::cron_run_all()];
    }

    protected static function cron_run_all(): array
    {
        $results = [];

        foreach (self::$crons as $name => $task) {
            $ok = self::cron_run_task($name, $task);
            if ($ok) {
                $results[$name] = 'OK';
            } else {
                $error = self::variable_get("cron_{$name}_error", 'Unknown error');
                $results[$name] = "FAILED: {$error}";
            }
        }

        return $results;
    }
}
