<?php

/**
 * Field validation and type coercion.
 *
 * Validates entity data against $TYPES definitions. Returns per-field errors
 * in a standard format for front-end consumption.
 */

// --- Build a standard validation error response ---

function validation_error(array $fields): array
{
    http_response_code(422);
    return [
        'error' => 'Validation failed',
        'fields' => $fields,
    ];
}

// --- Coerce data types before validation ---

function entity_coerce(string $type, array $data): array
{
    global $TYPES;
    $typeFields = $TYPES[$type] ?? [];

    foreach ($data as $key => $value) {
        if (!isset($typeFields[$key]) || $value === null || $value === '') continue;

        $fieldType = $typeFields[$key]['type'];
        $baseType = preg_replace('/\(.*\)/', '', $fieldType);

        $data[$key] = match ($baseType) {
            'integer', 'file' => is_numeric($value) ? (int) $value : $value,
            'number' => is_numeric($value) ? (float) $value : $value,
            'boolean' => coerce_boolean($value),
            default => $value,
        };
    }

    return $data;
}

function coerce_boolean(mixed $value): mixed
{
    if ($value === true || $value === 'true' || $value === '1' || $value === 1) return 1;
    if ($value === false || $value === 'false' || $value === '0' || $value === 0) return 0;
    return $value;
}

// --- Validate entity data against type definition ---

function entity_validate(string $type, array $data, bool $is_update = false): ?array
{
    global $TYPES;
    $typeFields = $TYPES[$type] ?? null;
    if ($typeFields === null) return null;

    $errors = [];

    foreach ($typeFields as $fieldName => $field) {
        // Reference many — validated separately
        if ($field['reference_many']) {
            if (array_key_exists($fieldName, $data)) {
                $err = validate_reference_many($data[$fieldName]);
                if ($err !== null) $errors[$fieldName] = $err;
            }
            continue;
        }

        $provided = array_key_exists($fieldName, $data);
        $value = $data[$fieldName] ?? null;

        // Required check — only on create
        if (!$is_update && $field['required'] && $field['default'] === null) {
            if (!$provided || $value === null || $value === '') {
                $errors[$fieldName] = 'Required';
                continue;
            }
        }

        // On update, only validate fields actually provided
        if ($is_update && !$provided) continue;

        // Skip null/empty on optional fields
        if ($value === null || $value === '') continue;

        // Type-specific validation
        $fieldError = validate_field_type($value, $field['type']);
        if ($fieldError !== null) {
            $errors[$fieldName] = $fieldError;
        }
    }

    // Check for unknown fields
    $systemFields = ['id', 'created_at', 'updated_at'];
    if ($type === 'user') $systemFields[] = 'password';
    foreach ($data as $key => $value) {
        if (in_array($key, $systemFields)) continue;
        if (!isset($typeFields[$key])) {
            $errors[$key] = 'Unknown field';
        }
    }

    return $errors ? validation_error($errors) : null;
}

// --- Type-specific validation dispatcher ---

function validate_field_type(mixed $value, string $type): ?string
{
    $options = [];
    $baseType = $type;
    if (preg_match('/^enum\((.+)\)$/', $type, $m)) {
        $baseType = 'enum';
        $options = array_map('trim', explode(',', $m[1]));
    }

    return match ($baseType) {
        'string', 'text', 'richtext' => validate_string($value),
        'email' => validate_email($value),
        'date' => validate_date($value),
        'datetime' => validate_datetime($value),
        'integer' => validate_integer($value),
        'number' => validate_number($value),
        'boolean' => validate_boolean($value),
        'enum' => validate_enum($value, $options),
        'json' => validate_json($value),
        'file' => validate_file_id($value),
        'reference' => validate_reference($value),
        default => null,
    };
}

// --- Individual validators ---

function validate_string(mixed $value): ?string
{
    if (is_array($value) || is_object($value)) {
        return 'Must be a string';
    }
    return null;
}

function validate_email(mixed $value): ?string
{
    if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return 'Invalid email format';
    }
    return null;
}

function validate_date(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return 'Invalid date format (expected YYYY-MM-DD)';
    }
    [$y, $m, $d] = explode('-', $value);
    if (!checkdate((int) $m, (int) $d, (int) $y)) {
        return 'Invalid date (date does not exist)';
    }
    return null;
}

function validate_datetime(mixed $value): ?string
{
    if (!is_string($value) || strtotime($value) === false) {
        return 'Invalid datetime format';
    }
    return null;
}

function validate_integer(mixed $value): ?string
{
    if (!is_numeric($value) || (float) $value != (int) $value) {
        return 'Must be an integer';
    }
    return null;
}

function validate_number(mixed $value): ?string
{
    if (!is_numeric($value)) {
        return 'Must be a number';
    }
    return null;
}

function validate_boolean(mixed $value): ?string
{
    $allowed = [true, false, 0, 1, '0', '1', 'true', 'false'];
    if (!in_array($value, $allowed, true)) {
        return 'Must be a boolean';
    }
    return null;
}

function validate_enum(mixed $value, array $options): ?string
{
    if (!in_array((string) $value, $options, true)) {
        return 'Must be one of: ' . implode(', ', $options);
    }
    return null;
}

function validate_json(mixed $value): ?string
{
    if (!is_string($value)) return 'Must be valid JSON';
    json_decode($value);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return 'Must be valid JSON';
    }
    return null;
}

function validate_file_id(mixed $value): ?string
{
    if (!is_numeric($value)) {
        return 'Must be a valid file ID';
    }
    return null;
}

function validate_reference(mixed $value): ?string
{
    if (!is_numeric($value)) {
        return 'Must be a valid entity ID';
    }
    return null;
}

function validate_reference_many(mixed $value): ?string
{
    if (!is_array($value)) {
        return 'Must be an array of IDs';
    }
    foreach ($value as $item) {
        if (!is_numeric($item)) {
            return 'Must be an array of numeric IDs';
        }
    }
    return null;
}
