<?php

trait LFBootstrap
{
    public static function bootstrap(string $dir): void
    {
        self::$project_dir = $dir;
        self::$db = new Database($dir . '/data.db');
        self::$request = new Request();
        self::settings_load($dir . '/settings.yml');
        self::roles_load($dir . '/config/roles.yml');

        // Schema from types.yml
        $typesFile = $dir . '/config/types.yml';
        if (file_exists($typesFile)) {
            self::$types = self::parse_types($typesFile);
            self::schema_apply(self::$db, self::$types);
        }

        // Load hooks and user functions
        self::hooks_load($dir . '/hooks');
        self::functions_load($dir . '/functions');

        // Cron — parse config and run due tasks
        $cronFile = $dir . '/config/cron.yml';
        if (file_exists($cronFile)) {
            $current = null;
            foreach (file($cronFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if ($line[0] === '#') continue;
                $line = preg_replace('/\s+#.*$/', '', $line);
                if ($line[0] !== ' ' && str_ends_with(trim($line), ':')) {
                    $current = rtrim(trim($line), ':');
                    self::$crons[$current] = [];
                } elseif ($current && str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if ($key === 'every') $value = (int) $value;
                    self::$crons[$current][$key] = $value;
                }
            }
        }
    }

    public static function dispatch(): void
    {
        // CORS
        if (self::cors_headers()) return;

        // Run due cron tasks
        self::cron_run();

        // Parse routes
        $routes = [];
        $dir = defined('LITEFRAME_PROJECT_DIR') ? LITEFRAME_PROJECT_DIR : self::$project_dir;
        $routesFile = $dir . '/config/routes.yml';
        if (file_exists($routesFile)) {
            $current = null;
            foreach (file($routesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), '#')) continue;
                $line = preg_replace('/\s+#.*$/', '', $line);
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

        // Auto-generated routes from $api() directives
        $apiRoutes = self::api_routes_from_types();

        // Add built-in routes
        $routes['auth_login'] = ['path' => '/api/auth/login', 'handler' => '_auth_login', 'method' => 'POST', 'auth' => 'public'];
        $routes['auth_refresh'] = ['path' => '/api/auth/refresh', 'handler' => '_auth_refresh', 'method' => 'POST', 'auth' => 'public'];
        $routes['auth_logout'] = ['path' => '/api/auth/logout', 'handler' => '_auth_logout', 'method' => 'POST', 'auth' => 'public'];
        $routes['_lf_file_serve'] = ['path' => '/api/files/:id', 'handler' => '_lf_file_serve', 'method' => 'GET', 'auth' => 'public'];
        $routes['_lf_cron_run'] = ['path' => '/api/cron', 'handler' => '_lf_cron_run', 'method' => 'GET', 'auth' => 'public'];

        $router = new Router();
        foreach ($routes as $name => $route) {
            $router->addRoute($name, $route);
        }
        foreach ($apiRoutes as $name => $route) {
            $router->addRoute($name, $route);
        }

        // Dispatch
        $uri = self::$request->uri;
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        if ($scriptDir !== '/' && $scriptDir !== '\\') {
            $uri = substr($uri, strlen($scriptDir)) ?: '/';
        }

        self::$matched_route = $router->match($uri, self::$request->method);

        header('Content-Type: application/json');

        if (!self::$matched_route) {
            // Not an API route — serve the SPA
            $spaFile = $dir . '/frontend/build/index.html';
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
        if (!self::rate_limit_check(self::$matched_route['handler'])) {
            echo json_encode(self::error(429, 'Too many requests'));
            return;
        }

        // Authenticate request
        self::auth_authenticate_request();

        // Dev facade
        $_dev_facade_info = null;
        if (self::setting('dev_mode', false) && self::setting('allow_facade', false)) {
            $facadeId = self::input('facade');
            if ($facadeId) {
                $facadeUser = self::load((int) $facadeId);
                if ($facadeUser && ($facadeUser->_type ?? null) === 'user') {
                    self::$current_user = $facadeUser;
                    $_dev_facade_info = [
                        'NOTICE' => 'FACADE ACTIVE — REQUEST IS BEING MADE AS ANOTHER USER',
                        'facade_user' => ['id' => $facadeUser->id, 'email' => $facadeUser->email ?? null, 'role' => $facadeUser->role ?? null],
                    ];
                }
            }
        }

        // Check route auth
        $authError = self::auth_check_route(self::$matched_route);
        if ($authError) {
            if ($_dev_facade_info) {
                $authError = ['_DEV' => $_dev_facade_info] + $authError;
            }
            echo json_encode($authError);
            return;
        }

        // Helper to prepend _DEV facade info
        $devResponse = function($data) use ($_dev_facade_info): string {
            if ($_dev_facade_info && is_array($data)) {
                $data = ['_DEV' => $_dev_facade_info] + $data;
            }
            return json_encode($data);
        };

        // Dispatch handler
        $handler_name = self::$matched_route['handler'];
        try {
            if ($handler_name === '_lf_file_serve') {
                self::file_serve((int) self::route_param('id'));
                return;
            }

            if ($handler_name === '_lf_cron_run') {
                echo $devResponse(self::cron_handle_run());
                return;
            }

            if (str_starts_with($handler_name, '_auth_')) {
                $result = match ($handler_name) {
                    '_auth_login' => self::auth_handle_login(),
                    '_auth_refresh' => self::auth_handle_refresh(),
                    '_auth_logout' => self::auth_handle_logout(),
                    default => self::error(404, 'Unknown auth handler'),
                };
                echo $devResponse($result);
                return;
            }

            if (isset(self::$matched_route['_type'])) {
                echo $devResponse(self::type_dispatch(self::$matched_route));
                return;
            }

            // User-defined handler
            $handlerFile = $dir . '/handlers/' . $handler_name . '.php';
            if (file_exists($handlerFile)) {
                $handler = require $handlerFile;
                echo $devResponse($handler());
            } else {
                http_response_code(500);
                echo $devResponse(['error' => "Handler not found: {$handler_name}"]);
            }
        } catch (\Throwable $e) {
            http_response_code(500);
            $response = ['error' => 'Internal server error'];
            if (self::setting('dev_mode', false)) {
                $response['message'] = $e->getMessage();
                $response['file'] = $e->getFile() . ':' . $e->getLine();
            }
            echo $devResponse($response);
        }
    }

    /**
     * Initialize LF for testing or custom bootstrap.
     * Accepts an array of properties to set directly.
     */
    public static function init(array $props): void
    {
        foreach ($props as $key => $value) {
            self::$$key = $value;
        }
    }
}
