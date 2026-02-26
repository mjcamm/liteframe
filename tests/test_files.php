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

echo "=== File Upload Tests ===\n\n";

// Bootstrap
$db = new Database(':memory:');
settings_load(__DIR__ . '/../settings.yml');
functions_load(__DIR__ . '/../functions');
$TYPES = parse_types(__DIR__ . '/../config/types.yml');
schema_sync($db, $TYPES);

// Use a temp directory for file storage during tests
$testDir = sys_get_temp_dir() . '/lightframe_test_' . uniqid();
mkdir($testDir . '/files/public', 0755, true);
mkdir($testDir . '/files/protected', 0755, true);
define('LIGHTFRAME_PROJECT_DIR', $testDir);

// --- Test 1: parse_field recognizes file type ---
$f = parse_field('file');
assert($f['type'] === 'file' && $f['public'] === false);
echo "[PASS] parse_field('file') — type=file, public=false\n";

$f = parse_field('file, public=true');
assert($f['type'] === 'file' && $f['public'] === true);
echo "[PASS] parse_field('file, public=true') — public=true\n";

// --- Test 2: file field maps to INTEGER in SQLite ---
assert(field_to_sqlite('file') === 'INTEGER');
echo "[PASS] file maps to INTEGER column\n";

// --- Test 3: _files table exists ---
$tables = $db->all("SELECT name FROM sqlite_master WHERE type='table' AND name='_files'");
assert(count($tables) === 1);
echo "[PASS] _files table created\n";

// --- Test 4: file_validate — valid file ---
$fakeFile = [
    'name' => 'test.jpg',
    'type' => 'image/jpeg',
    'tmp_name' => tempnam(sys_get_temp_dir(), 'test'),
    'error' => UPLOAD_ERR_OK,
    'size' => 1024,
];
file_put_contents($fakeFile['tmp_name'], str_repeat('x', 1024));
$error = file_validate($fakeFile);
assert($error === null, 'Valid file should pass validation');
echo "[PASS] file_validate — valid jpg passes\n";

// --- Test 5: file_validate — disallowed extension ---
$badFile = $fakeFile;
$badFile['name'] = 'test.exe';
$error = file_validate($badFile);
assert($error !== null && str_contains($error, '.exe'));
echo "[PASS] file_validate — .exe rejected: {$error}\n";

// --- Test 6: file_validate — too large ---
$bigFile = $fakeFile;
$bigFile['size'] = 999 * 1024 * 1024; // 999MB
$error = file_validate($bigFile);
assert($error !== null && str_contains($error, 'size'));
echo "[PASS] file_validate — oversized rejected: {$error}\n";

// --- Test 7: parse_file_size ---
assert(parse_file_size('10M') === 10 * 1024 * 1024);
assert(parse_file_size('1K') === 1024);
assert(parse_file_size('2G') === 2 * 1024 * 1024 * 1024);
assert(parse_file_size('500') === 500);
echo "[PASS] parse_file_size works\n";

// --- Test 8: file_store — public file ---
$publicFile = [
    'name' => 'photo.jpg',
    'type' => 'image/jpeg',
    'tmp_name' => tempnam(sys_get_temp_dir(), 'test'),
    'error' => UPLOAD_ERR_OK,
    'size' => 512,
];
file_put_contents($publicFile['tmp_name'], str_repeat('x', 512));

$fileId = file_store($publicFile, 'public', 'article', 1, 'featured_image');
assert($fileId > 0, 'Should return a file ID');
$row = $db->one('SELECT * FROM _files WHERE id = ?', [$fileId]);
assert($row->filename === 'photo.jpg');
assert($row->storage === 'public');
assert($row->entity_type === 'article');
assert($row->entity_id === 1);
assert($row->field === 'featured_image');
// Check file exists on disk
assert(file_exists($testDir . '/files/public/' . $row->stored_name));
echo "[PASS] file_store — public file stored, _files row created\n";

// --- Test 9: file_resolve — public URL ---
$resolved = file_resolve($fileId);
assert($resolved->filename === 'photo.jpg');
assert(str_starts_with($resolved->url, '/files/public/'));
// Server-side MIME detection will detect the fake content as application/octet-stream
assert(!empty($resolved->mime_type));
assert($resolved->size === 512);
echo "[PASS] file_resolve — public URL: {$resolved->url}\n";

// --- Test 10: file_store — protected file ---
$protectedFile = [
    'name' => 'secret.pdf',
    'type' => 'application/pdf',
    'tmp_name' => tempnam(sys_get_temp_dir(), 'test'),
    'error' => UPLOAD_ERR_OK,
    'size' => 256,
];
file_put_contents($protectedFile['tmp_name'], str_repeat('x', 256));

