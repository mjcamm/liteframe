<?php

require_once __DIR__ . '/bootstrap.php';

echo "=== Pagination Tests ===\n\n";

$db = new Database(':memory:');
TestLF::set('db', $db);
TestLF::set('types', [
    'article' => [
        'title' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
    ],
]);
TestLF::call('schema_sync', $db, TestLF::get('types'));

for ($i = 1; $i <= 25; $i++) {
    $db->exec('INSERT INTO _entities (type) VALUES (?)', ['article']);
    $db->exec('INSERT INTO entities__article (id, title) VALUES (?, ?)', [$i, "Article {$i}"]);
}

$result = LF::query('article')->paginate(1, 10);
assert(count($result['data']) === 10);
assert($result['meta']['page'] === 1);
assert($result['meta']['per_page'] === 10);
assert($result['meta']['total'] === 25);
assert($result['meta']['total_pages'] === 3);
echo "[PASS] Page 1: 10 items, 3 total pages\n";

$result = LF::query('article')->paginate(2, 10);
assert(count($result['data']) === 10);
assert($result['meta']['page'] === 2);
echo "[PASS] Page 2: 10 items\n";

$result = LF::query('article')->paginate(3, 10);
assert(count($result['data']) === 5);
assert($result['meta']['page'] === 3);
assert($result['meta']['total_pages'] === 3);
echo "[PASS] Page 3 (last): 5 items\n";

$result = LF::query('article')->paginate(4, 10);
assert(count($result['data']) === 0);
assert($result['meta']['total'] === 25);
assert($result['meta']['total_pages'] === 3);
echo "[PASS] Beyond last page: empty data, meta still correct\n";

$result = LF::query('article')->paginate(0, 10);
assert($result['meta']['page'] === 1);
assert(count($result['data']) === 10);
echo "[PASS] Page 0 normalised to page 1\n";

$result = LF::query('article')->paginate();
assert($result['meta']['per_page'] === 20);
assert(count($result['data']) === 20);
assert($result['meta']['total_pages'] === 2);
echo "[PASS] Default pagination: 20 per page\n";

$result = LF::query('article')->where('title', 'Article 5')->paginate(1, 10);
assert(count($result['data']) === 1);
assert($result['meta']['total'] === 1);
assert($result['meta']['total_pages'] === 1);
echo "[PASS] Pagination with where clause\n";

$result = LF::query('article')->where('title', 'Nonexistent')->paginate(1, 10);
assert(count($result['data']) === 0);
assert($result['meta']['total'] === 0);
assert($result['meta']['total_pages'] === 0);
echo "[PASS] Empty results: total_pages = 0\n";

$result = LF::query('article')->sort('id', 'desc')->paginate(1, 5);
assert(count($result['data']) === 5);
assert($result['data'][0]->title === 'Article 25');
assert($result['data'][4]->title === 'Article 21');
echo "[PASS] Pagination with sort\n";

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/test';
$request = new Request();
TestLF::set('request', $request);
[$page, $perPage] = LF::paginate();
assert($page === 1);
assert($perPage === 20);
echo "[PASS] LF::paginate() defaults: page=1, per_page=20\n";

[$page, $perPage] = LF::paginate(50);
assert($perPage === 50);
echo "[PASS] LF::paginate(50) custom default per_page\n";

echo "\n=== All tests passed ===\n";
