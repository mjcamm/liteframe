<?php

// Settings must load first — controls dev mode and everything else
require_once __DIR__ . '/src/settings.php';
settings_load(__DIR__ . '/settings.yml');

if (setting('dev_mode', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// Dev mode: load files directly (breakpoints work)
// Production mode: compile and run dist/cms.php
if (!setting('dev_mode', false)) {
    $distFile = __DIR__ . '/dist/index.php';
    $needsBuild = !file_exists($distFile);

    if (!$needsBuild) {
        $distTime = filemtime($distFile);
        $srcDirs = ['/src', '/config', '/handlers', '/hooks', '/functions'];
        foreach ($srcDirs as $dir) {
            $fullDir = __DIR__ . $dir;
            if (!is_dir($fullDir)) continue;
            // Check directory mtime (changes when files are added/deleted)
            if (filemtime($fullDir) > $distTime) {
                $needsBuild = true;
                break;
            }
            foreach (glob($fullDir . '/*') as $f) {
                if (filemtime($f) > $distTime) {
                    $needsBuild = true;
                    break 2;
                }
            }
        }
        if (!$needsBuild && file_exists(__DIR__ . '/settings.yml') && filemtime(__DIR__ . '/settings.yml') > $distTime) {
            $needsBuild = true;
        }
    }

    if ($needsBuild) {
        require_once __DIR__ . '/build.php';
        lightframe_build(__DIR__, __DIR__ . '/dist');
    }
    require $distFile;
    return;
}

// === Dev mode — load everything directly ===

// Autoload classes
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Load non-class source files
require_once __DIR__ . '/src/hooks.php';
require_once __DIR__ . '/src/derived.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/validation.php';
require_once __DIR__ . '/src/functions.php';
require_once __DIR__ . '/src/schema.php';
require_once __DIR__ . '/src/variables.php';
require_once __DIR__ . '/src/cron.php';
require_once __DIR__ . '/src/files.php';
require_once __DIR__ . '/src/cors.php';

// Bootstrap
$db = new Database(__DIR__ . '/data.db');
$request = new Request();

// Schema from types.yml
$TYPES = parse_types(__DIR__ . '/config/types.yml');
schema_apply($db, $TYPES);

// CORS
if (cors_headers()) return;

// Load hooks and user functions
hooks_load(__DIR__ . '/hooks');
functions_load(__DIR__ . '/functions');

// Cron — parse config and run due tasks
$cronFile = __DIR__ . '/config/cron.yml';
if (file_exists($cronFile)) {
    $current = null;
    foreach (file($cronFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#') continue;
        if ($line[0] !== ' ' && str_ends_with(trim($line), ':')) {
            $current = rtrim(trim($line), ':');
            $CRONS[$current] = [];
        } elseif ($current && str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === 'every') $value = (int) $value;
            $CRONS[$current][$key] = $value;
        }
    }
}
cron_run();

// Parse routes (paths auto-prefixed with /api)
$routes = [];
$routesFile = __DIR__ . '/config/routes.yml';
if (file_exists($routesFile)) {
    $current = null;
    foreach (file($routesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if ($line[0] !== ' ' && str_ends_with(trim($line), ':')) {
            $current = rtrim(trim($line), ':');
            $routes[$current] = [];
        } elseif ($current && str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === 'path') $value = '/api' . $value;
            $routes[$current][$key] = $value;
        }
    }
}

// Add built-in routes
$routes['auth_login'] = ['path' => '/api/auth/login', 'handler' => '_auth_login', 'method' => 'POST', 'auth' => 'false'];
$routes['auth_refresh'] = ['path' => '/api/auth/refresh', 'handler' => '_auth_refresh', 'method' => 'POST', 'auth' => 'false'];
$routes['auth_logout'] = ['path' => '/api/auth/logout', 'handler' => '_auth_logout', 'method' => 'POST', 'auth' => 'false'];
$routes['file_serve'] = ['path' => '/api/files/:id', 'handler' => '_file_serve', 'method' => 'GET', 'auth' => 'false'];
$routes['cron_run'] = ['path' => '/api/cron', 'handler' => '_cron_run', 'method' => 'GET', 'auth' => 'false'];

$router = new Router();
foreach ($routes as $name => $route) {
    $router->addRoute($name, $route);
}

// Dispatch
$uri = $request->uri;
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
if ($scriptDir !== '/' && $scriptDir !== '\\') {
    $uri = substr($uri, strlen($scriptDir)) ?: '/';
}

$matched_route = $router->match($uri, $request->method);

header('Content-Type: application/json');

if (!$matched_route) {
    // Not an API route — serve the SPA
    $spaFile = __DIR__ . '/frontend/build/index.html';
    if (file_exists($spaFile)) {
        header('Content-Type: text/html');
        readfile($spaFile);
        return;
    }
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    return;
}

// Authenticate request (reads JWT from Authorization header)
auth_authenticate_request();

// Check route auth
$authError = auth_check_route($matched_route);
if ($authError) {
    echo json_encode($authError);
    return;
}

// Dispatch handler (all wrapped in exception handler)
$handler_name = $matched_route['handler'];
try {
    // Built-in file handler
    if ($handler_name === '_file_serve') {
        file_serve((int) route_param('id'));
        return;
    }

    // Built-in cron handler
    if ($handler_name === '_cron_run') {
        echo json_encode(cron_handle_run());
        return;
    }

    // Built-in auth handlers
    if (str_starts_with($handler_name, '_auth_')) {
        $result = match ($handler_name) {
            '_auth_login' => auth_handle_login(),
            '_auth_refresh' => auth_handle_refresh(),
            '_auth_logout' => auth_handle_logout(),
            default => error(404, 'Unknown auth handler'),
        };
        echo json_encode($result);
        return;
    }

    // User-defined handler
    $handlerFile = __DIR__ . '/handlers/' . $handler_name . '.php';
    if (file_exists($handlerFile)) {
        $handler = require $handlerFile;
        echo json_encode($handler());
    } else {
        http_response_code(500);
        echo json_encode(['error' => "Handler not found: {$handler_name}"]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    $response = ['error' => 'Internal server error'];
    if (setting('dev_mode', false)) {
        $response['message'] = $e->getMessage();
        $response['file'] = $e->getFile() . ':' . $e->getLine();
    }
    echo json_encode($response);
}
