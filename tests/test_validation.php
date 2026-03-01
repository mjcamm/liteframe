<?php

require_once __DIR__ . '/bootstrap.php';

echo "=== Validation Tests ===\n\n";

// Bootstrap
$db = new Database(':memory:');
TestLF::set('db', $db);
$TYPES = [
    'article' => [
        'title' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'body' => ['type' => 'richtext', 'required' => false, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'email' => ['type' => 'email', 'required' => false, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'publish_date' => ['type' => 'date', 'required' => false, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'updated_on' => ['type' => 'datetime', 'required' => false, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'view_count' => ['type' => 'integer', 'required' => false, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'rating' => ['type' => 'number', 'required' => false, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'published' => ['type' => 'boolean', 'required' => false, 'default' => 'false', 'reference' => null, 'reference_many' => false, 'public' => false],
        'category' => ['type' => 'enum(news, tutorial, review)', 'required' => false, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'metadata' => ['type' => 'json', 'required' => false, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'cover' => ['type' => 'file', 'required' => false, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'author' => ['type' => 'reference', 'required' => false, 'default' => null, 'reference' => 'user', 'reference_many' => false, 'public' => false],
        'tags' => ['type' => 'reference_many', 'required' => false, 'default' => null, 'reference' => 'tag', 'reference_many' => true, 'public' => false],
    ],
    'user' => [
        'name' => ['type' => 'string', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
        'email' => ['type' => 'email', 'required' => true, 'default' => null, 'reference' => null, 'reference_many' => false, 'public' => false],
    ],
];
TestLF::set('types', $TYPES);

$db->exec('CREATE TABLE _entities (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL)');
$db->exec('CREATE TABLE entities__article (
    id INTEGER PRIMARY KEY, title TEXT NOT NULL, body TEXT, email TEXT,
    publish_date TEXT, updated_on TEXT, view_count INTEGER, rating REAL,
    published INTEGER DEFAULT 0, category TEXT, metadata TEXT, cover INTEGER, author INTEGER,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)');
$db->exec('CREATE TABLE entities__article__tags (article_id INTEGER, tag_id INTEGER, PRIMARY KEY (article_id, tag_id))');

// --- Test 1: Required field missing on create ---
$result = TestLF::call('entity_validate', 'article', [], false);
assert($result !== null, 'Should fail validation');
assert($result['error'] === 'Validation failed');
assert($result['fields']['title'] === 'Required');
echo "[PASS] Required field missing on create\n";

// --- Test 2: Required field present and valid ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Hello'], false);
assert($result === null, 'Should pass validation');
echo "[PASS] Required field present and valid\n";

// --- Test 3: Required field with default — not required on create ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Hello'], false);
assert($result === null, 'published has default, not required');
echo "[PASS] Required field with default not flagged\n";

// --- Test 4: Optional field missing on create — passes ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Hello'], false);
assert($result === null);
echo "[PASS] Optional fields not required\n";

// --- Test 5: Partial update skips required check ---
$result = TestLF::call('entity_validate', 'article', ['id' => 1, 'body' => 'Updated body'], true);
assert($result === null, 'Update with only optional field should pass');
echo "[PASS] Partial update skips required check\n";

// --- Test 6: Update with invalid field value ---
$result = TestLF::call('entity_validate', 'article', ['id' => 1, 'view_count' => 'abc'], true);
assert($result !== null);
assert($result['fields']['view_count'] === 'Must be an integer');
echo "[PASS] Update with invalid value caught\n";

// --- Test 7: Email validation ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'email' => 'valid@example.com'], false);
assert($result === null);

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'email' => 'not-an-email'], false);
assert($result !== null);
assert($result['fields']['email'] === 'Invalid email format');
echo "[PASS] Email validation\n";

// --- Test 8: Date validation ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'publish_date' => '2024-06-15'], false);
assert($result === null);

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'publish_date' => '2024-02-30'], false);
assert($result !== null);
assert(str_contains($result['fields']['publish_date'], 'does not exist'));

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'publish_date' => 'not-a-date'], false);
assert($result !== null);
assert(str_contains($result['fields']['publish_date'], 'YYYY-MM-DD'));
echo "[PASS] Date validation\n";

// --- Test 9: Integer validation ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'view_count' => 123], false);
assert($result === null);

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'view_count' => '456'], false);
assert($result === null, '"456" is numeric and integer-like');

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'view_count' => 'abc'], false);
assert($result !== null);
assert($result['fields']['view_count'] === 'Must be an integer');

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'view_count' => 3.5], false);
assert($result !== null);
assert($result['fields']['view_count'] === 'Must be an integer');
echo "[PASS] Integer validation\n";

// --- Test 10: Number validation ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'rating' => 4.5], false);
assert($result === null);

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'rating' => 'abc'], false);
assert($result !== null);
assert($result['fields']['rating'] === 'Must be a number');
echo "[PASS] Number validation\n";

// --- Test 11: Boolean validation ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'published' => true], false);
assert($result === null);

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'published' => 0], false);
assert($result === null);

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'published' => 'false'], false);
assert($result === null);

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'published' => 'yes'], false);
assert($result !== null);
assert($result['fields']['published'] === 'Must be a boolean');
echo "[PASS] Boolean validation\n";

