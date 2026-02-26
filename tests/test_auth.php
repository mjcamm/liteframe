<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/EntityQuery.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/hooks.php';
require_once __DIR__ . '/../src/derived.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/validation.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/schema.php';
require_once __DIR__ . '/../src/files.php';

echo "=== Auth Tests ===\n\n";

// Bootstrap
$db = new Database(':memory:');
settings_load(__DIR__ . '/../settings.yml');
$types = parse_types(__DIR__ . '/../config/types.yml');
schema_apply($db, $types);

// Test 1: Create a user — password auto-hashed
$user = entity_save('user', [
    'name' => 'Alice',
    'email' => 'alice@test.com',
    'password' => 'secret123',
    'role' => 'admin',
]);
assert($user->name === 'Alice');
assert($user->email === 'alice@test.com');
assert(!isset($user->password), 'Password should be stripped from loaded entity');
echo "[PASS] Created user, password hidden from output\n";

// Test 2: Password is actually hashed in DB
$raw = $db->one('SELECT password FROM entities__user WHERE id = ?', [$user->id]);
assert(str_starts_with($raw->password, '$2y$'), 'Password should be bcrypt hashed');
assert($raw->password !== 'secret123', 'Password should not be plain text');
echo "[PASS] Password is bcrypt hashed in DB\n";

// Test 3: Verify password
assert(auth_verify_password('secret123', $raw->password) === true);
assert(auth_verify_password('wrong', $raw->password) === false);
echo "[PASS] Password verification works\n";

// Test 4: Load user by email (internal, with password)
$loaded = auth_load_user_by_email('alice@test.com');
assert($loaded !== null);
assert(isset($loaded->password), 'Internal load should include password');
echo "[PASS] Internal user load includes password\n";

// Test 5: Generate access token
$token = auth_token($user);
assert(!empty($token));
$parts = explode('.', $token);
assert(count($parts) === 3, 'JWT should have 3 parts');
echo "[PASS] Generated JWT: " . substr($token, 0, 20) . "...\n";

// Test 6: Validate access token
$payload = auth_validate_token($token);
assert($payload !== null);
assert($payload['sub'] === $user->id);
assert($payload['role'] === 'admin');
echo "[PASS] JWT validates — sub: {$payload['sub']}, role: {$payload['role']}\n";

// Test 7: Expired token fails
$secret = auth_secret();
$expired = jwt_encode(['sub' => 1, 'role' => 'admin', 'exp' => time() - 100], $secret);
assert(auth_validate_token($expired) === null);
echo "[PASS] Expired token rejected\n";

// Test 8: Tampered token fails
$tampered = $token . 'x';
assert(auth_validate_token($tampered) === null);
echo "[PASS] Tampered token rejected\n";

// Test 9: Generate refresh token
$refresh = auth_refresh_token($user);
assert(!empty($refresh));
assert(strlen($refresh) === 64, 'Refresh token should be 64 hex chars');
echo "[PASS] Generated refresh token\n";

// Test 10: Validate refresh token
$stored = auth_validate_refresh($refresh);
assert($stored !== null);
assert($stored->user_id === $user->id);
echo "[PASS] Refresh token validates\n";

// Test 11: Revoke refresh token
auth_revoke_refresh($refresh);
assert(auth_validate_refresh($refresh) === null);
echo "[PASS] Refresh token revoked\n";

// Test 12: Auth check — public route
$result = auth_check_route(['auth' => 'false']);
assert($result === null);
echo "[PASS] Public route allows anyone\n";

// Test 13: Auth check — protected route, no user
$_current_user = null;
$result = auth_check_route(['auth' => 'true']);
assert($result['error'] === 'Authentication required');
echo "[PASS] Protected route blocks unauthenticated\n";

// Test 14: Auth check — protected route, with user
$_current_user = $user;
$result = auth_check_route(['auth' => 'true']);
assert($result === null);
echo "[PASS] Protected route allows authenticated user\n";

// Test 15: Role check — correct role
$result = auth_check_route(['auth' => 'true', 'roles' => 'admin, editor']);
assert($result === null);
echo "[PASS] Role check passes for admin\n";

// Test 16: Role check — wrong role
$user2 = entity_save('user', [
    'name' => 'Bob',
    'email' => 'bob@test.com',
    'password' => 'pass456',
    'role' => 'user',
]);
$_current_user = $user2;
$result = auth_check_route(['auth' => 'true', 'roles' => 'admin, editor']);
assert($result['error'] === 'Insufficient permissions');
echo "[PASS] Role check blocks user without required role\n";

// Test 17: Secret key auto-generates and persists
$secret1 = auth_secret();
$secret2 = auth_secret();
assert($secret1 === $secret2, 'Secret should be consistent');
assert(strlen($secret1) === 64, 'Secret should be 64 hex chars');
echo "[PASS] JWT secret auto-generated and persists\n";

echo "\n=== All tests passed ===\n";
