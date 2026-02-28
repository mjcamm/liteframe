# LiteFrame CMS — LLM Developer Guide

> This document is designed for LLMs building applications with LiteFrame. It covers every feature, function, config option, and pattern you need to know.

## What Is LiteFrame?

A PHP micro-framework with zero external dependencies. SQLite database, procedural functions, YAML config, JWT auth, file uploads, and single-file compilation for production. Pair it with any frontend SPA (SvelteKit, React, Vue, etc).

**Key properties:**
- No Composer, no vendor directory — pure PHP 8.1+ with PDO SQLite
- Entity IDs are globally unique (central `_entities` registry table)
- All API responses are JSON
- All routes are prefixed with `/api/` automatically
- Dev mode loads files directly; production compiles everything into a single `dist/index.php`

---

## Project Structure

```
project/
  index.php              # Entry point (dev mode + production auto-build)
  build.php              # Compiler — produces dist/index.php
  settings.yml           # App settings (dev_mode, auth, uploads, CORS)
  .htaccess              # Apache rewrite rules (dev mode)
  config/
    types.yml            # Entity type definitions (schema)
    routes.yml           # API route definitions
    cron.yml             # Scheduled task definitions
  src/                   # Framework source (do not modify)
    Database.php         # SQLite wrapper
    EntityQuery.php      # Chainable query builder
    Router.php           # URL matching
    Request.php          # Request parsing
    auth.php             # JWT auth system
    validation.php       # Field validation
    schema.php           # Schema parser + auto-migration
    files.php            # File upload handling
    hooks.php            # Entity lifecycle hooks
    derived.php          # Computed fields + effects
    cron.php             # Task scheduling
    cors.php             # CORS headers
    settings.php         # Settings parser
    variables.php        # Key-value store
    functions.php        # Core entity CRUD + helpers
    api.php              # $api() route generation + CRUD handlers
  handlers/              # Route handler files (one per route)
  hooks/                 # Entity lifecycle hooks (one per type)
  functions/             # User-defined functions (derived, effects, cron)
  frontend/              # SPA frontend (SvelteKit by default)
    build/               # Built frontend output
  dist/                  # Compiled production output
  files/
    public/              # Publicly accessible uploads
    protected/           # Auth-protected uploads (served via /api/files/:id)
```

---

## Configuration

### settings.yml

Flat key-value pairs with one level of nesting. Comments with `#`.

```yaml
dev_mode: true                    # true = load files directly, false = use compiled dist/
token_expiry: 15m                 # JWT access token lifetime (m=minutes, h=hours, d=days)
refresh_expiry: 30d               # Refresh token lifetime
cron_key: my-secret-key           # Key for HTTP cron trigger (/api/cron?key=...)

uploads:
  max_size: 10M                   # Max upload size (K, M, G suffixes)
  allowed_types: jpg, jpeg, png, gif, webp, pdf, doc, docx  # or * for any

cors:
  origin: "*"                     # Allowed origin (quote the *)
  methods: GET, POST, PUT, DELETE, OPTIONS
  headers: Content-Type, Authorization
  max_age: 86400
```

**Reading settings in code:**
```php
setting('dev_mode')           // true
setting('uploads.max_size')   // '10M'
setting('cors.origin')        // '*'
setting('missing_key', 'default')  // 'default'
```

---

### config/types.yml — Defining Entity Types

This is your schema. Each type becomes a database table (`entities__typename`).

```yaml
# Type name (becomes table entities__user)
user:
  name*: string
  email*: email
  role: string, default=user
  avatar: file, public=true

article:
  title*: string
  body: richtext
  published: boolean, default=false
  publish_date: date
  category: enum(news, tutorial, review)
  featured_image: file, public=true
  document: file
  author: -> user                         # single reference (FK)
  tags: -> tag[]                          # many-to-many reference (junction table)
  $derived(slug): slugify                 # computed on every load, never stored
  $derived(reading_time): reading_time    # calls reading_time() function
  $effect(published): on_publish          # fires on_publish() when 'published' changes

tag:
  name*: string

page:
  title*: string
  body: richtext
  slug*: string
  sort_order: integer, default=0
```

