<?php

trait LFCors
{
    protected static function cors_headers(): bool
    {
        header('Access-Control-Allow-Origin: ' . self::setting('cors.origin', '*'));
        header('Access-Control-Allow-Methods: ' . self::setting('cors.methods', 'GET, POST, PUT, DELETE, OPTIONS'));
        header('Access-Control-Allow-Headers: ' . self::setting('cors.headers', 'Content-Type, Authorization'));
        header('Access-Control-Max-Age: ' . self::setting('cors.max_age', 86400));

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            return true;
        }

        return false;
    }
}
