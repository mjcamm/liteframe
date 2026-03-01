<?php

trait LFApi
{
    protected static function api_routes_from_types(): array
    {
        if (empty(self::$api_directives)) return [];

        $actionMap = [
            'list'   => ['method' => 'GET',    'suffix' => '',     '_action' => 'list'],
            'view'   => ['method' => 'GET',    'suffix' => '/:id', '_action' => 'get'],
            'create' => ['method' => 'POST',   'suffix' => '',     '_action' => 'create'],
            'update' => ['method' => 'PUT',    'suffix' => '/:id', '_action' => 'update'],
            'delete' => ['method' => 'DELETE', 'suffix' => '/:id', '_action' => 'delete'],
        ];

        $routes = [];

        foreach (self::$api_directives as $type => $actions) {
            foreach ($actions as $action => $permission) {
                if (!isset($actionMap[$action])) continue;
                if ($type === 'user' && $action === 'delete') continue;
                $map = $actionMap[$action];

                $route = [
                    'path'    => '/api/' . $type . $map['suffix'],
                    'handler' => '_lf_type_handle',
                    'method'  => $map['method'],
                    '_type'   => $type,
                    '_action' => $map['_action'],
                ];

                $route['auth'] = $permission;

                $routes['_api_' . $type . '_' . $map['_action']] = $route;
            }
        }

        return $routes;
    }

    private static function api_apply_filter(EntityQuery $query, string $field, string $raw): void
    {
        if (str_contains($raw, '|')) {
            $pipePos = strrpos($raw, '|');
            $value = substr($raw, 0, $pipePos);
            $op = strtoupper(trim(substr($raw, $pipePos + 1)));

            $allowed = ['=', '!=', '>', '<', '>=', '<=', 'CONTAINS', 'STARTS_WITH'];
            if (!in_array($op, $allowed, true)) {
                return;
            }

            if ($op === 'CONTAINS') {
                $query->where($field, 'LIKE', '%' . $value . '%');
            } elseif ($op === 'STARTS_WITH') {
                $query->where($field, 'LIKE', $value . '%');
            } else {
                $query->where($field, $op, $value);
            }
        } else {
            $query->where($field, $raw);
        }
    }

    private static function api_apply_combined_filter(EntityQuery $query, array $fields, string $raw): void
    {
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
            self::validate_identifier($field);
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

    protected static function type_handle_list(string $type): array
    {
        [$page, $perPage] = self::paginate();
        $query = self::query($type);

        $filters = self::input('filter', []);
        if (is_array($filters)) {
            $typeFields = self::$types[$type] ?? [];
            $systemFields = ['id', 'created_at', 'updated_at'];
            foreach ($filters as $field => $values) {
                if (!isset($typeFields[$field]) && !in_array($field, $systemFields, true)) continue;
                if (!is_array($values)) $values = [$values];
                foreach ($values as $value) {
                    if ($value === '') continue;
                    self::api_apply_filter($query, $field, $value);
                }
            }
        }

        $combinedFilters = self::input('combined_filter', []);
        if (is_array($combinedFilters)) {
            $typeFields = self::$types[$type] ?? [];
            $systemFields = ['id', 'created_at', 'updated_at'];
            foreach ($combinedFilters as $fieldList => $value) {
                if ($value === '') continue;
                $fields = array_map('trim', explode(',', $fieldList));
                $validFields = array_filter($fields, fn($f) => isset($typeFields[$f]) || in_array($f, $systemFields, true));
                if (empty($validFields)) continue;
                self::api_apply_combined_filter($query, $validFields, $value);
            }
        }

        $sort = self::input('sort', 'id');
        $order = self::input('order', 'desc');

        return $query
            ->sort($sort, $order)
            ->paginate($page, $perPage);
    }

    protected static function type_handle_get(string $type): array|object
    {
        $id = (int) self::route_param('id');
        $entity = self::load($id, ['*']);
        if (!$entity) {
            return self::error(404, 'Not found');
        }
        return $entity;
    }

    protected static function type_handle_create(string $type): array|object
    {
        $data = self::input_all();

        if ($type === 'user') {
            $routeAuth = self::$matched_route['auth'] ?? '';

            if (empty($data['password'])) {
                return self::error(422, 'Password is required');
            }

            $email = $data['email'] ?? null;
            if ($email && self::load_by('user', 'email', $email)) {
                return self::error(409, 'Email already registered');
            }

            unset($data['role']);
            $data['role'] = self::role_default();

            $user = self::save('user', $data);

            if (is_array($user) && isset($user['error'])) {
                return $user;
            }

            if ($routeAuth === 'public') {
                return [
                    'user' => $user,
                    'token' => self::auth_token($user),
                    'refresh_token' => self::auth_refresh_token($user),
                ];
            }

            return $user;
        }

        return self::save($type, $data);
    }

    protected static function type_handle_update(string $type): array|object
    {
        $id = (int) self::route_param('id');
        $data = self::input_all();

        if ($type === 'user') {
            $caller = self::user();
            if (!$caller || $caller->id !== $id) {
                return self::error(403, 'You can only update your own account');
            }

            if (isset($data['email'])) {
                $existing = self::load_by('user', 'email', $data['email']);
                if ($existing && $existing->id !== $id) {
                    return self::error(409, 'Email already registered');
                }
            }

            unset($data['role']);
        }

        return self::save($type, ['id' => $id] + $data);
    }

    protected static function type_handle_delete(string $type): array
    {
        $id = (int) self::route_param('id');
        $result = self::delete($id);
        if ($result === false) {
            return self::error(404, 'Not found');
        }
        if (is_array($result)) {
            return $result;
        }
        return ['deleted' => true];
    }

    protected static function type_dispatch(array $matched_route): mixed
    {
        $type = $matched_route['_type'];
        $action = $matched_route['_action'];

        return match ($action) {
            'list'   => self::type_handle_list($type),
            'get'    => self::type_handle_get($type),
            'create' => self::type_handle_create($type),
            'update' => self::type_handle_update($type),
            'delete' => self::type_handle_delete($type),
            default  => self::error(404, 'Unknown action'),
        };
    }
}