**Field types:**

| Type | SQLite | Validation | Notes |
|------|--------|------------|-------|
| `string` | TEXT | Not an array/object | General text |
| `text` | TEXT | Not an array/object | Same as string (semantic) |
| `richtext` | TEXT | Not an array/object | Same as string (semantic) |
| `email` | TEXT | FILTER_VALIDATE_EMAIL | |
| `date` | TEXT | YYYY-MM-DD + checkdate() | |
| `datetime` | TEXT | strtotime() parseable | |
| `integer` | INTEGER | Numeric + integer-valued | Coerced to (int) |
| `number` | REAL | Numeric | Coerced to (float) |
| `boolean` | INTEGER | true/false/0/1 | Coerced to 0 or 1 |
| `enum(a,b,c)` | TEXT | Must be one of the options | |
| `json` | TEXT | Valid JSON string | |
| `file` | INTEGER | Numeric (file ID) | See File Uploads section |
| `-> type` | INTEGER | Numeric (entity ID) | Single reference (FK column) |
| `-> type[]` | (junction) | Array of numeric IDs | Many-to-many (junction table) |

**Field modifiers:**
- `field_name*` — append `*` to the field name to mark it as required (validated on create, not on partial update)
- `default=value` — SQLite DEFAULT clause; required fields with defaults don't fail validation
- `public=true` — for file fields: stored in `files/public/` for direct web access

**Special features:**
- `$derived(field_name): function_name` — computed field, calculated on every load by calling the named function. Never stored in the database.
- `$effect(field_name): function_name` — side-effect function, called after save when the watched field changes value.
- `$api(action): permission` — auto-generates a CRUD API route for this type. See **$api() Directives** below.

**Schema auto-migration:** When you add a new field to types.yml, it's automatically added to the database via `ALTER TABLE ADD COLUMN` on the next request. Existing rows get a safe default (0 for integers, empty string for text). Removing a field from types.yml hides it from queries but does NOT drop the column (SQLite limitation).

---

### $api() Directives — Auto-Generated CRUD Routes

Instead of manually defining routes in `routes.yml` and writing handler files, you can declare API routes directly on a type using `$api()` directives. Only declared actions get routes — a type with no `$api()` lines has no routes.

```yaml
article:
  title*: string
  body: richtext
  author: -> user
  published: boolean, default=false
  $api(list): public
  $api(view): public
  $api(create): auth
  $api(update): auth
  $api(delete): admin

page:
  title*: string
  body: richtext
  $api(list): public
  $api(view): public

audit_log:
  action: string
  user: -> user
  # no $api() — internal only, no routes generated
```

**Actions and generated routes:**

| Directive | Method | Path | Needs :id |
|-----------|--------|------|-----------|
| `$api(list)` | GET | `/api/{type}` | No |
| `$api(view)` | GET | `/api/{type}/:id` | Yes |
| `$api(create)` | POST | `/api/{type}` | No |
| `$api(update)` | PUT | `/api/{type}/:id` | Yes |
| `$api(delete)` | DELETE | `/api/{type}/:id` | Yes |

**Permission values:**

| Value | Meaning |
|-------|---------|
| `public` | No auth required (`auth: false`) |
| `auth` | Requires valid JWT (`auth: true`) |
| Any role name (e.g. `admin`) | Requires JWT + that role (`auth: true, roles: admin`) |

**Built-in handler behavior:**
- **list** — returns paginated results (uses `paginate_request()`, supports `?page=` and `?per_page=`). Supports sorting via `?sort=field&order=asc|desc` (defaults to `id` desc). Supports filtering via `?filter[field]=value` for any field defined in the type schema (unknown fields are ignored)
- **view** — returns the entity with all references eager-loaded (`['*']`), or 404
- **create** — passes all input to `entity_save()` (validation, hooks, file uploads all apply)
- **update** — passes input + route `:id` to `entity_save()` as an update
- **delete** — calls `entity_delete()`, respects `before_delete` hooks that can block

