<?php

/**
 * Chainable query builder for entities.
 *
 * This is the one class developers interact with, because chaining
 * requires an object. But they never instantiate it directly —
 * they call entity_query('type').
 */
class EntityQuery
{
    private const ALLOWED_OPERATORS = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'IS', 'IS NOT'];

    private Database $db;
    private string $table;
    private string $entityType;
    private array $wheres = [];
    private array $params = [];
    private ?string $orderBy = null;
    private ?int $limit = null;
    private ?int $offset = null;
    private array $withRefs = [];

    public function __construct(Database $db, string $table, string $entityType)
    {
        $this->db = $db;
        $this->table = $table;
        $this->entityType = $entityType;
    }

    public function where(string $field, mixed $operatorOrValue = null, mixed $value = null): self
    {
        _lf_validate_identifier($field);
        if (func_num_args() === 2) {
            // Two-arg: where('published', true) or where('field', null)
            if ($operatorOrValue === null) {
                $this->wheres[] = "{$field} IS NULL";
            } else {
                $this->wheres[] = "{$field} = ?";
                $this->params[] = $operatorOrValue;
            }
        } else {
            // Three-arg: where('price', '>', 100) or where('field', 'IS', null)
            $op = strtoupper(trim($operatorOrValue));
            if (!in_array($op, self::ALLOWED_OPERATORS, true)) {
                throw new \InvalidArgumentException("Invalid operator: {$operatorOrValue}");
            }
            if ($value === null && in_array($op, ['IS', 'IS NOT'], true)) {
                $this->wheres[] = "{$field} {$op} NULL";
            } else {
                $this->wheres[] = "{$field} {$op} ?";
                $this->params[] = $value;
            }
        }
        return $this;
    }

    public function sort(string $field, string $direction = 'asc'): self
    {
        _lf_validate_identifier($field);
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orderBy = "{$field} {$direction}";
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Eager-load references. Pass field names, or '*' for all.
     * entity_query('article')->with('author', 'tags')->get()
     */
    public function with(string ...$fields): self
    {
        $this->withRefs = $fields;
        return $this;
    }

    public function get(): array
    {
        $results = $this->db->all($this->buildSql(), $this->params);
        $results = $this->applyHooks($results);
        if ($this->withRefs) {
            $results = array_map(fn($e) => $this->resolveRefs($e), $results);
        }
        return $results;
    }

    public function first(): ?object
    {
        $this->limit = 1;
        $entity = $this->db->one($this->buildSql(), $this->params);
        if ($entity) {
            $entity->_type = $this->entityType;
            if ($this->entityType === 'user') unset($entity->password);
            if (hook_exists($this->entityType, 'on_load')) {
                $entity = hook_fire($this->entityType, 'on_load', $entity);
            }
            $entity = _lf_apply_derived($this->entityType, $entity);
            $entity = _lf_file_resolve_entity($this->entityType, $entity);
            if ($this->withRefs) {
                $entity = $this->resolveRefs($entity);
            }
        }
        return $entity;
    }

    public function count(): int
    {
        $sql = "SELECT COUNT(*) as total FROM {$this->table}";
        if ($this->wheres) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        return (int) $this->db->one($sql, $this->params)->total;
    }

    /**
     * Paginated results with meta info.
     * entity_query('article')->where('published', true)->paginate(1, 20)
     */
    public function paginate(int $page = 1, int $perPage = 20): array
    {
        if ($page < 1) $page = 1;
        if ($perPage < 1) $perPage = 20;

        $total = $this->count();
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;

        $this->limit = $perPage;
        $this->offset = ($page - 1) * $perPage;
        $data = $this->get();

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    private function applyHooks(array $results): array
    {
        $hasHook = hook_exists($this->entityType, 'on_load');
        $isUser = $this->entityType === 'user';
        foreach ($results as &$entity) {
            $entity->_type = $this->entityType;
            if ($isUser) unset($entity->password);
            if ($hasHook) {
                $entity = hook_fire($this->entityType, 'on_load', $entity);
            }
            $entity = _lf_apply_derived($this->entityType, $entity);
            $entity = _lf_file_resolve_entity($this->entityType, $entity);
        }
        return $results;
    }

    private function resolveRefs(object $entity): object
    {
        global $TYPES;
        $typeFields = $TYPES[$this->entityType] ?? [];
        $loadAll = in_array('*', $this->withRefs);

        foreach ($typeFields as $fieldName => $field) {
            if (!$loadAll && !in_array($fieldName, $this->withRefs)) continue;

            if ($field['type'] === 'reference' && isset($entity->$fieldName)) {
                // Single reference — replace ID with loaded entity
                $ref = entity_load((int) $entity->$fieldName);
                $entity->$fieldName = $ref;
            } elseif ($field['reference_many']) {
                // Many-to-many — query junction table
                $junctionTable = "entities__{$this->entityType}__{$fieldName}";
                $refType = $field['reference'];
                $rows = $this->db->all(
                    "SELECT {$refType}_id FROM {$junctionTable} WHERE {$this->entityType}_id = ?",
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

        return $entity;
    }

    private function buildSql(): string
    {
        $cols = _lf_entity_columns($this->entityType);
        $sql = "SELECT {$cols} FROM {$this->table}";

        if ($this->wheres) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        if ($this->orderBy) {
            $sql .= ' ORDER BY ' . $this->orderBy;
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }
}
