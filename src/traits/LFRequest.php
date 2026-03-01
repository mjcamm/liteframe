<?php

trait LFRequest
{
    public static function input(string $key, mixed $default = null): mixed
    {
        return self::$request->get($key, $default);
    }

    public static function input_exists(string $key): bool
    {
        return self::$request->has($key);
    }

    public static function input_all(): array
    {
        $data = self::$request->all();
        unset($data['facade']);
        return $data;
    }

    public static function input_file(string $key): ?array
    {
        return self::$request->file($key);
    }

    public static function route_param(string $key): mixed
    {
        return self::$matched_route['params'][$key] ?? null;
    }

    public static function paginate(int $defaultPerPage = 20): array
    {
        $page = max(1, (int) self::input('page', 1));
        $perPage = max(1, min(100, (int) self::input('per_page', $defaultPerPage)));
        return [$page, $perPage];
    }
}