$protectedId = file_store($protectedFile, 'protected', 'article', 1, 'document');
$resolved2 = file_resolve($protectedId);
assert($resolved2->url === '/api/files/' . $protectedId);
assert(file_exists($testDir . '/files/protected/' . $db->one('SELECT stored_name FROM _files WHERE id = ?', [$protectedId])->stored_name));
echo "[PASS] file_store — protected file stored, URL: {$resolved2->url}\n";

// --- Test 11: file_delete — removes from disk and DB ---
$storedName = $db->one('SELECT stored_name FROM _files WHERE id = ?', [$fileId])->stored_name;
$diskPath = $testDir . '/files/public/' . $storedName;
assert(file_exists($diskPath));
file_delete($fileId);
assert(!file_exists($diskPath), 'File should be deleted from disk');
assert($db->one('SELECT id FROM _files WHERE id = ?', [$fileId]) === null, 'Row should be deleted');
echo "[PASS] file_delete — removed from disk and DB\n";

// --- Test 12: file_resolve_entity — resolves file IDs on loaded entity ---
// Create an article with a file ID manually
$article = entity_save('article', ['title' => 'With File', 'body' => 'test']);
// Manually set file field (normally done by file_process_uploads)
$db->exec('UPDATE entities__article SET document = ? WHERE id = ?', [$protectedId, $article->id]);
$loaded = entity_load($article->id);
assert(is_object($loaded->document), 'File field should be resolved to object');
assert($loaded->document->filename === 'secret.pdf');
assert($loaded->document->url === '/api/files/' . $protectedId);
echo "[PASS] file_resolve_entity — file ID resolved on load\n";

// --- Test 13: file_resolve_entity via entity_query ---
$queried = entity_query('article')->first();
assert(is_object($queried->document));
assert($queried->document->filename === 'secret.pdf');
echo "[PASS] file_resolve_entity — works via entity_query\n";

// --- Test 14: file_cleanup_entity — deletes all files for an entity ---
$cleanupFile = [
    'name' => 'cleanup.jpg',
    'type' => 'image/jpeg',
    'tmp_name' => tempnam(sys_get_temp_dir(), 'test'),
    'error' => UPLOAD_ERR_OK,
    'size' => 100,
];
file_put_contents($cleanupFile['tmp_name'], str_repeat('x', 100));
$cleanupId = file_store($cleanupFile, 'public', 'article', $article->id, 'featured_image');
$filesBefore = $db->all('SELECT id FROM _files WHERE entity_type = ? AND entity_id = ?', ['article', $article->id]);
assert(count($filesBefore) === 2, 'Should have 2 files');

file_cleanup_entity('article', $article->id);
$filesAfter = $db->all('SELECT id FROM _files WHERE entity_type = ? AND entity_id = ?', ['article', $article->id]);
assert(count($filesAfter) === 0, 'All files should be cleaned up');
echo "[PASS] file_cleanup_entity — removed all files\n";

// --- Test 15: entity_delete cleans up files ---
$article2 = entity_save('article', ['title' => 'Delete Me', 'body' => 'test']);
$delFile = [
    'name' => 'to_delete.png',
    'type' => 'image/png',
    'tmp_name' => tempnam(sys_get_temp_dir(), 'test'),
    'error' => UPLOAD_ERR_OK,
    'size' => 50,
];
file_put_contents($delFile['tmp_name'], str_repeat('x', 50));
$delFileId = file_store($delFile, 'public', 'article', $article2->id, 'featured_image');
entity_delete($article2->id);
assert($db->one('SELECT id FROM _files WHERE id = ?', [$delFileId]) === null, 'File should be deleted with entity');
echo "[PASS] entity_delete cleans up associated files\n";

// --- Test 16: types.yml has file fields parsed correctly ---
assert($TYPES['article']['featured_image']['type'] === 'file');
assert($TYPES['article']['featured_image']['public'] === true);
assert($TYPES['article']['document']['type'] === 'file');
assert($TYPES['article']['document']['public'] === false);
echo "[PASS] types.yml file fields parsed correctly\n";

// Cleanup temp dir
array_map('unlink', glob($testDir . '/files/public/*'));
array_map('unlink', glob($testDir . '/files/protected/*'));
rmdir($testDir . '/files/public');
rmdir($testDir . '/files/protected');
rmdir($testDir . '/files');
rmdir($testDir);

echo "\n=== All tests passed ===\n";
