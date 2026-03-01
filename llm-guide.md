# LiteFrame CMS — LLM Developer Guide

> This document is designed for LLMs building applications with LiteFrame. It covers every feature, function, config option, and pattern you need to know.

## What Is LiteFrame?

A PHP micro-framework with zero external dependencies. SQLite database, static class API (`LF::`), YAML config, JWT auth, file uploads, and single-file compilation for production. Pair it with any frontend SPA (SvelteKit, React, Vue, etc).

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
    LF.php               # Main LF class (loads traits)
    Database.php         # SQLite wrapper
    EntityQuery.php      # Chainable query builder
    Router.php           # URL matching
    Request.php          # Request parsing
    traits/              # LF class traits (16 files)
      LFEntity.php       # CRUD operations
      LFAuth.php         # JWT auth system
      LFSchema.php       # Schema parser + auto-migration
      LFValidation.php   # Field validation
      LFFiles.php        # File upload handling
      LFHooks.php        # Entity lifecycle hooks
      LFDerived.php      # Computed fields + effects
      LFSettings.php     # Settings parser
      LFVariables.php    # Key-value store
      LFRequest.php      # Input helpers
      LFResponse.php     # Error responses
      LFCron.php         # Task scheduling
      LFCors.php         # CORS headers
      LFRateLimit.php    # Rate limiting
      LFApi.php          # $api() route generation + CRUD handlers
      LFBootstrap.php    # Bootstrap + dispatch
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
allow_facade: true                # Dev only: enables ?facade=<user_id> to impersonate users
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
LF::setting('dev_mode')           // true
LF::setting('uploads.max_size')   // '10M'
LF::setting('cors.origin')        // '*'
LF::setting('missing_key', 'default')  // 'default'
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
- **view** — returns the entity with all references eager-loaded (`['*']`), or 404
- **create** — passes all input to `LF::save()` (validation, hooks, file uploads all apply)
- **update** — passes input + route `:id` to `LF::save()` as an update
- **delete** — calls `LF::delete()`, respects `before_delete` hooks that can block
- **list** — paginated, filterable, sortable results. Full details below.

**Override with routes.yml:** If you define a route in `routes.yml` that matches the same path and method as an auto-generated `$api()` route, the `routes.yml` route takes precedence. This lets you start with `$api()` and customize individual endpoints when needed.

**Edge cases:**
- Invalid action names (e.g. `$api(patch)`) are silently skipped
- Duplicate actions on the same type — last one wins
- Types without any `$api()` lines generate zero routes

### $api() on User Type — Auto Registration & Profile

When `$api()` directives are used on the `user` type, the framework applies automatic safety handling. This means adding `$api(create): public` gives you a working registration endpoint with no custom code.

```yaml
user:
  name*: string
  email*: email
  role: string, default=user
  $api(create): public      # registration endpoint
  $api(update): auth         # edit own profile
```

**Create (`$api(create)`) on user:**
- **Password required** — returns 422 if missing
- **Duplicate email check** — returns 409 if email already exists
- **Role always stripped** — the `role` field is removed from input; SQLite applies the schema `default` value. This prevents role injection regardless of the route's permission level.
- **Public routes return tokens** — when the permission is `public`, the response includes `user`, `token`, and `refresh_token` (same format as `/api/auth/login`), so the user is logged in immediately after registration
- **Non-public routes return entity only** — when the permission is `auth` or a role name, the response is just the entity (no token wrapper)

```
POST /api/user
Body: { "name": "Jane", "email": "jane@example.com", "password": "secret" }
Response: { "user": {...}, "token": "jwt...", "refresh_token": "hex..." }
```

**Update (`$api(update)`) on user:**
- **Ownership enforced** — users can only update their own account (`LF::user()->id` must match `:id`). Returns 403 otherwise.
- **Duplicate email check** — if email is being changed, checks it's not taken by another user. Keeping your own email is fine.
- **Role always stripped** — same as create, role cannot be changed through `$api()`.

**Delete (`$api(delete)`) on user:**
- **Not allowed** — `$api(delete)` is silently ignored on the user type. The route is never generated. User deletion requires a custom handler where you control the logic (cleanup related data, revoke tokens, etc).

**Role elevation:** The `role` field is always stripped on `$api()` routes for user. If you need to change a user's role (e.g. promoting someone), write a custom handler:

