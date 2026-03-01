<?php

require_once __DIR__ . '/bootstrap.php';

echo "=== Reference Tests ===\n\n";

$db = new Database(':memory:');
TestLF::set('db', $db);

TestLF::set('types', [
    'user' => [
        'name' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'email' => ['type' => 'email', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'role' => ['type' => 'string', 'required' => false, 'default' => 'user', 'reference' => null, 'reference_many' => false, 'public' => false],
    ],
    'article' => [
        'title' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'body' => ['type' => 'richtext', 'required' => false, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'slug' => ['type' => 'string', 'required' => false, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'published' => ['type' => 'boolean', 'required' => false, 'default' => 'false', 'reference' => null, 'reference_many' => false, 'public' => false],
        'author' => ['type' => 'reference', 'required' => false, 'default' => null, 'reference' => 'user', 'reference_many' => false, 'public' => false],
        'tags' => ['type' => 'reference_many', 'required' => false, 'default' => null, 'reference' => 'tag', 'reference_many' => true, 'public' => false],
    ],
    'tag' => [
        'name' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
    ],
]);

$db->exec('CREATE TABLE _entities (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL)');
$db->exec('CREATE TABLE entities__user (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL, role TEXT DEFAULT \'user\', password TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE entities__article (id INTEGER PRIMARY KEY, title TEXT NOT NULL, body TEXT, slug TEXT, published INTEGER DEFAULT 0, author INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE entities__tag (id INTEGER PRIMARY KEY, name TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE entities__article__tags (article_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY (article_id, tag_id))');

$db->exec('INSERT INTO _entities (type) VALUES (?)', ['user']);
$userId = $db->lastId();
$db->exec('INSERT INTO entities__user (id, name, email, role, password) VALUES (?, ?, ?, ?, ?)', [$userId, 'Jane Author', 'jane@example.com', 'editor', 'hashed']);
echo "[PASS] Created user with ID: {$userId}\n";

$db->exec('INSERT INTO _entities (type) VALUES (?)', ['tag']);
$tag1Id = $db->lastId();
$db->exec('INSERT INTO entities__tag (id, name) VALUES (?, ?)', [$tag1Id, 'PHP']);
$db->exec('INSERT INTO _entities (type) VALUES (?)', ['tag']);
$tag2Id = $db->lastId();
$db->exec('INSERT INTO entities__tag (id, name) VALUES (?, ?)', [$tag2Id, 'Tutorial']);
echo "[PASS] Created tags: PHP (ID: {$tag1Id}), Tutorial (ID: {$tag2Id})\n";

$article = LF::save('article', ['title' => 'Getting Started with PHP', 'body' => 'A quick tutorial...', 'author' => $userId, 'tags' => [$tag1Id, $tag2Id]]);
assert($article->id !== null);
assert($article->title === 'Getting Started with PHP');
echo "[PASS] Created article with ID: {$article->id}\n";

$raw = $db->one('SELECT author FROM entities__article WHERE id = ?', [$article->id]);
assert((int) $raw->author === $userId);
echo "[PASS] Author FK column = {$raw->author}\n";

$junctions = $db->all('SELECT * FROM entities__article__tags WHERE article_id = ?', [$article->id]);
assert(count($junctions) === 2);
echo "[PASS] Junction table has " . count($junctions) . " rows\n";

$loaded = LF::load($article->id);
assert((int) $loaded->author === $userId);
echo "[PASS] LF::load without refs: author = {$loaded->author} (raw ID)\n";

$loaded = LF::load($article->id, ['author', 'tags']);
assert(is_object($loaded->author));
assert($loaded->author->name === 'Jane Author');
assert(is_array($loaded->tags));
assert(count($loaded->tags) === 2);
assert($loaded->tags[0]->name === 'PHP' || $loaded->tags[1]->name === 'PHP');
echo "[PASS] LF::load with refs: author = {$loaded->author->name}, tags = " . count($loaded->tags) . "\n";

$loaded = LF::load($article->id, ['*']);
assert(is_object($loaded->author));
assert(count($loaded->tags) === 2);
echo "[PASS] LF::load with '*' resolves all refs\n";

$results = LF::query('article')->with('author', 'tags')->get();
assert(count($results) === 1);
assert(is_object($results[0]->author));
assert($results[0]->author->name === 'Jane Author');
assert(count($results[0]->tags) === 2);
echo "[PASS] LF::query()->with() resolves references\n";

$results = LF::query('article')->with('*')->first();
assert(is_object($results->author));
assert(count($results->tags) === 2);
echo "[PASS] LF::query()->with('*')->first() resolves all refs\n";

$db->exec('INSERT INTO _entities (type) VALUES (?)', ['tag']);
$tag3Id = $db->lastId();
$db->exec('INSERT INTO entities__tag (id, name) VALUES (?, ?)', [$tag3Id, 'Guide']);

$updated = LF::save('article', ['id' => $article->id, 'tags' => [$tag1Id, $tag3Id]]);
$loaded = LF::load($article->id, ['tags']);
assert(count($loaded->tags) === 2);
$tagNames = array_map(fn($t) => $t->name, $loaded->tags);
assert(in_array('PHP', $tagNames));
assert(in_array('Guide', $tagNames));
assert(!in_array('Tutorial', $tagNames));
echo "[PASS] Updated many-to-many: tags changed from [PHP, Tutorial] to [PHP, Guide]\n";

$db->exec('INSERT INTO _entities (type) VALUES (?)', ['user']);
$user2Id = $db->lastId();
$db->exec('INSERT INTO entities__user (id, name, email, role, password) VALUES (?, ?, ?, ?, ?)', [$user2Id, 'Bob Writer', 'bob@example.com', 'user', 'hashed']);

$updated = LF::save('article', ['id' => $article->id, 'author' => $user2Id]);
$loaded = LF::load($article->id, ['author']);
assert($loaded->author->name === 'Bob Writer');
echo "[PASS] Updated single reference: author changed to Bob Writer\n";

echo "\n=== All reference tests passed ===\n";
