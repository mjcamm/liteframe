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

function _lf_type_handle_list(string $type): array
{
    global $TYPES;
    [$page, $perPage] = paginate_request();
    $query = entity_query($type);

    // Filtering: ?filter[field]=value (only allows known fields)
    $filters = query_param('filter', []);
    if (is_array($filters)) {
        $typeFields = $TYPES[$type] ?? [];
        foreach ($filters as $field => $value) {
            if (!isset($typeFields[$field])) continue;
            if ($value === '') continue;
            $query->where($field, $value);
        }
    }

    // Sorting: ?sort=field&order=desc (defaults to id desc)
    $sort = query_param('sort', 'id');
    $order = query_param('order', 'desc');

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
    return entity_save($type, query_param_all());
}

function _lf_type_handle_update(string $type): array|object
{
    $id = (int) route_param('id');
    return entity_save($type, ['id' => $id] + query_param_all());
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
