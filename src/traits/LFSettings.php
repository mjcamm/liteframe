<?php

trait LFSettings
{
    protected static function settings_load(string $file): void
    {
        if (!file_exists($file)) return;

        $currentSection = null;

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            $line = preg_replace('/\s+#.*$/', '', $line);

            // Nested key (indented under a section)
            if ($currentSection && preg_match('/^\s+/', $line)) {
                $trimmed = trim($line);
                if (!str_contains($trimmed, ':')) continue;
                [$key, $value] = explode(':', $trimmed, 2);
                self::$settings[$currentSection][trim($key)] = self::cast_setting(trim($value));
                continue;
            }

            $trimmed = trim($line);
            if (!str_contains($trimmed, ':')) continue;

            [$key, $value] = explode(':', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);

            // Section header (key with no value)
            if ($value === '') {
                $currentSection = $key;
                if (!isset(self::$settings[$currentSection])) {
                    self::$settings[$currentSection] = [];
                }
                continue;
            }

            $currentSection = null;
            self::$settings[$key] = self::cast_setting($value);
        }
    }

    private static function cast_setting(string $value): mixed
    {
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if (is_numeric($value)) return $value + 0;
        // Strip surrounding quotes
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        if (str_contains($key, '.')) {
            [$section, $subkey] = explode('.', $key, 2);
            return self::$settings[$section][$subkey] ?? $default;
        }

        return self::$settings[$key] ?? $default;
    }

    protected static function roles_load(string $file): void
    {
        if (!file_exists($file)) return;

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            $line = preg_replace('/\s+#.*$/', '', $line);
            $trimmed = trim($line);
            if (!str_contains($trimmed, ':')) continue;
            [$key, $value] = explode(':', $trimmed, 2);
            self::$roles[trim($key)] = trim($value);
        }
    }

    public static function role_default(): string
    {
        return self::$roles['default_role'] ?? 'user';
    }
}
