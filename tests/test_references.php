<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/EntityQuery.php';
require_once __DIR__ . '/../src/hooks.php';
require_once __DIR__ . '/../src/derived.php';
require_once __DIR__ . '/../src/validation.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/files.php';

echo "=== Reference Tests ===\n\n";

// Bootstrap with in-memory DB
$db = new Database(':memory:');

// Set up $TYPES — mimics what parse_types() produces from types.yml
$TYPES = [
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
];

// Create tables
$db->exec('CREATE TABLE _entities (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL)');

$db->exec('CREATE TABLE entities__user (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    role TEXT DEFAULT \'user\',
    password TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)');

$db->exec('CREATE TABLE entities__article (
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    body TEXT,
    slug TEXT,
    published INTEGER DEFAULT 0,
    author INTEGER,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)');

$db->exec('CREATE TABLE entities__tag (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)');

$db->exec('CREATE TABLE entities__article__tags (
    article_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    PRIMARY KEY (article_id, tag_id)
)');

// --- Create test data ---

// Test 1: Create a user (author)
// Need to manually insert since entity_save auto-hashes password and auth functions aren't loaded
$db->exec('INSERT INTO _entities (type) VALUES (?)', ['user']);
$userId = $db->lastId();
$db->exec(
    'INSERT INTO entities__user (id, name, email, role, password) VALUES (?, ?, ?, ?, ?)',
    [$userId, 'Jane Author', 'jane@example.com', 'editor', 'hashed']
);
echo "[PASS] Created user with ID: {$userId}\n";

// Test 2: Create tags
$db->exec('INSERT INTO _entities (type) VALUES (?)', ['tag']);
$tag1Id = $db->lastId();
$db->exec('INSERT INTO entities__tag (id, name) VALUES (?, ?)', [$tag1Id, 'PHP']);

$db->exec('INSERT INTO _entities (type) VALUES (?)', ['tag']);
$tag2Id = $db->lastId();
$db->exec('INSERT INTO entities__tag (id, name) VALUES (?, ?)', [$tag2Id, 'Tutorial']);

echo "[PASS] Created tags: PHP (ID: {$tag1Id}), Tutorial (ID: {$tag2Id})\n";

// Test 3: Create article with author reference and tags
$article = entity_save('article', [
    'title' => 'Getting Started with PHP',
    'body' => 'A quick tutorial...',
    'author' => $userId,
    'tags' => [$tag1Id, $tag2Id],
]);
assert($article->id !== null, 'Article has an ID');
assert($article->title === 'Getting Started with PHP');
echo "[PASS] Created article with ID: {$article->id}\n";

// Test 4: Verify author FK column was saved
$raw = $db->one('SELECT author FROM entities__article WHERE id = ?', [$article->id]);
assert((int) $raw->author === $userId, 'Author FK stored correctly');
echo "[PASS] Author FK column = {$raw->author}\n";

// Test 5: Verify junction table rows for tags
$junctions = $db->all('SELECT * FROM entities__article__tags WHERE article_id = ?', [$article->id]);
assert(count($junctions) === 2, 'Two junction rows created');
echo "[PASS] Junction table has " . count($junctions) . " rows\n";

// Test 6: entity_load without references — author is just an ID
$loaded = entity_load($article->id);
assert((int) $loaded->author === $userId, 'Author is raw ID without with()');
echo "[PASS] entity_load without refs: author = {$loaded->author} (raw ID)\n";

// Test 7: entity_load WITH references
$loaded = entity_load($article->id, ['author', 'tags']);
assert(is_object($loaded->author), 'Author is resolved to object');
assert($loaded->author->name === 'Jane Author', 'Author name loaded');
assert(is_array($loaded->tags), 'Tags is an array');
assert(count($loaded->tags) === 2, 'Two tags loaded');
assert($loaded->tags[0]->name === 'PHP' || $loaded->tags[1]->name === 'PHP', 'PHP tag present');
echo "[PASS] entity_load with refs: author = {$loaded->author->name}, tags = " . count($loaded->tags) . "\n";

// Test 8: entity_load with wildcard '*'
$loaded = entity_load($article->id, ['*']);
assert(is_object($loaded->author), 'Wildcard loads author');
assert(count($loaded->tags) === 2, 'Wildcard loads tags');
echo "[PASS] entity_load with '*' resolves all refs\n";

// Test 9: EntityQuery with()
$results = entity_query('article')->with('author', 'tags')->get();
assert(count($results) === 1);
assert(is_object($results[0]->author), 'Query with() resolves author');
assert($results[0]->author->name === 'Jane Author');
assert(count($results[0]->tags) === 2, 'Query with() resolves tags');
echo "[PASS] entity_query()->with() resolves references\n";

// Test 10: EntityQuery with('*')
$results = entity_query('article')->with('*')->first();
assert(is_object($results->author));
assert(count($results->tags) === 2);
echo "[PASS] entity_query()->with('*')->first() resolves all refs\n";

// Test 11: Update many-to-many — change tags
$db->exec('INSERT INTO _entities (type) VALUES (?)', ['tag']);
$tag3Id = $db->lastId();
$db->exec('INSERT INTO entities__tag (id, name) VALUES (?, ?)', [$tag3Id, 'Guide']);

$updated = entity_save('article', [
    'id' => $article->id,
    'tags' => [$tag1Id, $tag3Id],
]);
// Load with refs to check
$loaded = entity_load($article->id, ['tags']);
assert(count($loaded->tags) === 2, 'Still 2 tags after update');
$tagNames = array_map(fn($t) => $t->name, $loaded->tags);
assert(in_array('PHP', $tagNames), 'PHP tag kept');
assert(in_array('Guide', $tagNames), 'Guide tag added');
assert(!in_array('Tutorial', $tagNames), 'Tutorial tag removed');
echo "[PASS] Updated many-to-many: tags changed from [PHP, Tutorial] to [PHP, Guide]\n";

// Test 12: Update author reference
$db->exec('INSERT INTO _entities (type) VALUES (?)', ['user']);
$user2Id = $db->lastId();
$db->exec(
    'INSERT INTO entities__user (id, name, email, role, password) VALUES (?, ?, ?, ?, ?)',
    [$user2Id, 'Bob Writer', 'bob@example.com', 'user', 'hashed']
);

$updated = entity_save('article', [
    'id' => $article->id,
    'author' => $user2Id,
]);
$loaded = entity_load($article->id, ['author']);
assert($loaded->author->name === 'Bob Writer', 'Author updated');
echo "[PASS] Updated single reference: author changed to Bob Writer\n";

echo "\n=== All reference tests passed ===\n";
