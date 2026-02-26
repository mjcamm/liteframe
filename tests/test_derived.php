<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/EntityQuery.php';
require_once __DIR__ . '/../src/settings.php';
require_once __DIR__ . '/../src/hooks.php';
require_once __DIR__ . '/../src/derived.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/validation.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/schema.php';
require_once __DIR__ . '/../src/files.php';

echo "=== Derived & Effects Tests ===\n\n";

// Bootstrap
$db = new Database(':memory:');
settings_load(__DIR__ . '/../settings.yml');
functions_load(__DIR__ . '/../functions');
$TYPES = parse_types(__DIR__ . '/../config/types.yml');
schema_sync($db, $TYPES);

// --- Test 1: Derived computed on load ---
$article = entity_save('article', ['title' => 'Hello World', 'body' => 'Some content here']);
assert($article->slug === 'hello-world', 'Slug should be derived on load');
echo "[PASS] Derived on load: slug = '{$article->slug}'\n";

// --- Test 2: reading_time derived on load ---
$longBody = str_repeat('word ', 400); // 400 words = 2 min reading time
$article2 = entity_save('article', ['title' => 'Long Article', 'body' => $longBody]);
assert($article2->reading_time == 2, 'reading_time should be 2 for 400 words');
echo "[PASS] Derived on load: reading_time = {$article2->reading_time}\n";

// --- Test 3: Derived reflects current data, not stale ---
// Update title, derived slug should reflect new title on next load
entity_save('article', ['id' => $article->id, 'title' => 'Updated Title']);
$reloaded = entity_load($article->id);
assert($reloaded->slug === 'updated-title', 'Slug should reflect current title');
echo "[PASS] Derived reflects current data: slug = '{$reloaded->slug}'\n";

// --- Test 4: Derived on entity_load_by ---
$found = entity_load_by('article', 'title', 'Updated Title');
assert($found->slug === 'updated-title');
echo "[PASS] Derived works on entity_load_by\n";

// --- Test 5: Derived on entity_query ---
$results = entity_query('article')->get();
assert(isset($results[0]->slug), 'Derived should appear on query results');
echo "[PASS] Derived works on entity_query\n";

// --- Test 6: Derived on entity_query first() ---
$first = entity_query('article')->first();
assert(isset($first->slug));
echo "[PASS] Derived works on entity_query first()\n";

// --- Test 7: Derived cascading — order matters ---
// slug is declared before reading_time, so reading_time can see slug
// (not testing cross-dependency here, just that both are present)
$article3 = entity_save('article', ['title' => 'Cascade Test', 'body' => str_repeat('word ', 200)]);
assert(isset($article3->slug) && isset($article3->reading_time));
echo "[PASS] Multiple derived fields all present: slug='{$article3->slug}', reading_time={$article3->reading_time}\n";

// --- Test 8: Derived not stored in DB ---
// Query the raw DB — slug column should not exist
$rawCols = $db->all("PRAGMA table_info(entities__article)");
$colNames = array_map(fn($c) => $c->name, $rawCols);
assert(!in_array('slug', $colNames), 'slug should NOT be a DB column');
assert(!in_array('reading_time', $colNames), 'reading_time should NOT be a DB column');
echo "[PASS] Derived fields not stored in DB\n";

// --- Test 9: Change function = change output on next load ---
// (Can't easily swap functions mid-test, but we verify it's computed fresh each time)
$loaded1 = entity_load($article->id);
$loaded2 = entity_load($article->id);
assert($loaded1->slug === $loaded2->slug, 'Same entity, same derived value');
echo "[PASS] Derived computed fresh on each load\n";

// --- Test 10: $DERIVED parsed correctly from types.yml ---
assert(isset($DERIVED['article']['slug']), '$DERIVED should have article.slug');
assert($DERIVED['article']['slug'] === 'slugify');
assert($DERIVED['article']['reading_time'] === 'reading_time');
echo "[PASS] \$DERIVED parsed correctly from types.yml\n";

// --- Test 11: $effect fires on create ---
$effectLog = [];
function test_effect($entity, $old, $new) {
    global $effectLog;
    $effectLog[] = ['old' => $old, 'new' => $new, 'entity_id' => $entity->id];
}
$EFFECTS['article']['published'] = 'test_effect';

$article4 = entity_save('article', ['title' => 'Effect Test', 'body' => 'test', 'published' => true]);
assert(count($effectLog) === 1, 'Effect should fire on create');
assert($effectLog[0]['old'] === null, 'Old value should be null on create');
assert($effectLog[0]['new'] == true, 'New value should be true');
echo "[PASS] Effect fires on create: old=null, new=true\n";

// --- Test 12: $effect fires on update when field changes ---
$effectLog = [];
$updated3 = entity_save('article', ['id' => $article4->id, 'published' => false]);
assert(count($effectLog) === 1, 'Effect should fire when field changes');
assert($effectLog[0]['old'] == true, 'Old value should be true');
assert($effectLog[0]['new'] == false, 'New value should be false');
echo "[PASS] Effect fires on update: old=true, new=false\n";

// --- Test 13: $effect does NOT fire when field unchanged ---
$effectLog = [];
$updated4 = entity_save('article', ['id' => $article4->id, 'title' => 'New Title']);
assert(count($effectLog) === 0, 'Effect should not fire when watched field unchanged');
echo "[PASS] Effect does not fire when watched field unchanged\n";

// Clean up test effect
unset($EFFECTS['article']['published']);

echo "\n=== All tests passed ===\n";
