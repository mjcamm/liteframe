<?php

/**
 * LightFrame core functions.
 *
 * Plain functions — no classes, no $app object.
 * The $db variable exists in the global scope from bootstrap.
 *
 * All entity IDs are globally unique via the _entities registry table.
 * entity_load() only needs an ID. entity_save() handles both create and update.
 */

// --- Identifier validation ---

function validate_identifier(string $name): void
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
        throw new \InvalidArgumentException("Invalid identifier: {$name}");
    }
}

// --- Entity functions ---

/**
 * Convert entity type name to table name.
 * article → entities__article
 */
function entity_table(string $type): string
{
    validate_identifier($type);
    return 'entities__' . $type;
}

/**
 * Build explicit column list for a type from $TYPES config.
 * Returns '*' if type is unknown (graceful fallback for tests with empty $TYPES).
 */
function entity_columns(string $type): string
{
    global $TYPES;
    if (!isset($TYPES[$type])) return '*';

    $columns = ['id'];
    foreach ($TYPES[$type] as $name => $field) {
        if ($field['reference_many']) continue;
        $columns[] = $name;
    }
    if ($type === 'user') {
        $columns[] = 'password';
    }
    $columns[] = 'created_at';
    $columns[] = 'updated_at';

    return implode(', ', $columns);
}

/**
 * Load an entity by ID. No type needed — IDs are globally unique.
 * Pass $with to eager-load references: entity_load($id, ['author', 'tags']) or ['*']
 */
function entity_load(int $id, array $with = []): ?object
{
    global $db, $TYPES;
    $registry = $db->one('SELECT type FROM _entities WHERE id = ?', [$id]);
    if (!$registry) {
        return null;
    }
    $table = entity_table($registry->type);
    $cols = entity_columns($registry->type);
    $entity = $db->one("SELECT {$cols} FROM {$table} WHERE id = ?", [$id]);
    if ($entity) {
        $entity->_type = $registry->type;
        // Never expose password
        if ($registry->type === 'user') unset($entity->password);
        if (hook_exists($registry->type, 'on_load')) {
            $entity = hook_fire($registry->type, 'on_load', $entity);
        }
        $entity = apply_derived($registry->type, $entity);
        $entity = file_resolve_entity($registry->type, $entity);

        // Resolve references
        if ($with) {
            $type = $registry->type;
            $typeFields = $TYPES[$type] ?? [];
            $loadAll = in_array('*', $with);

            foreach ($typeFields as $fieldName => $field) {
                if (!$loadAll && !in_array($fieldName, $with)) continue;

                if ($field['type'] === 'reference' && isset($entity->$fieldName)) {
                    $entity->$fieldName = entity_load((int) $entity->$fieldName);
                } elseif ($field['reference_many']) {
                    $junctionTable = "entities__{$type}__{$fieldName}";
                    $refType = $field['reference'];
                    $rows = $db->all(
                        "SELECT {$refType}_id FROM {$junctionTable} WHERE {$type}_id = ?",
                        [$entity->id]
                    );
                    $refs = [];
                    foreach ($rows as $row) {
                        $refIdField = $refType . '_id';
                        $ref = entity_load((int) $row->$refIdField);
                        if ($ref) $refs[] = $ref;
                    }
                    $entity->$fieldName = $refs;
                }
            }
        }
    }
    return $entity;
}

/**
 * Load an entity by a field value. Type needed because we're searching a specific table.
 */
function entity_load_by(string $type, string $field, mixed $value): ?object
{
    global $db;
    validate_identifier($field);
    $table = entity_table($type);
    $cols = entity_columns($type);
    $entity = $db->one("SELECT {$cols} FROM {$table} WHERE {$field} = ?", [$value]);
    if ($entity) {
        $entity->_type = $type;
        if ($type === 'user') unset($entity->password);
        if (hook_exists($type, 'on_load')) {
            $entity = hook_fire($type, 'on_load', $entity);
        }
        $entity = apply_derived($type, $entity);
        $entity = file_resolve_entity($type, $entity);
    }
    return $entity;
}

