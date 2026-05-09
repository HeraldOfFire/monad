<?php

namespace Monad;

use PDO;
use PDOStatement;
use Throwable;
use ErrorException;

// --- SERVICE INTERFACES ---

interface DB {
    public function query(string $sql, array $params = []): PDOStatement;
    public function fetchOne(string $sql, array $params = []): array|null;
    public function fetchAll(string $sql, array $params = []): array;
    public function execute(string $sql, array $params = []): int;
    public function insert(string $table, array $data): int;
    public function update(string $table, array $data, array $where): int;
    public function delete(string $table, array $where): int;
}

interface Session {
    public function get(string $name): mixed;
    public function has(string $name): bool;
    public function set(string $name, mixed $value): void;
    public function destroy(): bool;
    public function regenerate(bool $del = true): bool;
}

interface CSRF {
    public function token(string $key = 'default'): string;
    public function rotate(string $key = 'default'): string;
    public function verify(?string $token, string $key = 'default'): bool;
}

/**
 * @property-read string $method
 * @property-read string $path
 * @property-read array $params
 * @property-read MagicObject $htmx
 */
interface Request {
    public function body(): string;
    public function getPostVar(string $name): mixed;
    public function getHeader(string $name): mixed;
}

/**
 * @property int $statusCode
 * @property array $headers
 * @property string|null $layout
 * @property-read MagicObject $htmx
 */
interface Response {
    public function setStatusCode(int $code): void;
    public function setHeader(string $name, string $value): void;
    public function redirect(string $url): void;
    public function json(mixed $data, int $code = 200): void;
    public function partial(string $template, array $data = []): string;
    public function render(string $template, array $data = [], int $statusCode = 200): void;
}

/**
 * @property-read MagicObject $config
 * @property-read Request $request
 * @property-read Session $session
 * 
 * @method string partial(string $template, array $data = [])
 * @method mixed raw(string $key)
 * @method string string(string $key)
 * @method string number(string $key, int $decimals = 0)
 * @method string date(string $key, string $format = \DATE_ATOM)
 */
interface View {}

/**
 * Base class for dynamic, magic-enabled objects.
 */
class MagicObject {
    protected array $props;
    public function __construct(array $props) { $this->props = $props; }

    public function &__get(string $name): mixed {
        if (array_key_exists($name, $this->props)) return $this->props[$name];
        if (isset($this->props['registry'][$name]) && $this->props['registry'][$name] instanceof \Closure) {
            $this->props[$name] = $this->props['registry'][$name]->call($this, $this);
            return $this->props[$name];
        }
        if (isset($this->props['registry'][$name])) return $this->props['registry'][$name];
        $null = null; return $null;
    }

    public function __isset(string $name): bool { 
        return array_key_exists($name, $this->props) || isset($this->props['registry'][$name]); 
    }

    public function __set(string $name, mixed $value): void {
        $this->props[$name] = $value;
    }

    public function props(): array {
        return $this->props;
    }

    public function __call(string $name, array $args): mixed {
        $callable = $this->props[$name] ?? $this->props['registry'][$name] ?? null;
        if (!$callable || !is_callable($callable)) {
            throw new \BadMethodCallException("Call to undefined method " . static::class . "::$name()");
        }
        return ($callable instanceof \Closure) ? $callable->call($this, $this, ...$args) : $callable($this, ...$args);
    }
}

/**
 * The Monad App - Inherits magic powers from MagicObject.
 * 
 * @property-read DB $db
 * @property-read Session $session
 * @property-read CSRF $csrf
 * @property-read Request $request
 * @property-read Response $response
 * @property-read object $config
 */
class App extends MagicObject {
    /**
     * Resolves a bound service from the container.
     * 
     * @template T of object
     * @param class-string<T> $name
     * @return T
     */
    public function get(string $name): mixed {
        return $this->__get($name);
    }
}

/**
 * Composer Integration & Minimalist Autoloader.
 */
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

spl_autoload_register(function ($class) {
    $path = str_replace('\\', '/', $class);
    $file = __DIR__ . "/{$path}.php";
    if (file_exists($file)) require $file;
});

