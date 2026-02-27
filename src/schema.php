<?php

/**
 * Config parser and schema generator.
 *
 * Parses types.yml into PHP arrays and generates SQLite CREATE TABLE statements.
 */

// --- Field type to SQLite column mapping ---

function _lf_field_to_sqlite(string $type): string
{
    // Strip enum values for matching: enum(a,b,c) → enum
    $baseType = preg_replace('/\(.*\)/', '', $type);

    return match ($baseType) {
        'string'   => 'TEXT',
        'text'     => 'TEXT',
        'richtext' => 'TEXT',
        'email'    => 'TEXT',
        'date'     => 'TEXT',
        'datetime' => 'TEXT',
        'enum'     => 'TEXT',
        'json'     => 'TEXT',
        'file'     => 'INTEGER',
        'integer'  => 'INTEGER',
        'number'   => 'REAL',
        'boolean'  => 'INTEGER',
        default    => 'TEXT',
    };
}

// --- Parse a field definition string ---

function _lf_parse_field(string $definition): array
{
    $field = [
        'type' => null,
        'required' => false,
        'default' => null,
        'public' => false,
        'reference' => null,
        'reference_many' => false,
    ];

    // Split on comma, but not commas inside parentheses
    $parts = preg_split('/,\s*(?![^(]*\))/', $definition);

    // First part is always the type
    $typePart = trim($parts[0]);

    // Check for reference: -> type or -> type[]
    if (str_starts_with($typePart, '-> ')) {
        $ref = substr($typePart, 3);
        if (str_ends_with($ref, '[]')) {
            $field['type'] = 'reference_many';
            $field['reference'] = rtrim($ref, '[]');
            $field['reference_many'] = true;
        } else {
            $field['type'] = 'reference';
            $field['reference'] = $ref;
        }
        return $field;
    }

    $field['type'] = $typePart;

    // Remaining parts are key=value modifiers
    for ($i = 1; $i < count($parts); $i++) {
        $modifier = trim($parts[$i]);
        if (!str_contains($modifier, '=')) continue;
        [$key, $value] = explode('=', $modifier, 2);
        $key = trim($key);
        $value = trim($value);
        match ($key) {
            'required' => $field['required'] = ($value === 'true'),
            'default' => $field['default'] = $value,
            'public' => $field['public'] = ($value === 'true'),
            default => null,
        };
    }

    return $field;
}

// --- Parse types.yml content ---

function _lf_parse_types(string $file): array
{
    global $DERIVED, $EFFECTS;
    $DERIVED = [];
    $EFFECTS = [];
    $types = [];
    $currentType = null;

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;

        if ($line[0] !== ' ' && str_ends_with(trim($line), ':')) {
            $currentType = rtrim(trim($line), ':');
            $types[$currentType] = [];
            continue;
        }

        if ($currentType && str_contains($line, ':')) {
            $trimmed = trim($line);

            // $derived(field): function
            if (preg_match('/^\$derived\((\w+)\):\s*(\w+)/', $trimmed, $m)) {
                $DERIVED[$currentType][$m[1]] = $m[2];
                continue;
            }

            // $effect(field): function_name
            if (preg_match('/^\$effect\((\w+)\):\s*(\w+)$/', $trimmed, $m)) {
                $EFFECTS[$currentType][$m[1]] = $m[2];
                continue;
            }

            if (str_starts_with($trimmed, '$')) continue;

            [$name, $definition] = explode(':', $line, 2);
            $types[$currentType][trim($name)] = _lf_parse_field(trim($definition));
        }
    }

    return $types;
}

// --- Generate SQL from parsed types ---