**Override with routes.yml:** If you define a route in `routes.yml` that matches the same path and method as an auto-generated `$api()` route, the `routes.yml` route takes precedence. This lets you start with `$api()` and customize individual endpoints when needed.

**Edge cases:**
- Invalid action names (e.g. `$api(patch)`) are silently skipped
- Duplicate actions on the same type — last one wins
- Types without any `$api()` lines generate zero routes

---

### config/routes.yml — Defining API Routes

```yaml
# Route name (used internally)
articles_list:
  path: /articles              # becomes /api/articles
  handler: articles            # loads handlers/articles.php
  method: GET
  auth: false                  # public route

articles_create:
  path: /articles
  handler: articles_create
  method: POST
  auth: true                   # requires JWT
  roles: admin, editor         # optional: restrict to specific roles

user_profile:
  path: /users/:id             # :id is a route parameter
  handler: user_profile
  method: GET
  auth: false
```

**Important:**
- Paths are automatically prefixed with `/api/`
- Route parameters (`:id`, `:slug`, etc) are accessed via `route_param('id')` in handlers
- `auth: false` — public, no token needed
- `auth: true` — requires valid JWT in Authorization header
- `roles: admin, editor` — requires auth AND user must have one of these roles
- Comments with `#` are supported
- Unknown auth values (typos like `True`, `yes`) return 500 error

**Built-in routes (always available, no config needed):**
- `POST /api/auth/login` — email/password login
- `POST /api/auth/refresh` — refresh token exchange
- `POST /api/auth/logout` — revoke refresh token
- `GET /api/files/:id` — serve protected files
- `GET /api/cron?key=...` — trigger cron tasks

---

### config/cron.yml — Scheduled Tasks

```yaml
cleanup_tokens:
  function: cleanup_expired_tokens   # function name to call
  every: 60                          # interval in minutes

daily_report:
  function: send_daily_report
  every: 1440                        # 24 hours
```

Tasks are checked on every web request (near-zero overhead when nothing is due). The function must be defined in a file in the `functions/` directory.

---

## Writing Handlers

Each handler is a PHP file in `handlers/` that returns a closure. The closure returns an array which is JSON-encoded automatically.

### handlers/articles.php — List articles
```php
<?php
return function () {
    return entity_query('article')
        ->sort('id', 'desc')
        ->get();
};
```

### handlers/articles_create.php — Create an article
```php
<?php
return function () {
    return entity_save('article', [
        'title' => query_param('title'),
        'body' => query_param('body', ''),
        'published' => query_param('published', false),
        'author' => current_user()->id,
    ]);
};
```

### handlers/article_show.php — Single article with references
```php
<?php
return function () {
    $id = (int) route_param('id');
    $article = entity_load($id, ['author', 'tags']);
    if (!$article) {
        return error(404, 'Article not found');
    }
    return $article;
};
```

### handlers/articles_paginated.php — Paginated list
```php
<?php
return function () {
    [$page, $perPage] = paginate_request(20);
    return entity_query('article')
        ->where('published', true)
        ->sort('publish_date', 'desc')
        ->paginate($page, $perPage);
};
```
Returns:
```json
{
  "data": [...],
  "meta": { "page": 1, "per_page": 20, "total": 57, "total_pages": 3 }
}
```

### handlers/article_update.php — Update an article
```php
<?php
return function () {
    $id = (int) route_param('id');
    return entity_save('article', [
        'id' => $id,
        'title' => query_param('title'),
        'body' => query_param('body'),
    ]);
};
```

### handlers/article_delete.php — Delete an article
```php
<?php
return function () {
    $id = (int) route_param('id');
    $result = entity_delete($id);
    if ($result === false) {
        return error(404, 'Not found');
    }
    if (is_array($result)) {
        return $result; // blocked by hook
    }
    return ['deleted' => true];
};
```

