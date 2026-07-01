---
name: monad
description: Single-file PHP micro-framework
license: MIT
metadata:
  audience: agents
  workflow: php
---

## What do I do

I give you everything you need to work on Monad, a single-file PHP micro-framework. I cover the architecture (DI container, router, template engine, test runner, PDO wrapper), conventions (template dot-notation, HTMX integration, autoloader), and commands you can run (`./monad test`, `php -S localhost:8000 -t .`).

## When to use me

Use me whenever you are writing or modifying code in a Monad project — adding routes, templates, middleware, database queries, or tests. Also use me when you need to understand how a Monad feature works (DI resolution, view escaping, HTMX headers, test request simulation) or when something breaks and you need to debug the framework internals.

## Architecture

- **No `src/`, no Composer, no build step.** Everything lives in `index.php` (~860 lines).
- `index.php` returns `$app` when `require`d; auto-dispatches only when executed directly.
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
./monad routes                    # list routes
./monad help                      # list commands
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
- Override internal services by implementing the `Monad\*` interface:
  ```php
  $app->bind('db', function($app) {
      return new MyCustomDB(); // Must implement Monad\DB
  });
  ```

## Templates & Views

Templates use **dot notation**: `html.admin.dashboard` → `./html/admin/dashboard.php`.

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
<?= $view->partial('sub.template', [...]) ?> <!-- render a partial -->
```

`$view->data` wraps every value in `MagicValue` (strings/numbers) or `ViewContext` (arrays), recursively. `foreach ($view->data->items as $item)` yields `ViewContext` objects — `$item->name` is still auto-escaped.

Layout slot pattern: `$slot` is available in layout templates. Template content is captured and passed as `$slot`.

### HTMX integration

- `$app->request->htmx->is` is `true` on HTMX requests.
- **Layout is automatically skipped** during HTMX requests.
- Send HTMX response headers via `$app->response->htmx`:
  ```php
  $app->response->htmx->trigger = 'refresh';
  $app->response->htmx->trigger = '{"event1":"A", "event2":"B"}';
  $app->response->htmx->redirect = '/new-url';
  // Any prop set becomes an HX-* response header.
  ```

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
- `$test->request('GET', '/path')` simulates in-process HTTP requests, returns `{statusCode, headers, body}`. Third arg `$options`: `['headers' => [...], 'post' => [...], 'files' => [...]]`.
- Without `beforeEach` setting `['path' => ':memory:']`, DB tests operate on the real `db.sqlite`.

Assertions: `->toEqual()`, `->toBeTrue()`, `->toBeFalse()`, `->toContain()`, `->toBeInstanceOf()`, `$test->assertThrows(callable, class)`.

## Routing & Middleware

```php
$app->addRoute('GET', '/path', $handler, [$mw1, $mw2]);

$app->group('/prefix', ['mw1'], function($app) {
    $app->addRoute('GET', '/sub', handler);
});
```

- URL params: `/users/:id` → `$app->request->params['id']`.
- Handlers: Closures, middleware names (string), or `[Class::class, 'method']`.
- Middleware signature: `function($app, $next)` — call `$next($app)`.
- Global middleware: `$app->use('name')`.

## Database

PDO/SQLite by default (configured in `monad.ini` `[db] path`).

```php
$app->db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => 1]);
$app->db->fetchAll('SELECT * FROM users');
$app->db->insert('users', ['name' => 'Mario']);   // returns lastInsertId
$app->db->update('users', ['role' => 'admin'], ['id' => 1]); // returns affected rows
$app->db->delete('users', ['id' => 1]);
$app->db->execute('CREATE TABLE ...');             // for DDL
```

Column names validated against `/^[a-zA-Z0-9_]+$/` — invalid names throw `InvalidArgumentException`.