/**
 * Save an entity. If data has 'id', it's an update. If not, it's a create.
 * Returns the saved entity.
 */
function entity_save(string $type, array $data): object|array
{
    global $db, $TYPES;
    $table = entity_table($type);

    // Coerce and validate before anything else
    $is_update = isset($data['id']);
    $data = entity_coerce($type, $data);
    $validationResult = entity_validate($type, $data, $is_update);
    if ($validationResult !== null) return $validationResult;

    // Auto-hash password for user type
    if ($type === 'user' && isset($data['password'])) {
        $data['password'] = auth_hash_password($data['password']);
    }

    // Separate many-to-many fields from regular fields
    $manyToMany = [];
    $typeFields = $TYPES[$type] ?? [];
    foreach ($data as $key => $value) {
        if (isset($typeFields[$key]) && $typeFields[$key]['reference_many']) {
            $manyToMany[$key] = is_array($value) ? $value : [$value];
            unset($data[$key]);
        }
    }

    if (isset($data['id'])) {
        // --- Update ---
        $id = $data['id'];
        unset($data['id']);

        $original = entity_load($id);

        if (hook_exists($type, 'before_update')) {
            $result = hook_fire($type, 'before_update', $data, $original);
            if (is_array($result) && isset($result['error'])) return $result;
            if (is_array($result)) $data = $result;
        }

        // Process file uploads
        $data = file_process_uploads($type, $data, $id, $original);
        if (is_array($data) && isset($data['error'])) return $data;

        $db->transaction(function($db) use ($table, $type, &$data, $id, $manyToMany, $typeFields, $TYPES) {
            if (!empty($data)) {
                // Set updated_at timestamp (only for schema-managed types)
                if (isset($TYPES[$type])) {
                    $data['updated_at'] = date('Y-m-d H:i:s');
                }
                array_map('validate_identifier', array_keys($data));
                $set = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
                $db->exec(
                    "UPDATE {$table} SET {$set} WHERE id = ?",
                    [...array_values($data), $id]
                );
            }

            // Update many-to-many
            foreach ($manyToMany as $field => $ids) {
                $ref = $typeFields[$field]['reference'];
                $junctionTable = "entities__{$type}__{$field}";
                $db->exec("DELETE FROM {$junctionTable} WHERE {$type}_id = ?", [$id]);
                foreach ($ids as $refId) {
                    $db->exec(
                        "INSERT INTO {$junctionTable} ({$type}_id, {$ref}_id) VALUES (?, ?)",
                        [$id, $refId]
                    );
                }
            }
        });

        $entity = entity_load($id);

        // Fire effects for changed fields
        fire_effects($type, $entity, $original);

        if (hook_exists($type, 'after_update')) {
            hook_fire($type, 'after_update', $entity, $original);
        }

        return $entity;
    }

    // --- Create ---

    if (hook_exists($type, 'before_create')) {
        $result = hook_fire($type, 'before_create', $data);
        if (is_array($result) && isset($result['error'])) return $result;
        if (is_array($result)) $data = $result;
    }

    // Process file uploads (entityId=0 temporarily; corrected after insert)
    $data = file_process_uploads($type, $data, 0);
    if (is_array($data) && isset($data['error'])) return $data;

    // Track file IDs that need entity_id correction after insert
    $pendingFileIds = [];
    foreach ($typeFields as $fieldName => $field) {
        if ($field['type'] === 'file' && isset($data[$fieldName])) {
            $pendingFileIds[] = (int) $data[$fieldName];
        }
    }

    $id = $db->transaction(function($db) use ($table, $type, &$data, $manyToMany, $typeFields) {
        $db->exec('INSERT INTO _entities (type) VALUES (?)', [$type]);
        $id = $db->lastId();

        $data['id'] = $id;
        array_map('validate_identifier', array_keys($data));
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $db->exec(
            "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})",
            array_values($data)
        );

        // Insert many-to-many
        foreach ($manyToMany as $field => $ids) {
            $ref = $typeFields[$field]['reference'];
            $junctionTable = "entities__{$type}__{$field}";
            foreach ($ids as $refId) {
                $db->exec(
                    "INSERT INTO {$junctionTable} ({$type}_id, {$ref}_id) VALUES (?, ?)",
                    [$id, $refId]
                );
            }
        }

        return $id;
    });

    // Correct file entity_id now that we have the real ID
    foreach ($pendingFileIds as $fileId) {
        $db->exec('UPDATE _files SET entity_id = ? WHERE id = ?', [$id, $fileId]);
    }

    $entity = entity_load($id);

    // Fire effects (create — no original)
    fire_effects($type, $entity);

    if (hook_exists($type, 'after_create')) {
        hook_fire($type, 'after_create', $entity);
    }

    return $entity;
}

