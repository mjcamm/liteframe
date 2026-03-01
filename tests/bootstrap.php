<?php

/**
 * Test bootstrap — loads LF and provides TestLF subclass for accessing
 * protected/private methods in tests.
 */

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Request.php';
require_once __DIR__ . '/../src/Router.php';
require_once __DIR__ . '/../src/LF.php';
require_once __DIR__ . '/../src/EntityQuery.php';

class TestLF extends LF {
    /**
     * Call any protected/private static method on LF.
     */
    public static function call(string $method, mixed ...$args): mixed
    {
        return self::$method(...$args);
    }

    /**
     * Set any protected/private static property on LF.
     */
    public static function set(string $prop, mixed $value): void
    {
        self::$$prop = $value;
    }

    /**
     * Get any protected/private static property from LF.
     */
    public static function get(string $prop): mixed
    {
        return self::$$prop;
    }
}
