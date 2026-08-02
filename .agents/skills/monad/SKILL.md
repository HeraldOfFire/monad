---
name: monad
description: Single-file PHP micro-framework
license: MIT
metadata:
  audience: agents
  workflow: php
---

## What do I do

I give you everything you need to work on Monad, a single-file PHP micro-framework. I cover the architecture (DI container, router, template engine, test runner, PDO wrapper, sessions, flash, CSRF, auth, cache, migrations), conventions (template dot-notation, HTMX integration, autoloader), and commands you can run (`./monad test`, `php -S localhost:8000 -t .`).

## When to use me

Use me whenever you are writing or modifying code in a Monad project — adding routes, templates, middleware, database queries, authentication, caching, migrations, or tests. Also use me when you need to understand how a Monad feature works (DI resolution, view escaping, HTMX headers, test request simulation) or when something breaks and you need to debug the framework internals.

## Architecture

- **No `src/`, no Composer, no build step.** Everything lives in `index.php` (~1600 lines).
- All framework classes live in the `Monad` namespace (`Monad\App`, `Monad\MagicObject`, `Monad\RawHtml`, ...). App code starts with `use Monad\App;`.
- `index.php` returns `$app` when `require`d; auto-dispatches only when executed directly. The entry point is detected via `get_included_files()[0]`, so embedding never double-dispatches.
- CLI entry point: `#!/usr/bin/env php` wrapper that `require`s `index.php` and calls `$app->dispatch()`.
- Autoloader: `Monad\Foo\Bar` → `./Monad/Foo/Bar.php`. `vendor/autoload.php` auto-detected if present.
- `monad.ini` is gitignored. Clone flow: `cp monad.example.ini monad.ini`. Values go into `$app->config` and `getenv()`.
- PHP 8.0+, PDO extension. No other dependencies.

## Commands

```bash
php -S localhost:8000 -t .        # dev server
./monad test                      # all tests
./monad test tests.php            # single file
./monad test tests/               # recursive (*Test.php / *test.php)
./monad test --filter=csrf        # only tests matching the description
./monad routes                    # list routes
./monad help                      # list commands
./monad migrate                   # apply pending migrations
./monad migrate:rollback          # undo last batch
./monad migrate:status            # show ran / pending
./monad migrate:make users        # scaffold a migration
```

## MagicObject & DI Container

Services are registered with `$app->bind()` and resolved lazily:

```php
$app->bind('myService', function($app) {
    return new MyService();
});
$app->myService->doSomething(); // resolved on first access, cached forever
```

Key behaviors:

- **Closures are bound to `$app`** via `->call()`. Inside a factory closure, `$this` refers to `$app`, so you can chain: `$this->otherService`.
- **Singleton by default.** Factory runs once, result cached in `$props`. Use `$app->reset()` to wipe all cached services.
- `$app->get(Foo::class)` is typed retrieval; `$app->foo` is magic property access.
- **`$app->share('name', fn($app) => ...)`** registers a lazily-resolved global available as `$view->name` in every template. `auth`, `flash` and `csrf` are already shared.
- Override internal services by implementing the `Monad\*` interface:
  ```php
  $app->bind('db', function($app) {
      return new MyCustomDB(); // Must implement Monad\DB
  });
  ```

## Routing & Middleware

```php
$app->addRoute('GET', '/path', $handler, [$mw1, $mw2]);

$app->group('/prefix', ['mw1'], function($app) {
    $app->addRoute('GET', '/sub', handler);
});
```

- URL params: `/users/:id` → `$app->request->params['id']` (percent-decoded).
- Handlers: Closures or `[Class::class, 'method']`.
- Middleware signature: `function($app, $next)` — call `$next($app)`.
- Global middleware: `$app->use('name')` (string) or `$app->use(closure)`.
- **Named middleware** must be bound via `$app->bind('name', fn($app, $next) => ...)`. An unbound name throws a clear error; a service closure (accepting only `$app`) used as middleware is rejected at dispatch.
- Unknown paths → 404 with a body; known path with wrong verb → 405 with an `Allow` header. Both answer JSON or HTML depending on the `Accept` header.

