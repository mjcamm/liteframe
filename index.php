<?php

// Check dev_mode without loading the full framework
$_devMode = false;
$_settingsFile = __DIR__ . '/settings.yml';
if (file_exists($_settingsFile)) {
    foreach (file($_settingsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        if (preg_match('/^dev_mode\s*:\s*(.+)/', trim($_line), $_m)) {
            $_devMode = trim($_m[1]) === 'true';
            break;
        }
    }
}

error_reporting(E_ALL);
if (!$_devMode) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    require __DIR__ . '/dist/index.php';
    return;
}
ini_set('display_errors', '1');

// Serve static files (Nginx/Herd compatibility — Apache handles this via .htaccess)
$_staticUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$_scriptDir = dirname($_SERVER['SCRIPT_NAME']);
if ($_scriptDir !== '/' && $_scriptDir !== '\\') {
    $_staticUri = substr($_staticUri, strlen($_scriptDir)) ?: '/';
}
if ($_staticUri !== '/' && pathinfo($_staticUri, PATHINFO_EXTENSION)) {
    $_ext = strtolower(pathinfo($_staticUri, PATHINFO_EXTENSION));
    if (in_array($_ext, ['php', 'db', 'yml', 'env', 'htaccess']) && basename($_staticUri) !== 'build.php') {
        http_response_code(403);
        return;
    }
    $_staticFile = __DIR__ . '/frontend/build' . $_staticUri;
    if (file_exists($_staticFile) && !is_dir($_staticFile)) {
        $_mimeTypes = [
            'js' => 'application/javascript', 'css' => 'text/css', 'html' => 'text/html',
            'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff' => 'font/woff',
            'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'pdf' => 'application/pdf',
        ];
        header('Content-Type: ' . ($_mimeTypes[$_ext] ?? 'application/octet-stream'));
        readfile($_staticFile);
        return;
    }
}

// Autoload classes
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Load non-class source files
require_once __DIR__ . '/src/settings.php';
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
require_once __DIR__ . '/src/rate_limit.php';
require_once __DIR__ . '/src/api.php';

// Bootstrap
$db = new Database(__DIR__ . '/data.db');
$request = new Request();
_lf_settings_load(__DIR__ . '/settings.yml');

// Schema from types.yml
$TYPES = _lf_parse_types(__DIR__ . '/config/types.yml');
_lf_schema_apply($db, $TYPES);

// CORS
if (_lf_cors_headers()) return;

// Load hooks and user functions
_lf_hooks_load(__DIR__ . '/hooks');
_lf_functions_load(__DIR__ . '/functions');

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
_lf_cron_run();

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

// Auto-generated routes from $api() directives (added after routes.yml so routes.yml takes priority)
$apiRoutes = _lf_api_routes_from_types();

// Add built-in routes
$routes['auth_login'] = ['path' => '/api/auth/login', 'handler' => '_auth_login', 'method' => 'POST', 'auth' => 'false'];
$routes['auth_refresh'] = ['path' => '/api/auth/refresh', 'handler' => '_auth_refresh', 'method' => 'POST', 'auth' => 'false'];
$routes['auth_logout'] = ['path' => '/api/auth/logout', 'handler' => '_auth_logout', 'method' => 'POST', 'auth' => 'false'];
$routes['_lf_file_serve'] = ['path' => '/api/files/:id', 'handler' => '_lf_file_serve', 'method' => 'GET', 'auth' => 'false'];
$routes['_lf_cron_run'] = ['path' => '/api/cron', 'handler' => '_lf_cron_run', 'method' => 'GET', 'auth' => 'false'];

$router = new Router();
// routes.yml + built-in first (takes priority over auto-generated)
foreach ($routes as $name => $route) {
    $router->addRoute($name, $route);
}
// Auto-generated $api() routes (only matched if no routes.yml route matched first)
foreach ($apiRoutes as $name => $route) {
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

// Rate limiting
if (!_lf_rate_limit_check($matched_route['handler'])) {
    echo json_encode(error(429, 'Too many requests'));
    return;
}

// Authenticate request (reads JWT from Authorization header)
_lf_auth_authenticate_request();

// Dev facade: impersonate a user via ?facade=<user_id> (dev mode only — never compiled into dist/)
$_dev_facade_info = null;
if (setting('dev_mode', false) && setting('allow_facade', false)) {
    $facadeId = input('facade');
    if ($facadeId) {
        global $_current_user;
        $facadeUser = entity_load((int) $facadeId);
        if ($facadeUser && ($facadeUser->_type ?? null) === 'user') {
            $_current_user = $facadeUser;
            $_dev_facade_info = [
                'NOTICE' => 'FACADE ACTIVE — REQUEST IS BEING MADE AS ANOTHER USER',
                'facade_user' => ['id' => $facadeUser->id, 'email' => $facadeUser->email ?? null, 'role' => $facadeUser->role ?? null],
            ];
        }
    }
}

// Check route auth (blocks normally even with facade)
$authError = _lf_auth_check_route($matched_route);
if ($authError) {
    if ($_dev_facade_info) {
        $authError = ['_DEV' => $_dev_facade_info] + $authError;
    }
    echo json_encode($authError);
    return;
}

// Helper to prepend _DEV facade info to API responses
function _lf_dev_response($data): string {
    global $_dev_facade_info;
    if ($_dev_facade_info && is_array($data)) {
        $data = ['_DEV' => $_dev_facade_info] + $data;
    }
    return json_encode($data);
}

// Dispatch handler (all wrapped in exception handler)
$handler_name = $matched_route['handler'];
try {
    // Built-in file handler
    if ($handler_name === '_lf_file_serve') {
        _lf_file_serve((int) route_param('id'));
        return;
    }

    // Built-in cron handler
    if ($handler_name === '_lf_cron_run') {
        echo _lf_dev_response(_lf_cron_handle_run());
        return;
    }

    // Built-in auth handlers
    if (str_starts_with($handler_name, '_auth_')) {
        $result = match ($handler_name) {
            '_auth_login' => _lf_auth_handle_login(),
            '_auth_refresh' => _lf_auth_handle_refresh(),
            '_auth_logout' => _lf_auth_handle_logout(),
            default => error(404, 'Unknown auth handler'),
        };
        echo _lf_dev_response($result);
        return;
    }

    // Auto-generated $api() type handler
    if (isset($matched_route['_type'])) {
        echo _lf_dev_response(_lf_type_dispatch($matched_route));
        return;
    }

    // User-defined handler
    $handlerFile = __DIR__ . '/handlers/' . $handler_name . '.php';
    if (file_exists($handlerFile)) {
        $handler = require $handlerFile;
        echo _lf_dev_response($handler());
    } else {
        http_response_code(500);
        echo _lf_dev_response(['error' => "Handler not found: {$handler_name}"]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    $response = ['error' => 'Internal server error'];
    if (setting('dev_mode', false)) {
        $response['message'] = $e->getMessage();
        $response['file'] = $e->getFile() . ':' . $e->getLine();
    }
    echo _lf_dev_response($response);
}