// --- APP INITIALIZATION ---
$app = new App([
    'routes' => [],
    'globalMiddleware' => [],
    'registry' => [],
    'groupStack' => [],
    'bind' => function ($app, string $name, mixed $value) { 
        unset($this->props[$name]); 
        $this->registry[$name] = $value; 
    },
    'use' => function ($app, mixed $m) { $this->globalMiddleware[] = $m; },
    'group' => function ($app, string $prefix, array $mw, callable $callback) {
        $this->groupStack[] = ['prefix' => $prefix, 'middleware' => $mw];
        $callback($this);
        array_pop($this->groupStack);
    },
    'addRoute' => function ($app, string $m, string $p, mixed $h, array $mw = []) {
        $prefix = '';
        $groupMw = [];
        foreach ($this->groupStack as $g) {
            $prefix .= $g['prefix'];
            $groupMw = array_merge($groupMw, $g['middleware']);
        }
        $path = $prefix . $p;
        $allMw = array_merge($groupMw, $mw);
        $pattern = '#^' . preg_replace('#:([a-zA-Z_][a-zA-Z0-9_]*)#', '(?P<$1>[^/]+)', $path) . '$#';
        $this->routes[strtoupper($m)][] = ['pattern' => $pattern, 'handler' => $h, 'middleware' => $allMw];
    },
    'dispatch' => function ($app) {
        $method = $this->request->method;
        $path = $this->request->path;
        $matched = null;
        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                $this->request->params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $matched = $route;
                break;
            }
        }
        if (!$matched) { $this->response->setStatusCode(404); echo "404 Not Found"; return; }
        
        $resolver = function ($mw) {
            return is_string($mw) ? ($this->registry[$mw] ?? $mw) : $mw;
        };

        $pipeline = array_map($resolver, array_merge($this->globalMiddleware, $matched['middleware']));
        $handler = $matched['handler'];
        $chain = function ($app) use ($handler) {
            if (is_array($handler)) {
                $controller = new $handler[0]();
                $method = $handler[1];
                return $controller->$method($app);
            }
            return $handler($app);
        };
        
        foreach (array_reverse($pipeline) as $mw) {
            $next = $chain;
            $chain = fn ($app) => $mw($app, $next);
        }
        try { $chain($this); } catch (Throwable $e) { $this->renderError($e); }
    },
    'renderError' => function ($app, Throwable $e) {
        if ($this->response->statusCode === 200) $this->response->setStatusCode(500);
        $app->logger("ERROR: " . $e->getMessage());
        if (!($this->config->debug ?? false)) {
            $this->response->json(['error' => 'Internal Server Error']);
            return;
        }
        $msg = htmlspecialchars($e->getMessage());
        $file = htmlspecialchars($e->getFile());
        $line = $e->getLine();
        $class = htmlspecialchars(get_class($e));
        
        $snippet = "";
        if (file_exists($e->getFile())) {
            $lines = file($e->getFile());
            $start = max(0, $line - 6);
            $end = min(count($lines), $line + 5);
            for ($i = $start; $i < $end; $i++) {
                $num = $i + 1;
                $content = htmlspecialchars($lines[$i]);
                $style = ($num === $line) ? "background:#441111;color:#ff5555;font-weight:bold;" : "";
                $snippet .= "<div style='display:flex;$style'><span style='width:3em;opacity:0.5;user-select:none;'>$num</span><code>$content</code></div>";
            }
        }

        echo "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <title>$class: $msg</title>
            <style>
                body { background: #0f0f10; color: #e0e0e0; font-family: 'Inter', system-ui, sans-serif; line-height: 1.6; margin: 0; padding: 2rem; }
                .container { max-width: 1000px; margin: 0 auto; }
                .header { border-left: 4px solid #ff4444; padding-left: 1.5rem; margin-bottom: 2rem; }
                .type { color: #ff4444; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; font-weight: bold; }
                .msg { font-size: 1.8rem; font-weight: 600; margin: 0.5rem 0; color: #fff; }
                .file { color: #888; font-family: monospace; font-size: 0.9rem; }
                .snippet { background: #1a1a1c; border-radius: 8px; padding: 1rem; overflow-x: auto; margin: 2rem 0; border: 1px solid #333; }
                .trace { font-family: monospace; font-size: 0.85rem; color: #aaa; background: #151517; padding: 1rem; border-radius: 8px; }
                .trace-item { margin-bottom: 0.5rem; border-bottom: 1px solid #222; padding-bottom: 0.5rem; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='type'>$class</div>
                    <div class='msg'>$msg</div>
                    <div class='file'>$file : $line</div>
                </div>
                <div class='snippet'>$snippet</div>
                <h3>Stack Trace</h3>
                <div class='trace'>";
                foreach ($e->getTrace() as $i => $t) {
                    $f = htmlspecialchars($t['file'] ?? 'internal');
                    $l = $t['line'] ?? '?';
                    $fn = htmlspecialchars(($t['class'] ?? '') . ($t['type'] ?? '') . ($t['function'] ?? 'unknown'));
                    echo "<div class='trace-item'>#$i <strong>$f($l)</strong>: $fn()</div>";
                }
        echo "</div></div>
        </body>
        </html>
        ";
        exit;
    }
]);

// --- GLOBAL ERROR HANDLERS ---
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e) use ($app) {
    $app->renderError($e);
});

// --- CORE SERVICES ---
$app->bind('config', function() {
    $file = __DIR__ . '/monad.ini';
    $data = file_exists($file) ? parse_ini_file($file, true) : [];
    
    // Inject into Environment Variables
    $setEnv = function($array, $prefix = '') use (&$setEnv) {
        foreach ($array as $key => $value) {
            $name = strtoupper($prefix . $key);
            if (is_array($value)) {
                $setEnv($value, $name . '_');
            } else {
                putenv("$name=$value");
                $_ENV[$name] = $value;
            }
        }
    };
    $setEnv($data);

    return new MagicObject($data ?: [
        'debug' => true, 
    ]);
});

$app->bind('db', function($app) {
    $dbPath = __DIR__ . '/' . ($app->config->db['path'] ?? 'db.sqlite');
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    return new MagicObject([
        'pdo' => $pdo,
        'query' => function ($db, string $sql, array $params = []): PDOStatement { $stmt = $db->pdo->prepare($sql); $stmt->execute($params); return $stmt; },
        'fetchOne' => function ($db, string $sql, array $params = []): array|null { $row = $db->query($sql, $params)->fetch(); return $row === false ? null : $row; },
        'fetchAll' => function ($db, string $sql, array $params = []): array { return $db->query($sql, $params)->fetchAll(); },
        'execute' => function ($db, string $sql, array $params = []): int { return $db->query($sql, $params)->rowCount(); },
        'insert' => function ($db, string $table, array $data): int {
            $cols = implode(', ', array_keys($data));
            $pl = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));
            $db->execute("INSERT INTO $table ($cols) VALUES ($pl)", $data);
            return (int) $db->pdo->lastInsertId();
        },
        'update' => function ($db, string $table, array $data, array $where): int {
            $set = implode(', ', array_map(fn($k) => "$k = :set_$k", array_keys($data)));
            $cond = implode(' AND ', array_map(fn($k) => "$k = :wh_$k", array_keys($where)));
            $params = [];
            foreach ($data as $k => $v) $params["set_$k"] = $v;
            foreach ($where as $k => $v) $params["wh_$k"] = $v;
            return $db->execute("UPDATE $table SET $set WHERE $cond", $params);
        },
        'delete' => function ($db, string $table, array $where): int {
            $cond = implode(' AND ', array_map(fn($k) => "$k = :$k", array_keys($where)));
            return $db->execute("DELETE FROM $table WHERE $cond", $where);
        }
    ]);
});

$app->bind('session', function() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return new MagicObject([
        'get' => fn ($_, string $name) => $_SESSION[$name] ?? null,
        'has' => fn ($_, string $name) => array_key_exists($name, $_SESSION),
        'set' => function ($_, string $name, mixed $value) { $_SESSION[$name] = $value; },
        'destroy' => function (): bool { $_SESSION = []; return session_destroy(); },
        'regenerate' => fn ($_, bool $del = true) => session_regenerate_id($del),
    ]);
});

$app->bind('csrf', function($app) {
    $app->session; // Force session initialization
    return new MagicObject([
        'token' => function ($_, string $key = 'default'): string {
            $tokens = $_SESSION['_csrf_tokens'] ?? [];
            if (!isset($tokens[$key])) { $tokens[$key] = bin2hex(random_bytes(32)); $_SESSION['_csrf_tokens'] = $tokens; }
            return $tokens[$key];
        },
        'rotate' => function ($_, string $key = 'default'): string {
            $tokens = $_SESSION['_csrf_tokens'] ?? [];
            $tokens[$key] = bin2hex(random_bytes(32)); $_SESSION['_csrf_tokens'] = $tokens;
            return $tokens[$key];
        },
        'verify' => function ($_, ?string $token, string $key = 'default'): bool {
            return is_string($token) && hash_equals($_SESSION['_csrf_tokens'][$key] ?? '', $token);
        },
    ]);
});

$app->bind('request', function($app) {
    $getHeader = fn($n) => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $n))] ?? null;
    return new MagicObject([
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/',
        'params' => [],
        'getPostVar' => fn ($_, string $n) => $_POST[$n] ?? null,
        'getHeader' => $getHeader,
        'body' => function () { static $c = null; if ($c === null) $c = file_get_contents('php://input'); return $c; },
        'htmx' => new MagicObject([
            'is' => ($getHeader('HX-Request') === 'true'),
            'target' => $getHeader('HX-Target'),
            'trigger' => $getHeader('HX-Trigger'),
            'triggerName' => $getHeader('HX-Trigger-Name'),
            'boosted' => ($getHeader('HX-Boosted') === 'true'),
            'currentUrl' => $getHeader('HX-Current-URL'),
        ])
    ]);
});