## Templates & Views

Templates use **dot notation**: `html.admin.dashboard` → `./html/admin/dashboard.php`. Template names are validated — no slashes, no `..`, so they can't escape the view root.

```php
$app->response->layout = 'html.main_layout';
$app->response->render('html.pages.home', ['user' => [...]]);
```

Inside a template, `$view` is a `ViewContext` with auto-escaping and fluent helpers:

```php
<?= $view->data->user->name ?>              <!-- auto-escaped via MagicValue::__toString() -->
<?= $view->data->user->balance->number(2) ?> <!-- number_format -->
<?= $view->data->user->balance->raw() ?>     <!-- bypass escaping (rarely needed) -->
<?= $view->config->debug ?>                  <!-- global config -->
<?= $view->session->get('key') ?>           <!-- session access -->
<?= $view->partial('sub.template', [...]) ?> <!-- renders a partial; returns RawHtml, not double-escaped -->
<?= $view->dump($data) ?>                    <!-- escaped <pre> var_dump, trusted markup -->
```

`$view->data` wraps every value in `MagicValue` (strings/numbers) or `ViewContext` (arrays), recursively. `foreach ($view->data->items as $item)` yields `ViewContext` objects — `$item->name` is still auto-escaped.

Escaping semantics to remember:

- `MagicValue` implements `Stringable` **and** `JsonSerializable` (an escaped value still `json_encode`s to its raw form).
- `->date()` returns `''` on unparseable input (no silent fallback to the epoch).
- **Service objects** reached from a template (`$view->request`, `$view->session`, `$view->auth`) are wrapped in a `ViewProxy`: values read through them are escaped too, but booleans stay real booleans (`if ($view->request->htmx->is)`).
- **`RawHtml`** marks already-safe markup and is emitted verbatim. Returned by `partial`, `dump`, `csrf->field()`, `csrf->htmxAttribute()`.

Layout slot pattern: `$slot` is available in layout templates. Template content is captured and passed as `$slot`.

### HTMX integration

- `$app->request->htmx->is` is `true` on HTMX requests.
- **Layout is automatically skipped** during HTMX requests.
- Send HTMX response headers via `$app->response->htmx`:
  ```php
  $app->response->htmx->trigger = 'refresh';
  $app->response->htmx->redirect = '/new-url';
  // Any prop set becomes an HX-* response header.
  ```
- Response helpers: `$app->response->htmxRedirect($url)` (200 + HX-Redirect, empty body), `$app->response->oob($template, $data)` (append an out-of-band fragment), `$app->response->stream(fn($emit, $app) => ...)` (Server-Sent Events; `$emit` returns false on disconnect).

## Database

PDO/SQLite by default (configured in `monad.ini` `[db] path`).

```php
$app->db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => 1]);
$app->db->fetchAll('SELECT * FROM users');
$app->db->insert('users', ['name' => 'Mario']);   // returns lastInsertId
$app->db->update('users', ['role' => 'admin'], ['id' => 1]); // returns affected rows
$app->db->delete('users', ['id' => 1]);
$app->db->execute('CREATE TABLE ...');             // for DDL

// Transactions: commit on success, rollback + rethrow on Throwable.
// Nested calls join the outer transaction.
$app->db->transaction(function($db) {
    $db->insert('users', ['name' => 'Mario']);
});
```

Table and column names are validated against `/^[a-zA-Z0-9_]+$/` — invalid names throw `InvalidArgumentException`. All values use bound parameters.

## Sessions, Flash & CSRF

Sessions are hardened by default (HttpOnly, SameSite=Lax, Secure behind HTTPS, strict id mode).

