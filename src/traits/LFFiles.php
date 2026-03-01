<?php

trait LFFiles
{
    protected static function file_validate(array $file): ?string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large',
                UPLOAD_ERR_PARTIAL => 'File upload incomplete',
                UPLOAD_ERR_NO_FILE => 'No file uploaded',
                default => 'Upload error',
            };
        }

        $maxSize = self::setting('uploads.max_size', '10M');
        $maxBytes = self::parse_file_size($maxSize);
        if ($file['size'] > $maxBytes) {
            return "File exceeds maximum size of {$maxSize}";
        }

        $allowed = self::setting('uploads.allowed_types', 'jpg, jpeg, png, gif, webp, pdf, doc, docx');
        if ($allowed !== '*') {
            $allowedList = array_map('trim', explode(',', $allowed));
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedList)) {
                return "File type .{$ext} not allowed";
            }
        }

        return null;
    }

    protected static function parse_file_size(string $size): int
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

    public static function file_store(array $file, string $storage, string $entityType = null, int $entityId = null, string $field = null): int
    {
        $projectDir = self::file_project_dir();

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

        $mimeType = mime_content_type($destination) ?: $file['type'];

        self::$db->exec(
            'INSERT INTO _files (filename, stored_name, mime_type, size, storage, entity_type, entity_id, field) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$file['name'], $storedName, $mimeType, $file['size'], $storage, $entityType, $entityId, $field]
        );

        return self::$db->lastId();
    }

    public static function file_delete(int $fileId): void
    {
        $file = self::$db->one('SELECT * FROM _files WHERE id = ?', [$fileId]);
        if (!$file) return;

        $dir = 'files/' . ($file->storage === 'public' ? 'public' : 'protected');
        $path = self::file_project_dir() . '/' . $dir . '/' . $file->stored_name;
        if (file_exists($path)) {
            unlink($path);
        }

        self::$db->exec('DELETE FROM _files WHERE id = ?', [$fileId]);
    }

    public static function file_resolve(int $fileId): ?object
    {
        $file = self::$db->one('SELECT * FROM _files WHERE id = ?', [$fileId]);
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

    protected static function file_serve(int $fileId): void
    {
        $file = self::$db->one('SELECT * FROM _files WHERE id = ?', [$fileId]);

        if (!$file) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            return;
        }

        if ($file->storage === 'protected') {
            $authError = self::auth_check_route([
                'auth' => 'true',
            ]);
            if ($authError) {
                echo json_encode($authError);
                return;
            }
        }

        $dir = 'files/' . ($file->storage === 'public' ? 'public' : 'protected');
        $path = self::file_project_dir() . '/' . $dir . '/' . $file->stored_name;

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

    protected static function file_cleanup_entity(string $type, int $id): void
    {
        try {
            $files = self::$db->all('SELECT id FROM _files WHERE entity_type = ? AND entity_id = ?', [$type, $id]);
        } catch (\Exception $e) {
            return;
        }
        foreach ($files as $file) {
            self::file_delete($file->id);
        }
    }

    protected static function file_resolve_entity(string $type, object $entity): object
    {
        if (!isset(self::$types[$type])) return $entity;

        $hasFileFields = false;
        foreach (self::$types[$type] as $fieldName => $field) {
            if ($field['type'] === 'file') { $hasFileFields = true; break; }
        }
        if (!$hasFileFields) return $entity;

        foreach (self::$types[$type] as $fieldName => $field) {
            if ($field['type'] !== 'file') continue;
            if (!isset($entity->$fieldName) || !$entity->$fieldName) continue;
            $entity->$fieldName = self::file_resolve((int) $entity->$fieldName);
        }

        return $entity;
    }

    protected static function file_process_uploads(string $type, array $data, int $entityId, ?object $original = null): array
    {
        if (!isset(self::$types[$type])) return $data;

        foreach (self::$types[$type] as $fieldName => $field) {
            if ($field['type'] !== 'file') continue;

            $uploaded = isset(self::$request) ? self::$request->file($fieldName) : null;
            if (!$uploaded) continue;

            $error = self::file_validate($uploaded);
            if ($error) {
                return ['error' => "File upload error ({$fieldName}): {$error}"];
            }

            if ($original && isset($original->$fieldName)) {
                $oldFileId = null;
                if (is_object($original->$fieldName) && isset($original->$fieldName->id)) {
                    $oldFileId = (int) $original->$fieldName->id;
                } elseif (is_numeric($original->$fieldName)) {
                    $oldFileId = (int) $original->$fieldName;
                }
                if ($oldFileId) {
                    self::file_delete($oldFileId);
                }
            }

            $storage = $field['public'] ? 'public' : 'protected';
            $fileId = self::file_store($uploaded, $storage, $type, $entityId, $fieldName);
            $data[$fieldName] = $fileId;
        }

        return $data;
    }

    protected static function file_project_dir(): string
    {
        $dir = defined('LITEFRAME_PROJECT_DIR') ? LITEFRAME_PROJECT_DIR : self::$project_dir;
        return $dir;
    }
}