```php
// handlers/promote_user.php
<?php
return function () {
    $id = (int) LF::route_param('id');
    return LF::save('user', [
        'id' => $id,
        'role' => LF::input('role'),
    ]);
};
```

```yaml
# config/routes.yml
promote_user:
  path: /users/:id/role
  handler: promote_user
  method: PUT
  auth: admin
```

Custom handlers bypass all `$api()` safety handling — they call `LF::save()` directly with full control.

### $api(list) — Full Reference

The list handler returns paginated, filtered, sorted results. Everything is controlled via query parameters — no custom handler code needed for most use cases.

**Pagination:**
```
GET /api/article?page=2&per_page=10
```
Defaults to page 1, 20 per page. Response format:
```json
{
  "data": [...],
  "meta": { "page": 2, "per_page": 10, "total": 57, "total_pages": 6 }
}
```

**Sorting:**
```
GET /api/article?sort=created_at&order=desc
```
Defaults to `sort=id&order=desc`. Any field name is accepted.

**Filtering** with `filter` — each filter is AND'd together. Works on any field in the type schema plus `id`, `created_at`, and `updated_at`. Unknown fields are silently ignored.

Exact match (no operator):
```
GET /api/article?filter[category]=news
GET /api/article?filter[published]=1
```

With operator — append `|OPERATOR` after the value:
```
GET /api/article?filter[title]=hello|CONTAINS
GET /api/article?filter[name]=John|STARTS_WITH
GET /api/article?filter[views]=100|>
GET /api/article?filter[status]=draft|!=
```

| Operator | SQL | Example |
|----------|-----|---------|
| *(none)* | `= value` | `filter[status]=active` |
| `>` | `> value` | `filter[id]=10|>` |
| `<` | `< value` | `filter[id]=10|<` |
| `>=` | `>= value` | `filter[date]=2024-01-01|>=` |
| `<=` | `<= value` | `filter[date]=2024-12-31|<=` |
| `!=` | `!= value` | `filter[status]=draft|!=` |
| `CONTAINS` | `LIKE %value%` | `filter[title]=hello|CONTAINS` |
| `STARTS_WITH` | `LIKE value%` | `filter[name]=John|STARTS_WITH` |

**Range filters** — use `[]` to apply multiple filters on the same field (e.g. date ranges):
```
GET /api/job?filter[date_completed][]=2024-01-01|>=&filter[date_completed][]=2024-12-31|<=
```

**Combined filter** with `combined_filter` — searches one value across multiple fields with OR. Field names are comma-separated in the key. Same pipe operators as `filter`.
```
GET /api/job?combined_filter[street,suburb,house_number]=king|CONTAINS
```
Generates: `WHERE (street LIKE '%king%' OR suburb LIKE '%king%' OR house_number LIKE '%king%')`

Combined filters are AND'd with regular filters:
```
GET /api/job?combined_filter[street,suburb]=king|CONTAINS&filter[project]=Residential
```
Finds jobs where (street OR suburb contains "king") AND project is "Residential".

**Full example** — paginated, sorted, filtered with date range and search:
```
GET /api/job?filter[project]=Residential&filter[date_completed][]=2024-01-01|>=&filter[date_completed][]=2024-12-31|<=&combined_filter[street,suburb]=king|CONTAINS&sort=date_completed&order=desc&page=1&per_page=25
```

---

### config/routes.yml — Defining API Routes

```yaml
# Route name (used internally)
articles_list:
  path: /articles              # becomes /api/articles
  handler: articles            # loads handlers/articles.php
  method: GET
  auth: public                 # no authentication required

articles_create:
  path: /articles
  handler: articles_create
  method: POST
  auth: admin, editor          # requires JWT + one of these roles

user_profile:
  path: /users/:id             # :id is a route parameter
  handler: user_profile
  method: GET
  auth: auth                   # requires JWT (any authenticated user)
```

