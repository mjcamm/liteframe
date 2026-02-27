<?php

/**
 * Database cleanup — drops orphaned columns and tables.
 *
 * CLI only. Refuses to run via web.
 * Auto-backs up database before any destructive work.
 *
 * Usage: php db-cleanup.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['error' => 'This script can only be run from the command line']);
    exit(1);
}

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/schema.php';

// Load types config
$typesFile = __DIR__ . '/config/types.yml';
if (!file_exists($typesFile)) {
    echo "Error: config/types.yml not found\n";
    exit(1);
}
$types = _lf_parse_types($typesFile);

// Open database
$dbPath = __DIR__ . '/data.db';
if (!file_exists($dbPath)) {
    echo "No database found at {$dbPath}\n";
    exit(0);
}
$db = new Database($dbPath);

echo "=== LiteFrame Database Cleanup ===\n\n";

// --- Find orphaned entity tables ---

$allTables = $db->all("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'entities__%' ORDER BY name");
$orphanedTables = [];
$entityTables = [];

foreach ($allTables as $table) {
    $name = $table->name;

    // Check if it's a junction table (entities__type__field)
    $parts = explode('__', $name);
    if (count($parts) === 3) {
        // Junction table — orphaned if the source type is gone
        $sourceType = $parts[1];
        $fieldName = $parts[2];
        if (!isset($types[$sourceType]) || !isset($types[$sourceType][$fieldName])) {
            $orphanedTables[] = $name;
        }
        continue;
    }

    // Entity table — entities__typename
    if (count($parts) === 2) {
        $typeName = $parts[1];
        if (!isset($types[$typeName])) {
            $orphanedTables[] = $name;
        } else {
            $entityTables[$typeName] = $name;
        }
    }
}

// --- Find orphaned columns ---

// Auto-added columns that are never orphaned
$systemColumns = ['id', 'created_at', 'updated_at', 'password'];

$orphanedColumns = []; // ['table' => [...columns]]

foreach ($entityTables as $typeName => $tableName) {
    $existing = $db->all("PRAGMA table_info({$tableName})");
    $typeFields = $types[$typeName];

    foreach ($existing as $col) {
        $colName = $col->name;
        if (in_array($colName, $systemColumns)) continue;
        if (isset($typeFields[$colName])) continue;

        // Check if it's a reference field that's still configured
        $found = false;
        foreach ($typeFields as $fName => $fDef) {
            if ($fName === $colName) {
                $found = true;
                break;
            }
        }
        if ($found) continue;

        if (!isset($orphanedColumns[$tableName])) {
            $orphanedColumns[$tableName] = [];
        }
        $orphanedColumns[$tableName][] = $colName;
    }
}

// --- Report findings ---

if (empty($orphanedTables) && empty($orphanedColumns)) {
    echo "Nothing to clean up. Database matches config.\n";
    exit(0);
}

if (!empty($orphanedTables)) {
    echo "Orphaned tables (not in types.yml):\n";
    foreach ($orphanedTables as $table) {
        $count = $db->one("SELECT COUNT(*) as n FROM {$table}")->n;
        echo "  - {$table} ({$count} rows)\n";
    }
    echo "\n";
}

if (!empty($orphanedColumns)) {
    echo "Orphaned columns (not in types.yml):\n";
    foreach ($orphanedColumns as $table => $cols) {
        echo "  {$table}:\n";
        foreach ($cols as $col) {
            echo "    - {$col}\n";
        }
    }
    echo "\n";
}

// --- Confirm ---

echo "This will permanently delete the above. Continue? [y/N] ";
$answer = trim(fgets(STDIN));

if (strtolower($answer) !== 'y') {
    echo "Aborted.\n";
    exit(0);
}

// --- Backup ---

$backupPath = $dbPath . '.bak.' . time();
copy($dbPath, $backupPath);
echo "\nBacked up database to: {$backupPath}\n\n";

// --- Drop orphaned tables ---

foreach ($orphanedTables as $table) {
    $db->exec("DROP TABLE {$table}");
    echo "Dropped table: {$table}\n";
}

// --- Drop orphaned columns ---

foreach ($orphanedColumns as $table => $cols) {
    foreach ($cols as $col) {
        try {
            $db->exec("ALTER TABLE {$table} DROP COLUMN {$col}");
            echo "Dropped column: {$table}.{$col}\n";
        } catch (\Exception $e) {
            echo "Warning: Could not drop {$table}.{$col} — {$e->getMessage()}\n";
        }
    }
}

echo "\nDone. Backup at: {$backupPath} (delete when satisfied)\n";
