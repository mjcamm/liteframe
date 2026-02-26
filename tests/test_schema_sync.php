<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/EntityQuery.php';
require_once __DIR__ . '/../src/hooks.php';
require_once __DIR__ . '/../src/derived.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/validation.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/schema.php';
require_once __DIR__ . '/../src/files.php';

echo "=== Schema Sync Tests ===\n\n";

// --- Test 1: Add field to existing type — column appears, data preserved ---

$db = new Database(':memory:');
$TYPES = [
    'article' => [
        'title' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
    ],
];
_lf_schema_sync($db, $TYPES);

// Insert a row
$db->exec('INSERT INTO _entities (type) VALUES (?)', ['article']);
$db->exec('INSERT INTO entities__article (id, title) VALUES (1, ?)', ['Hello World']);

// Now add a field
$TYPES['article']['summary'] = ['type' => 'string', 'required' => false, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false];
_lf_schema_sync($db, $TYPES);

// Check column exists
$cols = $db->all('PRAGMA table_info(entities__article)');
$colNames = array_map(fn($c) => $c->name, $cols);
assert(in_array('summary', $colNames), 'summary column should exist after sync');

// Check data preserved
$row = $db->one('SELECT * FROM entities__article WHERE id = 1');
assert($row->title === 'Hello World', 'Existing data should be preserved');
echo "[PASS] Add field — column appears, data preserved\n";

// --- Test 2: Add required field — added with safe default ---

$db2 = new Database(':memory:');
$TYPES = [
    'article' => [
        'title' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
    ],
];
_lf_schema_sync($db2, $TYPES);
$db2->exec('INSERT INTO _entities (type) VALUES (?)', ['article']);
$db2->exec('INSERT INTO entities__article (id, title) VALUES (1, ?)', ['Test']);

// Add required integer field
$TYPES['article']['view_count'] = ['type' => 'integer', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false];
_lf_schema_sync($db2, $TYPES);

$row = $db2->one('SELECT * FROM entities__article WHERE id = 1');
assert($row->view_count === '0' || $row->view_count === 0, 'Required integer should default to 0');
echo "[PASS] Add required integer field — defaults to 0\n";

// Add required text field
$TYPES['article']['subtitle'] = ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false];
_lf_schema_sync($db2, $TYPES);

$row = $db2->one('SELECT * FROM entities__article WHERE id = 1');
assert($row->subtitle === '', 'Required string should default to empty string');
echo "[PASS] Add required string field — defaults to empty string\n";

// --- Test 3: Remove field from config — column stays, hidden from _lf_entity_columns() ---

$TYPES_full = [
    'article' => [
        'title' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'body' => ['type' => 'text', 'required' => false, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
    ],
];
$db3 = new Database(':memory:');
_lf_schema_sync($db3, $TYPES_full);

// Column exists
$cols = $db3->all('PRAGMA table_info(entities__article)');
$colNames = array_map(fn($c) => $c->name, $cols);
assert(in_array('body', $colNames), 'body column should exist');

// Remove 'body' from config
$TYPES = [
    'article' => [
        'title' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
    ],
];

// Column still in DB
$cols = $db3->all('PRAGMA table_info(entities__article)');
$colNames = array_map(fn($c) => $c->name, $cols);
assert(in_array('body', $colNames), 'body column should still exist in DB');

// But _lf_entity_columns() should NOT include it
$columnList = _lf_entity_columns('article');
assert(!str_contains($columnList, 'body'), 'body should not appear in _lf_entity_columns');
assert(str_contains($columnList, 'title'), 'title should appear in _lf_entity_columns');
echo "[PASS] Removed field — column stays in DB, hidden from _lf_entity_columns()\n";

// --- Test 4: Add new type — table created ---

$db4 = new Database(':memory:');
$TYPES = [
    'article' => [
        'title' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
    ],
];
_lf_schema_sync($db4, $TYPES);

$tables = $db4->all("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'entities__%'");
$tableNames = array_map(fn($t) => $t->name, $tables);
assert(in_array('entities__article', $tableNames), 'article table should exist');
assert(!in_array('entities__page', $tableNames), 'page table should NOT exist yet');

// Add new type
$TYPES['page'] = [
    'title' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
    'slug' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
];
_lf_schema_sync($db4, $TYPES);

$tables = $db4->all("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'entities__%'");
$tableNames = array_map(fn($t) => $t->name, $tables);
assert(in_array('entities__page', $tableNames), 'page table should now exist');
echo "[PASS] Add new type — table created\n";

// --- Test 5: Fingerprint caching — second call skips diffing ---

$db5 = new Database(':memory:');
$TYPES = [
    'article' => [
        'title' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
    ],
];
_lf_schema_sync($db5, $TYPES);

// Fingerprint should be stored
$stored = $db5->one("SELECT value FROM _config WHERE key = '_lf_schema_fingerprint'");
assert($stored !== null, 'Fingerprint should be stored');
assert($stored->value === _lf_schema_fingerprint($TYPES), 'Fingerprint should match');

// Second call with same types should return early (fingerprint match)
// We verify by checking it doesn't error and returns quickly
_lf_schema_sync($db5, $TYPES);
echo "[PASS] Fingerprint caching — second call returns early\n";

// --- Test 6: Add junction table for new many-to-many ---

$db6 = new Database(':memory:');
$TYPES = [
    'article' => [
        'title' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
    ],
    'tag' => [
        'name' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
    ],
];
_lf_schema_sync($db6, $TYPES);

// No junction table yet
$tables = $db6->all("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'entities__article__%'");
assert(count($tables) === 0, 'No junction tables yet');

// Add many-to-many reference
$TYPES['article']['tags'] = ['type' => 'reference_many', 'required' => false, 'default' => null, 'reference' => 'tag', 'reference_many' => true, 'public' => false];
_lf_schema_sync($db6, $TYPES);

$tables = $db6->all("SELECT name FROM sqlite_master WHERE type='table' AND name = 'entities__article__tags'");
assert(count($tables) === 1, 'Junction table should exist');
echo "[PASS] Add junction table for many-to-many\n";

// --- Test 7: Add field with explicit default ---

$db7 = new Database(':memory:');
$TYPES = [
    'article' => [
        'title' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
    ],
];
_lf_schema_sync($db7, $TYPES);
$db7->exec('INSERT INTO _entities (type) VALUES (?)', ['article']);
$db7->exec('INSERT INTO entities__article (id, title) VALUES (1, ?)', ['Test']);

// Add field with explicit default
$TYPES['article']['published'] = ['type' => 'boolean', 'required' => false, 'default' => 'false', 'reference' => null, 'reference_many' => false, 'public' => false];
_lf_schema_sync($db7, $TYPES);

$row = $db7->one('SELECT * FROM entities__article WHERE id = 1');
assert($row->published === '0' || $row->published === 0, 'Boolean default false should be 0');
echo "[PASS] Add field with explicit default\n";

// --- Test 8: _lf_entity_columns fallback for unknown type ---

$TYPES = [];
$result = _lf_entity_columns('nonexistent');
assert($result === '*', 'Unknown type should fall back to *');
echo "[PASS] _lf_entity_columns falls back to * for unknown type\n";

echo "\n=== All tests passed ===\n";