**Important:**
- Paths are automatically prefixed with `/api/`
- Route parameters (`:id`, `:slug`, etc) are accessed via `LF::route_param('id')` in handlers
- `auth` is **required** on every route — omitting it returns a 500 error
- `auth: public` — no authentication required
- `auth: auth` — requires valid JWT in Authorization header
- `auth: admin` or `auth: admin, editor` — requires JWT + user must have one of the listed roles
- Same syntax as `$api()` directives in `types.yml`
- Comments with `#` are supported

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
    return LF::query('article')
        ->sort('id', 'desc')
        ->get();
};
```

### handlers/articles_create.php — Create an article
```php
<?php
return function () {
    return LF::save('article', [
        'title' => LF::input('title'),
        'body' => LF::input('body', ''),
        'published' => LF::input('published', false),
        'author' => LF::user()->id,
    ]);
};
```

### handlers/article_show.php — Single article with references
```php
<?php
return function () {
    $id = (int) LF::route_param('id');
    $article = LF::load($id, ['author', 'tags']);
    if (!$article) {
        return LF::error(404, 'Article not found');
    }
    return $article;
};
```

### handlers/articles_paginated.php — Paginated list
```php
<?php
return function () {
    [$page, $perPage] = LF::paginate(20);
    return LF::query('article')
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
    $id = (int) LF::route_param('id');
    return LF::save('article', [
        'id' => $id,
        'title' => LF::input('title'),
        'body' => LF::input('body'),
    ]);
};
```

### handlers/article_delete.php — Delete an article
```php
<?php
return function () {
    $id = (int) LF::route_param('id');
    $result = LF::delete($id);
    if ($result === false) {
        return LF::error(404, 'Not found');
    }
    if (is_array($result)) {
        return $result; // blocked by hook
    }
    return ['deleted' => true];
};
```

### User registration — no handler needed

Add `$api(create): public` to the user type in `types.yml` and registration is handled automatically (duplicate email check, password required, role stripped, tokens returned). See **$api() on User Type** above.

If you need custom registration logic (e.g. sending a welcome email, invite codes), write a handler:

### handlers/register.php — Custom user registration
```php
<?php
return function () {
    $email = LF::input('email');
    $password = LF::input('password');
    $name = LF::input('name');

    if (!$email || !$password || !$name) {
        return LF::error(400, 'Name, email, and password required');
    }

    if (LF::load_by('user', 'email', $email)) {
        return LF::error(409, 'Email already registered');
    }

    $user = LF::save('user', [
        'name' => $name,
        'email' => $email,
        'password' => $password,  // auto-hashed by framework
    ]);

    // Custom logic here (send welcome email, etc.)

    return [
        'user' => $user,
        'token' => _lf_auth_token($user),
        'refresh_token' => _lf_auth_refresh_token($user),
    ];
};
```

---

## Core Functions Reference

### Entity CRUD

```php
// Create — returns entity object or validation error array
LF::save('article', ['title' => 'Hello', 'body' => '...']);

// Update — include 'id' in data
LF::save('article', ['id' => 5, 'title' => 'Updated Title']);

// Load by ID (globally unique, no type needed)
LF::load(5);                         // basic load
LF::load(5, ['author']);             // eager-load author reference
LF::load(5, ['author', 'tags']);     // eager-load multiple
LF::load(5, ['*']);                  // eager-load ALL references

// Load by field value (type required)
LF::load_by('user', 'email', 'user@example.com');

// Delete by ID
LF::delete(5);   // returns true, false (not found), or error array (blocked by hook)
```

### Entity Query Builder

```php
LF::query('article')
    ->where('published', true)              // field = value
    ->where('category', 'news')             // AND field = value
    ->where('views', '>', 100)              // comparison operators: =, !=, <, >, <=, >=, LIKE
    ->where('deleted_at', null)             // IS NULL
    ->where('status', 'IS NOT', null)       // IS NOT NULL
    ->whereRaw('date(created_at) = ?', ['2024-03-15'])  // raw SQL fragment with params
    ->sort('created_at', 'desc')            // ORDER BY
    ->limit(10)                             // LIMIT
    ->offset(20)                            // OFFSET
    ->with('author', 'tags')                // eager-load references
    ->get();                                // returns array of entities

// Single result
LF::query('article')->where('slug', 'hello-world')->first();  // returns object or null

// Count
LF::query('article')->where('published', true)->count();  // returns int

// Paginate
LF::query('article')
    ->where('published', true)
    ->sort('created_at', 'desc')
    ->paginate($page, $perPage);  // returns {data: [...], meta: {page, per_page, total, total_pages}}
