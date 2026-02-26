<?php

/**
 * Derived fields — virtual, computed on every load, never stored in DB.
 *
 * Declared in types.yml:   $derived(slug): slugify
 * The function receives the full entity. Declaration order matters —
 * each derived field can see the ones computed before it.
 *
 * $effect — fires after save when a watched field changes.
 *
 * User functions live in functions/ — plain PHP files, just write normal functions.
 */

$DERIVED = [];
$EFFECTS = [];

// --- Load user functions from directory ---

function functions_load(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*.php') as $file) {
        require_once $file;
    }
}

// --- Apply derived fields to a loaded entity ---

function apply_derived(string $type, object $entity): object
{
    global $DERIVED;
    if (!isset($DERIVED[$type])) return $entity;

    foreach ($DERIVED[$type] as $field => $functionName) {
        if (function_exists($functionName)) {
            $entity->$field = $functionName($entity);
        }
    }

    return $entity;
}

// --- Fire effects after save ---

function fire_effects(string $type, object $entity, ?object $original = null): void
{
    global $EFFECTS;
    if (!isset($EFFECTS[$type])) return;

    foreach ($EFFECTS[$type] as $field => $functionName) {
        $new_value = $entity->$field ?? null;
        $original_value = $original ? ($original->$field ?? null) : null;

        if ($original === null) {
            if ($new_value !== null && function_exists($functionName)) {
                $functionName($entity, null, $new_value);
            }
        } else {
            if ($original_value != $new_value && function_exists($functionName)) {
                $functionName($entity, $original_value, $new_value);
            }
        }
    }
}
