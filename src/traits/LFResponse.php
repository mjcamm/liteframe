<?php

trait LFResponse
{
    public static function error(int $code, string $message): array
    {
        http_response_code($code);
        return ['error' => $message];
    }

    public static function validation_error(array $fields): array
    {
        http_response_code(422);
        return [
            'error' => 'Validation failed',
            'fields' => $fields,
        ];
    }
}