```

### Request Helpers

```php
LF::input('title')               // get input value (GET, POST, or JSON body)
LF::input('page', 1)             // with default
LF::input_exists('title')        // check if key exists
LF::input_all()                  // all input as array
LF::input_file('avatar')         // uploaded file info
LF::route_param('id')            // route parameter (:id from path)
LF::paginate(20)                 // returns [$page, $perPage] from query params
LF::user()                       // authenticated user object or null
```

### Response Helpers

```php
LF::error(404, 'Not found')           // sets HTTP status, returns ['error' => 'Not found']
LF::error(400, 'Bad request')
LF::error(401, 'Authentication required')
LF::error(403, 'Insufficient permissions')
```

### Variables (Key-Value Store)

Persistent storage for app state. Values are JSON-encoded, so any type works.

```php
LF::variable_set('site_name', 'My Site');
LF::variable_get('site_name');                  // 'My Site'
LF::variable_get('missing', 'default');         // 'default'
LF::variable_del('site_name');

// Works with any JSON-serializable value
LF::variable_set('config', ['theme' => 'dark', 'lang' => 'en']);
LF::variable_get('config');  // ['theme' => 'dark', 'lang' => 'en']
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

    // Block deletion — return LF::error() to prevent
    'before_delete' => function (object $entity) {
        if ($entity->published) {
            return LF::error(400, 'Cannot delete a published article');
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
- `before_create` and `before_update`: return the (possibly modified) data array, or return `LF::error(code, message)` to block the operation
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

**Properties:** Derived fields are computed fresh on every `LF::load`, `LF::load_by`, and `LF::query` call. They are never stored in the database. They appear as regular properties on the entity object.

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

**Properties:** Effects fire after `LF::save` completes. On create, `$old_value` is null. On update, it only fires if the watched field actually changed value.

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
2. `LF::save` automatically processes uploads — validates size/type, stores file, records in `_files` table
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
$user = LF::user();             // authenticated user or null
$user->id;                       // user ID
$user->role;                     // user role string
$user->email;                    // etc.
```

### Password Handling

Passwords are **automatically bcrypt-hashed** when saving a user entity via `LF::save('user', [...])`. The password field is **automatically stripped** from all entity load operations — it never appears in API responses.

### Auth Functions

```php
_lf_auth_token($user)            // generate JWT access token
_lf_auth_refresh_token($user)    // generate + store refresh token
_lf_auth_validate_token($token)  // validate JWT, returns payload or null
LF::user()                       // get authenticated user for current request
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

## User Facade (Dev Mode Only)

Impersonate any user during development by adding `?facade=<user_id>` to API URLs. Auth still runs normally — if the facade user doesn't have permission, the request is blocked as expected. This lets you quickly test how different users experience each endpoint. This feature only exists in the dev-mode request flow (`index.php`) and is **never compiled into `dist/index.php`**.

### Configuration

In `settings.yml`:
```yaml
dev_mode: true             # Must be true
allow_facade: true         # Enables ?facade= query param on API routes
```

Both `dev_mode` and `allow_facade` must be `true` for the facade to work.

### Usage

Append `?facade=<user_id>` to any API URL to make the request as that user:

```
GET /api/articles?facade=3        # Request as user ID 3
GET /api/admin/stats?facade=1     # Test admin-only endpoint as user ID 1
```

- The user ID must refer to a real user entity in the database
- If the ID doesn't exist or isn't a user, `LF::user()` remains `null`
- Auth checks run normally — if the facade user lacks permission, you get a 401/403 as expected
- Without `?facade=`, requests behave exactly as normal

### Response Format

When a facade is active, a `_DEV` key is prepended to every API response:

```json
{
  "_DEV": {
    "NOTICE": "FACADE ACTIVE — REQUEST IS BEING MADE AS ANOTHER USER",
    "facade_user": { "id": 3, "email": "admin@example.com", "role": "admin" }
  },
  "data": [...],
  "meta": { ... }
}
```

This also appears on blocked responses (401/403), so you can see which user was attempted.

### Production Safety

This feature is completely absent from compiled production builds. The `build.php` compiler generates its own auth flow that has no facade support.

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
    LF::db()->exec('DELETE FROM _auth_tokens WHERE expires_at < ?', [date('Y-m-d H:i:s')]);
}
```

### How It Works

- Tasks are checked on every web request (near-zero overhead when nothing is due)
- Last run time is stored in the `_variables` table
- Errors are stored in `_variables` as `cron_{name}_error`
- HTTP trigger: `GET /api/cron?key=your-cron-key` runs all tasks regardless of timing

---

## Database Access

For cases where entity methods aren't enough, use `LF::db()` directly:

```php
// Single row
$row = LF::db()->one('SELECT * FROM entities__article WHERE id = ?', [5]);

// Multiple rows
$rows = LF::db()->all('SELECT * FROM entities__article WHERE published = ?', [1]);

// Write operations
LF::db()->exec('UPDATE entities__article SET views = views + 1 WHERE id = ?', [5]);

// Insert and get ID
LF::db()->exec('INSERT INTO my_table (name) VALUES (?)', ['test']);
$id = LF::db()->lastId();

// Transaction
LF::db()->transaction(function ($db) {
    $db->exec('INSERT INTO ...', [...]);
    $db->exec('UPDATE ...', [...]);
    // automatically commits; rolls back on exception
});
```

**Always use parameterized queries** (`?` placeholders). Never interpolate user input into SQL strings.

---

## Building for Production

### Compile

Visit `build.php` in the browser to compile:

```
http://yoursite/build.php
```

This compiles the PHP backend and copies the frontend build into `dist/`. For the frontend, run `npm run build` in your `frontend/` directory first (standard for any SPA framework).

The result is a `dist/` folder ready to deploy:
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

When `LF::save` encounters validation errors, it returns:

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

For most cases, `$api(list)` handles this automatically via query parameters — no custom handler needed:

```
GET /api/article?filter[category]=news&filter[published]=1&sort=created_at&order=desc&page=1&per_page=10
```

For custom filtering logic beyond what `$api(list)` supports, write a handler:

```php
return function () {
    [$page, $perPage] = LF::paginate();
    $query = LF::query('article');

    if (LF::input_exists('category')) {
        $query->where('category', LF::input('category'));
    }
    if (LF::input_exists('published')) {
        $query->where('published', (bool) LF::input('published'));
    }

    return $query
        ->sort(LF::input('sort', 'created_at'), LF::input('order', 'desc'))
        ->with('author')
        ->paginate($page, $perPage);
};
```

### Checking Ownership

```php
return function () {
    $article = LF::load((int) LF::route_param('id'));
    if (!$article) return LF::error(404, 'Not found');

    $user = LF::user();
    if ($article->author !== $user->id && $user->role !== 'admin') {
        return LF::error(403, 'Not your article');
    }

    return LF::save('article', [
        'id' => $article->id,
        'title' => LF::input('title'),
    ]);
};
```

### Bulk Operations

```php
return function () {
    $ids = LF::input('ids');  // array of IDs
    if (!is_array($ids)) return LF::error(400, 'ids must be an array');

    $results = [];
    foreach ($ids as $id) {
        $results[] = LF::delete((int) $id);
    }
    return ['results' => $results];
};
```

### Using Variables for App State

```php
// Track feature flags
LF::variable_set('maintenance_mode', true);
if (LF::variable_get('maintenance_mode', false)) {
    return LF::error(503, 'Under maintenance');
}

// Track counters
$count = LF::variable_get('total_signups', 0);
LF::variable_set('total_signups', $count + 1);
```

---

## Function Index

Every user-facing method in the framework. Internal functions (`_lf_` prefix) are omitted — those are not meant to be called from handlers, hooks, or functions.

### Entity CRUD

| Function | Returns | Description |
|----------|---------|-------------|
| `LF::save(string $type, array $data)` | `object\|array` | Create or update an entity. Include `id` in data to update. Returns entity or validation error array. |
| `LF::load(int $id, array $with = [])` | `?object` | Load entity by ID. Pass reference field names in `$with` to eager-load, or `['*']` for all. |
| `LF::load_by(string $type, string $field, mixed $value)` | `?object` | Load entity by field value within a type. |
| `LF::delete(int $id)` | `bool\|array` | Delete entity. Returns `true`, `false` (not found), or error array (blocked by hook). |
| `LF::query(string $type)` | `EntityQuery` | Create a chainable query builder. |

### Entity Query Builder

Methods on the `EntityQuery` object returned by `LF::query()`. All filter/sort methods return `self` for chaining.

| Method | Returns | Description |
|--------|---------|-------------|
| `->where(string $field, mixed $operatorOrValue, mixed $value = null)` | `self` | WHERE clause. 2 args: equality. 3 args: operator (`=`, `!=`, `<`, `>`, `<=`, `>=`, `LIKE`, `IS`, `IS NOT`). Pass `null` for IS NULL. |
| `->whereRaw(string $sql, array $params = [])` | `self` | Raw SQL WHERE fragment with `?` placeholders. |
| `->sort(string $field, string $direction = 'asc')` | `self` | ORDER BY clause. |
| `->limit(int $limit)` | `self` | LIMIT clause. |
| `->offset(int $offset)` | `self` | OFFSET clause. |
| `->with(string ...$fields)` | `self` | Eager-load references. Pass field names or `'*'` for all. |
| `->get()` | `array` | Execute query, return array of entities. |
| `->first()` | `?object` | Execute query, return first result or null. |
| `->count()` | `int` | Count matching entities. |
| `->paginate(int $page = 1, int $perPage = 20)` | `array` | Paginated results: `{data: [...], meta: {page, per_page, total, total_pages}}`. |

### Request

| Function | Returns | Description |
|----------|---------|-------------|
| `LF::input(string $key, mixed $default = null)` | `mixed` | Get input value from GET, POST, or JSON body. |
| `LF::input_exists(string $key)` | `bool` | Check if input key exists. |
| `LF::input_all()` | `array` | Get all input as array. |
| `LF::input_file(string $key)` | `?array` | Get uploaded file info. |
| `LF::route_param(string $key)` | `mixed` | Get route parameter (`:id`, `:slug`, etc). |
| `LF::paginate(int $defaultPerPage = 20)` | `array` | Parse `page` and `per_page` from query params. Returns `[$page, $perPage]`. |
| `LF::user()` | `?object` | Get authenticated user for current request, or null. |

### Response

| Function | Returns | Description |
|----------|---------|-------------|
| `LF::error(int $code, string $message)` | `array` | Set HTTP status code, return `['error' => $message]`. |
| `LF::validation_error(array $fields)` | `array` | Return 422 response with per-field errors: `{error: 'Validation failed', fields: {...}}`. |

### Settings & Roles

| Function | Returns | Description |
|----------|---------|-------------|
| `LF::setting(string $key, mixed $default = null)` | `mixed` | Read from `settings.yml`. Dot notation for nested: `LF::setting('cors.origin')`. |
| `LF::role_default()` | `string` | Get `default_role` from `config/roles.yml`. Falls back to `'user'`. |

### Variables (Key-Value Store)

| Function | Returns | Description |
|----------|---------|-------------|
| `LF::variable_get(string $name, mixed $default = null)` | `mixed` | Get a persistent variable. Values are JSON-decoded. |
| `LF::variable_set(string $name, mixed $value)` | `void` | Set a persistent variable. Values are JSON-encoded. |
| `LF::variable_del(string $name)` | `void` | Delete a persistent variable. |

### Hooks

| Function | Returns | Description |
|----------|---------|-------------|
| `LF::hook_fire(string $type, string $event, mixed ...$args)` | `mixed` | Fire a lifecycle hook. Returns modified data or hook result. |
| `LF::hook_exists(string $type, string $event)` | `bool` | Check if a hook is registered. |

### Files

| Function | Returns | Description |
|----------|---------|-------------|
| `LF::file_store(array $file, string $storage, string $entityType = null, int $entityId = null, string $field = null)` | `int` | Store an uploaded file. `$storage` is `'public'` or `'protected'`. Returns file ID. |
| `LF::file_delete(int $fileId)` | `void` | Delete a file by ID (removes from disk and `_files` table). |
| `LF::file_resolve(int $fileId)` | `?object` | Resolve file ID to object with `id`, `filename`, `url`, `mime_type`, `size`. |

### Database (via `LF::db()`)

| Method | Returns | Description |
|--------|---------|-------------|
| `LF::db()->one(string $sql, array $params = [])` | `?object` | Query single row. |
| `LF::db()->all(string $sql, array $params = [])` | `array` | Query multiple rows. |
| `LF::db()->exec(string $sql, array $params = [])` | `int` | Execute write operation. Returns affected row count. |
| `LF::db()->lastId()` | `int` | Last inserted row ID. |
| `LF::db()->transaction(callable $fn)` | `mixed` | Run callback in transaction. Auto-commits; rolls back on exception. |
| `LF::db()->pdo()` | `PDO` | Get underlying PDO instance. |

### Auth (internal, but useful for custom registration handlers)

| Function | Returns | Description |
|----------|---------|-------------|
| `_lf_auth_token(object $user)` | `string` | Generate JWT access token. |
| `_lf_auth_refresh_token(object $user)` | `string` | Generate and store refresh token. |
| `_lf_auth_validate_token(string $token)` | `?array` | Validate JWT. Returns payload or null. |
