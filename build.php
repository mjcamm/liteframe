<?php

/**
 * Compiler: reads src/, config/, handlers/ and produces a single dist/index.php
 *
 * Can be called standalone (php build.php) or included by index.php on every request.
 */

function liteframe_build(string $projectDir, string $distDir): string
{
    $output = [];

    $output[] = '<?php';
    $output[] = '// === COMPILED BY LITEFRAME ===';
    $output[] = '// Generated: ' . date('Y-m-d H:i:s');
    $output[] = '';

    // --- Inline all src/ files ---
    // Order matters: Database first, then EntityQuery, then functions, then Router/Request
    // schema.php included for runtime _lf_schema_sync()
    $srcOrder = ['Database.php', 'EntityQuery.php', 'Request.php', 'Router.php', 'hooks.php', 'derived.php', 'settings.php', 'auth.php', 'validation.php', 'variables.php', 'cron.php', 'functions.php', 'schema.php', 'files.php', 'cors.php', 'rate_limit.php'];
    $output[] = '// === FRAMEWORK ===';
    foreach ($srcOrder as $filename) {
        $file = $projectDir . '/src/' . $filename;
        if (file_exists($file)) {
            $source = file_get_contents($file);
            $source = preg_replace('/^<\?php\s*/', '', $source);
            $output[] = '// --- ' . $filename . ' ---';
            $output[] = $source;
        }
    }

    // --- Parse and compile routes.yml ---
    $routesFile = $projectDir . '/config/routes.yml';
    $routes = [];
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
    $output[] = '// === COMPILED ROUTES ===';
    $output[] = '$ROUTES = ' . var_export($routes, true) . ';';
    $output[] = '';

    // --- Inline handler files ---
    $output[] = '// === COMPILED HANDLERS ===';
    $output[] = '$HANDLERS = [];';
    $handlersDir = $projectDir . '/handlers';
    if (is_dir($handlersDir)) {
        foreach (glob($handlersDir . '/*.php') as $file) {
            $name = basename($file, '.php');
            $source = file_get_contents($file);
            $source = preg_replace('/^<\?php\s*/', '', $source);
            $source = preg_replace('/^return\s+/m', '$HANDLERS[\'' . $name . '\'] = ', $source, 1);
            $output[] = $source;
        }
    }
    $output[] = '';

    // --- Parse types.yml and generate schema at build time ---
    require_once $projectDir . '/src/schema.php';
    $typesFile = $projectDir . '/config/types.yml';
    $types = file_exists($typesFile) ? _lf_parse_types($typesFile) : [];

    // Compile types config into output
    $output[] = '// === COMPILED TYPES ===';
    $output[] = '$TYPES = ' . var_export($types, true) . ';';
    $output[] = '';

    // Compile derived and effects config (set by _lf_parse_types)
    global $DERIVED, $EFFECTS;
    $output[] = '// === COMPILED DERIVED & EFFECTS ===';
    $output[] = '$DERIVED = ' . var_export($DERIVED ?? [], true) . ';';
    $output[] = '$EFFECTS = ' . var_export($EFFECTS ?? [], true) . ';';
    $output[] = '';

    // --- Parse and compile cron.yml ---
    $cronFile = $projectDir . '/config/cron.yml';
    $crons = [];
    if (file_exists($cronFile)) {
        $current = null;
        foreach (file($cronFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line[0] === '#') continue;
            if ($line[0] !== ' ' && str_ends_with(trim($line), ':')) {
                $current = rtrim(trim($line), ':');
                $crons[$current] = [];
            } elseif ($current && str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if ($key === 'every') $value = (int) $value;
                $crons[$current][$key] = $value;
            }
        }
    }
    $output[] = '// === COMPILED CRONS ===';
    $output[] = '$CRONS = ' . var_export($crons, true) . ';';
    $output[] = '';

    // --- Inline user function files ---
    $output[] = '// === COMPILED FUNCTIONS ===';
    $functionsDir = $projectDir . '/functions';
    if (is_dir($functionsDir)) {
        foreach (glob($functionsDir . '/*.php') as $file) {
            $name = basename($file, '.php');
            $source = file_get_contents($file);
            $source = preg_replace('/^<\?php\s*/', '', $source);
            $output[] = '// --- functions/' . $name . '.php ---';
            $output[] = $source;
        }
    }
    $output[] = '';

    // --- Inline hook files ---
    $output[] = '// === COMPILED HOOKS ===';
    $hooksDir = $projectDir . '/hooks';
    if (is_dir($hooksDir)) {
        foreach (glob($hooksDir . '/*.php') as $file) {
            $type = basename($file, '.php');
            $source = file_get_contents($file);
            $source = preg_replace('/^<\?php\s*/', '', $source);
            $source = preg_replace('/^return\s+/m', '$_hooks[\'' . $type . '\'] = ', $source, 1);
            $output[] = $source;
        }
    }
    $output[] = '';

    // --- Static file serving (Nginx/Herd compatibility) ---
    $output[] = '// Serve static files (Nginx/Herd compatibility — Apache handles this via .htaccess)';
    $output[] = '$_staticUri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);';
    $output[] = '$_scriptDir = dirname($_SERVER["SCRIPT_NAME"]);';
    $output[] = 'if ($_scriptDir !== "/" && $_scriptDir !== "\\\\") {';
    $output[] = '    $_staticUri = substr($_staticUri, strlen($_scriptDir)) ?: "/";';
    $output[] = '}';
    $output[] = 'if ($_staticUri !== "/" && pathinfo($_staticUri, PATHINFO_EXTENSION)) {';
    $output[] = '    $_ext = strtolower(pathinfo($_staticUri, PATHINFO_EXTENSION));';
    $output[] = '    if (in_array($_ext, ["php", "db", "yml", "env", "htaccess"])) {';
    $output[] = '        http_response_code(403);';
    $output[] = '        return;';
    $output[] = '    }';
    $output[] = '    $_staticFile = __DIR__ . $_staticUri;';
    $output[] = '    if (file_exists($_staticFile) && !is_dir($_staticFile)) {';
    $output[] = '        $_mimeTypes = [';
    $output[] = '            "js" => "application/javascript", "css" => "text/css", "html" => "text/html",';
    $output[] = '            "json" => "application/json", "svg" => "image/svg+xml", "png" => "image/png",';
    $output[] = '            "jpg" => "image/jpeg", "jpeg" => "image/jpeg", "gif" => "image/gif",';
    $output[] = '            "webp" => "image/webp", "ico" => "image/x-icon", "woff" => "font/woff",';
    $output[] = '            "woff2" => "font/woff2", "ttf" => "font/ttf", "pdf" => "application/pdf",';
    $output[] = '        ];';
    $output[] = '        header("Content-Type: " . ($_mimeTypes[$_ext] ?? "application/octet-stream"));';
    $output[] = '        readfile($_staticFile);';
    $output[] = '        return;';
    $output[] = '    }';
    $output[] = '}';
    $output[] = '';

    // --- Bootstrap ---
    $output[] = '// === BOOTSTRAP ===';
    $output[] = "error_reporting(E_ALL);";
    $output[] = "ini_set('display_errors', '0');";
    $output[] = "ini_set('log_errors', '1');";
    $output[] = "define('LITEFRAME_PROJECT_DIR', __DIR__);";
    $output[] = '// Database — find existing DB or choose a secure writable location';
    $output[] = '$_dbDir = null;';
    $output[] = '$_docRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__;';
    $output[] = '$_dbLocations = [';
    $output[] = '    dirname($_docRoot),';
    $output[] = '    dirname($_docRoot) . "/private_html",';
    $output[] = '    __DIR__ . "/.data",';
    $output[] = '];';
    $output[] = '// First pass: find an existing database';
    $output[] = 'foreach ($_dbLocations as $_loc) {';
    $output[] = '    if (file_exists($_loc . "/data.db")) {';
    $output[] = '        $_dbDir = $_loc;';
    $output[] = '        break;';
    $output[] = '    }';
    $output[] = '}';
    $output[] = '// Second pass (first run): find the best writable location';
    $output[] = 'if (!$_dbDir) {';
    $output[] = '    foreach ($_dbLocations as $_loc) {';
    $output[] = '        if ($_loc === __DIR__ . "/.data") continue;';
    $output[] = '        if (is_dir($_loc) && is_writable($_loc)) {';
    $output[] = '            $_dbDir = $_loc;';
    $output[] = '            break;';
    $output[] = '        }';
    $output[] = '    }';
    $output[] = '}';
    $output[] = '// Fallback: .data directory inside web root (protected by .htaccess)';
    $output[] = 'if (!$_dbDir) {';
    $output[] = '    $_dataDir = __DIR__ . "/.data";';
    $output[] = '    if (!is_dir($_dataDir)) mkdir($_dataDir, 0755, true);';
    $output[] = '    file_put_contents($_dataDir . "/.htaccess", "Deny from all\n");';
    $output[] = '    $_dbDir = $_dataDir;';
    $output[] = '}';
    $output[] = '$db = new Database($_dbDir . "/data.db");';
    $output[] = 'define("LITEFRAME_DB_DIR", $_dbDir);';
    $output[] = '$request = new Request();';
    $output[] = '';
    $output[] = '// Schema — sync from types config';
    $output[] = '_lf_schema_sync($db, $TYPES);';
    $output[] = '';

    // --- Auto-setup: create .htaccess and robots.txt on first run ---
    // Placed after bootstrap so data.db and schema are created before redirect
    $output[] = '// === AUTO-SETUP ===';
    $output[] = 'if (!file_exists(__DIR__ . "/.htaccess")) {';
    $output[] = '    $written = file_put_contents(__DIR__ . "/.htaccess", <<<\'HTACCESS\'';
    $output[] = 'RewriteEngine On';
    $output[] = '';
    $output[] = 'RewriteCond %{HTTP:Authorization} ^(.+)$';
    $output[] = 'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]';
    $output[] = '';
    $output[] = 'RewriteRule \.db$ - [F,L]';
    $output[] = 'RewriteRule \.yml$ - [F,L]';
    $output[] = 'RewriteRule ^files/protected/ - [F,L]';
    $output[] = 'RewriteRule ^files/.*\.ph(p[345s]?|ar|t|tml)$ - [F,L]';
    $output[] = 'RewriteRule ^\.data/ - [F,L]';
    $output[] = 'RewriteRule ^(src|config|handlers|hooks|functions|tests|dist|build\.php|info\.php) - [F,L]';
    $output[] = '';
    $output[] = 'RewriteCond %{REQUEST_FILENAME} -f';
    $output[] = 'RewriteRule ^ - [L]';
    $output[] = '';
    $output[] = 'RewriteRule ^ index.php [L]';
    $output[] = 'HTACCESS);';
    $output[] = '    if ($written === false) { http_response_code(500); echo "Setup failed: could not create .htaccess"; exit; }';
    $output[] = '    if (!file_exists(__DIR__ . "/robots.txt")) {';
    $output[] = '        file_put_contents(__DIR__ . "/robots.txt", <<<\'ROBOTS\'';
    $output[] = 'User-agent: *';
    $output[] = 'Allow: /';
    $output[] = 'Disallow: /api/';
    $output[] = 'ROBOTS);';
    $output[] = '    }';
    $output[] = '    if (!is_dir(__DIR__ . "/files/public")) mkdir(__DIR__ . "/files/public", 0755, true);';
    $output[] = '    if (!is_dir(__DIR__ . "/files/protected")) mkdir(__DIR__ . "/files/protected", 0755, true);';
    $output[] = '    $setupBase = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/\\\\") ?: "/";';
    $output[] = '    header("Location: " . $setupBase);';
    $output[] = '    exit;';
    $output[] = '}';
    $output[] = '';

    // --- Database security self-check (runs once) ---
    $output[] = '// === DB SECURITY CHECK ===';
    $output[] = 'if (!variable_get("_db_security_checked", false) && LITEFRAME_DB_DIR === __DIR__ . "/.data") {';
    $output[] = '    $_scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";';
    $output[] = '    $_base = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/\\\\");';
    $output[] = '    $_checkUrl = $_scheme . "://" . $_SERVER["HTTP_HOST"] . $_base . "/.data/data.db";';
    $output[] = '    $_ctx = stream_context_create(["http" => ["timeout" => 3, "method" => "HEAD"], "ssl" => ["verify_peer" => false]]);';
    $output[] = '    $_headers = @get_headers($_checkUrl, true, $_ctx);';
    $output[] = '    if ($_headers && strpos($_headers[0], "200") !== false) {';
    $output[] = '        http_response_code(500);';
    $output[] = '        header("Content-Type: text/html");';
    $output[] = '        echo "<!DOCTYPE html><html><head><title>LiteFrame Security Error</title><style>body{font-family:sans-serif;max-width:600px;margin:80px auto;padding:0 20px;color:#333}h1{color:#c00}code{background:#f4f4f4;padding:2px 6px;border-radius:3px}pre{background:#f4f4f4;padding:12px;border-radius:6px;overflow-x:auto}</style></head><body>";';
    $output[] = '        echo "<h1>Security Error</h1>";';
    $output[] = '        echo "<p>Your database file is publicly accessible at:</p>";';
    $output[] = '        echo "<pre>" . htmlspecialchars($_checkUrl) . "</pre>";';
    $output[] = '        echo "<p>LiteFrame tried to store the database outside the web root but could not find a writable directory. It fell back to <code>.data/</code> inside the web root, but your server is not blocking access to it.</p>";';
    $output[] = '        echo "<h2>How to fix (choose one)</h2>";';
    $output[] = '        echo "<ol>";';
    $output[] = '        echo "<li><strong>Best option:</strong> Make the parent directory writable:<br><pre>chmod 755 " . htmlspecialchars(dirname(__DIR__)) . "</pre></li>";';
    $output[] = '        echo "<li><strong>Nginx:</strong> Add this to your server config:<br><pre>location ~ /\\\\.data { deny all; }</pre></li>";';
    $output[] = '        echo "<li><strong>Apache:</strong> Ensure <code>AllowOverride All</code> is enabled so the <code>.data/.htaccess</code> deny rule works.</li>";';
    $output[] = '        echo "</ol>";';
    $output[] = '        echo "</body></html>";';
    $output[] = '        return;';
    $output[] = '    }';
    $output[] = '    variable_set("_db_security_checked", true);';
    $output[] = '}';
    $output[] = '';

    // --- Settings ---
    require_once $projectDir . '/src/settings.php';
    $settingsFile = $projectDir . '/settings.yml';
    if (file_exists($settingsFile)) {
        _lf_settings_load($settingsFile);
    }
    $output[] = '// === COMPILED SETTINGS ===';
    $output[] = '$_settings = ' . var_export($GLOBALS['_settings'], true) . ';';
    $output[] = '';

    // CORS (before cron, matching dev mode order)
    $output[] = '// CORS';
    $output[] = 'if (_lf_cors_headers()) return;';
    $output[] = '';

    // Cron — check and run due tasks
    $output[] = '// Cron';
    $output[] = '_lf_cron_run();';
    $output[] = '';

    // --- Dispatch ---
    $output[] = '// === DISPATCH ===';
    $output[] = '$uri = $request->uri;';
    $output[] = '$scriptDir = dirname($_SERVER["SCRIPT_NAME"]);';
    $output[] = 'if ($scriptDir !== "/" && $scriptDir !== "\\\\") {';
    $output[] = '    $uri = substr($uri, strlen($scriptDir)) ?: "/";';
    $output[] = '}';
    $output[] = '';

    // Add built-in auth routes
    $output[] = '// Built-in auth routes';
    $output[] = '$ROUTES["auth_login"] = ["path" => "/api/auth/login", "handler" => "_auth_login", "method" => "POST", "auth" => "false"];';
    $output[] = '$ROUTES["auth_refresh"] = ["path" => "/api/auth/refresh", "handler" => "_auth_refresh", "method" => "POST", "auth" => "false"];';
    $output[] = '$ROUTES["auth_logout"] = ["path" => "/api/auth/logout", "handler" => "_auth_logout", "method" => "POST", "auth" => "false"];';
    $output[] = '$ROUTES["_lf_file_serve"] = ["path" => "/api/files/:id", "handler" => "_lf_file_serve", "method" => "GET", "auth" => "false"];';
    $output[] = '$ROUTES["_lf_cron_run"] = ["path" => "/api/cron", "handler" => "_lf_cron_run", "method" => "GET", "auth" => "false"];';
    $output[] = '';

    $output[] = '$router = new Router();';
    $output[] = 'foreach ($ROUTES as $name => $route) {';
    $output[] = '    $router->addRoute($name, $route);';
    $output[] = '}';
    $output[] = '';
    $output[] = '$matched_route = $router->match($uri, $request->method);';
    $output[] = '';
    $output[] = 'header("Content-Type: application/json");';
    $output[] = '';
    $output[] = 'if (!$matched_route) {';
    $output[] = '    // Not an API route — serve the SPA';
    $output[] = '    $spaFile = __DIR__ . "/index.html";';
    $output[] = '    if (file_exists($spaFile)) {';
    $output[] = '        header("Content-Type: text/html");';
    $output[] = '        $html = file_get_contents($spaFile);';
    $output[] = '        $base = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/\\\\");';
    $output[] = '        if ($base !== "") {';
    $output[] = '            $html = str_replace("/_app/", $base . "/_app/", $html);';
    $output[] = '            $html = str_replace("base: \"\"", "base: \"" . $base . "\"", $html);';
    $output[] = '        }';
    $output[] = '        echo $html;';
    $output[] = '        return;';
    $output[] = '    }';
    $output[] = '    http_response_code(404);';
    $output[] = '    echo json_encode(["error" => "Not found"]);';
    $output[] = '    return;';
    $output[] = '}';
    $output[] = '';

    // Rate limiting
    $output[] = '// Rate limiting';
    $output[] = 'if (!_lf_rate_limit_check($matched_route["handler"])) {';
    $output[] = '    echo json_encode(error(429, "Too many requests"));';
    $output[] = '    return;';
    $output[] = '}';
    $output[] = '';

    // Auth middleware
    $output[] = '// Authenticate request';
    $output[] = '_lf_auth_authenticate_request();';
    $output[] = '$authError = _lf_auth_check_route($matched_route);';
    $output[] = 'if ($authError) {';
    $output[] = '    echo json_encode($authError);';
    $output[] = '    return;';
    $output[] = '}';
    $output[] = '';

    // Dispatch handler (all wrapped in exception handler)
    $output[] = '$handler_name = $matched_route["handler"];';
    $output[] = 'try {';
    $output[] = '';

    // Built-in file handler
    $output[] = '// Built-in file handler';
    $output[] = 'if ($handler_name === "_lf_file_serve") {';
    $output[] = '    _lf_file_serve((int) route_param("id"));';
    $output[] = '    return;';
    $output[] = '}';
    $output[] = '';

    // Built-in cron handler
    $output[] = '// Built-in cron handler';
    $output[] = 'if ($handler_name === "_lf_cron_run") {';
    $output[] = '    echo json_encode(_lf_cron_handle_run());';
    $output[] = '    return;';
    $output[] = '}';
    $output[] = '';

    // Built-in auth handlers
    $output[] = '// Built-in auth handlers';
    $output[] = 'if (str_starts_with($handler_name, "_auth_")) {';
    $output[] = '    $result = match ($handler_name) {';
    $output[] = '        "_auth_login" => _lf_auth_handle_login(),';
    $output[] = '        "_auth_refresh" => _lf_auth_handle_refresh(),';
    $output[] = '        "_auth_logout" => _lf_auth_handle_logout(),';
    $output[] = '        default => error(404, "Unknown auth handler"),';
    $output[] = '    };';
    $output[] = '    echo json_encode($result);';
    $output[] = '    return;';
    $output[] = '}';
    $output[] = '';

    // User-defined handlers
    $output[] = 'if (isset($HANDLERS[$handler_name])) {';
    $output[] = '    echo json_encode($HANDLERS[$handler_name]());';
    $output[] = '} else {';
    $output[] = '    http_response_code(500);';
    $output[] = '    echo json_encode(["error" => "Handler not found: " . $handler_name]);';
    $output[] = '}';
    $output[] = '';
    $output[] = '} catch (\Throwable $e) {';
    $output[] = '    http_response_code(500);';
    $output[] = '    echo json_encode(["error" => "Internal server error"]);';
    $output[] = '}';

    // Write it atomically (temp file + rename)
    if (!is_dir($distDir)) {
        mkdir($distDir, 0755, true);
    }

    $compiled = implode("\n", $output);
    $distFile = $distDir . '/index.php';
    $tmpFile = $distDir . '/index.php.' . getmypid() . '.tmp';
    file_put_contents($tmpFile, $compiled, LOCK_EX);
    rename($tmpFile, $distFile);

    // Copy frontend build output into dist/ (flat structure for deployment)
    $frontendBuild = $projectDir . '/frontend/build';
    if (is_dir($frontendBuild)) {
        copy_dir($frontendBuild, $distDir, ['robots.txt']);
    }

    // Ensure files directories exist
    if (!is_dir($distDir . '/files/public')) mkdir($distDir . '/files/public', 0755, true);
    if (!is_dir($distDir . '/files/protected')) mkdir($distDir . '/files/protected', 0755, true);

    return $distFile;
}

// Recursively copy a directory
function copy_dir(string $src, string $dst, array $exclude = []): void
{
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..' || in_array($item, $exclude)) continue;
        $srcPath = $src . '/' . $item;
        $dstPath = $dst . '/' . $item;
        if (is_dir($srcPath)) {
            copy_dir($srcPath, $dstPath);
        } else {
            copy($srcPath, $dstPath);
        }
    }
}

// Run build when accessed directly (CLI or browser)
if (php_sapi_name() === 'cli' || !isset($_SERVER['REQUEST_URI']) || basename($_SERVER['SCRIPT_FILENAME']) === 'build.php') {
    $distFile = liteframe_build(__DIR__, __DIR__ . '/dist');
    $size = number_format(filesize($distFile));
    $frontend = is_dir(__DIR__ . '/frontend/build') ? ' + frontend' : '';
    echo "Build complete — dist/index.php ({$size} bytes){$frontend}";
}
