<?php

/**
 * Hook system.
 *
 * Hooks are auto-discovered from the hooks/ directory.
 * Each file is named after the entity type (e.g. hooks/article.php)
 * and returns an array of lifecycle functions.
 *
 * Available hooks:
 *   before_create($data)           → return modified $data, or error() to block
 *   after_create($entity)          → side effects after insert
 *   before_update($data, $original)→ return modified $data, or error() to block
 *   after_update($entity, $original)→ side effects after update
 *   before_delete($entity)         → return error() to block
 *   after_delete($entity)          → side effects after delete
 */

// Global hooks registry
$_hooks = [];

/**
 * Load all hook files from a directory.
 */
function _lf_hooks_load(string $dir): void
{
    global $_hooks;
    if (!is_dir($dir)) return;

    foreach (glob($dir . '/*.php') as $file) {
        $type = basename($file, '.php');
        $_hooks[$type] = require $file;
    }
}

/**
 * Fire a hook for a given type and event.
 * Returns the (possibly modified) data, or an error array if blocked.
 */
function hook_fire(string $type, string $event, mixed ...$args): mixed
{
    global $_hooks;

    if (!isset($_hooks[$type][$event])) {
        return $args[0] ?? null;
    }

    return ($_hooks[$type][$event])(...$args);
}

/**
 * Check if a hook exists for a type and event.
 */
function hook_exists(string $type, string $event): bool
{
    global $_hooks;
    return isset($_hooks[$type][$event]);
}
