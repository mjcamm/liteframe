<?php

/**
 * Key-value variable storage.
 *
 * Simple persistent variables stored in the _variables table.
 * Values are JSON-encoded, so arrays, objects, booleans, numbers all work.
 *
 *   variable_set('site_name', 'My Site');
 *   variable_get('site_name');            // 'My Site'
 *   variable_get('missing', 'default');   // 'default'
 *   variable_del('site_name');
 */

function variable_get(string $name, mixed $default = null): mixed
{
    global $db;
    $row = $db->one("SELECT value FROM _variables WHERE name = ?", [$name]);
    if (!$row) return $default;
    return json_decode($row->value, true);
}

function variable_set(string $name, mixed $value): void
{
    global $db;
    $encoded = json_encode($value);
    $db->exec("INSERT OR REPLACE INTO _variables (name, value) VALUES (?, ?)", [$name, $encoded]);
}

function variable_del(string $name): void
{
    global $db;
    $db->exec("DELETE FROM _variables WHERE name = ?", [$name]);
}
