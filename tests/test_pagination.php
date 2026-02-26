<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/EntityQuery.php';
require_once __DIR__ . '/../src/Request.php';
require_once __DIR__ . '/../src/hooks.php';
require_once __DIR__ . '/../src/derived.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/validation.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/schema.php';
require_once __DIR__ . '/../src/files.php';

echo "=== Pagination Tests ===\n\n";

// Bootstrap
$db = new Database(':memory:');
$TYPES = [
    'article' => [
        'title' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
    ],
];
_lf_schema_sync($db, $TYPES);

// Seed 25 articles
for ($i = 1; $i <= 25; $i++) {
    $db->exec('INSERT INTO _entities (type) VALUES (?)', ['article']);
    $db->exec('INSERT INTO entities__article (id, title) VALUES (?, ?)', [$i, "Article {$i}"]);
}

// Test 1: Basic pagination — page 1 of 10 per page
$result = entity_query('article')->paginate(1, 10);
assert(count($result['data']) === 10, 'Page 1 should have 10 items');
assert($result['meta']['page'] === 1);
assert($result['meta']['per_page'] === 10);
assert($result['meta']['total'] === 25);
assert($result['meta']['total_pages'] === 3);
echo "[PASS] Page 1: 10 items, 3 total pages\n";

// Test 2: Page 2
$result = entity_query('article')->paginate(2, 10);
assert(count($result['data']) === 10, 'Page 2 should have 10 items');
assert($result['meta']['page'] === 2);
echo "[PASS] Page 2: 10 items\n";

// Test 3: Last page — partial
$result = entity_query('article')->paginate(3, 10);
assert(count($result['data']) === 5, 'Page 3 should have 5 items');
assert($result['meta']['page'] === 3);
assert($result['meta']['total_pages'] === 3);
echo "[PASS] Page 3 (last): 5 items\n";

// Test 4: Beyond last page — empty
$result = entity_query('article')->paginate(4, 10);
assert(count($result['data']) === 0, 'Page 4 should be empty');
assert($result['meta']['total'] === 25);
assert($result['meta']['total_pages'] === 3);
echo "[PASS] Beyond last page: empty data, meta still correct\n";

// Test 5: Page 0 treated as page 1
$result = entity_query('article')->paginate(0, 10);
assert($result['meta']['page'] === 1);
assert(count($result['data']) === 10);
echo "[PASS] Page 0 normalised to page 1\n";

// Test 6: Default per_page
$result = entity_query('article')->paginate();
assert($result['meta']['per_page'] === 20);
assert(count($result['data']) === 20);
assert($result['meta']['total_pages'] === 2);
echo "[PASS] Default pagination: 20 per page\n";

// Test 7: With where clause
$result = entity_query('article')->where('title', 'Article 5')->paginate(1, 10);
assert(count($result['data']) === 1);
assert($result['meta']['total'] === 1);
assert($result['meta']['total_pages'] === 1);
echo "[PASS] Pagination with where clause\n";

// Test 8: Empty results
$result = entity_query('article')->where('title', 'Nonexistent')->paginate(1, 10);
assert(count($result['data']) === 0);
assert($result['meta']['total'] === 0);
assert($result['meta']['total_pages'] === 0);
echo "[PASS] Empty results: total_pages = 0\n";

// Test 9: With sort
$result = entity_query('article')->sort('id', 'desc')->paginate(1, 5);
assert(count($result['data']) === 5);
assert($result['data'][0]->title === 'Article 25', 'First item should be Article 25 (desc sort)');
assert($result['data'][4]->title === 'Article 21');
echo "[PASS] Pagination with sort\n";

// Test 10: paginate_request defaults
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/test';
$request = new Request();
[$page, $perPage] = paginate_request();
assert($page === 1);
assert($perPage === 20);
echo "[PASS] paginate_request() defaults: page=1, per_page=20\n";

// Test 11: paginate_request custom default
[$page, $perPage] = paginate_request(50);
assert($perPage === 50);
echo "[PASS] paginate_request(50) custom default per_page\n";

echo "\n=== All tests passed ===\n";