$app->bind('logger', function($app) {
    return function($msg) use ($app) {
        error_log("[MONAD] {$app->request->method} {$app->request->path} | $msg");
    };
});

$app->bind('response', function($app) {
    $compileViewContext = function ($res, array $data) {
        return new MagicObject([
            'config' => $res->app->config,
            'request' => $res->app->request,
            'session' => $res->app->session,
            'partial' => fn ($_, string $n, array $d = []) => $res->partial($n, $d),
            'raw' => fn ($_, string $key) => $data[$key] ?? null,
            'string' => fn ($_, string $key) => htmlspecialchars((string)($data[$key] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'number' => fn ($_, string $key, int $decimals = 0) => number_format((float)($data[$key] ?? 0), $decimals),
            'date' => fn ($_, string $key, string $f = DATE_ATOM) => date($f, strtotime($data[$key] ?? 'now')),
        ]);
    };

    $resolveTemplate = function (string $name) {
        return __DIR__ . '/' . str_replace('.', '/', $name) . '.php';
    };

    return new MagicObject([
        'app' => $app,
        'statusCode' => 200,
        'headers' => [],
        'layout' => null,
        'htmx' => new MagicObject([]),
        'setStatusCode' => function ($res, int $c) { $res->statusCode = $c; http_response_code($c); },
        'setHeader' => function ($_, string $n, string $v) { header("$n: $v"); },
        'redirect' => function ($_, string $url) { header("Location: $url"); exit; },
        'json' => function ($res, mixed $d, int $c = 200) { $res->setStatusCode($c); header('Content-Type: application/json'); echo json_encode($d); exit; },
        'partial' => function ($res, string $template, array $data = []) use ($compileViewContext, $resolveTemplate) {
            $view = $compileViewContext($res, $data);
            ob_start();
            include $resolveTemplate($template);
            return ob_get_clean();
        },
        'render' => function ($res, string $template, array $data = [], int $statusCode = 200) use ($compileViewContext, $resolveTemplate) {
            $res->setStatusCode($statusCode);

            foreach ($res->htmx->props() as $key => $val) {
                $header = 'HX-' . str_replace(' ', '-', ucwords(str_replace(['_', '-'], ' ', $key)));
                $res->setHeader($header, is_array($val) ? json_encode($val) : (string)$val);
            }

            $view = $compileViewContext($res, $data);
            
            ob_start();
            include $resolveTemplate($template);
            $slot = ob_get_clean();

            if ($res->layout && !$res->app->request->htmx->is) {
                include $resolveTemplate($res->layout);
            } else {
                echo $slot;
            }
        }
    ]);
});

// --- MIDDLEWARES ---
$app->bind('log', function ($app, $next) {
    $start = microtime(true);
    $next($app);
    $ms = number_format((microtime(true) - $start) * 1000, 2);
    $app->logger("{$app->response->statusCode} ({$ms}ms)");
});

$app->bind('auth', function ($app, $next) {
    $public = ['GET /login', 'POST /login'];
    if (in_array("{$app->request->method} {$app->request->path}", $public)) return $next($app);
    if (!$app->session->get('auth_user')) return $app->response->redirect('/login');
    return $next($app);
});

$app->use('log');

// --- ROUTES ---

$app->addRoute('GET', '/health', fn($app) => $app->response->json(['ok' => true, 'time' => date(DATE_ATOM)]));

$app->dispatch();