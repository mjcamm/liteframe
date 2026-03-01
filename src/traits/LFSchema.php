<?php

trait LFSchema
{
    protected static function field_to_sqlite(string $type): string
    {
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

    protected static function parse_field(string $definition): array
    {
        $field = [
            'type' => null,
            'required' => false,
            'default' => null,
            'public' => false,
            'reference' => null,
            'reference_many' => false,
        ];

        $parts = preg_split('/,\s*(?![^(]*\))/', $definition);
        $typePart = trim($parts[0]);

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

        for ($i = 1; $i < count($parts); $i++) {
            $modifier = trim($parts[$i]);
            if (!str_contains($modifier, '=')) continue;
            [$key, $value] = explode('=', $modifier, 2);
            $key = trim($key);
            $value = trim($value);
            match ($key) {
                'default' => $field['default'] = $value,
                'public' => $field['public'] = ($value === 'true'),
                default => null,
            };
        }

        return $field;
    }

    protected static function parse_types(string $file): array
    {
        self::$derived = [];
        self::$effects = [];
        self::$api_directives = [];
        $types = [];
        $currentType = null;

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            $line = preg_replace('/\s+#.*$/', '', $line);

            if ($line[0] !== ' ' && str_ends_with(trim($line), ':')) {
                $currentType = rtrim(trim($line), ':');
                $types[$currentType] = [];
                continue;
            }

            if ($currentType && str_contains($line, ':')) {
                $trimmed = trim($line);

                // $api(action): permission
                if (preg_match('/^\$api\((\w+)\):\s*(.+)$/', $trimmed, $m)) {
                    $action = $m[1];
                    if (in_array($action, ['list', 'view', 'create', 'update', 'delete'])) {
                        self::$api_directives[$currentType][$action] = trim($m[2]);
                    }
                    continue;
                }

                // $derived(field): function
                if (preg_match('/^\$derived\((\w+)\):\s*(\w+)/', $trimmed, $m)) {
                    self::$derived[$currentType][$m[1]] = $m[2];
                    continue;
                }

                // $effect(field): function_name
                if (preg_match('/^\$effect\((\w+)\):\s*(\w+)$/', $trimmed, $m)) {
                    self::$effects[$currentType][$m[1]] = $m[2];
                    continue;
                }

                if (str_starts_with($trimmed, '$')) continue;

                [$name, $definition] = explode(':', $line, 2);
                $name = trim($name);
                $field = self::parse_field(trim($definition));
                if (str_ends_with($name, '*')) {
                    $name = rtrim($name, '*');
                    $field['required'] = true;
                }
                $types[$currentType][$name] = $field;
            }
        }

        return $types;
    }

    protected static function generate_schema(array $types): array
    {
        $statements = [];

        $statements[] = 'CREATE TABLE IF NOT EXISTS _entities ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'type TEXT NOT NULL'
            . ')';

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
                if ($field['reference_many']) {
                    $junctionTables[] = [
                        'from' => $typeName,
                        'to' => $field['reference'],
                        'field' => $fieldName,
                    ];
                    continue;
                }

                if ($field['type'] === 'reference') {
                    $columns[] = "{$fieldName} INTEGER";
                    continue;
                }

                $col = $fieldName . ' ' . self::field_to_sqlite($field['type']);

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

            $tableName = 'entities__' . $typeName;
            $statements[] = "CREATE TABLE IF NOT EXISTS {$tableName} ("
                . implode(', ', $columns)
                . ')';
        }

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

    protected static function schema_apply(Database $db, array $types): void
    {
        self::schema_sync($db, $types);
    }

    protected static function schema_fingerprint(array $types): string
    {
        return md5(serialize($types));
    }

    private static function field_default_for_sqlite(string $sqliteType): string
    {
        return match ($sqliteType) {
            'INTEGER' => '0',
            'REAL' => '0',
            default => "''",
        };
    }

    private static function column_definition_for_alter(string $fieldName, array $field): string
    {
        if ($field['type'] === 'reference') {
            return "{$fieldName} INTEGER";
        }

        $sqliteType = self::field_to_sqlite($field['type']);
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
                $col .= ' DEFAULT ' . self::field_default_for_sqlite($sqliteType);
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

    protected static function schema_sync(Database $db, array $types): void
    {
        // 1. Create system tables (idempotent)
        $db->exec('CREATE TABLE IF NOT EXISTS _entities (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS _auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL, expires_at TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $db->exec('CREATE TABLE IF NOT EXISTS _config (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS _variables (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS _files (id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT NOT NULL, stored_name TEXT NOT NULL, mime_type TEXT NOT NULL, size INTEGER NOT NULL, storage TEXT NOT NULL, entity_type TEXT, entity_id INTEGER, field TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $db->exec('CREATE TABLE IF NOT EXISTS _rate_limits (key TEXT PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 0, window_start INTEGER NOT NULL)');

        // 2. Fingerprint check — skip if unchanged (fast path)
        $fingerprint = self::schema_fingerprint($types);
        $stored = $db->one("SELECT value FROM _config WHERE key = '_lf_schema_fingerprint'");
        if ($stored && $stored->value === $fingerprint) {
            return;
        }

        // 3. For each type: CREATE TABLE IF NOT EXISTS, then ADD COLUMN for missing fields
        foreach ($types as $typeName => $fields) {
            $table = 'entities__' . $typeName;

            $columns = ['id INTEGER PRIMARY KEY'];
            foreach ($fields as $fieldName => $field) {
                if ($field['reference_many']) continue;
                if ($field['type'] === 'reference') {
                    $columns[] = "{$fieldName} INTEGER";
                    continue;
                }
                $col = $fieldName . ' ' . self::field_to_sqlite($field['type']);
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

                $colDef = self::column_definition_for_alter($fieldName, $field);
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
}
