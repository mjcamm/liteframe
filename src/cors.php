<?php

/**
 * CORS headers.
 *
 * Reads CORS settings from settings.yml.
 * Handles OPTIONS preflight requests automatically.
 */

/**
 * Send CORS headers. Returns true if this is a preflight request (caller should exit).
 */
function _lf_cors_headers(): bool
{
    header('Access-Control-Allow-Origin: ' . setting('cors.origin', '*'));
    header('Access-Control-Allow-Methods: ' . setting('cors.methods', 'GET, POST, PUT, DELETE, OPTIONS'));
    header('Access-Control-Allow-Headers: ' . setting('cors.headers', 'Content-Type, Authorization'));
    header('Access-Control-Max-Age: ' . setting('cors.max_age', 86400));

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        return true;
    }

    return false;
}
