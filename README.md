# Monad

Monad is an experimental, ultra-lightweight PHP micro-framework contained in a single `index.php`. It leverages metaprogramming and PHP magic methods to offer an extremely smooth development experience, focused on modern Server-Side Rendering (SSR) and natively integrated with HTMX.

## Philosophy
*   **Monofile Core**: The entire framework engine resides in `index.php`.
*   **Zero Dependencies**: Works standalone. If you use Composer, `vendor/autoload.php` is automatically detected and integrated.
*   **HTMX First**: Intelligent layout and HTMX header management to build Single Page Applications (SPA) writing only PHP and HTML.
*   **Fluent & Recursive View Context**: Security first with a modern twist. Views are recursive objects that provide fluent helpers and automatic XSS protection.
*   **Onion Middleware**: A recursive middleware system that allows processing requests both "on the way in" and "on the way out".
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
3.  Optional: configure your database in the `monad.ini` file.

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

--- 

## Routing and Middleware

Registering routes is simple. Monad supports dynamic URL parameters and middleware.

```php
// A global middleware
$app->use(function ($app, $next) {
    // Do something before...
    $response = $next($app);
    // Do something after...
    return $response;
});

// Route group protected by 'auth' middleware
$app->group('/admin', ['auth'], function($app) {
    
    $app->addRoute('GET', '/dashboard', function($app) {
        $app->response->render('admin.dashboard', ['title' => 'Admin Dashboard']);
    });
    
});
```

Full controllers are supported by passing an array `[Class::class, 'method']`.

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
```

Templates are resolved using **dot notation** relative to the project root. For example, `html.admin.dashboard` maps to `./html/admin/dashboard.php`.

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
```

---

## CLI

Monad is not just for HTTP! It includes a built-in micro CLI. If you run `index.php` from the terminal, it will route the execution to your registered commands instead of the HTTP routes.

```php
// Register a command
$app->addCommand('migrate', function($app, $args) {
    echo "Running migrations...\n";
    $app->db->execute('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY)');
    echo "Done!\n";
});
```

You can execute it from the terminal:
```bash
php index.php migrate
```

Or use the `monad` script and run it directly:

```bash
chmod +x monad
./monad migrate
```

---

## Embedding Monad in Other Applications

Monad is great on its own, but it's also designed to be embeddable. If you `require` or `include` `index.php` from another script, it will **not** execute the router automatically. Instead, it will return the `$app` instance, allowing you to use Monad as a service container or a library.

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
```

### Writing Tests

Test files are completely free of setup boilerplate. The CLI automatically boots the framework and injects `$app` (the application instance) and `$test` (the test suite helper) directly into the test file scope.

Example (`tests.php`):

```php
// Setup hooks run before/after each test case
$test->beforeEach(function($app) {
    $app->config->db = ['path' => ':memory:'];
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
- `$test->beforeEach(callable $fn)` / `$test->afterEach(callable $fn)`: Lifecycle hooks.
- `$test->expect($actual)`: Starts a fluent assertion chain.
  - `->toEqual($expected)`: Strict equality (===).
  - `->toBeTrue()` / `->toBeFalse()`: Boolean checks.
  - `->toContain($needle)`: String or array containment.
  - `->toBeInstanceOf($class)`: Class type check.
- `$test->assertThrows(callable $fn, string $exceptionClass)`: Asserts that code throws a specific exception.
- `$test->request(string $method, string $uri)`: Simulates an in-process HTTP request.

---

## Configuration (`monad.ini`)

The `monad.ini` file manages settings. Monad automatically loads these values into both `$app->config` and **Environment Variables** (`getenv()`).

```ini
debug = true

[db]
path = "db.sqlite"
```

---

## Tips

### 1. CSRF Protection
Monad includes built-in CSRF protection. To secure your POST forms, generate a token in your controller and add it as a hidden field in your HTML:

```php
// In your route/controller
$app->addRoute('GET', '/login', function($app) {
    $app->response->render('login', [
        'csrf' => $app->csrf->token()
    ]);
});
```
```html
<!-- In your template -->
<input type="hidden" name="_csrf" value="<?= $view->csrf ?>">
```

### 2. Production Deployment
Since Monad routes everything through a single file, you need to configure your web server (like Nginx or Apache) to send all traffic to `index.php`. 

Example for **Nginx**:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 3. Dev-Friendly Error Page
When `debug = true` in your `monad.ini`, Monad catches any unhandled exceptions or fatal errors and displays a beautiful, dev-friendly HTML error page showing the exact file, line number, and a full stack trace. When `debug = false` (e.g. in production), it safely hides the details and returns a generic 500 Server Error to protect your application.