<?php

require_once __DIR__ . '/bootstrap.php';

echo "=== Entity Tests ===\n\n";

$db = new Database(':memory:');
TestLF::set('db', $db);
TestLF::set('types', []);

$db->exec('CREATE TABLE _entities (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL)');
$db->exec('CREATE TABLE entities__article (id INTEGER PRIMARY KEY, title TEXT NOT NULL, body TEXT, published INTEGER DEFAULT 0)');
$db->exec('CREATE TABLE entities__page (id INTEGER PRIMARY KEY, title TEXT NOT NULL, slug TEXT)');

$article = LF::save('article', ['title' => 'First Post', 'body' => 'Hello world']);
assert($article->id === 1, 'First entity gets ID 1');
assert($article->title === 'First Post');
assert($article->_type === 'article');
echo "[PASS] Created article with global ID: {$article->id}\n";

$page = LF::save('page', ['title' => 'About Us', 'slug' => 'about']);
assert($page->id === 2, 'Second entity gets ID 2, regardless of type');
assert($page->_type === 'page');
echo "[PASS] Created page with global ID: {$page->id}\n";

$article2 = LF::save('article', ['title' => 'Second Post', 'body' => 'More content']);
assert($article2->id === 3, 'Third entity gets ID 3');
echo "[PASS] Second article has global ID: {$article2->id}\n";

$loaded = LF::load(1);
assert($loaded->title === 'First Post');
assert($loaded->_type === 'article');
echo "[PASS] LF::load(1) found article: {$loaded->title}\n";

$loaded2 = LF::load(2);
assert($loaded2->title === 'About Us');
assert($loaded2->_type === 'page');
echo "[PASS] LF::load(2) found page: {$loaded2->title}\n";

$updated = LF::save('article', ['id' => 1, 'title' => 'Updated First Post']);
assert($updated->title === 'Updated First Post');
echo "[PASS] LF::save with id updates: {$updated->title}\n";

$deleted = LF::delete(2);
assert($deleted === true);
$gone = LF::load(2);
assert($gone === null);
echo "[PASS] LF::delete(2) removed the page\n";

$nope = LF::delete(999);
assert($nope === false);
echo "[PASS] LF::delete(999) returns false\n";

$found = LF::load_by('article', 'title', 'Updated First Post');
assert($found->id === 1);
echo "[PASS] LF::load_by found article by title\n";

$all = LF::query('article')->sort('id', 'desc')->get();
assert(count($all) === 2);
assert($all[0]->id === 3);
echo "[PASS] LF::query returns " . count($all) . " articles\n";

echo "\n=== All tests passed ===\n";
