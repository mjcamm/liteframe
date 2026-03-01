<?php

trait LFDerived
{
    protected static function functions_load(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/*.php') as $file) {
            require_once $file;
        }
    }

    protected static function apply_derived(string $type, object $entity): object
    {
        if (!isset(self::$derived[$type])) return $entity;

        foreach (self::$derived[$type] as $field => $functionName) {
            if (function_exists($functionName)) {
                $entity->$field = $functionName($entity);
            }
        }

        return $entity;
    }

    protected static function fire_effects(string $type, object $entity, ?object $original = null): void
    {
        if (!isset(self::$effects[$type])) return;

        foreach (self::$effects[$type] as $field => $functionName) {
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
}
