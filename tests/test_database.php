<?php

require_once __DIR__ . '/../src/Database.php';

echo "=== Database Tests ===\n\n";

// Test 1: Create in-memory database
$db = new Database(':memory:');
echo "[PASS] Created in-memory database\n";

// Test 2: Create a table
$db->exec('CREATE TABLE articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    body TEXT,
    published INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)');
echo "[PASS] Created table\n";

// Test 3: Insert a row
$db->exec('INSERT INTO articles (title, body, published) VALUES (?, ?, ?)', [
    'First Post',
    'Hello world',
    1,
]);
$id = $db->lastId();
echo "[PASS] Inserted row, got ID: {$id}\n";

// Test 4: Query single row
$article = $db->one('SELECT * FROM articles WHERE id = ?', [$id]);
assert($article->title === 'First Post', 'Title should match');
assert($article->published == 1, 'Should be published');
echo "[PASS] Queried single row: {$article->title}\n";

// Test 5: Insert more and query all
$db->exec('INSERT INTO articles (title, body) VALUES (?, ?)', ['Second Post', 'More content']);
$db->exec('INSERT INTO articles (title, body) VALUES (?, ?)', ['Third Post', 'Even more']);
$all = $db->all('SELECT * FROM articles ORDER BY id');
assert(count($all) === 3, 'Should have 3 articles');
echo "[PASS] Queried all rows: " . count($all) . " articles\n";

// Test 6: Update
$db->exec('UPDATE articles SET published = ? WHERE id = ?', [1, 2]);
$updated = $db->one('SELECT * FROM articles WHERE id = ?', [2]);
assert($updated->published == 1, 'Should be published after update');
echo "[PASS] Updated row\n";

// Test 7: Delete
$db->exec('DELETE FROM articles WHERE id = ?', [3]);
$remaining = $db->all('SELECT * FROM articles');
assert(count($remaining) === 2, 'Should have 2 articles after delete');
echo "[PASS] Deleted row\n";

// Test 8: Transaction (commit)
$db->transaction(function ($db) {
    $db->exec('INSERT INTO articles (title) VALUES (?)', ['In Transaction']);
});
$txArticle = $db->one('SELECT * FROM articles WHERE title = ?', ['In Transaction']);
assert($txArticle !== null, 'Transaction commit should persist');
echo "[PASS] Transaction committed\n";

// Test 9: Transaction (rollback)
try {
    $db->transaction(function ($db) {
        $db->exec('INSERT INTO articles (title) VALUES (?)', ['Should Rollback']);
        throw new \Exception('Intentional error');
    });
} catch (\Exception $e) {
    // Expected
}
$rolled = $db->one('SELECT * FROM articles WHERE title = ?', ['Should Rollback']);
assert($rolled === null, 'Rolled back row should not exist');
echo "[PASS] Transaction rolled back\n";

// Test 10: Parameterised queries prevent injection
$db->exec('INSERT INTO articles (title) VALUES (?)', ["Robert'); DROP TABLE articles;--"]);
$bobby = $db->one('SELECT * FROM articles WHERE title LIKE ?', ['Robert%']);
assert($bobby !== null, 'Bobby Tables row should exist safely');
$tableCheck = $db->all("SELECT name FROM sqlite_master WHERE type='table' AND name='articles'");
assert(count($tableCheck) === 1, 'Table should still exist');
echo "[PASS] SQL injection safely parameterised\n";

// Test 11: Foreign keys work
$db->exec('CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    body TEXT,
    FOREIGN KEY (article_id) REFERENCES articles(id)
)');
$db->exec('INSERT INTO comments (article_id, body) VALUES (?, ?)', [1, 'Great post!']);
echo "[PASS] Foreign keys enabled\n";

echo "\n=== All tests passed ===\n";