```php
$app->session->set('theme', 'dark');
$app->session->get('theme');          // 'dark'
$app->session->has('theme');
$app->session->regenerate();
$app->session->destroy();
```

Flash: one-shot session messages (read consumes). `set`, `get` (consumes), `peek` (doesn't), `has`, `all` (drains).

```php
$app->flash->set('success', 'Saved!');
$app->response->redirect('/next');
// next request:
$app->flash->get('success');          // 'Saved!' — now gone
```

CSRF: `token()`, `rotate()`, `verify($t)` are always available; markup helpers return `RawHtml` — `csrf->field()` (hidden input for plain forms) and `csrf->htmxAttribute()` (hx-headers on `<body>`). Enforcement is **opt-in** via the `verifyCsrf` middleware (checks POST/PUT/PATCH/DELETE, 403 on mismatch):

```php
$app->use('verifyCsrf');   // or per route/group: ['verifyCsrf']
```

## Authentication

Session-based auth over a user table you define. Fully opt-in; no table is auto-created. Config: `[auth] table / identifier / password / loginPath`.

```php
$app->db->insert('users', ['email' => 'a@b.c', 'password' => $app->auth->hash('secret')]);
$app->auth->attempt('a@b.c', 'secret');   // bool, logs in on success
$app->auth->check();                       // bool
$app->auth->user();                        // row without password column, or null
$app->auth->id();
$app->auth->logout();
```

Protect routes with the built-in `requireAuth` middleware (redirects to `loginPath`, 401 JSON for API clients, HX-Redirect for HTMX):

```php
$app->addRoute('GET', '/dashboard', handler, ['requireAuth']);
```

## Caching

Filesystem cache with atomic writes (temp + rename). TTL in seconds; `0` = never expires.

```php
$app->cache->set('key', $value, 3600);
$app->cache->get('key', $default);
$app->cache->has('key');
$app->cache->remember('key', 3600, fn() => expensive());
$app->cache->forget('key');
$app->cache->flush();
```

Config: `[cache] path = "cache"`.

## Migrations

A migration file returns `['up' => fn($db), 'down' => fn($db)]`. Files run inside a transaction; state lives in the `_migrations` table.

```php
// migrations/001_create_users.php
return [
    'up'   => fn($db) => $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password TEXT)'),
    'down' => fn($db) => $db->execute('DROP TABLE users'),
];
```

Config: `[migrations] path = "migrations"`. Run with `./monad migrate` / `./monad migrate:rollback` / `./monad migrate:status` / `./monad migrate:make <name>`.

## Testing

Not PHPUnit — built-in `TestSuite`, run via `./monad test`.

```php
$test->beforeEach(function($app) {
    $app->config->db = ['path' => ':memory:'];
});

$test->it("description", function($app, $test) {
    $test->expect($actual)->toEqual($expected);
});
```

Key behaviors:

- `$app->reset()` is called automatically between cases.
- Lifecycle hooks **accumulate** (a second `beforeEach` adds to the first). `beforeAll` / `afterAll` run once.
- `$test->request('GET', '/path')` simulates in-process HTTP requests, returns `{statusCode, headers, body}`. Third arg `$options`: `['headers' => [...], 'post' => [...], 'files' => [...]]`.
- `./monad test tests.php --filter=csrf` runs only matching descriptions.
- Without `beforeEach` setting `['path' => ':memory:']`, DB tests operate on the real `db.sqlite`.

Assertions: `->toEqual()`, `->toBeTrue()`, `->toBeFalse()`, `->toContain()`, `->toBeInstanceOf()`, `$test->assertThrows(callable, class)`.

## Error handling

- `debug = true` (default in `monad.ini`): dev-friendly HTML error page with file, line and stack trace. `debug = false`: generic 500 page.
- API requests get JSON errors instead of HTML.
- CLI errors print plain text to STDERR.
- PHP deprecations are logged, not promoted to exceptions.
- A reentrancy guard keeps an error that happens while reporting an error from masking the original one.
