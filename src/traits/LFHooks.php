<?php

trait LFHooks
{
    protected static function hooks_load(string $dir): void
    {
        if (!is_dir($dir)) return;

        foreach (glob($dir . '/*.php') as $file) {
            $type = basename($file, '.php');
            self::$hooks[$type] = require $file;
        }
    }

    public static function hook_fire(string $type, string $event, mixed ...$args): mixed
    {
        if (!isset(self::$hooks[$type][$event])) {
            return $args[0] ?? null;
        }

        return (self::$hooks[$type][$event])(...$args);
    }

    public static function hook_exists(string $type, string $event): bool
    {
        return isset(self::$hooks[$type][$event]);
    }
}
