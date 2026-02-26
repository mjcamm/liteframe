<?php

/**
 * File upload handling.
 *
 * Files are tracked in the _files table. Protected files (default) are served
 * through /api/files/:id with auth checks. Public files are served directly.
 */

// --- Validate an uploaded file against settings ---

function _lf_file_validate(array $file): ?string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large',
            UPLOAD_ERR_PARTIAL => 'File upload incomplete',
            UPLOAD_ERR_NO_FILE => 'No file uploaded',
            default => 'Upload error',
        };
    }

    // Check max size
    $maxSize = setting('uploads.max_size', '10M');
    $maxBytes = _lf_parse_file_size($maxSize);
    if ($file['size'] > $maxBytes) {
        return "File exceeds maximum size of {$maxSize}";
    }

    // Check allowed types
    $allowed = setting('uploads.allowed_types', 'jpg, jpeg, png, gif, webp, pdf, doc, docx');
    if ($allowed !== '*') {
        $allowedList = array_map('trim', explode(',', $allowed));
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedList)) {
            return "File type .{$ext} not allowed";
        }
    }

    return null;
}

// --- Parse file size string to bytes ---

function _lf_parse_file_size(string $size): int
{
    $size = trim($size);
    $unit = strtoupper(substr($size, -1));
    $value = (int) $size;
    return match ($unit) {
        'K' => $value * 1024,
        'M' => $value * 1024 * 1024,
        'G' => $value * 1024 * 1024 * 1024,
        default => $value,
    };
}

// --- Store an uploaded file ---

function file_store(array $file, string $storage, string $entityType = null, int $entityId = null, string $field = null): int
{
    global $db;
    $projectDir = _lf_file_project_dir();

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $storedName = uniqid() . '_' . bin2hex(random_bytes(4)) . ($ext ? ".{$ext}" : '');

    $dir = $projectDir . '/files/' . ($storage === 'public' ? 'public' : 'protected');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $destination = $dir . '/' . $storedName;
    if (is_uploaded_file($file['tmp_name'])) {
        move_uploaded_file($file['tmp_name'], $destination);
    } else {
        copy($file['tmp_name'], $destination);
    }

    // Detect actual MIME type server-side instead of trusting client
    $mimeType = mime_content_type($destination) ?: $file['type'];

    $db->exec(
        'INSERT INTO _files (filename, stored_name, mime_type, size, storage, entity_type, entity_id, field) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$file['name'], $storedName, $mimeType, $file['size'], $storage, $entityType, $entityId, $field]
    );

    return $db->lastId();
}

// --- Delete a file by ID ---

function file_delete(int $fileId): void
{
    global $db;
    $file = $db->one('SELECT * FROM _files WHERE id = ?', [$fileId]);
    if (!$file) return;

    $dir = 'files/' . ($file->storage === 'public' ? 'public' : 'protected');
    $path = _lf_file_project_dir() . '/' . $dir . '/' . $file->stored_name;
    if (file_exists($path)) {
        unlink($path);
    }

    $db->exec('DELETE FROM _files WHERE id = ?', [$fileId]);
}

// --- Resolve a file ID to an object with url ---

function file_resolve(int $fileId): ?object
{
    global $db;
    $file = $db->one('SELECT * FROM _files WHERE id = ?', [$fileId]);
    if (!$file) return null;

    $url = $file->storage === 'public'
        ? '/files/public/' . $file->stored_name
        : '/api/files/' . $file->id;

    return (object) [
        'id' => $file->id,
        'filename' => $file->filename,
        'url' => $url,
        'mime_type' => $file->mime_type,
        'size' => (int) $file->size,
    ];
}

// --- Serve a protected file (streams with headers) ---

function _lf_file_serve(int $fileId): void
{
    global $db;
    $file = $db->one('SELECT * FROM _files WHERE id = ?', [$fileId]);

    if (!$file) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        return;
    }

    // Protected files always require authentication
    if ($file->storage === 'protected') {
        $authError = _lf_auth_check_route([
            'auth' => 'true',
        ]);
        if ($authError) {
            echo json_encode($authError);
            return;
        }
    }

    $dir = 'files/' . ($file->storage === 'public' ? 'public' : 'protected');
    $path = _lf_file_project_dir() . '/' . $dir . '/' . $file->stored_name;

    if (!file_exists($path)) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found on disk']);
        return;
    }

    header('Content-Type: ' . $file->mime_type);
    header('Content-Length: ' . $file->size);
    $safeFilename = str_replace(['"', "\r", "\n"], '', $file->filename);
    header('Content-Disposition: inline; filename="' . $safeFilename . '"; filename*=UTF-8\'\'' . rawurlencode($file->filename));
    readfile($path);
}

// --- Delete all files for an entity ---

function _lf_file_cleanup_entity(string $type, int $id): void
{
    global $db;
    try {
        $files = $db->all('SELECT id FROM _files WHERE entity_type = ? AND entity_id = ?', [$type, $id]);
    } catch (\Exception $e) {
        return; // _files table may not exist (e.g. tests with manual schema)
    }
    foreach ($files as $file) {
        file_delete($file->id);
    }
}

// --- Resolve all file fields on a loaded entity ---

function _lf_file_resolve_entity(string $type, object $entity): object
{
    global $TYPES;
    if (!isset($TYPES[$type])) return $entity;

    $hasFileFields = false;
    foreach ($TYPES[$type] as $fieldName => $field) {
        if ($field['type'] === 'file') { $hasFileFields = true; break; }
    }
    if (!$hasFileFields) return $entity;

    foreach ($TYPES[$type] as $fieldName => $field) {
        if ($field['type'] !== 'file') continue;
        if (!isset($entity->$fieldName) || !$entity->$fieldName) continue;
        $entity->$fieldName = file_resolve((int) $entity->$fieldName);
    }

    return $entity;
}

// --- Process file uploads for entity_save ---

function _lf_file_process_uploads(string $type, array $data, int $entityId, ?object $original = null): array
{
    global $TYPES, $request;
    if (!isset($TYPES[$type])) return $data;

    foreach ($TYPES[$type] as $fieldName => $field) {
        if ($field['type'] !== 'file') continue;

        $uploaded = isset($request) ? $request->file($fieldName) : null;
        if (!$uploaded) continue;

        // Validate
        $error = _lf_file_validate($uploaded);
        if ($error) {
            return ['error' => "File upload error ({$fieldName}): {$error}"];
        }

        // Delete old file if replacing
        if ($original && isset($original->$fieldName)) {
            $oldFileId = null;
            if (is_object($original->$fieldName) && isset($original->$fieldName->id)) {
                $oldFileId = (int) $original->$fieldName->id;
            } elseif (is_numeric($original->$fieldName)) {
                $oldFileId = (int) $original->$fieldName;
            }
            if ($oldFileId) {
                file_delete($oldFileId);
            }
        }

        // Store new file
        $storage = $field['public'] ? 'public' : 'protected';
        $fileId = file_store($uploaded, $storage, $type, $entityId, $fieldName);
        $data[$fieldName] = $fileId;
    }

    return $data;
}

// --- Get project directory ---

function _lf_file_project_dir(): string
{
    // In compiled mode, LIGHTFRAME_PROJECT_DIR = __DIR__ (dist/ is the web root)
    // In dev mode, falls back to dirname(__DIR__) (project root from src/)
    $dir = defined('LIGHTFRAME_PROJECT_DIR') ? LIGHTFRAME_PROJECT_DIR : dirname(__DIR__);
    return $dir;
}