/**
 * Delete an entity by ID. No type needed.
 */
function entity_delete(int $id): bool|array
{
    global $db, $TYPES;
    $registry = $db->one('SELECT type FROM _entities WHERE id = ?', [$id]);
    if (!$registry) {
        return false;
    }

    $type = $registry->type;
    $table = entity_table($type);
    $entity = entity_load($id);

    // before_delete hook — can block
    if (hook_exists($type, 'before_delete')) {
        $result = hook_fire($type, 'before_delete', $entity);
        if (is_array($result) && isset($result['error'])) return $result;
    }

    // Delete entity and junction data in a transaction
    $db->transaction(function($db) use ($type, $table, $id, $TYPES) {
        // Clean up junction table entries (this entity's many-to-many fields)
        if (isset($TYPES[$type])) {
            foreach ($TYPES[$type] as $fieldName => $field) {
                if (!$field['reference_many']) continue;
                $junctionTable = "entities__{$type}__{$fieldName}";
                $db->exec("DELETE FROM {$junctionTable} WHERE {$type}_id = ?", [$id]);
            }
        }
        // Clean up reverse junction references (where this entity is the target)
        foreach ($TYPES ?? [] as $otherType => $otherFields) {
            foreach ($otherFields as $fieldName => $field) {
                if (!$field['reference_many']) continue;
                if ($field['reference'] !== $type) continue;
                $junctionTable = "entities__{$otherType}__{$fieldName}";
                $db->exec("DELETE FROM {$junctionTable} WHERE {$type}_id = ?", [$id]);
            }
        }

        $db->exec("DELETE FROM {$table} WHERE id = ?", [$id]);
        $db->exec('DELETE FROM _entities WHERE id = ?', [$id]);
    });

    // Clean up associated files (disk I/O — outside transaction)
    file_cleanup_entity($type, $id);

    // after_delete hook
    if (hook_exists($type, 'after_delete')) {
        hook_fire($type, 'after_delete', $entity);
    }

    return true;
}

/**
 * Query entities of a given type.
 */
function entity_query(string $type): EntityQuery
{
    global $db;
    return new EntityQuery($db, entity_table($type), $type);
}

// --- Request helpers ---

function input(string $key, mixed $default = null): mixed
{
    global $request;
    return $request->get($key, $default);
}

function input_has(string $key): bool
{
    global $request;
    return $request->has($key);
}

function input_all(): array
{
    global $request;
    return $request->all();
}

function input_file(string $key): ?array
{
    global $request;
    return $request->file($key);
}

function route_param(string $key): mixed
{
    global $matched_route;
    return $matched_route['params'][$key] ?? null;
}

// --- Pagination helpers ---

/**
 * Read page and per_page from query params.
 * Returns [int $page, int $perPage].
 */
function paginate_request(int $defaultPerPage = 20): array
{
    $page = max(1, (int) input('page', 1));
    $perPage = max(1, min(100, (int) input('per_page', $defaultPerPage)));
    return [$page, $perPage];
}

// --- Response helpers ---

function error(int $code, string $message): array
{
    http_response_code($code);
    return ['error' => $message];
}
