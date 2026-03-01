<?php

trait LFAuth
{
    public static function user(): ?object
    {
        return self::$current_user;
    }

    // --- JWT functions ---

    protected static function jwt_encode(array $payload, string $secret): string
    {
        $header = self::base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::base64url_encode(json_encode($payload));
        $signature = self::base64url_encode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));

        return "{$header}.{$payload}.{$signature}";
    }

    protected static function jwt_decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $signature] = $parts;

        $expected = self::base64url_encode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));
        if (!hash_equals($expected, $signature)) return null;

        $data = json_decode(self::base64url_decode($payload), true);
        if (!$data) return null;

        if (isset($data['exp']) && $data['exp'] < time()) return null;

        return $data;
    }

    protected static function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected static function base64url_decode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    // --- Secret key management ---

    protected static function auth_secret(): string
    {
        $env = getenv('JWT_SECRET') ?: ($_ENV['JWT_SECRET'] ?? null);
        if ($env) return $env;

        $row = self::$db->one("SELECT value FROM _config WHERE key = 'jwt_secret'");
        if ($row) return $row->value;

        $secret = bin2hex(random_bytes(32));
        self::$db->exec("INSERT OR IGNORE INTO _config (key, value) VALUES ('jwt_secret', ?)", [$secret]);
        $row = self::$db->one("SELECT value FROM _config WHERE key = 'jwt_secret'");
        return $row->value;
    }

    // --- Parse duration strings (15m, 30d, 1h) ---

    private static function parse_duration(string $duration): int
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

    protected static function auth_token(object $user): string
    {
        $secret = self::auth_secret();

        return self::jwt_encode([
            'sub' => $user->id,
            'role' => $user->role ?? 'user',
            'exp' => time() + self::parse_duration(self::setting('token_expiry', '15m')),
        ], $secret);
    }

    protected static function auth_refresh_token(object $user): string
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + self::parse_duration(self::setting('refresh_expiry', '30d')));

        self::$db->exec(
            'INSERT INTO _auth_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
            [$user->id, $hash, $expires]
        );

        return $token;
    }

    // --- Token validation ---

    protected static function auth_validate_token(string $token): ?array
    {
        $secret = self::auth_secret();
        return self::jwt_decode($token, $secret);
    }

    protected static function auth_validate_refresh(string $token): ?object
    {
        $hash = hash('sha256', $token);
        $row = self::$db->one(
            'SELECT * FROM _auth_tokens WHERE token_hash = ? AND expires_at > ?',
            [$hash, date('Y-m-d H:i:s')]
        );

        return $row;
    }

    protected static function auth_revoke_refresh(string $token): void
    {
        $hash = hash('sha256', $token);
        self::$db->exec('DELETE FROM _auth_tokens WHERE token_hash = ?', [$hash]);
    }

    // --- Internal: load user with password (for login verification only) ---

    protected static function auth_load_user_by_email(string $email): ?object
    {
        return self::$db->one(
            'SELECT * FROM entities__user WHERE email = ?',
            [$email]
        );
    }

    // --- Password helpers ---

    protected static function auth_hash_password(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    protected static function auth_verify_password(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    // --- Authenticate request ---

    protected static function auth_authenticate_request(): void
    {
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
        $payload = self::auth_validate_token($token);
        if (!$payload) return;

        self::$current_user = self::load($payload['sub']);
    }

    // --- Route auth check ---

    protected static function auth_check_route(array $route): ?array
    {
        $auth = $route['auth'] ?? null;

        if ($auth === null) {
            return self::error(500, 'Route missing auth config');
        }

        $user = self::user();

        if ($auth === 'public') return null;

        if ($auth === 'auth') {
            if (!$user) {
                return self::error(401, 'Authentication required');
            }
            return null;
        }

        if (!$user) {
            return self::error(401, 'Authentication required');
        }
        $allowed = array_map('trim', explode(',', $auth));
        if (!in_array($user->role, $allowed)) {
            return self::error(403, 'Insufficient permissions');
        }
        return null;
    }

    // --- Built-in auth handlers ---

    protected static function auth_handle_login(): array
    {
        $email = self::input('email');
        $password = self::input('password');

        if (!$email || !$password) {
            return self::error(400, 'Email and password required');
        }

        $user = self::auth_load_user_by_email($email);
        if (!$user || !self::auth_verify_password($password, $user->password)) {
            return self::error(401, 'Invalid email or password');
        }

        $publicUser = self::load($user->id);

        return [
            'user' => $publicUser,
            'token' => self::auth_token($user),
            'refresh_token' => self::auth_refresh_token($user),
        ];
    }

    protected static function auth_handle_refresh(): array
    {
        $refreshToken = self::input('refresh_token');
        if (!$refreshToken) {
            return self::error(400, 'Refresh token required');
        }

        $stored = self::auth_validate_refresh($refreshToken);
        if (!$stored) {
            return self::error(401, 'Invalid or expired refresh token');
        }

        self::auth_revoke_refresh($refreshToken);

        $user = self::load($stored->user_id);
        if (!$user) {
            return self::error(401, 'User not found');
        }

        return [
            'token' => self::auth_token($user),
            'refresh_token' => self::auth_refresh_token($user),
        ];
    }

    protected static function auth_handle_logout(): array
    {
        $refreshToken = self::input('refresh_token');
        if ($refreshToken) {
            self::auth_revoke_refresh($refreshToken);
        }

        return ['message' => 'Logged out'];
    }
}
