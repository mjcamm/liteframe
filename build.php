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

    // --- Inline Database, Request, Router classes ---
    $output[] = '// === FRAMEWORK CLASSES ===';
    foreach (['Database.php', 'Request.php', 'Router.php'] as $filename) {
        $file = $projectDir . '/src/' . $filename;
        if (file_exists($file)) {
            $source = file_get_contents($file);
            $source = preg_replace('/^<\?php\s*/', '', $source);
            $output[] = '// --- ' . $filename . ' ---';
            $output[] = $source;
        }
    }

    // --- Build the LF class with inlined traits ---
    $output[] = '// === LF CLASS (traits inlined) ===';

    // Collect all trait method bodies
    $traitBodies = [];
    $traitFiles = glob($projectDir . '/src/traits/LF*.php');
    foreach ($traitFiles as $file) {
        $source = file_get_contents($file);
        // Strip <?php tag
        $source = preg_replace('/^<\?php\s*/', '', $source);
        // Extract content between trait Name { ... } — everything inside the braces
        if (preg_match('/^trait\s+\w+\s*\{(.+)\}\s*$/s', trim($source), $m)) {
            $traitBodies[] = $m[1];
        }
    }

    $output[] = 'class LF {';
    $output[] = '    protected static ?Database $db = null;';
    $output[] = '    protected static array $types = [];';
    $output[] = '    protected static array $hooks = [];';
    $output[] = '    protected static array $derived = [];';
    $output[] = '    protected static array $effects = [];';
    $output[] = '    protected static array $settings = [];';
    $output[] = '    protected static array $roles = [];';
    $output[] = '    protected static ?Request $request = null;';
    $output[] = '    protected static ?array $matched_route = null;';
    $output[] = '    protected static ?object $current_user = null;';
    $output[] = '    protected static array $crons = [];';
    $output[] = '    protected static array $api_directives = [];';
    $output[] = '';
    $output[] = '    public static function db(): Database { return self::$db; }';
    $output[] = '    public static function types(): array { return self::$types; }';
    $output[] = '';

    foreach ($traitBodies as $body) {
        $output[] = $body;
    }

    $output[] = '}';
    $output[] = '';

    // --- Inline EntityQuery ---
    $eqFile = $projectDir . '/src/EntityQuery.php';
    if (file_exists($eqFile)) {
        $source = file_get_contents($eqFile);
        $source = preg_replace('/^<\?php\s*/', '', $source);
        $output[] = '// --- EntityQuery.php ---';
        $output[] = $source;
    }

    // --- Parse and compile routes.yml ---
    $routesFile = $projectDir . '/config/routes.yml';
    $routes = [];
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
    $output[] = '// === COMPILED ROUTES ===';
    $output[] = '$_ROUTES = ' . var_export($routes, true) . ';';
    $output[] = '';

    // --- Inline handler files ---
    $output[] = '// === COMPILED HANDLERS ===';
    $output[] = '$_HANDLERS = [];';
    $handlersDir = $projectDir . '/handlers';
    if (is_dir($handlersDir)) {
        foreach (glob($handlersDir . '/*.php') as $file) {
            $name = basename($file, '.php');
            $source = file_get_contents($file);
            $source = preg_replace('/^<\?php\s*/', '', $source);
            $source = preg_replace('/^return\s+/m', '$_HANDLERS[\'' . $name . '\'] = ', $source, 1);
            $output[] = $source;
        }
    }
    $output[] = '';

    // --- Parse types.yml at build time ---
    // We need to load LF to use parse_types
    require_once $projectDir . '/src/Database.php';
    require_once $projectDir . '/src/Request.php';
    require_once $projectDir . '/src/Router.php';
    if (!class_exists('LF')) {
        require_once $projectDir . '/src/LF.php';
    }

    $typesFile = $projectDir . '/config/types.yml';
    // Initialize LF with a temp in-memory DB just for parsing
    LF::init(['db' => new Database(':memory:')]);
    $types = file_exists($typesFile) ? LF::types() : [];
    // Actually parse the types file (parse_types is protected, but we need it)
    // Use reflection to call protected method
    if (file_exists($typesFile)) {
        $ref = new ReflectionMethod('LF', 'parse_types');
        $ref->setAccessible(true);
        $types = $ref->invoke(null, $typesFile);
    }

    $output[] = '// === COMPILED TYPES ===';
    $output[] = '$_TYPES = ' . var_export($types, true) . ';';
    $output[] = '';

    // Get derived, effects, and API directives (set by parse_types on LF static props)
    $refDerived = new ReflectionProperty('LF', 'derived');
    $refDerived->setAccessible(true);
    $refEffects = new ReflectionProperty('LF', 'effects');
    $refEffects->setAccessible(true);
    $refApiDir = new ReflectionProperty('LF', 'api_directives');
    $refApiDir->setAccessible(true);

    $output[] = '// === COMPILED DERIVED & EFFECTS ===';
    $output[] = '$_DERIVED = ' . var_export($refDerived->getValue(), true) . ';';
    $output[] = '$_EFFECTS = ' . var_export($refEffects->getValue(), true) . ';';
    $output[] = '$_API_DIRECTIVES = ' . var_export($refApiDir->getValue(), true) . ';';
    $output[] = '';

    // --- Parse and compile cron.yml ---
    $cronFile = $projectDir . '/config/cron.yml';
    $crons = [];
    if (file_exists($cronFile)) {
        $current = null;
        foreach (file($cronFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line[0] === '#') continue;
            $line = preg_replace('/\s+#.*$/', '', $line);
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
    $output[] = '$_CRONS = ' . var_export($crons, true) . ';';
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
    $output[] = '$_HOOKS = [];';
    $hooksDir = $projectDir . '/hooks';
    if (is_dir($hooksDir)) {
        foreach (glob($hooksDir . '/*.php') as $file) {
            $type = basename($file, '.php');
            $source = file_get_contents($file);
            $source = preg_replace('/^<\?php\s*/', '', $source);
            $source = preg_replace('/^return\s+/m', '$_HOOKS[\'' . $type . '\'] = ', $source, 1);
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
    $output[] = '';
    $output[] = '// Initialize LF with compiled config';
    $output[] = 'LF::init([';
    $output[] = '    "db" => new Database($_dbDir . "/data.db"),';
    $output[] = '    "request" => new Request(),';
    $output[] = '    "types" => $_TYPES,';
    $output[] = '    "derived" => $_DERIVED,';
    $output[] = '    "effects" => $_EFFECTS,';
    $output[] = '    "api_directives" => $_API_DIRECTIVES,';
    $output[] = '    "crons" => $_CRONS,';
    $output[] = '    "hooks" => $_HOOKS,';
    $output[] = '    "settings" => $_settings ?? [],';
    $output[] = '    "roles" => $_roles ?? [],';
    $output[] = ']);';
    $output[] = 'define("LITEFRAME_DB_DIR", $_dbDir);';
    $output[] = '';
    $output[] = '// Schema — sync from types config';
    $output[] = '$_schemaRef = new ReflectionMethod("LF", "schema_sync");';
    $output[] = '$_schemaRef->setAccessible(true);';
    $output[] = '$_schemaRef->invoke(null, LF::db(), $_TYPES);';
    $output[] = '';

    // --- Auto-setup ---
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

    // --- Database security self-check ---
    $output[] = '// === DB SECURITY CHECK ===';
    $output[] = 'if (!LF::variable_get("_db_security_checked", false) && LITEFRAME_DB_DIR === __DIR__ . "/.data") {';
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
    $output[] = '    LF::variable_set("_db_security_checked", true);';
    $output[] = '}';
    $output[] = '';

    // --- Settings ---
    // Load settings at build time to compile them
    $settingsFile = $projectDir . '/settings.yml';
    $compiledSettings = [];
    if (file_exists($settingsFile)) {
        // Parse settings manually (same logic as LFSettings trait)
        $currentSection = null;
        foreach (file($settingsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            $line = preg_replace('/\s+#.*$/', '', $line);
            if ($currentSection && preg_match('/^\s+/', $line)) {
                $trimmed = trim($line);
                if (!str_contains($trimmed, ':')) continue;
                [$key, $value] = explode(':', $trimmed, 2);
                $compiledSettings[$currentSection][trim($key)] = _lf_build_cast_setting(trim($value));
                continue;
            }
            $trimmed = trim($line);
            if (!str_contains($trimmed, ':')) continue;
            [$key, $value] = explode(':', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);
            if ($value === '') {
                $currentSection = $key;
                if (!isset($compiledSettings[$currentSection])) {
                    $compiledSettings[$currentSection] = [];
                }
                continue;
            }
            $currentSection = null;
            $compiledSettings[$key] = _lf_build_cast_setting($value);
        }
    }
    $output[] = '// === COMPILED SETTINGS ===';
    $output[] = '$_settings = ' . var_export($compiledSettings, true) . ';';
    $output[] = 'LF::init(["settings" => $_settings]);';
    $output[] = '';

    // --- Roles ---
    $rolesFile = $projectDir . '/config/roles.yml';
    $compiledRoles = [];
    if (file_exists($rolesFile)) {
        foreach (file($rolesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            $line = preg_replace('/\s+#.*$/', '', $line);
            $trimmed = trim($line);
            if (!str_contains($trimmed, ':')) continue;
            [$key, $value] = explode(':', $trimmed, 2);
            $compiledRoles[trim($key)] = trim($value);
        }
    }
    $output[] = '// === COMPILED ROLES ===';
    $output[] = '$_roles = ' . var_export($compiledRoles, true) . ';';
    $output[] = 'LF::init(["roles" => $_roles]);';
    $output[] = '';

    // CORS
    $output[] = '// CORS';
    $output[] = '$_corsRef = new ReflectionMethod("LF", "cors_headers");';
    $output[] = '$_corsRef->setAccessible(true);';
    $output[] = 'if ($_corsRef->invoke(null)) return;';
    $output[] = '';

    // Cron
    $output[] = '// Cron';
    $output[] = '$_cronRef = new ReflectionMethod("LF", "cron_run");';
    $output[] = '$_cronRef->setAccessible(true);';
    $output[] = '$_cronRef->invoke(null);';
    $output[] = '';

    // --- Dispatch ---
    $output[] = '// === DISPATCH ===';
    $output[] = '$uri = LF::db() ? true : true; // ensure init';
    $output[] = '$_req = new Request();';
    $output[] = '$uri = $_req->uri;';
    $output[] = '$scriptDir = dirname($_SERVER["SCRIPT_NAME"]);';
    $output[] = 'if ($scriptDir !== "/" && $scriptDir !== "\\\\") {';
    $output[] = '    $uri = substr($uri, strlen($scriptDir)) ?: "/";';
    $output[] = '}';
    $output[] = '';

    // Add built-in auth routes
    $output[] = '// Built-in auth routes';
    $output[] = '$_ROUTES["auth_login"] = ["path" => "/api/auth/login", "handler" => "_auth_login", "method" => "POST", "auth" => "public"];';
    $output[] = '$_ROUTES["auth_refresh"] = ["path" => "/api/auth/refresh", "handler" => "_auth_refresh", "method" => "POST", "auth" => "public"];';
    $output[] = '$_ROUTES["auth_logout"] = ["path" => "/api/auth/logout", "handler" => "_auth_logout", "method" => "POST", "auth" => "public"];';
    $output[] = '$_ROUTES["_lf_file_serve"] = ["path" => "/api/files/:id", "handler" => "_lf_file_serve", "method" => "GET", "auth" => "public"];';
    $output[] = '$_ROUTES["_lf_cron_run"] = ["path" => "/api/cron", "handler" => "_lf_cron_run", "method" => "GET", "auth" => "public"];';
    $output[] = '';

    // Auto-generated $api() routes
    $output[] = '// Auto-generated $api() routes';
    $output[] = '$_apiRef = new ReflectionMethod("LF", "api_routes_from_types");';
    $output[] = '$_apiRef->setAccessible(true);';
    $output[] = '$_apiRoutes = $_apiRef->invoke(null);';
    $output[] = '';

    $output[] = '$router = new Router();';
    $output[] = 'foreach ($_ROUTES as $name => $route) {';
    $output[] = '    $router->addRoute($name, $route);';
    $output[] = '}';
    $output[] = 'foreach ($_apiRoutes as $name => $route) {';
    $output[] = '    $router->addRoute($name, $route);';
    $output[] = '}';
    $output[] = '';
    $output[] = '$_matchRef = new ReflectionProperty("LF", "matched_route");';
    $output[] = '$_matchRef->setAccessible(true);';
    $output[] = '$_matchRef->setValue(null, $router->match($uri, $_req->method));';
    $output[] = '$matched_route = $_matchRef->getValue();';
    $output[] = '';
    $output[] = 'header("Content-Type: application/json");';
    $output[] = '';
    $output[] = 'if (!$matched_route) {';
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
    $output[] = '$_rlRef = new ReflectionMethod("LF", "rate_limit_check");';
    $output[] = '$_rlRef->setAccessible(true);';
    $output[] = 'if (!$_rlRef->invoke(null, $matched_route["handler"])) {';
    $output[] = '    echo json_encode(LF::error(429, "Too many requests"));';
    $output[] = '    return;';
    $output[] = '}';
    $output[] = '';

    // Auth middleware
    $output[] = '// Authenticate request';
    $output[] = '$_authReqRef = new ReflectionMethod("LF", "auth_authenticate_request");';
    $output[] = '$_authReqRef->setAccessible(true);';
    $output[] = '$_authReqRef->invoke(null);';
    $output[] = '$_authCheckRef = new ReflectionMethod("LF", "auth_check_route");';
    $output[] = '$_authCheckRef->setAccessible(true);';
    $output[] = '$authError = $_authCheckRef->invoke(null, $matched_route);';
    $output[] = 'if ($authError) {';
    $output[] = '    echo json_encode($authError);';
    $output[] = '    return;';
    $output[] = '}';
    $output[] = '';

    // Dispatch handler
    $output[] = '$handler_name = $matched_route["handler"];';
    $output[] = 'try {';
    $output[] = '';

    // Built-in file handler
    $output[] = 'if ($handler_name === "_lf_file_serve") {';
    $output[] = '    $_fsRef = new ReflectionMethod("LF", "file_serve");';
    $output[] = '    $_fsRef->setAccessible(true);';
    $output[] = '    $_fsRef->invoke(null, (int) LF::route_param("id"));';
    $output[] = '    return;';
    $output[] = '}';
    $output[] = '';

    // Built-in cron handler
    $output[] = 'if ($handler_name === "_lf_cron_run") {';
    $output[] = '    $_chRef = new ReflectionMethod("LF", "cron_handle_run");';
    $output[] = '    $_chRef->setAccessible(true);';
    $output[] = '    echo json_encode($_chRef->invoke(null));';
    $output[] = '    return;';
    $output[] = '}';
    $output[] = '';

    // Built-in auth handlers
    $output[] = 'if (str_starts_with($handler_name, "_auth_")) {';
    $output[] = '    $_ahLoginRef = new ReflectionMethod("LF", "auth_handle_login");';
    $output[] = '    $_ahLoginRef->setAccessible(true);';
    $output[] = '    $_ahRefreshRef = new ReflectionMethod("LF", "auth_handle_refresh");';
    $output[] = '    $_ahRefreshRef->setAccessible(true);';
    $output[] = '    $_ahLogoutRef = new ReflectionMethod("LF", "auth_handle_logout");';
    $output[] = '    $_ahLogoutRef->setAccessible(true);';
    $output[] = '    $result = match ($handler_name) {';
    $output[] = '        "_auth_login" => $_ahLoginRef->invoke(null),';
    $output[] = '        "_auth_refresh" => $_ahRefreshRef->invoke(null),';
    $output[] = '        "_auth_logout" => $_ahLogoutRef->invoke(null),';
    $output[] = '        default => LF::error(404, "Unknown auth handler"),';
    $output[] = '    };';
    $output[] = '    echo json_encode($result);';
    $output[] = '    return;';
    $output[] = '}';
    $output[] = '';

    // Auto-generated $api() type handler
    $output[] = 'if (isset($matched_route["_type"])) {';
    $output[] = '    $_tdRef = new ReflectionMethod("LF", "type_dispatch");';
    $output[] = '    $_tdRef->setAccessible(true);';
    $output[] = '    echo json_encode($_tdRef->invoke(null, $matched_route));';
    $output[] = '    return;';
    $output[] = '}';
    $output[] = '';

    // User-defined handlers
    $output[] = 'if (isset($_HANDLERS[$handler_name])) {';
    $output[] = '    echo json_encode($_HANDLERS[$handler_name]());';
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

    // Remove .gitkeep if present
    $gitkeep = $distDir . '/.gitkeep';
    if (file_exists($gitkeep)) {
        unlink($gitkeep);
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

// Helper for build-time settings parsing
function _lf_build_cast_setting(string $value): mixed
{
    if ($value === 'true') return true;
    if ($value === 'false') return false;
    if (is_numeric($value)) return $value + 0;
    if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
        || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
        return substr($value, 1, -1);
    }
    return $value;
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
    $sizeKB = number_format(filesize($distFile) / 1024, 1) . ' KB';

    $frontendInfo = '';
    $frontendDir = __DIR__ . '/frontend/build';
    if (is_dir($frontendDir)) {
        $frontendBytes = 0;
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($frontendDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $file) {
            $frontendBytes += $file->getSize();
        }
        $frontendKB = number_format($frontendBytes / 1024, 1) . ' KB';
        $frontendInfo = '<p><code>frontend/build/</code> — ' . $frontendKB . '</p>';
        $frontendInfoCli = " + frontend ({$frontendKB})";
    } else {
        $frontendInfoCli = '';
    }

    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/html');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>LiteFrame Build</title>';
        echo '<style>body{font-family:monospace;max-width:600px;margin:60px auto;padding:0 20px;color:#1a1a2e}';
        echo '.ok{color:#16a34a;font-weight:bold}code{background:#f1f5f9;padding:2px 6px;border-radius:4px}</style></head><body>';
        echo '<h2>LiteFrame Build</h2>';
        echo '<p class="ok">Build complete</p>';
        echo '<p><code>dist/index.php</code> — ' . $sizeKB . '</p>';
        echo $frontendInfo;
        echo '</body></html>';
    } else {
        echo "Build complete — dist/index.php ({$sizeKB}){$frontendInfoCli}\n";
    }
}