function _lf_generate_schema(array $types): array
{
    $statements = [];

    // Entity registry table
    $statements[] = 'CREATE TABLE IF NOT EXISTS _entities ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
        . 'type TEXT NOT NULL'
        . ')';

    // Auth tables
    $statements[] = 'CREATE TABLE IF NOT EXISTS _auth_tokens ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
        . 'user_id INTEGER NOT NULL, '
        . 'token_hash TEXT NOT NULL, '
        . 'expires_at TEXT NOT NULL, '
        . 'created_at TEXT DEFAULT CURRENT_TIMESTAMP'
        . ')';

    $statements[] = 'CREATE TABLE IF NOT EXISTS _config ('
        . 'key TEXT PRIMARY KEY, '
        . 'value TEXT NOT NULL'
        . ')';

    $statements[] = 'CREATE TABLE IF NOT EXISTS _variables ('
        . 'name TEXT PRIMARY KEY, '
        . 'value TEXT NOT NULL'
        . ')';

    $statements[] = 'CREATE TABLE IF NOT EXISTS _files ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
        . 'filename TEXT NOT NULL, '
        . 'stored_name TEXT NOT NULL, '
        . 'mime_type TEXT NOT NULL, '
        . 'size INTEGER NOT NULL, '
        . 'storage TEXT NOT NULL, '
        . 'entity_type TEXT, '
        . 'entity_id INTEGER, '
        . 'field TEXT, '
        . 'created_at TEXT DEFAULT CURRENT_TIMESTAMP'
        . ')';

    $junctionTables = [];

    foreach ($types as $typeName => $fields) {
        $columns = [];
        $columns[] = 'id INTEGER PRIMARY KEY';

        foreach ($fields as $fieldName => $field) {
            // Many-to-many → junction table, no column on this table
            if ($field['reference_many']) {
                $junctionTables[] = [
                    'from' => $typeName,
                    'to' => $field['reference'],
                    'field' => $fieldName,
                ];
                continue;
            }

            // Single reference → integer FK column
            if ($field['type'] === 'reference') {
                $columns[] = "{$fieldName} INTEGER";
                continue;
            }

            // Regular field
            $col = $fieldName . ' ' . _lf_field_to_sqlite($field['type']);

            if ($field['required']) {
                $col .= ' NOT NULL';
            }

            if ($field['default'] !== null) {
                $default = $field['default'];
                if (is_numeric($default) || in_array($default, ['true', 'false'])) {
                    if ($default === 'true') $default = '1';
                    if ($default === 'false') $default = '0';
                    $col .= " DEFAULT {$default}";
                } else {
                    $col .= " DEFAULT '{$default}'";
                }
            }

            $columns[] = $col;
        }

        // User type gets automatic password field
        if ($typeName === 'user') {
            $columns[] = 'password TEXT NOT NULL';
        }

        // Auto-add timestamps
        $columns[] = "created_at TEXT DEFAULT CURRENT_TIMESTAMP";
        $columns[] = "updated_at TEXT DEFAULT CURRENT_TIMESTAMP";

        $tableName = 'entities__' . $typeName;
        $statements[] = "CREATE TABLE IF NOT EXISTS {$tableName} ("
            . implode(', ', $columns)
            . ')';
    }

    // Junction tables for many-to-many
    foreach ($junctionTables as $jt) {
        $tableName = "entities__{$jt['from']}__{$jt['field']}";
        $statements[] = "CREATE TABLE IF NOT EXISTS {$tableName} ("
            . "{$jt['from']}_id INTEGER NOT NULL, "
            . "{$jt['to']}_id INTEGER NOT NULL, "
            . "PRIMARY KEY ({$jt['from']}_id, {$jt['to']}_id)"
            . ')';
    }

    return $statements;
}

// --- Run schema against database ---

function _lf_schema_apply(Database $db, array $types): void
{
    _lf_schema_sync($db, $types);
}

// --- Schema fingerprint ---

function _lf_schema_fingerprint(array $types): string
{
    return md5(serialize($types));
}

// --- Safe default for ADD COLUMN ---

function _lf_field_default_for_sqlite(string $sqliteType): string
{
    return match ($sqliteType) {
        'INTEGER' => '0',
        'REAL' => '0',
        default => "''",
    };
}

// --- Column definition for ALTER TABLE ADD COLUMN ---

function _lf_column_definition_for_alter(string $fieldName, array $field): string
{
    if ($field['type'] === 'reference') {
        return "{$fieldName} INTEGER";
    }

    $sqliteType = _lf_field_to_sqlite($field['type']);
    $col = "{$fieldName} {$sqliteType}";

    if ($field['required']) {
        $col .= ' NOT NULL';
        if ($field['default'] !== null) {
            $default = $field['default'];
            if (is_numeric($default) || in_array($default, ['true', 'false'])) {
                if ($default === 'true') $default = '1';
                if ($default === 'false') $default = '0';
                $col .= " DEFAULT {$default}";
            } else {
                $col .= " DEFAULT '{$default}'";
            }
        } else {
            $col .= ' DEFAULT ' . _lf_field_default_for_sqlite($sqliteType);
        }
    } elseif ($field['default'] !== null) {
        $default = $field['default'];
        if (is_numeric($default) || in_array($default, ['true', 'false'])) {
            if ($default === 'true') $default = '1';
            if ($default === 'false') $default = '0';
            $col .= " DEFAULT {$default}";
        } else {
            $col .= " DEFAULT '{$default}'";
        }
    }

    return $col;
}