// --- Test 12: Enum validation ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'category' => 'news'], false);
assert($result === null);

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'category' => 'opinion'], false);
assert($result !== null);
assert($result['fields']['category'] === 'Must be one of: news, tutorial, review');
echo "[PASS] Enum validation\n";

// --- Test 13: JSON validation ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'metadata' => '{"key": "value"}'], false);
assert($result === null);

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'metadata' => 'not json {'], false);
assert($result !== null);
assert($result['fields']['metadata'] === 'Must be valid JSON');
echo "[PASS] JSON validation\n";

// --- Test 14: Type coercion ---
$data = TestLF::call('entity_coerce', 'article', ['view_count' => '123', 'rating' => '4.5', 'published' => 'true', 'cover' => '7']);
assert($data['view_count'] === 123, 'String to int');
assert($data['rating'] === 4.5, 'String to float');
assert($data['published'] === 1, 'String true to 1');
assert($data['cover'] === 7, 'String file ID to int');
echo "[PASS] Type coercion\n";

// --- Test 15: Unknown field ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'nonexistent' => 'value'], false);
assert($result !== null);
assert($result['fields']['nonexistent'] === 'Unknown field');
echo "[PASS] Unknown field detected\n";

// --- Test 16: Multiple errors at once ---
$result = TestLF::call('entity_validate', 'article', ['email' => 'bad', 'view_count' => 'abc', 'unknown_field' => 'x'], false);
assert($result !== null);
assert(count($result['fields']) >= 3, 'Should have at least 3 errors');
assert(isset($result['fields']['title']), 'Missing required title');
assert(isset($result['fields']['email']), 'Invalid email');
assert(isset($result['fields']['view_count']), 'Invalid integer');
echo "[PASS] Multiple errors returned at once\n";

// --- Test 17: entity_validate standalone returns null on valid ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Valid Article'], false);
assert($result === null);
echo "[PASS] entity_validate returns null on valid data\n";

// --- Test 18: entity_save integration — returns validation error ---
$result = LF::save('article', ['view_count' => 'abc']);
assert(is_array($result) && isset($result['error']), 'entity_save should return error array');
assert($result['error'] === 'Validation failed');
assert(isset($result['fields']));
echo "[PASS] entity_save returns validation error\n";

// --- Test 19: entity_save integration — valid data saves ---
$article = LF::save('article', ['title' => 'Valid Article', 'view_count' => '42']);
assert(is_object($article), 'Valid save should return object');
assert($article->title === 'Valid Article');
assert($article->view_count == 42, 'Coerced integer');
echo "[PASS] entity_save with valid data saves and coerces\n";

// --- Test 20: Reference field validation ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'author' => 'not-a-number'], false);
assert($result !== null);
assert($result['fields']['author'] === 'Must be a valid entity ID');

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'author' => 5], false);
assert($result === null);
echo "[PASS] Reference field validation\n";

// --- Test 21: Reference many validation ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'tags' => 'not-array'], false);
assert($result !== null);
assert($result['fields']['tags'] === 'Must be an array of IDs');

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'tags' => [1, 'abc']], false);
assert($result !== null);
assert($result['fields']['tags'] === 'Must be an array of numeric IDs');

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'tags' => [1, 2, 3]], false);
assert($result === null);
echo "[PASS] Reference many validation\n";

// --- Test 22: User password not flagged as unknown ---
$result = TestLF::call('entity_validate', 'user', ['name' => 'Alice', 'email' => 'alice@test.com', 'password' => 'secret'], false);
assert($result === null, 'Password should not be flagged as unknown');
echo "[PASS] User password not flagged as unknown field\n";

// --- Test 23: String type rejects arrays ---
$result = TestLF::call('entity_validate', 'article', ['title' => ['nested', 'array']], false);
assert($result !== null);
assert($result['fields']['title'] === 'Must be a string');
echo "[PASS] String field rejects array value\n";

// --- Test 24: Datetime validation ---
$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'updated_on' => '2024-06-15 14:30:00'], false);
assert($result === null);

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'updated_on' => '2024-06-15T14:30:00Z'], false);
assert($result === null, 'ISO 8601 should work');

$result = TestLF::call('entity_validate', 'article', ['title' => 'Test', 'updated_on' => 'not-a-datetime'], false);
assert($result !== null);
assert($result['fields']['updated_on'] === 'Invalid datetime format');
echo "[PASS] Datetime validation\n";

// --- Test 25: Unknown type skips validation ---
$result = TestLF::call('entity_validate', 'nonexistent', ['anything' => 'goes'], false);
assert($result === null, 'Unknown type should skip validation');
echo "[PASS] Unknown type skips validation\n";

// --- Test 26: validation_error helper ---
$err = LF::validation_error(['name' => 'Required']);
assert($err['error'] === 'Validation failed');
assert($err['fields']['name'] === 'Required');
echo "[PASS] validation_error() helper builds correct format\n";

echo "\n=== All tests passed ===\n";
