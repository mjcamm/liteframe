<?php

/**
 * Settings parser.
 *
 * Reads settings.yml — flat key: value pairs and one level of nesting.
 * Access with setting('key') or setting('section.key') for nested values.
 */

$_settings = [];

function settings_load(string $file): void
{
    global $_settings;
    if (!file_exists($file)) return;

    $currentSection = null;

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;

        // Nested key (indented under a section)
        if ($currentSection && preg_match('/^\s+/', $line)) {
            $trimmed = trim($line);
            if (!str_contains($trimmed, ':')) continue;
            [$key, $value] = explode(':', $trimmed, 2);
            $_settings[$currentSection][trim($key)] = cast_setting(trim($value));
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
            if (!isset($_settings[$currentSection])) {
                $_settings[$currentSection] = [];
            }
            continue;
        }

        $currentSection = null;
        $_settings[$key] = cast_setting($value);
    }
}

function cast_setting(string $value): mixed
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

/**
 * Read a setting. Dot notation for nested: setting('cors.origin')
 */
function setting(string $key, mixed $default = null): mixed
{
    global $_settings;

    if (str_contains($key, '.')) {
        [$section, $subkey] = explode('.', $key, 2);
        return $_settings[$section][$subkey] ?? $default;
    }

    return $_settings[$key] ?? $default;
}
