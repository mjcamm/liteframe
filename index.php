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

// Dev mode: bootstrap and dispatch
LF::bootstrap(__DIR__);
LF::dispatch();
