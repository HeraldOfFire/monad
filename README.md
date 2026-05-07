# Monad

Monad is an experimental, ultra-lightweight PHP micro-framework contained in a single `index.php`. It leverages metaprogramming and PHP magic methods to offer an extremely smooth development experience, focused on modern Server-Side Rendering (SSR) and natively integrated with HTMX.

## Philosophy
*   **Monofile Core**: The entire framework engine resides in `index.php`.
*   **Zero Dependencies**: Works standalone. If you use Composer, `vendor/autoload.php` is automatically detected and integrated.
*   **HTMX First**: Intelligent layout and HTMX header management to build Single Page Applications (SPA) writing only PHP and HTML.
*   **Strict View Context**: Security first. Views do not have implicit access to raw data, preventing XSS vulnerabilities.
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

## Installation

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

## Views and Templates (Strict View Context)

PHP works fine as a templating language, but Monad gives you the tools to use it safely: variables are not globally "extracted". You must use helpers to print them.

Templates are resolved using **dot notation** starting from the location of `index.php`. There are no hidden folders or configurations. For example, `html.admin.dashboard` perfectly maps to `__DIR__ . '/html/admin/dashboard.php'`.

### Rendering
```php
$app->response->layout = 'html.main_layout';
$app->response->render('html.pages.home', ['username' => 'Sam']);
```

### Inside the Template (`html/pages/home.php`)
```html
<?php /** @var Monad\View $view */ ?>

<!-- Secure Access (Escaped for XSS) -->
<h1>Welcome, <?= $view->string('username') ?></h1>

<!-- Access to numerical data and dates -->
<p>Visits: <?= $view->number('visits') ?></p>
<p>Today: <?= $view->date('today', 'm/d/Y') ?></p>

<!-- Unprotected access to raw data (Use with caution!) -->
<?php $userObj = $view->raw('user_object'); ?>

<!-- Include another partial template -->
<?= $view->partial('html.components.status_bar') ?>

<!-- Access to the global context ($session, $config, $request) -->
<?php if ($view->session->has('auth')): ?>
    <p>You are logged in.</p>
<?php endif; ?>
```

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

## Database and Micro Query-Builder

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
<input type="hidden" name="_csrf" value="<?= $view->string('csrf') ?>">
```

### 2. Overriding Core Services
Monad acts as a giant Dependency Injection (DI) Container. Because all core functionalities are registered using `$app->bind()`, you can easily overwrite any service (like `db`, `logger`, or `response`) with your own custom implementation simply by binding it again before adding your routes.

```php
// Example: Overwrite the default logger to write to a file
$app->bind('logger', function($app) {
    return function($msg) {
        file_put_contents(__DIR__ . '/app.log', "[CUSTOM] " . $msg . PHP_EOL, FILE_APPEND);
    };
});
```

### 3. Production Deployment
Since Monad routes everything through a single file, you need to configure your web server (like Nginx or Apache) to send all traffic to `index.php`. 

Example for **Nginx**:
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 4. IDE Autocompletion in Templates
Monad is designed to be fully compatible with your IDE's autocompletion. To enable it, simply add this PHPDoc block at the top of every template:
```php
<?php /** @var Monad\View $view */ ?>
```
This will give you instant intellisense for `$view->session`, `$view->request`, and all helper methods like `$view->string()` and `$view->partial()`.

### 5. Dev-Friendly Error Page
When `debug = true` in your `monad.ini`, Monad catches any unhandled exceptions or fatal errors and displays a beautiful, dev-friendly HTML error page showing the exact file, line number, and a full stack trace. When `debug = false` (e.g. in production), it safely hides the details and returns a generic 500 Server Error to protect your application.