// --- Automatic schema sync ---

function _lf_schema_sync(Database $db, array $types): void
{
    // 1. Create system tables (idempotent)
    $db->exec('CREATE TABLE IF NOT EXISTS _entities (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS _auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL, expires_at TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE TABLE IF NOT EXISTS _config (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS _variables (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS _files (id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT NOT NULL, stored_name TEXT NOT NULL, mime_type TEXT NOT NULL, size INTEGER NOT NULL, storage TEXT NOT NULL, entity_type TEXT, entity_id INTEGER, field TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE TABLE IF NOT EXISTS _rate_limits (key TEXT PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 0, window_start INTEGER NOT NULL)');

    // 2. Fingerprint check — skip if unchanged (fast path)
    $fingerprint = _lf_schema_fingerprint($types);
    $stored = $db->one("SELECT value FROM _config WHERE key = '_lf_schema_fingerprint'");
    if ($stored && $stored->value === $fingerprint) {
        return;
    }

    // 3. For each type: CREATE TABLE IF NOT EXISTS, then ADD COLUMN for missing fields
    foreach ($types as $typeName => $fields) {
        $table = 'entities__' . $typeName;

        // Build CREATE TABLE (same logic as _lf_generate_schema)
        $columns = ['id INTEGER PRIMARY KEY'];
        foreach ($fields as $fieldName => $field) {
            if ($field['reference_many']) continue;
            if ($field['type'] === 'reference') {
                $columns[] = "{$fieldName} INTEGER";
                continue;
            }
            $col = $fieldName . ' ' . _lf_field_to_sqlite($field['type']);
            if ($field['required']) {
                $col .= ' NOT NULL';
            }
            if ($field['default'] !== null) {
                $default = $field['default'];
                if (is_numeric($default) || in_array($default, ['true', 'false'])) {
                    if ($default === 'true') $default = '1';
                    if ($default === 'false') $default = '0';
                    $col .= " DEFAULT {$default}";
                } else {
                    $col .= " DEFAULT '{$default}'";
                }
            }
            $columns[] = $col;
        }
        if ($typeName === 'user') {
            $columns[] = 'password TEXT NOT NULL';
        }
        $columns[] = "created_at TEXT DEFAULT CURRENT_TIMESTAMP";
        $columns[] = "updated_at TEXT DEFAULT CURRENT_TIMESTAMP";

        $db->exec("CREATE TABLE IF NOT EXISTS {$table} (" . implode(', ', $columns) . ')');

        // Check for missing columns via PRAGMA table_info
        $existing = $db->all("PRAGMA table_info({$table})");
        $existingNames = array_map(fn($c) => $c->name, $existing);

        foreach ($fields as $fieldName => $field) {
            if ($field['reference_many']) continue;
            if (in_array($fieldName, $existingNames)) continue;

            $colDef = _lf_column_definition_for_alter($fieldName, $field);
            try {
                $db->exec("ALTER TABLE {$table} ADD COLUMN {$colDef}");
            } catch (\Exception $e) {
                if (!str_contains($e->getMessage(), 'duplicate column name')) {
                    throw $e;
                }
            }
        }
    }

    // 4. Junction tables (idempotent)
    foreach ($types as $typeName => $fields) {
        foreach ($fields as $fieldName => $field) {
            if (!$field['reference_many']) continue;
            $refType = $field['reference'];
            $junctionTable = "entities__{$typeName}__{$fieldName}";
            $db->exec("CREATE TABLE IF NOT EXISTS {$junctionTable} ({$typeName}_id INTEGER NOT NULL, {$refType}_id INTEGER NOT NULL, PRIMARY KEY ({$typeName}_id, {$refType}_id))");
        }
    }

    // 5. Store fingerprint
    $db->exec("INSERT OR REPLACE INTO _config (key, value) VALUES ('_lf_schema_fingerprint', ?)", [$fingerprint]);
}
