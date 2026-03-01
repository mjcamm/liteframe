<?php

trait LFVariables
{
    public static function variable_get(string $name, mixed $default = null): mixed
    {
        $row = self::$db->one("SELECT value FROM _variables WHERE name = ?", [$name]);
        if (!$row) return $default;
        return json_decode($row->value, true);
    }

    public static function variable_set(string $name, mixed $value): void
    {
        $encoded = json_encode($value);
        self::$db->exec("INSERT OR REPLACE INTO _variables (name, value) VALUES (?, ?)", [$name, $encoded]);
    }

    public static function variable_del(string $name): void
    {
        self::$db->exec("DELETE FROM _variables WHERE name = ?", [$name]);
    }
}
