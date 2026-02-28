<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/EntityQuery.php';
require_once __DIR__ . '/../src/Request.php';
require_once __DIR__ . '/../src/Router.php';
require_once __DIR__ . '/../src/hooks.php';
require_once __DIR__ . '/../src/derived.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/validation.php';
require_once __DIR__ . '/../src/variables.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/schema.php';
require_once __DIR__ . '/../src/files.php';
require_once __DIR__ . '/../src/api.php';

echo "=== API Directives Tests ===\n\n";

// Set up request global for handler tests
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$request = new Request();

// --- Test 1: Parse $api() directives from types.yml ---

$TYPES = _lf_parse_types(__DIR__ . '/fixtures/types_api.yml');

global $API_DIRECTIVES;
assert(isset($API_DIRECTIVES['article']), 'article should have API directives');
assert(count($API_DIRECTIVES['article']) === 5, 'article should have 5 API actions');
assert($API_DIRECTIVES['article']['list'] === 'public');
assert($API_DIRECTIVES['article']['view'] === 'public');
assert($API_DIRECTIVES['article']['create'] === 'auth');
assert($API_DIRECTIVES['article']['update'] === 'auth');
assert($API_DIRECTIVES['article']['delete'] === 'admin');
echo '[PASS] Parsed article $api() directives (5 actions)' . "\n";

assert(isset($API_DIRECTIVES['page']), 'page should have API directives');
assert(count($API_DIRECTIVES['page']) === 2, 'page should have 2 API actions');
assert($API_DIRECTIVES['page']['list'] === 'public');
assert($API_DIRECTIVES['page']['view'] === 'public');
echo '[PASS] Parsed page $api() directives (2 actions, read-only)' . "\n";

assert(!isset($API_DIRECTIVES['user']), 'user should have no API directives');
assert(!isset($API_DIRECTIVES['audit_log']), 'audit_log should have no API directives');
echo '[PASS] Types without $api() have no directives' . "\n";

// --- Test 2: Route generation ---

$routes = _lf_api_routes_from_types();

// Article routes
assert(isset($routes['_api_article_list']));
assert($routes['_api_article_list']['path'] === '/api/article');
assert($routes['_api_article_list']['method'] === 'GET');
assert($routes['_api_article_list']['auth'] === 'false');
assert($routes['_api_article_list']['_type'] === 'article');
assert($routes['_api_article_list']['_action'] === 'list');
echo "[PASS] article list route: GET /api/article (public)\n";

assert(isset($routes['_api_article_get']));
assert($routes['_api_article_get']['path'] === '/api/article/:id');
assert($routes['_api_article_get']['method'] === 'GET');
assert($routes['_api_article_get']['auth'] === 'false');
assert($routes['_api_article_get']['_action'] === 'get');
echo "[PASS] article view route: GET /api/article/:id (public)\n";

assert(isset($routes['_api_article_create']));
assert($routes['_api_article_create']['path'] === '/api/article');
assert($routes['_api_article_create']['method'] === 'POST');
assert($routes['_api_article_create']['auth'] === 'true');
assert(!isset($routes['_api_article_create']['roles']));
echo "[PASS] article create route: POST /api/article (auth)\n";

assert(isset($routes['_api_article_update']));
assert($routes['_api_article_update']['path'] === '/api/article/:id');
assert($routes['_api_article_update']['method'] === 'PUT');
assert($routes['_api_article_update']['auth'] === 'true');
assert(!isset($routes['_api_article_update']['roles']));
echo "[PASS] article update route: PUT /api/article/:id (auth)\n";

assert(isset($routes['_api_article_delete']));
assert($routes['_api_article_delete']['path'] === '/api/article/:id');
assert($routes['_api_article_delete']['method'] === 'DELETE');
assert($routes['_api_article_delete']['auth'] === 'true');
assert($routes['_api_article_delete']['roles'] === 'admin');
echo "[PASS] article delete route: DELETE /api/article/:id (admin)\n";

// Page routes (read-only)
assert(isset($routes['_api_page_list']));
assert(isset($routes['_api_page_get']));
assert(!isset($routes['_api_page_create']), 'page should not have create route');
assert(!isset($routes['_api_page_update']), 'page should not have update route');
assert(!isset($routes['_api_page_delete']), 'page should not have delete route');
echo "[PASS] page has only list + view routes (read-only)\n";