### handlers/register.php — User registration
```php
<?php
return function () {
    $email = query_param('email');
    $password = query_param('password');
    $name = query_param('name');

    if (!$email || !$password || !$name) {
        return error(400, 'Name, email, and password required');
    }

    if (entity_load_by('user', 'email', $email)) {
        return error(409, 'Email already registered');
    }

    $user = entity_save('user', [
        'name' => $name,
        'email' => $email,
        'password' => $password,  // auto-hashed by framework
    ]);

    return [
        'user' => $user,
        'token' => auth_token($user),
        'refresh_token' => auth_refresh_token($user),
    ];
};
```

---

## Core Functions Reference

### Entity CRUD

```php
// Create — returns entity object or validation error array
entity_save('article', ['title' => 'Hello', 'body' => '...']);

// Update — include 'id' in data
entity_save('article', ['id' => 5, 'title' => 'Updated Title']);

// Load by ID (globally unique, no type needed)
entity_load(5);                         // basic load
entity_load(5, ['author']);             // eager-load author reference
entity_load(5, ['author', 'tags']);     // eager-load multiple
entity_load(5, ['*']);                  // eager-load ALL references

// Load by field value (type required)
entity_load_by('user', 'email', 'user@example.com');

// Delete by ID
entity_delete(5);   // returns true, false (not found), or error array (blocked by hook)
```

### Entity Query Builder

```php
entity_query('article')
    ->where('published', true)              // field = value
    ->where('category', 'news')             // AND field = value
    ->where('views', '>', 100)              // comparison operators: =, !=, <, >, <=, >=, LIKE
    ->where('deleted_at', null)             // IS NULL
    ->where('status', 'IS NOT', null)       // IS NOT NULL
    ->sort('created_at', 'desc')            // ORDER BY
    ->limit(10)                             // LIMIT
    ->offset(20)                            // OFFSET
    ->with('author', 'tags')                // eager-load references
    ->get();                                // returns array of entities

// Single result
entity_query('article')->where('slug', 'hello-world')->first();  // returns object or null

// Count
entity_query('article')->where('published', true)->count();  // returns int

// Paginate
entity_query('article')
    ->where('published', true)
    ->sort('created_at', 'desc')
    ->paginate($page, $perPage);  // returns {data: [...], meta: {page, per_page, total, total_pages}}
```

### Request Helpers

```php
query_param('title')                    // get input value (GET, POST, or JSON body)
query_param('page', 1)                  // with default
query_param_exists('title')                // check if key exists
query_param_all()                       // all input as array
query_param_file('avatar')              // uploaded file info
route_param('id')                 // route parameter (:id from path)
paginate_request(20)              // returns [$page, $perPage] from query params
current_user()                    // authenticated user object or null
```

### Response Helpers

```php
error(404, 'Not found')           // sets HTTP status, returns ['error' => 'Not found']
error(400, 'Bad request')
error(401, 'Authentication required')
error(403, 'Insufficient permissions')
```

### Variables (Key-Value Store)

Persistent storage for app state. Values are JSON-encoded, so any type works.

```php
variable_set('site_name', 'My Site');
variable_get('site_name');                  // 'My Site'
variable_get('missing', 'default');         // 'default'
variable_del('site_name');

// Works with any JSON-serializable value
variable_set('config', ['theme' => 'dark', 'lang' => 'en']);
variable_get('config');  // ['theme' => 'dark', 'lang' => 'en']
```

---

## Hooks — Entity Lifecycle

Create a file in `hooks/` named after the entity type. Return an array of event callbacks.

### hooks/article.php

```php
<?php
return [
    // Modify data before create — return modified data or error() to block
    'before_create' => function (array $data) {
        $data['slug'] = strtolower(str_replace(' ', '-', $data['title']));
        return $data;
    },

    // Side effects after create
    'after_create' => function (object $entity) {
        // send notification, update cache, etc.
    },

    // Modify data before update — receives current data and original entity
    'before_update' => function (array $data, object $original) {
        if (isset($data['title'])) {
            $data['slug'] = strtolower(str_replace(' ', '-', $data['title']));
        }
        return $data;
    },

    // Side effects after update
    'after_update' => function (object $entity, object $original) {
        // compare $entity with $original to see what changed
    },

    // Block deletion — return error() to prevent
    'before_delete' => function (object $entity) {
        if ($entity->published) {
            return error(400, 'Cannot delete a published article');
        }
    },

    // Side effects after delete
    'after_delete' => function (object $entity) {
        // cleanup related data
    },

    // Transform entity on every load
    'on_load' => function (object $entity) {
        $entity->is_recent = strtotime($entity->created_at) > strtotime('-7 days');
        return $entity;
    },
];
```

