<?php

require_once __DIR__ . '/bootstrap.php';

echo "=== Schema Tests ===\n\n";

// Test 1: Parse field definitions
$f = TestLF::call('parse_field', 'string');
assert($f['type'] === 'string' && !$f['required'] && $f['default'] === null && $f['public'] === false);
echo "[PASS] Parse: string\n";

// required=true modifier no longer supported — use field_name* syntax in types.yml
$f = TestLF::call('parse_field', 'string, required=true');
assert($f['required'] === false, 'required=true modifier should be ignored');
echo "[PASS] Parse: required=true modifier ignored\n";

$f = TestLF::call('parse_field', 'boolean, default=false');
assert($f['type'] === 'boolean' && $f['default'] === 'false');
echo "[PASS] Parse: boolean, default=false\n";

$f = TestLF::call('parse_field', 'enum(news, tutorial, review)');
assert($f['type'] === 'enum(news, tutorial, review)');
echo "[PASS] Parse: enum(news, tutorial, review)\n";

$f = TestLF::call('parse_field', 'file, public=true');
assert($f['type'] === 'file' && $f['public'] === true);
echo "[PASS] Parse: file, public=true\n";

// Test 2: field_name* shorthand sets required=true via _lf_parse_types
$types = TestLF::call('parse_types', __DIR__ . '/fixtures/types.yml');
assert($types['article']['title']['required'] === true, 'title* should be required');
assert($types['article']['body']['required'] === false, 'body should not be required');
assert($types['user']['name']['required'] === true, 'name* should be required');
assert($types['user']['email']['required'] === true, 'email* should be required');
echo "[PASS] field_name* shorthand sets required=true\n";

assert(isset($types['article']), 'article type should exist');
assert(isset($types['page']), 'page type should exist');
echo "[PASS] Parsed types.yml: " . count($types) . " types\n";

// Test 3: Generate SQL — check table names have prefix
$statements = TestLF::call('generate_schema', $types);
$hasRegistry = false;
$hasArticle = false;
$hasPage = false;
foreach ($statements as $sql) {
    if (str_contains($sql, '_entities')) $hasRegistry = true;
    if (str_contains($sql, 'entities__article')) $hasArticle = true;
    if (str_contains($sql, 'entities__page')) $hasPage = true;
}
assert($hasRegistry, 'Should have _entities table');
assert($hasArticle, 'Should have entities__article table');
assert($hasPage, 'Should have entities__page table');
echo "[PASS] Tables use entities__ prefix\n";

// Test 4: Apply schema and test with entity functions
$db = new Database(':memory:');
TestLF::set('db', $db);
TestLF::call('schema_apply', $db, $types);

TestLF::set('types', $types);

// Check tables exist
$tables = $db->all("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
$tableNames = array_map(fn($t) => $t->name, $tables);
assert(in_array('entities__article', $tableNames));
assert(in_array('entities__page', $tableNames));
echo "[PASS] Tables created: " . implode(', ', $tableNames) . "\n";

// Test 5: entity_save uses type name, not table name
$article = LF::save('article', ['title' => 'Test', 'body' => 'Hello']);
assert($article->id === 1);
assert($article->_type === 'article');
echo "[PASS] entity_save('article', ...) works — ID: {$article->id}\n";

$page = LF::save('page', ['title' => 'About', 'slug' => 'about']);
assert($page->id === 2);
assert($page->_type === 'page');
echo "[PASS] entity_save('page', ...) works — ID: {$page->id}\n";

// Test 6: entity_load by ID only
$loaded = LF::load(1);
assert($loaded->title === 'Test');
assert($loaded->_type === 'article');
echo "[PASS] entity_load(1) found article\n";

$loaded2 = LF::load(2);
assert($loaded2->_type === 'page');
echo "[PASS] entity_load(2) found page\n";

// Test 7: entity_query uses type name
$all = LF::query('article')->get();
assert(count($all) === 1);
echo "[PASS] entity_query('article') returns " . count($all) . " result\n";

// Test 8: entity_delete
LF::delete(1);
assert(LF::load(1) === null);
echo "[PASS] entity_delete(1) removed article\n";

echo "\n=== All tests passed ===\n";