// No routes for user or audit_log
$routeKeys = array_keys($routes);
$userRoutes = array_filter($routeKeys, fn($k) => str_starts_with($k, '_api_user_'));
$auditRoutes = array_filter($routeKeys, fn($k) => str_starts_with($k, '_api_audit_log_'));
assert(empty($userRoutes), 'user should have no auto-generated routes');
assert(empty($auditRoutes), 'audit_log should have no auto-generated routes');
echo '[PASS] No routes for types without $api() directives' . "\n";

// --- Test 3: Route count ---

assert(count($routes) === 7, 'Should have 7 auto-generated routes (5 article + 2 page)');
echo "[PASS] Total route count: " . count($routes) . "\n";

// --- Test 4: CRUD handlers with in-memory DB ---

$db = new Database(':memory:');
_lf_schema_apply($db, $TYPES);

// Create
$article = entity_save('article', ['title' => 'Test Article', 'body' => 'Hello world']);
assert($article->id === 1);
assert($article->title === 'Test Article');
echo "[PASS] entity_save create works for article\n";

$article2 = entity_save('article', ['title' => 'Second Article', 'body' => 'More content']);
assert($article2->id === 2);

// Test list handler
$listResult = _lf_type_handle_list('article');
assert(isset($listResult['data']), 'list should return paginated result');
assert(isset($listResult['meta']), 'list should have meta');
assert($listResult['meta']['total'] === 2, 'Should have 2 articles');
assert($listResult['data'][0]->id === 2, 'Should be sorted desc by id');
echo "[PASS] _lf_type_handle_list returns paginated results sorted desc\n";

// Test get handler (need to set up matched_route for route_param)
$matched_route = ['params' => ['id' => '1']];
$getResult = _lf_type_handle_get('article');
assert($getResult->id === 1);
assert($getResult->title === 'Test Article');
echo "[PASS] _lf_type_handle_get returns entity by id\n";

// Test get handler with non-existent ID
$matched_route = ['params' => ['id' => '999']];
$notFound = _lf_type_handle_get('article');
assert(isset($notFound['error']), 'Should return error for non-existent entity');
echo "[PASS] _lf_type_handle_get returns 404 for non-existent entity\n";

// Test delete handler
$matched_route = ['params' => ['id' => '2']];
$deleteResult = _lf_type_handle_delete('article');
assert($deleteResult['deleted'] === true);
assert(entity_load(2) === null);
echo "[PASS] _lf_type_handle_delete removes entity\n";

// Test delete non-existent
$matched_route = ['params' => ['id' => '999']];
$deleteNotFound = _lf_type_handle_delete('article');
assert(isset($deleteNotFound['error']));
echo "[PASS] _lf_type_handle_delete returns 404 for non-existent entity\n";

// --- Test 5: Invalid actions are skipped ---

$tmpFile = tempnam(sys_get_temp_dir(), 'lf_test_');
file_put_contents($tmpFile, "test_type:\n  name: string\n  \$api(list): public\n  \$api(patch): public\n  \$api(view): auth\n");
_lf_parse_types($tmpFile);
assert(isset($API_DIRECTIVES['test_type']));
assert(count($API_DIRECTIVES['test_type']) === 2, 'Should only have list and view, not patch');
assert(isset($API_DIRECTIVES['test_type']['list']));
assert(isset($API_DIRECTIVES['test_type']['view']));
assert(!isset($API_DIRECTIVES['test_type']['patch']), 'patch should be skipped');
unlink($tmpFile);
echo "[PASS] Invalid action (patch) is skipped\n";

// --- Test 6: Dispatch helper ---
// Restore types and DB for dispatch test
$TYPES = _lf_parse_types(__DIR__ . '/fixtures/types_api.yml');
$db = new Database(':memory:');
_lf_schema_apply($db, $TYPES);
entity_save('article', ['title' => 'Dispatch Test', 'body' => 'test']);

$matched_route = ['_type' => 'article', '_action' => 'list', 'params' => []];
$dispatchResult = _lf_type_dispatch($matched_route);
assert(isset($dispatchResult['data']));
echo "[PASS] _lf_type_dispatch routes to correct handler\n";

echo "\n=== All API directive tests passed ===\n";
