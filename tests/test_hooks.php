<?php

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/EntityQuery.php';
require_once __DIR__ . '/../src/hooks.php';
require_once __DIR__ . '/../src/derived.php';
require_once __DIR__ . '/../src/validation.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/schema.php';
require_once __DIR__ . '/../src/files.php';

echo "=== Hook Tests ===\n\n";

// Bootstrap
$db = new Database(':memory:');
$types = _lf_parse_types(__DIR__ . '/fixtures/types.yml');
_lf_schema_apply($db, $types);

// Load hooks
_lf_hooks_load(__DIR__ . '/../hooks');

// Test 1: before_create hook can modify data
global $_hooks;
$_hooks['article']['before_create'] = function ($data) {
    $data['body'] = $data['body'] ?? 'default body';
    return $data;
};

$article = entity_save('article', ['title' => 'Hook Test']);
assert($article->body === 'default body', 'before_create should modify data');
echo "[PASS] before_create: hook modified data\n";

// Test 2: before_update hook can modify data
$_hooks['article']['before_update'] = function ($data, $original) {
    if (isset($data['title'])) {
        $data['title'] = strtoupper($data['title']);
    }
    return $data;
};

$updated = entity_save('article', ['id' => $article->id, 'title' => 'updated']);
assert($updated->title === 'UPDATED', 'before_update should modify data');
echo "[PASS] before_update: hook modified data\n";

// Reset hooks
unset($_hooks['article']['before_create']);
unset($_hooks['article']['before_update']);

// Test 3: Pages have no hooks — should work without errors
$page = entity_save('page', [
    'title' => 'About Us',
    'slug' => 'about',
]);
assert($page->title === 'About Us');
echo "[PASS] No hooks for page type — works fine\n";

// Test 4: before_delete can block deletion
$_hooks['article']['before_delete'] = function ($entity) {
    if ($entity->published) {
        return error(400, 'Cannot delete published articles');
    }
};

// Publish the article first
entity_save('article', ['id' => $article->id, 'published' => 1]);

$result = entity_delete($article->id);
assert(is_array($result) && $result['error'] === 'Cannot delete published articles');
assert(entity_load($article->id) !== null, 'Article should still exist');
echo "[PASS] before_delete blocked deletion of published article\n";

// Test 5: Unpublished article can be deleted
$article2 = entity_save('article', ['title' => 'Delete Me', 'body' => 'test', 'published' => 0]);
$result = entity_delete($article2->id);
assert($result === true);
assert(entity_load($article2->id) === null);
echo "[PASS] Unpublished article deleted successfully\n";

// Test 6: on_load hook transforms entities
$_hooks['article']['on_load'] = function ($entity) {
    $entity->loaded = true;
    return $entity;
};

$loaded = entity_load($article->id);
assert($loaded->loaded === true, 'on_load should add property');
echo "[PASS] on_load hook adds property\n";

unset($_hooks['article']['on_load']);

echo "\n=== All tests passed ===\n";
