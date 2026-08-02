# Monad

Monad is an experimental, ultra-lightweight PHP micro-framework contained in a single `index.php`. It leverages metaprogramming and PHP magic methods to offer an extremely smooth development experience, focused on modern Server-Side Rendering (SSR) and natively integrated with HTMX.

Despite the tiny core, it ships a surprisingly complete feature set out of the box: DI container, router with onion middleware, auto-escaping view engine, PDO wrapper, sessions, flash messages, CSRF, authentication, filesystem cache, schema migrations and a built-in test runner.

## Philosophy
*   **Monofile Core**: The entire framework engine resides in `index.php`.
*   **Zero Dependencies**: Works standalone. If you use Composer, `vendor/autoload.php` is automatically detected and integrated.
*   **HTMX First**: Intelligent layout and HTMX header management to build Single Page Applications (SPA) writing only PHP and HTML.
*   **Fluent & Recursive View Context**: Security first with a modern twist. Views are recursive objects that provide fluent helpers and automatic XSS protection.
*   **Onion Middleware**: A recursive middleware system that allows processing requests both "on the way in" and "on the way out".
*   **Batteries Included**: Sessions, flash messages, CSRF, auth, caching and migrations are built-in and opt-in.
*   **MagicObject**: The heart of Monad. It allows defining dynamic behaviors and lazy-loading with a clean and expressive syntax.

---

## Who is Monad for?

Monad is an experimental framework. It is perfect for:
*   **Rapid Prototyping**: Build APIs or SSR web apps in minutes without configuring complex build steps or downloading massive vendor directories.
*   **HTMX Enthusiasts**: If you love the hypermedia-driven approach, Monad gives you a backend that respects it perfectly, rather than fighting against JS-centric paradigms.
*   **Educational Purposes**: An excellent way to study PHP metaprogramming, closures, magic methods, and Dependency Injection containers under the hood.
*   **Micro-Apps & Hackathons**: When you just need a robust router, a fast query builder, and a clean template engine, all in one file.

It is **NOT** recommended for massive enterprise monoliths where strict static typing across thousands of files is necessary, as its reliance on `MagicObject` trades static analysis for extreme dynamic flexibility.

---

## Quickstart
Monad requires **PHP 8.0+** and **PDO extension**.

1.  Clone the repository or copy `index.php`.
2.  Start the PHP development server:
    ```bash
    php -S localhost:8000 -t .
    ```
3.  Optional: configure your database, auth, cache and migrations in the `monad.ini` file.

All framework classes live in the `Monad` namespace (declared at the top of `index.php`), so app code typically starts with:

```php
use Monad\App;
use Monad\MagicObject;
use Monad\MagicValue;
use Monad\RawHtml;
```

---

## The `MagicObject`

The power of Monad comes from the `MagicObject` class, allowing you to create dynamic and expressive objects. Every framework service (App, Request, Response, DB) is a `MagicObject`.

`MagicObject`s allow you to define properties and closures that are resolved dynamically and "bound" to the instance.

```php
$app = new App([
    // Closures are executed in the App context
    'greet' => function($app, $name) {
        return "Hello $name!";
    }
]);

echo $app->greet('World');
```

## Services
Monad acts as a giant Dependency Injection (DI) Container.
You can register your own services using `$app->bind()` and access them using magic properties.

```php
$app->bind('myService', function($app) {
    return new MyService();
});

$app->myService->sayHello();
```

This works, but you won't get any autocompletion for it. To fix that, just use the class/interface name as binding key and then retrieve the service using `$app->get()`:

```php
$app->bind(MyService::class, function($app) {
    return new MyService();
});

$myService = $app->get(MyService::class);
$myService->sayHello(); // Autocomplete works fine :)
```
You can even override default services just by binding them again:
```php
// Re-bind the internal db service to your custom implementation
$app->bind('db', function($app) {
    return new MyCustomDB(); // Must implement Monad\DB interface!
});

// Now you can use your custom DB implementation
$app->db->select('users', ['id' => 1]);
``` 
Just remember that when overriding internal services, custom services **must implement Monad interfaces** (i.e. `Monad\DB`) and you won't get any custom methods autocompletion for them.

