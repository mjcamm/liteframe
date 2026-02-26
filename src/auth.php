<?php

/**
 * Authentication system.
 *
 * JWT access tokens (short-lived, stateless) + refresh tokens (long-lived, stored in DB).
 * Password hashing is automatic on entity_save for user type.
 */

// Current authenticated user for this request
$_current_user = null;

// --- JWT functions (no library needed) ---

function _lf_jwt_encode(array $payload, string $secret): string
{
    $header = _lf_base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = _lf_base64url_encode(json_encode($payload));
    $signature = _lf_base64url_encode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));
    return "{$header}.{$payload}.{$signature}";
}

function _lf_jwt_decode(string $token, string $secret): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $signature] = $parts;

    // Verify signature
    $expected = _lf_base64url_encode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));
    if (!hash_equals($expected, $signature)) return null;

    $data = json_decode(_lf_base64url_decode($payload), true);
    if (!$data) return null;

    // Check expiry
    if (isset($data['exp']) && $data['exp'] < time()) return null;

    return $data;
}

function _lf_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function _lf_base64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

// --- Secret key management ---

function _lf_auth_secret(): string
{
    global $db;

    // Environment variable takes priority
    $env = getenv('JWT_SECRET') ?: ($_ENV['JWT_SECRET'] ?? null);
    if ($env) return $env;

    // Fall back to auto-generated secret in DB
    $row = $db->one("SELECT value FROM _config WHERE key = 'jwt_secret'");
    if ($row) return $row->value;

    // Auto-generate on first use (INSERT OR IGNORE handles race conditions)
    $secret = bin2hex(random_bytes(32));
    $db->exec("INSERT OR IGNORE INTO _config (key, value) VALUES ('jwt_secret', ?)", [$secret]);
    $row = $db->one("SELECT value FROM _config WHERE key = 'jwt_secret'");
    return $row->value;
}

// --- Parse duration strings (15m, 30d, 1h) ---

function _lf_parse_duration(string $duration): int
{
    $value = (int) $duration;
    $unit = substr($duration, -1);
    return match ($unit) {
        'm' => $value * 60,
        'h' => $value * 3600,
        'd' => $value * 86400,
        default => $value,
    };
}

// --- Token generation ---

function _lf_auth_token(object $user): string
{
    $secret = _lf_auth_secret();

    return _lf_jwt_encode([
        'sub' => $user->id,
        'role' => $user->role ?? 'user',
        'exp' => time() + _lf_parse_duration(setting('token_expiry', '15m')),
    ], $secret);
}

function _lf_auth_refresh_token(object $user): string
{
    global $db;

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + _lf_parse_duration(setting('refresh_expiry', '30d')));

    $db->exec(
        'INSERT INTO _auth_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
        [$user->id, $hash, $expires]
    );

    return $token;
}

// --- Token validation ---

function _lf_auth_validate_token(string $token): ?array
{
    $secret = _lf_auth_secret();
    return _lf_jwt_decode($token, $secret);
}

function _lf_auth_validate_refresh(string $token): ?object
{
    global $db;

    $hash = hash('sha256', $token);
    $row = $db->one(
        'SELECT * FROM _auth_tokens WHERE token_hash = ? AND expires_at > ?',
        [$hash, date('Y-m-d H:i:s')]
    );

    return $row;
}

function _lf_auth_revoke_refresh(string $token): void
{
    global $db;
    $hash = hash('sha256', $token);
    $db->exec('DELETE FROM _auth_tokens WHERE token_hash = ?', [$hash]);
}

// --- Internal: load user with password (for login verification only) ---

function _lf_auth_load_user_by_email(string $email): ?object
{
    global $db;
    return $db->one(
        'SELECT * FROM entities__user WHERE email = ?',
        [$email]
    );
}

// --- Password helpers ---

function _lf_auth_hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT);
}

function _lf_auth_verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

// --- Current user ---

function current_user(): ?object
{
    global $_current_user;
    return $_current_user;
}

/**
 * Authenticate the current request from the Authorization header.
 * Called during dispatch, before the handler runs.
 */
function _lf_auth_authenticate_request(): void
{
    global $_current_user;

    // Check standard header, then Apache CGI/FastCGI rewrite fallback,
    // then getallheaders() as a universal last resort.
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if (!$header && function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strtolower($key) === 'authorization') { $header = $value; break; }
        }
    }
    if (!str_starts_with($header, 'Bearer ')) return;

    $token = substr($header, 7);
    $payload = _lf_auth_validate_token($token);
    if (!$payload) return;

    $_current_user = entity_load($payload['sub']);
}

/**
 * Check if the current request is authorized for a route.
 * Returns null if authorized, or an error array if not.
 */
function _lf_auth_check_route(array $route): ?array
{
    $auth = $route['auth'] ?? 'false';
    $user = current_user();

    // Public route
    if ($auth === 'false') return null;

    // Auth required
    if ($auth === 'true') {
        if (!$user) {
            return error(401, 'Authentication required');
        }

        // Check roles if specified
        $roles = $route['roles'] ?? null;
        if ($roles) {
            $allowed = array_map('trim', explode(',', $roles));
            if (!in_array($user->role, $allowed)) {
                return error(403, 'Insufficient permissions');
            }
        }

        return null;
    }

    // Reject unknown auth values (typos like 'True', 'yes', etc.)
    return error(500, "Invalid auth config: {$auth}");
}

// --- Built-in auth handlers ---

function _lf_auth_handle_login(): array
{
    $email = input('email');
    $password = input('password');

    if (!$email || !$password) {
        return error(400, 'Email and password required');
    }

    $user = _lf_auth_load_user_by_email($email);
    if (!$user || !_lf_auth_verify_password($password, $user->password)) {
        return error(401, 'Invalid email or password');
    }

    // Load the public user (without password)
    $publicUser = entity_load($user->id);

    return [
        'user' => $publicUser,
        'token' => _lf_auth_token($user),
        'refresh_token' => _lf_auth_refresh_token($user),
    ];
}

function _lf_auth_handle_refresh(): array
{
    $refreshToken = input('refresh_token');
    if (!$refreshToken) {
        return error(400, 'Refresh token required');
    }

    $stored = _lf_auth_validate_refresh($refreshToken);
    if (!$stored) {
        return error(401, 'Invalid or expired refresh token');
    }

    // Revoke old refresh token
    _lf_auth_revoke_refresh($refreshToken);

    // Load user and issue new tokens
    $user = entity_load($stored->user_id);
    if (!$user) {
        return error(401, 'User not found');
    }

    return [
        'token' => _lf_auth_token($user),
        'refresh_token' => _lf_auth_refresh_token($user),
    ];
}

function _lf_auth_handle_logout(): array
{
    $refreshToken = input('refresh_token');
    if ($refreshToken) {
        _lf_auth_revoke_refresh($refreshToken);
    }

    return ['message' => 'Logged out'];
}