**Hook rules:**
- `before_create` and `before_update`: return the (possibly modified) data array, or return `error(code, message)` to block the operation
- `on_load`: must return the entity object
- Other hooks: return value is ignored
- You only need to include the events you want — omit the rest

---

## Derived Fields & Effects

### Derived Fields (Computed on Load)

Define in `types.yml`:
```yaml
article:
  title*: string
  body: richtext
  $derived(slug): slugify
  $derived(reading_time): reading_time
```

Create the function in `functions/`:

### functions/slugify.php
```php
<?php
function slugify(object $entity): string
{
    $slug = strtolower($entity->title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
}
```

### functions/reading_time.php
```php
<?php
function reading_time(object $entity): int
{
    $text = strip_tags($entity->body ?? '');
    $words = str_word_count($text);
    return max(1, (int) ceil($words / 200));
}
```

**Properties:** Derived fields are computed fresh on every `entity_load`, `entity_load_by`, and `entity_query` call. They are never stored in the database. They appear as regular properties on the entity object.

### Effects (Side Effects on Field Change)

Define in `types.yml`:
```yaml
article:
  published: boolean, default=false
  $effect(published): on_publish
```

### functions/on_publish.php
```php
<?php
function on_publish(object $entity, mixed $old_value, mixed $new_value): void
{
    if ($new_value) {
        // article was just published
        // send notification, update sitemap, etc.
    } else {
        // article was unpublished
    }
}
```

**Properties:** Effects fire after `entity_save` completes. On create, `$old_value` is null. On update, it only fires if the watched field actually changed value.

---

## File Uploads

### Defining File Fields

In `types.yml`:
```yaml
article:
  featured_image: file, public=true     # direct web access via /files/public/
  document: file                        # protected, served via /api/files/:id with auth
```

### How It Works

1. Client sends `multipart/form-data` with file fields matching the field names
2. `entity_save` automatically processes uploads — validates size/type, stores file, records in `_files` table
3. On entity load, file fields are resolved to objects:

```json
{
  "id": 1,
  "title": "My Article",
  "featured_image": {
    "id": 5,
    "filename": "photo.jpg",
    "url": "/files/public/64a1b2c3_8f9d0e1a.jpg",
    "mime_type": "image/jpeg",
    "size": 245000
  },
  "document": {
    "id": 6,
    "filename": "report.pdf",
    "url": "/api/files/6",
    "mime_type": "application/pdf",
    "size": 1024000
  }
}
```

- **Public files** (`public=true`): stored in `files/public/`, served directly by the web server
- **Protected files** (default): stored in `files/protected/`, served through `/api/files/:id` which checks authentication

### File Replacement

When updating an entity with a new file upload, the old file is automatically deleted from disk and the `_files` table.

### Upload Settings

In `settings.yml`:
```yaml
uploads:
  max_size: 10M
  allowed_types: jpg, jpeg, png, gif, webp, pdf, doc, docx
```

Set `allowed_types: *` to allow any extension (not recommended — PHP execution is blocked in `files/` via .htaccess, but still risky).

---

## Authentication

### Built-in Auth Flow

**Login:**
```
POST /api/auth/login
Body: { "email": "user@example.com", "password": "secret" }
Response: { "user": {...}, "token": "jwt...", "refresh_token": "hex..." }
```

**Using the token:**
```
GET /api/articles
Authorization: Bearer <jwt-token>
```

**Refreshing:**
```
POST /api/auth/refresh
Body: { "refresh_token": "hex..." }
Response: { "token": "new-jwt...", "refresh_token": "new-hex..." }
```