Services are singletons: a factory runs once and the result is cached. Call `$app->reset()` to clear all resolved instances and force re-resolution.

### Shared view globals
Expose a lazily-resolved global to every template with `$app->share()`:

```php
$app->share('stats', fn($app) => new StatsService());
// In any template: $view->stats->today()
```

Globals resolve lazily (declaring one costs nothing until a template touches it). `auth`, `flash` and `csrf` are already shared for you.

---

## Routing and Middleware

Registering routes is simple. Monad supports dynamic URL parameters, route groups, named middleware and full controllers.

```php
// A global middleware
$app->use(function ($app, $next) {
    // Do something before...
    $response = $next($app);
    // Do something after...
    return $response;
});

// Route group protected by a named middleware
$app->group('/admin', ['requireAuth'], function($app) {

    $app->addRoute('GET', '/dashboard', function($app) {
        $app->response->render('admin.dashboard', ['title' => 'Admin Dashboard']);
    });

});
```

Full controllers are supported by passing an array `[Class::class, 'method']`.

Routing behaviors:

* URL parameters use `:param` syntax: `/users/:id` → `$app->request->params['id']` (percent-decoded).
* **404 responses have a body** (JSON or HTML depending on the `Accept` header).
* A known path with the wrong verb returns **405 with an `Allow` header**.
* Middleware can be bound by name with `$app->bind()` and referenced as a string. An unbound name throws a clear error. A service closure used as middleware (one that only accepts `$app`) is rejected at dispatch time instead of silently aborting the chain.

---

## Views and Templates

PHP is your templating engine, and Monad makes it safe and elegant. Data passed to the view is wrapped in a **recursive** context that provides automatic escaping and fluent helpers.

### Rendering
```php
$app->response->layout = 'html.main_layout';
$app->response->render('html.pages.home', [
    'user' => ['name' => 'Sam', 'balance' => 1250.50],
    'items' => [['name' => 'Pizza', 'price' => 12], ['name' => 'Soda', 'price' => 2]]
]);
```

### Inside the Template (`html/pages/home.php`)
```php
<?php /** @var Monad\View $view */ ?>

<!-- Automatic Escaping (XSS Protected) -->
<h1>Welcome, <?= $view->data->user->name ?></h1>

<!-- Fluent Helpers -->
<p>Balance: <?= $view->data->user->balance->number(2) ?> €</p>

<!-- Recursive Loops -->
<ul>
    <?php foreach ($view->data->items as $item): ?>
        <li><?= $item->name ?>: <?= $item->price->number(2) ?> €</li>
    <?php endforeach; ?>
</ul>

<!-- Access to the global context ($session, $config, $request) -->
<?php if ($view->session->has('auth')): ?>
    <p>Logged in as <?= $view->session->get('username') ?></p>
<?php endif; ?>

<!-- Shared globals (auth / flash / csrf / anything you shared) -->
<?php if ($view->auth->check()): ?>
    <p>Ciao, <?= $view->auth->user()->name ?></p>
<?php endif; ?>
```

Templates are resolved using **dot notation** relative to the project root. For example, `html.admin.dashboard` maps to `./html/admin/dashboard.php`. Template names are validated (no slashes, no `..`) so they can never escape the view root.

### Escaping semantics
* Values are wrapped in `MagicValue`, whose `__toString()` HTML-escapes automatically. Helpers: `->number($d)`, `->date($fmt)`, `->default($fallback)`, `->json()`, `->raw()`.
* `MagicValue` implements `Stringable` and `JsonSerializable`: a value that was escaped still serializes to its raw form in `json_encode` (previously it came out as `{}`).
* `->date()` returns an empty string on unparseable input instead of silently falling back to the Unix epoch.
* Service objects reached from a template (`$view->request`, `$view->session`, `$view->auth`) are wrapped in a `ViewProxy` so values read *through* them are escaped too, while booleans stay real booleans (`if ($view->request->htmx->is)` stays honest).
* Markup that is already safe is returned as `RawHtml` and emitted verbatim: `$view->partial(...)`, `$view->dump(...)` and the CSRF helpers.

