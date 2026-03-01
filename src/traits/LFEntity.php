<?php

trait LFEntity
{
    public static function validate_identifier(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid identifier: {$name}");
        }
    }

    public static function entity_table(string $type): string
    {
        self::validate_identifier($type);
        return 'entities__' . $type;
    }

    public static function entity_columns(string $type): string
    {
        if (!isset(self::$types[$type])) return '*';

        $columns = ['id'];
        foreach (self::$types[$type] as $name => $field) {
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

    public static function resolve_entity(string $type, object $entity): object
    {
        if (self::hook_exists($type, 'on_load')) {
            $entity = self::hook_fire($type, 'on_load', $entity);
        }
        $entity = self::apply_derived($type, $entity);
        $entity = self::file_resolve_entity($type, $entity);
        return $entity;
    }

    public static function load(int $id, array $with = []): ?object
    {
        $registry = self::$db->one('SELECT type FROM _entities WHERE id = ?', [$id]);
        if (!$registry) {
            return null;
        }
        $table = self::entity_table($registry->type);
        $cols = self::entity_columns($registry->type);
        $entity = self::$db->one("SELECT {$cols} FROM {$table} WHERE id = ?", [$id]);
        if ($entity) {
            $entity->_type = $registry->type;
            if ($registry->type === 'user') unset($entity->password);
            $entity = self::resolve_entity($registry->type, $entity);

            // Resolve references
            if ($with) {
                $type = $registry->type;
                $typeFields = self::$types[$type] ?? [];
                $loadAll = in_array('*', $with);

                foreach ($typeFields as $fieldName => $field) {
                    if (!$loadAll && !in_array($fieldName, $with)) continue;

                    if ($field['type'] === 'reference' && isset($entity->$fieldName)) {
                        $entity->$fieldName = self::load((int) $entity->$fieldName);
                    } elseif ($field['reference_many']) {
                        $junctionTable = "entities__{$type}__{$fieldName}";
                        $refType = $field['reference'];
                        $rows = self::$db->all(
                            "SELECT {$refType}_id FROM {$junctionTable} WHERE {$type}_id = ?",
                            [$entity->id]
                        );
                        $refs = [];
                        foreach ($rows as $row) {
                            $refIdField = $refType . '_id';
                            $ref = self::load((int) $row->$refIdField);
                            if ($ref) $refs[] = $ref;
                        }
                        $entity->$fieldName = $refs;
                    }
                }
            }
        }
        return $entity;
    }

    public static function load_by(string $type, string $field, mixed $value): ?object
    {
        self::validate_identifier($field);
        $table = self::entity_table($type);
        $cols = self::entity_columns($type);
        $entity = self::$db->one("SELECT {$cols} FROM {$table} WHERE {$field} = ?", [$value]);
        if ($entity) {
            $entity->_type = $type;
            if ($type === 'user') unset($entity->password);
            $entity = self::resolve_entity($type, $entity);
        }
        return $entity;
    }

    public static function save(string $type, array $data): object|array
    {
        $table = self::entity_table($type);

        // Coerce and validate before anything else
        $is_update = isset($data['id']);
        $data = self::entity_coerce($type, $data);
        $validationResult = self::entity_validate($type, $data, $is_update);
        if ($validationResult !== null) return $validationResult;

        // Auto-hash password for user type
        if ($type === 'user' && isset($data['password'])) {
            $data['password'] = self::auth_hash_password($data['password']);
        }

        // Separate many-to-many fields from regular fields
        $manyToMany = [];
        $typeFields = self::$types[$type] ?? [];
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

            $original = self::load($id);

            if (self::hook_exists($type, 'before_update')) {
                $result = self::hook_fire($type, 'before_update', $data, $original);
                if (is_array($result) && isset($result['error'])) return $result;
                if (is_array($result)) $data = $result;
            }

            // Process file uploads
            $data = self::file_process_uploads($type, $data, $id, $original);
            if (is_array($data) && isset($data['error'])) return $data;

            self::$db->transaction(function($db) use ($table, $type, &$data, $id, $manyToMany, $typeFields) {
                if (!empty($data)) {
                    if (isset(self::$types[$type])) {
                        $data['updated_at'] = date('Y-m-d H:i:s');
                    }
                    array_map([self::class, 'validate_identifier'], array_keys($data));
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

            $entity = self::load($id);

            // Fire effects for changed fields
            self::fire_effects($type, $entity, $original);

            if (self::hook_exists($type, 'after_update')) {
                self::hook_fire($type, 'after_update', $entity, $original);
            }

            return $entity;
        }

        // --- Create ---

        if (self::hook_exists($type, 'before_create')) {
            $result = self::hook_fire($type, 'before_create', $data);
            if (is_array($result) && isset($result['error'])) return $result;
            if (is_array($result)) $data = $result;
        }

        // Process file uploads (entityId=0 temporarily; corrected after insert)
        $data = self::file_process_uploads($type, $data, 0);
        if (is_array($data) && isset($data['error'])) return $data;

        // Track file IDs that need entity_id correction after insert
        $pendingFileIds = [];
        foreach ($typeFields as $fieldName => $field) {
            if ($field['type'] === 'file' && isset($data[$fieldName])) {
                $pendingFileIds[] = (int) $data[$fieldName];
            }
        }

        $id = self::$db->transaction(function($db) use ($table, $type, &$data, $manyToMany, $typeFields) {
            $db->exec('INSERT INTO _entities (type) VALUES (?)', [$type]);
            $id = $db->lastId();

            $data['id'] = $id;
            array_map([self::class, 'validate_identifier'], array_keys($data));
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
            self::$db->exec('UPDATE _files SET entity_id = ? WHERE id = ?', [$id, $fileId]);
        }

        $entity = self::load($id);

        // Fire effects (create — no original)
        self::fire_effects($type, $entity);

        if (self::hook_exists($type, 'after_create')) {
            self::hook_fire($type, 'after_create', $entity);
        }

        return $entity;
    }

    public static function delete(int $id): bool|array
    {
        $registry = self::$db->one('SELECT type FROM _entities WHERE id = ?', [$id]);
        if (!$registry) {
            return false;
        }

        $type = $registry->type;
        $table = self::entity_table($type);
        $entity = self::load($id);

        // before_delete hook — can block
        if (self::hook_exists($type, 'before_delete')) {
            $result = self::hook_fire($type, 'before_delete', $entity);
            if (is_array($result) && isset($result['error'])) return $result;
        }

        // Delete entity and junction data in a transaction
        self::$db->transaction(function($db) use ($type, $table, $id) {
            // Clean up junction table entries (this entity's many-to-many fields)
            if (isset(self::$types[$type])) {
                foreach (self::$types[$type] as $fieldName => $field) {
                    if (!$field['reference_many']) continue;
                    $junctionTable = "entities__{$type}__{$fieldName}";
                    $db->exec("DELETE FROM {$junctionTable} WHERE {$type}_id = ?", [$id]);
                }
            }
            // Clean up reverse junction references (where this entity is the target)
            foreach (self::$types as $otherType => $otherFields) {
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
        self::file_cleanup_entity($type, $id);

        // after_delete hook
        if (self::hook_exists($type, 'after_delete')) {
            self::hook_fire($type, 'after_delete', $entity);
        }

        return true;
    }

    public static function query(string $type): EntityQuery
    {
        return new EntityQuery(self::$db, self::entity_table($type), $type);
    }
}