**Logout:**
```
POST /api/auth/logout
Body: { "refresh_token": "hex..." }
Response: { "message": "Logged out" }
```

### Auth in Handlers

```php
$user = current_user();          // authenticated user or null
$user->id;                       // user ID
$user->role;                     // user role string
$user->email;                    // etc.
```

### Password Handling

Passwords are **automatically bcrypt-hashed** when saving a user entity via `entity_save('user', [...])`. The password field is **automatically stripped** from all entity load operations — it never appears in API responses.

### Auth Functions

```php
auth_token($user)                // generate JWT access token
auth_refresh_token($user)        // generate + store refresh token
auth_validate_token($token)      // validate JWT, returns payload or null
current_user()                   // get authenticated user for current request
```

### JWT Secret

Priority order:
1. `JWT_SECRET` environment variable
2. Auto-generated secret stored in `_config` table (created on first use)

---

## Rate Limiting

### Configuration

In `settings.yml`:
```yaml
rate_limit:
  enabled: true          # Set to false to disable rate limiting entirely
  window: 60             # General API window in seconds
  max_requests: 100      # Max requests per IP per window
  login_window: 900      # Auth endpoint window in seconds (15 minutes)
  login_max: 5           # Max login attempts per IP per window
```

### How It Works

- **General API limit:** All matched API routes are rate limited per IP address. Default: 100 requests per 60 seconds.
- **Auth endpoint limit:** Login, refresh, and logout routes get a stricter limit. Default: 5 requests per 15 minutes. Protects against brute-force login attacks.
- **SPA requests are not rate limited.** Only routes that match an API endpoint are checked.
- **IP detection:** Uses `$_SERVER['REMOTE_ADDR']` directly. No header-based detection (X-Forwarded-For) to prevent spoofing.

### Response Headers

All API responses include:
- `X-RateLimit-Limit` — maximum requests allowed in the window
- `X-RateLimit-Remaining` — requests remaining in the current window
- `X-RateLimit-Reset` — Unix timestamp when the window resets

When rate limited (HTTP 429):
- `Retry-After` — seconds until the client can retry

### System Table

`_rate_limits` — stores per-IP hit counters with sliding windows. Expired entries are cleaned up automatically (~1% of requests trigger cleanup). Schema: `key TEXT PRIMARY KEY, hits INTEGER, window_start INTEGER`.

---

## Cron Tasks

### Defining Tasks

In `config/cron.yml`:
```yaml
cleanup:
  function: cleanup_expired_tokens
  every: 60    # minutes
```

### Writing the Function

In `functions/cleanup.php`:
```php
<?php
function cleanup_expired_tokens(): void
{
    global $db;
    $db->exec('DELETE FROM _auth_tokens WHERE expires_at < ?', [date('Y-m-d H:i:s')]);
}
```

### How It Works

- Tasks are checked on every web request (near-zero overhead when nothing is due)
- Last run time is stored in the `_variables` table
- Errors are stored in `_variables` as `cron_{name}_error`
- HTTP trigger: `GET /api/cron?key=your-cron-key` runs all tasks regardless of timing

---

## Database Access

For cases where entity functions aren't enough, use the `$db` global directly:

```php
global $db;

// Single row
$row = $db->one('SELECT * FROM entities__article WHERE id = ?', [5]);

// Multiple rows
$rows = $db->all('SELECT * FROM entities__article WHERE published = ?', [1]);

// Write operations
$db->exec('UPDATE entities__article SET views = views + 1 WHERE id = ?', [5]);

// Insert and get ID
$db->exec('INSERT INTO my_table (name) VALUES (?)', ['test']);
$id = $db->lastId();

// Transaction
$db->transaction(function ($db) {
    $db->exec('INSERT INTO ...', [...]);
    $db->exec('UPDATE ...', [...]);
    // automatically commits; rolls back on exception
});
```

**Always use parameterized queries** (`?` placeholders). Never interpolate user input into SQL strings.

---

## Building for Production

### Compile

```bash
php build.php
```

Or hit `build.php` in the browser during development.