### Partials & debugging
```php
<?= $view->partial('partials.header', ['title' => 'Home']) ?>
<?= $view->dump($data) ?>   <!-- <pre> var_dump, escaped, trusted markup -->
```
Outside templates, use `$app->dump(...)` (or `$app->dd(...)` to die), and `$app->dumpHtml(...)` to get the HTML string.

---

## HTMX Integration

[HTMX](https://htmx.org) is a powerful frontend library that allows you to access AJAX, WebSockets, and Server Sent Events directly in HTML, using standard attributes.

Monad is designed from the ground up to pair perfectly with HTMX. It knows when a request comes from HTMX (`$app->request->htmx->is`).
If you call `$app->response->render(...)` during an HTMX request, the main `layout` is **automatically ignored**, returning only the requested HTML fragment!

Furthermore, you can send commands to the HTMX frontend using the Response's magic `htmx` object:
```php
// Trigger a JS event on the client
$app->response->htmx->trigger = 'updateList';
$app->response->render('user_list', [...]);
```
Any property set on `$app->response->htmx` becomes an `HX-*` response header.

### HTMX-specific response helpers
```php
// Navigate the browser instead of swapping a fragment (e.g. after login/logout)
$app->response->htmxRedirect('/login');

// Append an out-of-band fragment after the main body
// (its root element must carry hx-swap-oob="true")
$app->response->oob('cart.count', ['n' => 3]);

// Server-Sent Events stream. $emit returns false once the client disconnects.
$app->response->stream(function($emit, $app) {
    while ($emit('tick')) {
        usleep(1_000_000);
    }
});
```

---

## Database

Monad uses SQLite by default (configurable in `monad.ini`).
The DB service includes quick methods to avoid writing tedious SQL boilerplate:

```php
// Read
$user = $app->db->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => 1]);
$users = $app->db->fetchAll('SELECT * FROM users');

// Insert (Returns the ID)
$id = $app->db->insert('users', ['username' => 'mario', 'role' => 'admin']);

// Update (Returns affected rows)
$app->db->update('users', ['role' => 'user'], ['id' => $id]);

// Delete
$app->db->delete('users', ['id' => $id]);

// Transactions: commit on success, rollback and rethrow on any Throwable.
// Nested transaction() calls join the outer transaction.
$app->db->transaction(function($db) {
    $db->insert('users', ['username' => 'mario']);
    $db->update('accounts', ['balance' => 100], ['id' => 1]);
});
```

Table and column names are validated against `/^[a-zA-Z0-9_]+$/` — invalid names throw `InvalidArgumentException`. All values are bound parameters, so queries are injection-safe.

---

## Authentication

The `auth` service is session-based and reads a user table you define. It is fully opt-in: nothing touches the database until you call it, and no table is auto-created.

### Configuration (`monad.ini`)
```ini
[auth]
table = "users"
identifier = "email"
password = "password"
loginPath = "/login"
```

### Service API
```php
// Registration
$app->db->insert('users', [
    'email'    => 'mario@example.com',
    'password' => $app->auth->hash('secret123'),
]);

// Login
if ($app->auth->attempt('mario@example.com', 'secret123')) {
    $app->response->redirect('/dashboard');
}

// Auth state
$app->auth->check();   // bool
$app->auth->id();      // user id or null
$app->auth->user();    // row without the password column, or null

// Logout (clears the identity and regenerates the session)
$app->auth->logout();
```

### Protecting routes
`requireAuth` is a built-in middleware. Reference it by name on a route, a group, or globally:

```php
$app->use('requireAuth');
$app->addRoute('GET', '/dashboard', handler, ['requireAuth']);
$app->group('/admin', ['requireAuth'], function($app) { ... });
```

When unauthenticated it: redirects to `loginPath` (302) for browser requests, answers `401 {"error":"Unauthenticated"}` for JSON clients, and sends an `HX-Redirect` for HTMX requests.

### Notes
* The login form and `/login` route are up to your app.
* Login state is just the user id stored in the session (`_auth_id`); `login()` regenerates the session id to prevent fixation.
* The password hash is never exposed through `user()`.

---

## Sessions, Flash & CSRF

### Sessions
Cookies are hardened by default: `HttpOnly`, `SameSite=Lax`, `Secure` behind HTTPS, strict session id mode.

```php
$app->session->set('theme', 'dark');
$app->session->get('theme');     // 'dark'
$app->session->has('theme');     // true
$app->session->regenerate();     // session_regenerate_id
$app->session->destroy();
```

### Flash messages
One-shot session messages, the backbone of the POST → redirect → show pattern. Reading a key consumes it.

```php
// After handling a POST, before redirecting
$app->flash->set('success', 'Transazione salvata!');
$app->response->redirect('/transactions');

// In the next request's template or controller
$app->flash->has('success');      // true
$app->flash->get('success');      // 'Transazione salvata!' (and it's now gone)
$app->flash->peek('success');     // read without consuming
$app->flash->all();               // drain everything
```

### CSRF
Tokens are per-key, capped at 32 entries. Generation and verification are always available:

```php
$app->csrf->token();            // generate/read
$app->csrf->rotate();
$app->csrf->verify($token);
```

Markup helpers return `RawHtml` so the view layer emits them verbatim:
```html
<!-- Plain HTML form -->
<?= $view->csrf->field() ?>               <!-- <input type="hidden" name="_csrf" ...> -->
<!-- HTMX: put on <body>, it is inherited by all requests -->
<body <?= $view->csrf->htmxAttribute() ?>> <!-- hx-headers='{"X-CSRF-Token":"..."}' -->
```

Enforcement is **opt-in** via the `verifyCsrf` middleware (checks POST/PUT/PATCH/DELETE against the form field or the `X-CSRF-Token` header, returns 403 on mismatch):

```php
$app->use('verifyCsrf');          // globally
// or per route/group: ['verifyCsrf']
```

---

## Caching

A small filesystem cache with atomic writes (temp file + rename, so a concurrent reader never sees a half-written entry).

```php
$app->cache->set('key', $value, 3600);     // TTL in seconds; 0 = never expires
$app->cache->get('key', $default);
$app->cache->has('key');
$app->cache->remember('key', 3600, fn() => $expensiveComputation());
$app->cache->forget('key');
$app->cache->flush();                       // returns the number of removed files
```

```ini
[cache]
path = "cache"
```

---

## Migrations

A migration is a plain PHP file returning `up`/`down` closures that receive the `db` service. Files run inside a transaction (SQLite supports transactional DDL), and state lives in a `_migrations` table.

`migrations/001_create_users.php`:
```php
return [
    'up' => function($db) {
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password TEXT)');
    },
    'down' => function($db) {
        $db->execute('DROP TABLE users');
    },
];
```

```ini
[migrations]
path = "migrations"
```

---

## CLI

Monad is not just for HTTP! It includes a built-in micro CLI. If you run `index.php` from the terminal, it will route the execution to your registered commands instead of the HTTP routes.

```php
// Register a command
$app->addCommand('db:reset', function($app, $args) {
    echo "Resetting database...\n";
    $app->db->execute('DROP TABLE IF EXISTS users');
    echo "Done!\n";
});
```

You can execute it from the terminal:
```bash
php index.php db:reset
```

Or use the `monad` script and run it directly:
```bash
chmod +x monad
./monad db:reset
```

Built-in commands:

| Command | Purpose |
| --- | --- |
| `./monad help` | List available commands |
| `./monad routes` | List registered routes |
| `./monad migrate` | Apply all pending migrations (one batch) |
| `./monad migrate:rollback` | Undo the last batch |
| `./monad migrate:status` | Show which migrations have run / are pending |
| `./monad migrate:make <name>` | Scaffold a new migration file |
| `./monad test` | Run the test suite |

---

## Embedding Monad in Other Applications

Monad is great on its own, but it's also designed to be embeddable. If you `require` or `include` `index.php` from another script, it will **not** execute the router automatically. Instead, it will return the `$app` instance, allowing you to use Monad as a service container or a library.

Auto-dispatch is detected via `get_included_files()[0]` — the real entry script on every SAPI — so embedding from another file never triggers a second, silent dispatch.

### Use Monad in standalone scripts
You can reuse your database connection, configuration, and services in cron jobs or background tasks:

```php
// cron.php
$app = require 'index.php';

// Reuse the DB service
$users = $app->db->fetchAll("SELECT * FROM users WHERE active = 0");
echo "Found " . count($users) . " inactive users.";
```

### Manual Dispatching
If you embed Monad in another framework or a custom entry point, you can trigger the routing manually. One example of this is Monad's own testing script (see below).

---

## Testing

Monad features a zero-boilerplate, CLI-driven testing engine. Tests run in isolation with a freshly reset container for each test case.

### Running Tests

Tests must be run exclusively via the Monad CLI:

```bash
# Run the default test suite (tests.php)
./monad test

# Run a single specific test file
./monad test tests/UserTest.php

# Run all test files (ending in *Test.php or *test.php) inside a folder recursively
./monad test tests/

# Only tests whose description contains "csrf"
./monad test tests.php --filter=csrf
```

### Writing Tests

Test files are completely free of setup boilerplate. The CLI automatically boots the framework and injects `$app` (the application instance) and `$test` (the test suite helper) directly into the test file scope.

Example (`tests.php`):

```php
// Setup hooks run before/after each test case (they accumulate)
$test->beforeEach(function($app) {
    $app->config->db = ['path' => ':memory:'];
});
$test->beforeAll(function($app) {
    // runs once, before the first test
});
$test->afterAll(function($app) {
    // runs once, after the last test
});

// Register a test case
$test->it("DI Container acts as a Singleton", function($app, $test) {
    $app->bind('rand', fn() => rand(1, 1000000));
    $test->expect($app->rand)->toEqual($app->rand);
});

// Simulate in-process HTTP requests
$test->it("handles basic request simulation", function($app, $test) {
    $response = $test->request('GET', '/health');
    $test->expect($response->statusCode)->toEqual(200);
});
```

### Test API

- `$test->it(string $desc, callable $fn)`: Registers a test case.
- `$test->beforeEach(callable $fn)` / `$test->afterEach(callable $fn)`: Lifecycle hooks, run per case.
- `$test->beforeAll(callable $fn)` / `$test->afterAll(callable $fn)`: Lifecycle hooks, run once.
- `$test->expect($actual)`: Starts a fluent assertion chain.
  - `->toEqual($expected)`: Strict equality (===).
  - `->toBeTrue()` / `->toBeFalse()`: Boolean checks.
  - `->toContain($needle)`: String or array containment.
  - `->toBeInstanceOf($class)`: Class type check.
- `$test->assertThrows(callable $fn, string $exceptionClass)`: Asserts that code throws a specific exception.
- `$test->request(string $method, string $uri, array $options = [])`: Simulates an in-process HTTP request. Options: `headers`, `post`, `files`. Returns `{statusCode, headers, body}`.

---

## Configuration (`monad.ini`)

The `monad.ini` file manages settings. Monad automatically loads these values into both `$app->config` and **Environment Variables** (`getenv()`).

```ini
debug = true

[db]
path = "db.sqlite"

[auth]
table = "users"
identifier = "email"
password = "password"
loginPath = "/login"

[cache]
path = "cache"

[migrations]
path = "migrations"

[views]
path = "views"
```

The `views` path is optional: templates default to the project root. Putting them outside the docroot (e.g. `path = "views"`) keeps them from being directly requestable over HTTP.

---

## Tips

### 1. Production Deployment
Since Monad routes everything through a single file, you need to configure your web server (like Nginx or Apache) to send all traffic to `index.php`.

Example for **Nginx**:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 2. Dev-Friendly Error Page
When `debug = true` in your `monad.ini`, Monad catches any unhandled exceptions or fatal errors and displays a beautiful, dev-friendly HTML error page showing the exact file, line number, and a full stack trace. When `debug = false` (e.g. in production), it safely hides the details and returns a generic 500 Server Error to protect your application.

Error handling details:
* API requests (`Accept: application/json`) get JSON errors instead of HTML.
* CLI errors are reported as plain text on STDERR.
* PHP deprecations are logged, not promoted to exceptions.
* A reentrancy guard prevents an error raised while reporting an error from replacing the original one.
