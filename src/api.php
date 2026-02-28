<?php

/**
 * Auto-generated API routes from $api() directives in types.yml.
 *
 * Generates CRUD routes and provides built-in handler functions.
 */

// --- Route generation from $api() directives ---

function _lf_api_routes_from_types(): array
{
    global $API_DIRECTIVES;
    if (empty($API_DIRECTIVES)) return [];

    $actionMap = [
        'list'   => ['method' => 'GET',    'suffix' => '',     '_action' => 'list'],
        'view'   => ['method' => 'GET',    'suffix' => '/:id', '_action' => 'get'],
        'create' => ['method' => 'POST',   'suffix' => '',     '_action' => 'create'],
        'update' => ['method' => 'PUT',    'suffix' => '/:id', '_action' => 'update'],
        'delete' => ['method' => 'DELETE', 'suffix' => '/:id', '_action' => 'delete'],
    ];

    $routes = [];

    foreach ($API_DIRECTIVES as $type => $actions) {
        foreach ($actions as $action => $permission) {
            if (!isset($actionMap[$action])) continue;
            $map = $actionMap[$action];

            $route = [
                'path'    => '/api/' . $type . $map['suffix'],
                'handler' => '_lf_type_handle',
                'method'  => $map['method'],
                '_type'   => $type,
                '_action' => $map['_action'],
            ];

            // Permission mapping
            if ($permission === 'public') {
                $route['auth'] = 'false';
            } elseif ($permission === 'auth') {
                $route['auth'] = 'true';
            } else {
                $route['auth'] = 'true';
                $route['roles'] = $permission;
            }

            $routes['_api_' . $type . '_' . $map['_action']] = $route;
        }
    }

    return $routes;
}

// --- Built-in CRUD handlers ---

function _lf_api_apply_filter(EntityQuery $query, string $field, string $raw): void
{
    // Check for pipe-separated operator: value|OPERATOR
    if (str_contains($raw, '|')) {
        $pipePos = strrpos($raw, '|');
        $value = substr($raw, 0, $pipePos);
        $op = strtoupper(trim(substr($raw, $pipePos + 1)));

        $allowed = ['=', '!=', '>', '<', '>=', '<=', 'CONTAINS', 'STARTS_WITH'];
        if (!in_array($op, $allowed, true)) {
            return; // Invalid operator, skip silently
        }

        if ($op === 'CONTAINS') {
            $query->where($field, 'LIKE', '%' . $value . '%');
        } elseif ($op === 'STARTS_WITH') {
            $query->where($field, 'LIKE', $value . '%');
        } else {
            $query->where($field, $op, $value);
        }
    } else {
        // No pipe — exact match
        $query->where($field, $raw);
    }
}

function _lf_api_apply_combined_filter(EntityQuery $query, array $fields, string $raw): void
{
    // Parse pipe operator (same as regular filter)
    $op = '=';
    $value = $raw;
    if (str_contains($raw, '|')) {
        $pipePos = strrpos($raw, '|');
        $value = substr($raw, 0, $pipePos);
        $op = strtoupper(trim(substr($raw, $pipePos + 1)));
    }

    $allowed = ['=', '!=', '>', '<', '>=', '<=', 'CONTAINS', 'STARTS_WITH'];
    if (!in_array($op, $allowed, true)) return;

    $clauses = [];
    $params = [];
    foreach ($fields as $field) {
        _lf_validate_identifier($field);
        if ($op === 'CONTAINS') {
            $clauses[] = "{$field} LIKE ?";
            $params[] = '%' . $value . '%';
        } elseif ($op === 'STARTS_WITH') {
            $clauses[] = "{$field} LIKE ?";
            $params[] = $value . '%';
        } else {
            $clauses[] = "{$field} {$op} ?";
            $params[] = $value;
        }
    }

    $query->whereRaw('(' . implode(' OR ', $clauses) . ')', $params);
}

function _lf_type_handle_list(string $type): array
{
    global $TYPES;
    [$page, $perPage] = paginate_request();
    $query = entity_query($type);

    // Filtering: ?filter[field]=value or ?filter[field]=value|OPERATOR
    // Supported operators: =, !=, >, <, >=, <=, CONTAINS, STARTS_WITH
    // Multiple filters on the same field: ?filter[field][]=value1|>=&filter[field][]=value2|<=
    $filters = input('filter', []);
    if (is_array($filters)) {
        $typeFields = $TYPES[$type] ?? [];
        $systemFields = ['id', 'created_at', 'updated_at'];
        foreach ($filters as $field => $values) {
            if (!isset($typeFields[$field]) && !in_array($field, $systemFields, true)) continue;
            // Normalize single value to array so both syntaxes go through the same path
            if (!is_array($values)) $values = [$values];
            foreach ($values as $value) {
                if ($value === '') continue;
                _lf_api_apply_filter($query, $field, $value);
            }
        }
    }

    // Combined filter: ?combined_filter[field1,field2]=value|OPERATOR
    // Same syntax as filter but fields are comma-separated and OR'd together
    $combinedFilters = input('combined_filter', []);
    if (is_array($combinedFilters)) {
        $typeFields = $TYPES[$type] ?? [];
        $systemFields = ['id', 'created_at', 'updated_at'];
        foreach ($combinedFilters as $fieldList => $value) {
            if ($value === '') continue;
            $fields = array_map('trim', explode(',', $fieldList));
            // Validate all fields exist
            $validFields = array_filter($fields, fn($f) => isset($typeFields[$f]) || in_array($f, $systemFields, true));
            if (empty($validFields)) continue;
            _lf_api_apply_combined_filter($query, $validFields, $value);
        }
    }

    // Sorting: ?sort=field&order=desc (defaults to id desc)
    $sort = input('sort', 'id');
    $order = input('order', 'desc');

    return $query
        ->sort($sort, $order)
        ->paginate($page, $perPage);
}

function _lf_type_handle_get(string $type): array|object
{
    $id = (int) route_param('id');
    $entity = entity_load($id, ['*']);
    if (!$entity) {
        return error(404, 'Not found');
    }
    return $entity;
}

function _lf_type_handle_create(string $type): array|object
{
    return entity_save($type, input_all());
}

function _lf_type_handle_update(string $type): array|object
{
    $id = (int) route_param('id');
    return entity_save($type, ['id' => $id] + input_all());
}

function _lf_type_handle_delete(string $type): array
{
    $id = (int) route_param('id');
    $result = entity_delete($id);
    if ($result === false) {
        return error(404, 'Not found');
    }
    if (is_array($result)) {
        return $result;
    }
    return ['deleted' => true];
}

// --- Dispatch helper ---

function _lf_type_dispatch(array $matched_route): mixed
{
    $type = $matched_route['_type'];
    $action = $matched_route['_action'];

    return match ($action) {
        'list'   => _lf_type_handle_list($type),
        'get'    => _lf_type_handle_get($type),
        'create' => _lf_type_handle_create($type),
        'update' => _lf_type_handle_update($type),
        'delete' => _lf_type_handle_delete($type),
        default  => error(404, 'Unknown action'),
    };
}