This produces a `dist/` folder ready to deploy:
```
dist/
  index.php      # single compiled PHP file (all framework + handlers + hooks + config)
  index.html     # SPA entry point (from frontend/build/)
  _app/          # SvelteKit assets (from frontend/build/)
  files/
    public/
    protected/
```

### Deploy

Upload the contents of `dist/` to your web root (`public_html/`). On first request, `index.php` automatically:
1. Creates `data.db` (SQLite database)
2. Runs schema sync (creates all tables)
3. Generates `.htaccess` with security rules
4. Creates `robots.txt`
5. Creates `files/` directories
6. Redirects to `/`

### What the Compiled File Contains

Everything is inlined into a single `index.php`:
- All framework source code
- All handler closures
- All hook definitions
- All user functions
- Compiled routes, types, cron, settings, derived, and effects config
- Bootstrap, CORS, cron, routing, auth, and dispatch logic

### Auto-Rebuild (Production Mode)

When `dev_mode: false`, `index.php` checks if source files have changed (via mtime comparison) and automatically rebuilds `dist/index.php` when needed. This means you can edit source files on the server and they'll be picked up on the next request.

---

## SPA Fallback

LiteFrame handles SPA routing server-side. When a request doesn't match any API route:

1. If `index.html` exists (frontend SPA), it's served with `Content-Type: text/html`
2. If no `index.html` exists, returns JSON `{"error": "Not found"}` with 404 status

This means the framework works with **any server** (Apache, nginx, LiteSpeed, etc.) without special rewrite rules for SPA routing. All requests go through `index.php`, which decides whether to handle it as an API call or serve the SPA.

---

## Validation Error Format

When `entity_save` encounters validation errors, it returns:

```json
{
  "error": "Validation failed",
  "fields": {
    "title": "Required",
    "email": "Must be a valid email address",
    "category": "Must be one of: news, tutorial, review"
  }
}
```

HTTP status is set to 422.

**On create:** all required fields (marked with `*`) must be present (unless they have a `default`).
**On update:** only provided fields are validated. Missing fields are not flagged.

---

## System Tables

These are created automatically:

| Table | Purpose |
|-------|---------|
| `_entities` | Global entity registry (id + type mapping) |
| `_auth_tokens` | Refresh token storage |
| `_config` | Internal config (JWT secret, schema fingerprint) |
| `_variables` | Key-value persistent storage |
| `_files` | File upload metadata |
| `_rate_limits` | IP-based rate limiting counters |
| `entities__<type>` | One per entity type |
| `entities__<type>__<field>` | Junction tables for many-to-many references |

---

## Common Patterns

### Filtering + Sorting + Pagination

```php
return function () {
    [$page, $perPage] = paginate_request();
    $query = entity_query('article');

    if (query_param_exists('category')) {
        $query->where('category', query_param('category'));
    }
    if (query_param_exists('published')) {
        $query->where('published', (bool) query_param('published'));
    }

    return $query
        ->sort(query_param('sort', 'created_at'), query_param('order', 'desc'))
        ->with('author')
        ->paginate($page, $perPage);
};
```

### Checking Ownership

```php
return function () {
    $article = entity_load((int) route_param('id'));
    if (!$article) return error(404, 'Not found');

    $user = current_user();
    if ($article->author !== $user->id && $user->role !== 'admin') {
        return error(403, 'Not your article');
    }

    return entity_save('article', [
        'id' => $article->id,
        'title' => query_param('title'),
    ]);
};
```

### Bulk Operations

```php
return function () {
    $ids = query_param('ids');  // array of IDs
    if (!is_array($ids)) return error(400, 'ids must be an array');

    $results = [];
    foreach ($ids as $id) {
        $results[] = entity_delete((int) $id);
    }
    return ['results' => $results];
};
```

### Using Variables for App State

```php
// Track feature flags
variable_set('maintenance_mode', true);
if (variable_get('maintenance_mode', false)) {
    return error(503, 'Under maintenance');
}

// Track counters
$count = variable_get('total_signups', 0);
variable_set('total_signups', $count + 1);
```
