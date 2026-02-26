<?php

class Request
{
    public string $method;
    public string $uri;
    private array $input;
    private array $files;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $this->input = array_merge(
            $_GET,
            $_POST,
            json_decode(file_get_contents('php://input'), true) ?? []
        );
        $this->files = $_FILES ?? [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->input);
    }

    public function all(): array
    {
        return $this->input;
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $file;
    }

    public function hasFile(string $key): bool
    {
        return $this->file($key) !== null;
    }
}
