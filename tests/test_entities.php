<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/EntityQuery.php';
require_once __DIR__ . '/../src/hooks.php';
require_once __DIR__ . '/../src/derived.php';
require_once __DIR__ . '/../src/validation.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/files.php';

echo "=== Entity Tests ===\n\n";

// Bootstrap with in-memory DB
$db = new Database(':memory:');
$TYPES = [];

$db->exec('CREATE TABLE _entities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL
)');

$db->exec('CREATE TABLE entities__article (
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    body TEXT,
    published INTEGER DEFAULT 0
)');

$db->exec('CREATE TABLE entities__page (
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    slug TEXT
)');

// Test 1: Create an article
$article = entity_save('article', [
    'title' => 'First Post',
    'body' => 'Hello world',
]);
assert($article->id === 1, 'First entity gets ID 1');
assert($article->title === 'First Post');
assert($article->_type === 'article');
echo "[PASS] Created article with global ID: {$article->id}\n";

// Test 2: Create a page — gets the next global ID
$page = entity_save('page', [
    'title' => 'About Us',
    'slug' => 'about',
]);
assert($page->id === 2, 'Second entity gets ID 2, regardless of type');
assert($page->_type === 'page');
echo "[PASS] Created page with global ID: {$page->id}\n";

// Test 3: Create another article — ID is 3, not 2
$article2 = entity_save('article', [
    'title' => 'Second Post',
    'body' => 'More content',
]);
assert($article2->id === 3, 'Third entity gets ID 3');
echo "[PASS] Second article has global ID: {$article2->id}\n";

// Test 4: Load by ID only — no type needed
$loaded = entity_load(1);
assert($loaded->title === 'First Post');
assert($loaded->_type === 'article');
echo "[PASS] entity_load(1) found article: {$loaded->title}\n";

$loaded2 = entity_load(2);
assert($loaded2->title === 'About Us');
assert($loaded2->_type === 'page');
echo "[PASS] entity_load(2) found page: {$loaded2->title}\n";

// Test 5: Update via entity_save (data has 'id')
$updated = entity_save('article', [
    'id' => 1,
    'title' => 'Updated First Post',
]);
assert($updated->title === 'Updated First Post');
echo "[PASS] entity_save with id updates: {$updated->title}\n";

// Test 6: Delete by ID only
$deleted = entity_delete(2);
assert($deleted === true);
$gone = entity_load(2);
assert($gone === null);
echo "[PASS] entity_delete(2) removed the page\n";

// Test 7: Delete non-existent
$nope = entity_delete(999);
assert($nope === false);
echo "[PASS] entity_delete(999) returns false\n";

// Test 8: entity_load_by
$found = entity_load_by('article', 'title', 'Updated First Post');
assert($found->id === 1);
echo "[PASS] entity_load_by found article by title\n";

// Test 9: entity_query still works
$all = entity_query('article')->sort('id', 'desc')->get();
assert(count($all) === 2);
assert($all[0]->id === 3);
echo "[PASS] entity_query returns " . count($all) . " articles\n";

echo "\n=== All tests passed ===\n";
