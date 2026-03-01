<?php

require_once __DIR__ . '/bootstrap.php';

echo "=== Hook Tests ===\n\n";

// Bootstrap
$db = new Database(':memory:');
TestLF::set('db', $db);
$types = TestLF::call('parse_types', __DIR__ . '/fixtures/types.yml');
TestLF::set('types', $types);
TestLF::call('schema_apply', $db, $types);

// Load hooks
TestLF::call('hooks_load', __DIR__ . '/../hooks');

// Test 1: before_create hook can modify data
$hooks = TestLF::get('hooks');
$hooks['article']['before_create'] = function ($data) {
    $data['body'] = $data['body'] ?? 'default body';
    return $data;
};
TestLF::set('hooks', $hooks);

$article = LF::save('article', ['title' => 'Hook Test']);
assert($article->body === 'default body', 'before_create should modify data');
echo "[PASS] before_create: hook modified data\n";

// Test 2: before_update hook can modify data
$hooks = TestLF::get('hooks');
$hooks['article']['before_update'] = function ($data, $original) {
    if (isset($data['title'])) {
        $data['title'] = strtoupper($data['title']);
    }
    return $data;
};
TestLF::set('hooks', $hooks);

$updated = LF::save('article', ['id' => $article->id, 'title' => 'updated']);
assert($updated->title === 'UPDATED', 'before_update should modify data');
echo "[PASS] before_update: hook modified data\n";

// Reset hooks
$hooks = TestLF::get('hooks');
unset($hooks['article']['before_create']);
unset($hooks['article']['before_update']);
TestLF::set('hooks', $hooks);

// Test 3: Pages have no hooks — should work without errors
$page = LF::save('page', [
    'title' => 'About Us',
    'slug' => 'about',
]);
assert($page->title === 'About Us');
echo "[PASS] No hooks for page type — works fine\n";

// Test 4: before_delete can block deletion
$hooks = TestLF::get('hooks');
$hooks['article']['before_delete'] = function ($entity) {
    if ($entity->published) {
        return LF::error(400, 'Cannot delete published articles');
    }
};
TestLF::set('hooks', $hooks);

// Publish the article first
LF::save('article', ['id' => $article->id, 'published' => 1]);

$result = LF::delete($article->id);
assert(is_array($result) && $result['error'] === 'Cannot delete published articles');
assert(LF::load($article->id) !== null, 'Article should still exist');
echo "[PASS] before_delete blocked deletion of published article\n";

// Test 5: Unpublished article can be deleted
$article2 = LF::save('article', ['title' => 'Delete Me', 'body' => 'test', 'published' => 0]);
$result = LF::delete($article2->id);
assert($result === true);
assert(LF::load($article2->id) === null);
echo "[PASS] Unpublished article deleted successfully\n";

// Test 6: on_load hook transforms entities
$hooks = TestLF::get('hooks');
$hooks['article']['on_load'] = function ($entity) {
    $entity->loaded = true;
    return $entity;
};
TestLF::set('hooks', $hooks);

$loaded = LF::load($article->id);
assert($loaded->loaded === true, 'on_load should add property');
echo "[PASS] on_load hook adds property\n";

$hooks = TestLF::get('hooks');
unset($hooks['article']['on_load']);
TestLF::set('hooks', $hooks);

echo "\n=== All tests passed ===\n";
