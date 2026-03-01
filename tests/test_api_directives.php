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
assert($routes['_api_article_list']['auth'] === 'public');
assert($routes['_api_article_list']['_type'] === 'article');
assert($routes['_api_article_list']['_action'] === 'list');
echo "[PASS] article list route: GET /api/article (public)\n";

assert(isset($routes['_api_article_get']));
assert($routes['_api_article_get']['path'] === '/api/article/:id');
assert($routes['_api_article_get']['method'] === 'GET');
assert($routes['_api_article_get']['auth'] === 'public');
assert($routes['_api_article_get']['_action'] === 'get');
echo "[PASS] article view route: GET /api/article/:id (public)\n";

assert(isset($routes['_api_article_create']));
assert($routes['_api_article_create']['path'] === '/api/article');
assert($routes['_api_article_create']['method'] === 'POST');
assert($routes['_api_article_create']['auth'] === 'auth');
echo "[PASS] article create route: POST /api/article (auth)\n";

assert(isset($routes['_api_article_update']));
assert($routes['_api_article_update']['path'] === '/api/article/:id');
assert($routes['_api_article_update']['method'] === 'PUT');
assert($routes['_api_article_update']['auth'] === 'auth');
echo "[PASS] article update route: PUT /api/article/:id (auth)\n";

assert(isset($routes['_api_article_delete']));
assert($routes['_api_article_delete']['path'] === '/api/article/:id');
assert($routes['_api_article_delete']['method'] === 'DELETE');
assert($routes['_api_article_delete']['auth'] === 'admin');
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

// --- Test 7: Filter with pipe operators ---
$TYPES = _lf_parse_types(__DIR__ . '/fixtures/types_api.yml');
$db = new Database(':memory:');
_lf_schema_apply($db, $TYPES);

entity_save('article', ['title' => 'Alpha Article', 'body' => 'first post', 'published' => true]);
entity_save('article', ['title' => 'Beta Article', 'body' => 'second post', 'published' => false]);
entity_save('article', ['title' => 'Gamma Guide', 'body' => 'third post', 'published' => true]);

// Grab actual IDs for comparison/range tests
$allIds = array_map(fn($e) => $e->id, entity_query('article')->sort('id', 'asc')->get());
$firstId = $allIds[0];
$secondId = $allIds[1];
$thirdId = $allIds[2];

// Exact match (no pipe)
$_GET = ['filter' => ['published' => '1']];
$request = new Request();
$result = _lf_type_handle_list('article');
assert($result['meta']['total'] === 2, 'Exact filter: 2 published articles');
echo "[PASS] Filter exact match: filter[published]=1\n";

// CONTAINS operator
$_GET = ['filter' => ['title' => 'Article|CONTAINS']];
$request = new Request();
$result = _lf_type_handle_list('article');
assert($result['meta']['total'] === 2, 'CONTAINS filter: 2 articles with "Article" in title');
echo "[PASS] Filter CONTAINS: filter[title]=Article|CONTAINS\n";

// STARTS_WITH operator
$_GET = ['filter' => ['title' => 'Gamma|STARTS_WITH']];
$request = new Request();
$result = _lf_type_handle_list('article');
assert($result['meta']['total'] === 1, 'STARTS_WITH filter: 1 article starting with "Gamma"');
echo "[PASS] Filter STARTS_WITH: filter[title]=Gamma|STARTS_WITH\n";

// Comparison operator
$_GET = ['filter' => ['id' => "{$secondId}|>"]];
$request = new Request();
$result = _lf_type_handle_list('article');
assert($result['meta']['total'] === 1, 'Greater than filter: 1 article with id > secondId');
echo "[PASS] Filter comparison: filter[id]={$secondId}|>\n";

// Multiple filters on same field (date range style) using [] syntax
$_GET = ['filter' => ['id' => ["{$firstId}|>=", "{$secondId}|<="]]];
$request = new Request();
$result = _lf_type_handle_list('article');
assert($result['meta']['total'] === 2, 'Range filter: 2 articles with id >= first AND id <= second');
echo "[PASS] Filter range: filter[id][]={$firstId}|>=&filter[id][]={$secondId}|<=\n";

// Invalid operator is silently skipped (no filter applied)
$_GET = ['filter' => ['title' => 'test|INVALID']];
$request = new Request();
$result = _lf_type_handle_list('article');
assert($result['meta']['total'] === 3, 'Invalid operator skipped: all 3 articles returned');
echo "[PASS] Invalid operator silently skipped\n";

// Unknown field is ignored
$_GET = ['filter' => ['nonexistent' => 'test']];
$request = new Request();
$result = _lf_type_handle_list('article');
assert($result['meta']['total'] === 3, 'Unknown field ignored: all 3 articles returned');
echo "[PASS] Unknown filter field ignored\n";

// --- Test 8: Combined filter (OR across fields) ---

// Search across title and body with CONTAINS
$_GET = ['combined_filter' => ['title,body' => 'Alpha|CONTAINS']];
$request = new Request();
$result = _lf_type_handle_list('article');
assert($result['meta']['total'] === 1, 'Combined filter: 1 article with "Alpha" in title or body');
echo "[PASS] Combined filter: combined_filter[title,body]=Alpha|CONTAINS\n";

// "post" appears in body of all 3 articles
$_GET = ['combined_filter' => ['title,body' => 'post|CONTAINS']];
$request = new Request();
$result = _lf_type_handle_list('article');
assert($result['meta']['total'] === 3, 'Combined filter: 3 articles with "post" in title or body');
echo "[PASS] Combined filter OR: matches across body field\n";

// "Guide" is in title of one, "first" is in body of another — OR finds both
$_GET = ['combined_filter' => ['title,body' => 'Guide|CONTAINS']];
$request = new Request();
$result = _lf_type_handle_list('article');
$guideCount = $result['meta']['total'];
$_GET = ['combined_filter' => ['title,body' => 'first|CONTAINS']];
$request = new Request();
$result = _lf_type_handle_list('article');
$firstCount = $result['meta']['total'];
assert($guideCount === 1 && $firstCount === 1, 'Combined filter finds matches in different fields');
echo "[PASS] Combined filter finds matches in different fields\n";

// Combined filter with regular filter (AND between them)
$_GET = ['filter' => ['published' => '1'], 'combined_filter' => ['title,body' => 'Article|CONTAINS']];
$request = new Request();
$result = _lf_type_handle_list('article');
assert($result['meta']['total'] === 1, 'Combined + regular filter: 1 published article with "Article"');
echo "[PASS] Combined filter works with regular filter (AND)\n";

// Unknown fields in combined filter are skipped
$_GET = ['combined_filter' => ['nonexistent,title' => 'Alpha|CONTAINS']];
$request = new Request();
$result = _lf_type_handle_list('article');
assert($result['meta']['total'] === 1, 'Combined filter: unknown fields skipped, valid ones still work');
echo "[PASS] Combined filter skips unknown fields\n";

// Clean up
$_GET = [];

echo "\n=== All API directive tests passed ===\n";
