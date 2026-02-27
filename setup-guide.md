# LiteFrame — Setup Guide

## Quick Start

LiteFrame is designed to be drop-and-go. Upload the contents of `dist/` to your web root and visit the URL. On first load, it automatically:

1. Finds a secure location and creates the SQLite database (`data.db`)
2. Runs schema sync (creates all tables)
3. Generates `.htaccess` with security rules
4. Creates `robots.txt`
5. Creates `files/` directories for uploads
6. Redirects to `/`

No Composer. No command line. No database setup.

---

## Deployment Scenarios

### Apache (Shared Hosting, cPanel, MAMP, XAMPP, WAMP)

**No extra configuration needed.** LiteFrame auto-generates an `.htaccess` file on first run that handles URL rewriting, auth headers, and security rules.

Just upload the `dist/` contents to your `public_html/` directory (or a subdirectory) and hit the URL.

### Nginx (Cloudways, VPS, Docker)

LiteFrame works on Nginx but may need a small config addition depending on your setup.

#### Deployed at domain root (e.g., `yourdomain.com/`)

Most Nginx configs already include a `try_files` directive for the root location. If your default config has something like this, you're good:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

This is the default on most managed platforms (Cloudways, Forge, ServerPilot) — it should work out of the box.

#### Deployed in a subdirectory (e.g., `yourdomain.com/myapp/`)

You need to tell Nginx to route requests through `index.php` for your subdirectory. Add this to your Nginx config:

```nginx
location /myapp {
    try_files $uri $uri/ /myapp/index.php?$query_string;
}
```

Replace `/myapp` with your actual subdirectory path.

**Where to add this:**
- **Cloudways:** Application Settings > Vhost (Nginx Config)
- **Laravel Forge:** Sites > Your Site > Nginx Config
- **ServerPilot:** Use an `.htaccess` (ServerPilot runs Apache behind Nginx)
- **Manual Nginx:** Add to your `server {}` block and run `nginx -s reload`

#### How to tell if you need this

If your site loads on the first visit but returns a **404 on page refresh**, Nginx is intercepting the request before PHP can handle it. Add the `try_files` directive above.

### PHP Built-in Server (Local Development)

```bash
php -S localhost:8000 index.php
```

This uses PHP's built-in router. Static files and SPA routing are handled automatically.

### Laravel Herd / Valet

Link or park the project directory as usual. No extra setup needed — Herd/Valet already routes requests through `index.php`.

---

## Subdirectory Deployment

LiteFrame fully supports running in a subdirectory (e.g., `yourdomain.com/myapp/`). The compiled `dist/index.php` automatically detects the subdirectory and adjusts:

- API route matching (strips the subdirectory prefix)
- SPA asset paths (rewrites `/_app/` URLs to include the subdirectory)
- SvelteKit base path (updated at runtime)
- Static file serving
- First-run setup redirect

**The only exception** is Nginx in a subdirectory, which needs the `try_files` directive described above.

---

## Building for Production

From the project root:

```bash
php build.php
```

Or visit `build.php` in your browser during development.

This produces a `dist/` folder:
```
dist/
  index.php      # Single compiled PHP file (everything inlined)
  index.html     # SPA entry point (from frontend/build/)
  _app/          # Frontend assets (from frontend/build/)
  files/
    public/      # Public uploads
    protected/   # Auth-protected uploads
```

Upload the contents of `dist/` — not the `dist/` folder itself — to your deployment target.

---

## Database Security

LiteFrame automatically finds a secure location for `data.db` outside the web root. It tries the following locations in order:

### Location priority chain

| Priority | Location | Example | Works on |
|----------|----------|---------|----------|
| 1 | Parent of web root | `~/data.db` | cPanel, VPS, PaaS, most hosting |
| 2 | `private_html` directory | `~/private_html/data.db` | Cloudways, Plesk |
| 3 | `.data/` inside web root (fallback) | `public_html/.data/data.db` | Everywhere |

The framework tries each in order and uses the first writable location it finds.

### How each location is protected

**Options 1 & 2** store the database outside the web root entirely — no web server can serve it regardless of configuration.

**Option 3** (`.data/` fallback) uses three layers of protection:
- A `.htaccess` file inside `.data/` with `Deny from all`
- A rewrite rule in the main `.htaccess` blocking `^.data/`
- An automatic HTTP self-check that verifies the database is not downloadable

### Self-verification check

When the `.data/` fallback is used, LiteFrame makes a one-time HTTP request to its own database URL. If it gets a 200 response (meaning the file is publicly accessible), it shows an error page with specific fix instructions for your server. Once verified as secure, it stores a flag and never checks again.

### If you see the "Security Error" page

This means the `.data/` fallback is in use AND your server isn't blocking access to it. Fix options:

1. **Best option** — make the parent directory writable so the DB moves outside the web root:
   ```bash
   chmod 755 /path/to/parent/directory
   ```

2. **Nginx** — add a deny rule for dotfiles:
   ```nginx
   location ~ /\.data { deny all; }
   ```

3. **Apache** — ensure `AllowOverride All` is enabled so the `.data/.htaccess` deny rule works.

---

## Troubleshooting

### "Security Error" page on first visit
The database is publicly accessible. See [Database Security](#database-security) above for fix options.

### 403 Forbidden on first visit
The web server user needs write permission to the deployment directory. LiteFrame creates `.htaccess`, `robots.txt`, and the `files/` directories on first run. Ensure the directory is writable.

### "MIME type not allowed" errors for JS files
The SPA asset paths aren't resolving correctly. This usually means a subdirectory path issue. Check that:
1. You uploaded `dist/` contents (not nested inside another folder)
2. If on Nginx in a subdirectory, the `try_files` directive is set

### 404 on page refresh (but first load works)
This is the Nginx subdirectory issue. See the [Nginx subdirectory](#deployed-in-a-subdirectory-eg-yourdomaincommyapp) section above.

### API returns "Not found" for all routes
API routes are prefixed with `/api/` automatically. If your route is `/articles`, the full URL is `yourdomain.com/api/articles` (or `yourdomain.com/myapp/api/articles` in a subdirectory).

### Auth header not passed through
Some Apache configurations strip the `Authorization` header. The auto-generated `.htaccess` includes a fix for this. If you're using a custom `.htaccess`, add:
```apache
RewriteCond %{HTTP:Authorization} ^(.+)$
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

### Files/folders created by PHP can't be deleted via SSH/FTP
PHP may run as a different user (e.g., `www-data`) than your SSH user. Create a temporary PHP script to delete them:
```php
<?php
exec("rm -rf " . __DIR__ . "/files");
echo "done";
```
Hit it in the browser, then delete the script.
