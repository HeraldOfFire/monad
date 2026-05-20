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
 * @property-read ViewContext $data
 * @property-read MagicObject $config
 * @property-read MagicObject $session
 * @property-read MagicObject $request
 * @property-read MagicValue|ViewContext $balance
 * @property-read MagicValue|ViewContext $prefix
 * 
 * @method string partial(string $template, array $data = [])
 */
interface View extends \IteratorAggregate, \ArrayAccess, \Countable {}

/**
 * @method string number(int $decimals = 0)
 * @method string date(string $format = 'Y-m-d')
 * @method mixed default(mixed $fallback)
 * @method string json()
 * @method mixed raw()
 */
class MagicValue {
    public function __construct(private mixed $v) {}
    public function __toString(): string { return htmlspecialchars((string)($this->v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public function number(int $d = 0): string { return number_format((float)($this->v ?? 0), $d); }
    public function date(string $f = 'Y-m-d'): string { return date($f, is_numeric($this->v) ? (int)$this->v : strtotime((string)($this->v ?? 'now'))); }
    public function default(mixed $f): mixed { return empty($this->v) ? new MagicValue($f) : $this; }
    public function json(): string { return htmlspecialchars(json_encode($this->v), ENT_QUOTES, 'UTF-8'); }
    public function raw() { return $this->v; }
}

class MagicObject {
    protected array $props;
    public function __construct(array $props) { $this->props = $props; }

    public function __get(string $name): mixed {
        if (array_key_exists($name, $this->props)) return $this->props[$name];
        if (isset($this->props['registry'][$name]) && $this->props['registry'][$name] instanceof \Closure) {
            $this->props[$name] = $this->props['registry'][$name]->call($this, $this);
            return $this->props[$name];
        }
        if (isset($this->props['registry'][$name])) return $this->props['registry'][$name];
        return null;
    }

    public function __isset(string $name): bool { 
        return array_key_exists($name, $this->props) || isset($this->props['registry'][$name]); 
    }

    public function __set(string $name, mixed $value): void { $this->props[$name] = $value; }
    public function __unset(string $name): void { unset($this->props[$name]); }
    public function props(): array { return $this->props; }

    public function __call(string $name, array $args): mixed {
        $callable = $this->props[$name] ?? $this->props['registry'][$name] ?? null;
        if (!$callable || !is_callable($callable)) throw new \BadMethodCallException("Undefined method $name");
        return ($callable instanceof \Closure) ? $callable->call($this, $this, ...$args) : $callable($this, ...$args);
    }
}

class ViewContext extends MagicObject implements View {
    public function __get(string $name): mixed {
        $v = parent::__get($name);
        if (is_array($v)) return new ViewContext($v);
        return (is_object($v) || $v === null) ? $v : new MagicValue($v);
    }
    public function getIterator(): \Traversable { foreach ($this->props as $k => $v) yield $k => $this->__get($k); }
    public function offsetExists($o): bool { return isset($this->props[$o]); }
    public function offsetGet($o): mixed { return $this->__get($o); }
    public function offsetSet($o, $v): void { $this->props[$o] = $v; }
    public function offsetUnset($o): void { unset($this->props[$o]); }
    public function count(): int { return count($this->props); }
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
    'commands' => [
        'help' => function($app) {
            echo <<<EOT
          ┓
┏┳┓┏┓┏┓┏┓┏┫
┛┗┗┗┛┛┗┗┻┗┻ CLI
Available commands:\n
EOT;
            foreach (array_keys($app->props()['commands']) as $cmd) {
                echo "  - $cmd\n";
            }
        },
        'routes' => function($app) {
            $routes = $app->props()['routes'] ?? [];
            if (empty($routes)) {
                echo "  (No routes registered)\n";
                return;
            }
            foreach ($routes as $method => $methodRoutes) {
                foreach ($methodRoutes as $route) {
                    $path = $route['path'] ?? $route['pattern'];
                    $mw = implode(', ', array_map(fn($m) => is_string($m) ? $m : 'Closure', $route['middleware']));
                    $mwStr = $mw ? " [$mw]" : "";
                    $h = $route['handler'];
                    $cntrl = is_array($h) ? $h[0] . '::' . $h[1] : (is_string($h) ? $h : 'Closure');
                    echo "  " . str_pad("[$method]", 7) . " $path" . " [$cntrl] " . ($mwStr ? "($mw)" : "") . "\n";
                }   
            }
        },
        'test' => function($app) {
            passthru('php ' . __DIR__ . '/test.php');
        }
    ],
    'groupStack' => [],
    'bind' => function ($app, string $name, mixed $value) { 
        unset($this->props[$name]); 
        $this->props['registry'][$name] = $value; 
    },
    'use' => function ($app, mixed $m) { $this->props['globalMiddleware'][] = $m; },
    'group' => function ($app, string $prefix, array $mw, callable $callback) {
        $this->props['groupStack'][] = ['prefix' => $prefix, 'middleware' => $mw];
        $callback($this);
        array_pop($this->props['groupStack']);
    },
    'addRoute' => function ($app, string $m, string $p, mixed $h, array $mw = []) {
        $prefix = '';
        $groupMw = [];
        foreach ($this->props['groupStack'] as $g) {
            $prefix .= $g['prefix'];
            $groupMw = array_merge($groupMw, $g['middleware']);
        }
        $path = $prefix . $p;
        $allMw = array_merge($groupMw, $mw);
        $pattern = '#^' . preg_replace('#:([a-zA-Z_][a-zA-Z0-9_]*)#', '(?P<$1>[^/]+)', $path) . '$#';
        $this->props['routes'][strtoupper($m)][] = ['path' => $path, 'pattern' => $pattern, 'handler' => $h, 'middleware' => $allMw];
    },
    'addCommand' => function ($app, string $name, callable $handler) {
        $this->props['commands'][$name] = $handler;
    },
    'dispatch' => function ($app) {
        if (php_sapi_name() === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
            $argv = $_SERVER['argv'] ?? $GLOBALS['argv'] ?? [];
            $cmdName = $argv[1] ?? 'help';
            $args = array_slice($argv, 2);
            if (isset($this->commands[$cmdName])) {
                try {
                    return $this->commands[$cmdName]($app, $args);
                } catch (Throwable $e) {
                    $app->logger("CLI ERROR: " . $e->getMessage());
                    echo "Error: " . $e->getMessage() . "\n";
                    exit(1);
                }
            }
            echo "Command '$cmdName' not found.\n";
            return;
        }

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
        if (!$matched) { 
            $this->response->setStatusCode(404);
            if (($this->request->getHeader('Accept') ?? '') === 'application/json') {
                $this->response->json(['error' => 'Not Found'], 404);
                return;
            }
            return; 
        }
        
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
            if (($this->request->getHeader('Accept') ?? '') === 'application/json') {
                $this->response->json(['error' => 'Internal Server Error']);
            }
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
                $st = ($num === $line) ? "background:#441111;color:#ff5555;font-weight:bold;" : "";
                $snippet .= "<div style='display:flex;$st'><span style='width:3em;opacity:0.5;user-select:none;'>$num</span><code>$content</code></div>";
            }
        }

        $css = "#monad-error{background:#0f0f10;color:#e0e0e0;font-family:system-ui,sans-serif;padding:2rem;position:fixed;top:0;left:0;width:100%;height:100%;overflow:auto;z-index:999999}
                #monad-error .cnt{max-width:1000px;margin:0 auto}
                #monad-error .hdr{border-left:4px solid #ff4444;padding-left:1.5rem;margin-bottom:2rem}
                #monad-error .type{color:#ff4444;font-size:.9rem;font-weight:bold;text-transform:uppercase}
                #monad-error .msg{font-size:1.8rem;font-weight:600;margin:.5rem 0;color:#fff}
                #monad-error .file{color:#888;font-family:monospace}
                #monad-error .snip{background:#1a1a1c;padding:1rem;overflow-x:auto;margin:2rem 0;border:1px solid #333;border-radius:8px}
                #monad-error .trc{font-family:monospace;font-size:.85rem;color:#aaa;background:#151517;padding:1rem;border-radius:8px}
                #monad-error .item{margin-bottom:.5rem;border-bottom:1px solid #222;padding-bottom:.5rem}";

        echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Error: $msg</title><style>$css</style></head><body>
        <div id='monad-error'><div class='cnt'>
            <div class='hdr'><div class='type'>$class</div><div class='msg'>$msg</div><div class='file'>$file : $line</div></div>
            <div class='snip'>$snippet</div>
            <h3>Stack Trace</h3><div class='trc'>";
        foreach ($e->getTrace() as $i => $t) {
            $f = htmlspecialchars($t['file'] ?? 'internal'); $l = $t['line'] ?? '?'; $fn = htmlspecialchars(($t['class'] ?? '') . ($t['type'] ?? '') . ($t['function'] ?? 'unknown'));
            echo "<div class='item'>#$i <strong>$f($l)</strong>: $fn()</div>";
        }
        echo "</div></div></div></body></html>";
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
    $path = $app->config->db['path'] ?? 'db.sqlite';
    $dsn = ($path === ':memory:') ? 'sqlite::memory:' : 'sqlite:' . __DIR__ . '/' . $path;
    $pdo = new PDO($dsn);
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
            $safeKeys = array_filter(array_keys($data), fn($k) => preg_match('/^[a-zA-Z0-9_]+$/', $k));
            if (count($safeKeys) !== count($data)) throw new \InvalidArgumentException("Invalid column name in insert data");
            $cols = implode(', ', $safeKeys);
            $pl = implode(', ', array_map(fn($k) => ":$k", $safeKeys));
            $db->execute("INSERT INTO $table ($cols) VALUES ($pl)", $data);
            return (int) $db->pdo->lastInsertId();
        },
        'update' => function ($db, string $table, array $data, array $where): int {
            $safeDataKeys = array_filter(array_keys($data), fn($k) => preg_match('/^[a-zA-Z0-9_]+$/', $k));
            $safeWhereKeys = array_filter(array_keys($where), fn($k) => preg_match('/^[a-zA-Z0-9_]+$/', $k));
            if (count($safeDataKeys) !== count($data) || count($safeWhereKeys) !== count($where)) throw new \InvalidArgumentException("Invalid column name in update data");
            $set = implode(', ', array_map(fn($k) => "$k = :set_$k", $safeDataKeys));
            $cond = implode(' AND ', array_map(fn($k) => "$k = :wh_$k", $safeWhereKeys));
            $params = [];
            foreach ($data as $k => $v) $params["set_$k"] = $v;
            foreach ($where as $k => $v) $params["wh_$k"] = $v;
            return $db->execute("UPDATE $table SET $set WHERE $cond", $params);
        },
        'delete' => function ($db, string $table, array $where): int {
            $safeWhereKeys = array_filter(array_keys($where), fn($k) => preg_match('/^[a-zA-Z0-9_]+$/', $k));
            if (count($safeWhereKeys) !== count($where)) throw new \InvalidArgumentException("Invalid column name in delete data");
            $cond = implode(' AND ', array_map(fn($k) => "$k = :$k", $safeWhereKeys));
            return $db->execute("DELETE FROM $table WHERE $cond", $where);
        }
    ]);
});

$app->bind('session', function() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true
        ]);
    }
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
    $getHeader = fn($_, $n) => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', (string)$n))] ?? null;
    return new MagicObject([
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/',
        'params' => [],
        'getQueryVar' => fn($_, string $n, mixed $d = null) => $_GET[$n] ?? $d,
        'getPostVar' => fn ($_, string $n, mixed $d = null) => $_POST[$n] ?? $d,
        'getFile' => fn ($_, string $n) => $_FILES[$n] ?? null,
        'getHeader' => $getHeader,
        'body' => function () { static $c = null; if ($c === null) $c = file_get_contents('php://input'); return $c; },
        'htmx' => new MagicObject([
            'is' => ($getHeader(null, 'HX-Request') === 'true'),
            'target' => $getHeader(null, 'HX-Target'),
            'trigger' => $getHeader(null, 'HX-Trigger'),
            'triggerName' => $getHeader(null, 'HX-Trigger-Name'),
            'boosted' => ($getHeader(null, 'HX-Boosted') === 'true'),
            'currentUrl' => $getHeader(null, 'HX-Current-URL'),
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
        return new ViewContext(array_merge(
            ['data' => $data], 
            [
                'config' => $res->app->config,
                'request' => $res->app->request,
                'session' => $res->app->session,
                'partial' => fn ($_, string $n, array $d = []) => $res->partial($n, $d)
            ]
        ));
    };

    $resolveTemplate = function ($name) {
        return __DIR__ . '/' . str_replace('.', '/', (string)$name) . '.php';
    };

    return new MagicObject([
        'app' => $app,
        'statusCode' => 200,
        'headers' => [],
        'layout' => null,
        'htmx' => new MagicObject([]),
        'setStatusCode' => function ($res, int $c) { $res->statusCode = $c; http_response_code($c); },
        'setHeader' => function ($_, string $n, string $v) { header("$n: $v"); },
        'redirect' => function ($_, string $url) { header("Location: $url"); },
        'json' => function ($res, mixed $d, int $c = 200) { $res->setStatusCode($c); header('Content-Type: application/json'); echo json_encode($d); },
        'partial' => function ($res, string $template, array $data = []) use ($compileViewContext, $resolveTemplate) {
            $view = $compileViewContext($res, $data);
            ob_start();
            include $resolveTemplate($template);
            return ob_get_clean();
        },
        'render' => function ($res, string $template, array $data = [], int $statusCode = 200) use ($compileViewContext, $resolveTemplate) {
            $res->setStatusCode($statusCode);

            foreach ($res->htmx->props() as $key => $val) {
                $header = 'HX-' . str_replace(' ', '-', ucwords(str_replace(['_', '-'], ' ', (string)$key)));
                $res->setHeader($header, is_array($val) ? json_encode($val) : (string)$val);
            }

            $view = $compileViewContext($res, $data);
            
            $renderFile = function($path, $view, $slot = null) {
                include $path;
            };

            ob_start();
            $renderFile($resolveTemplate($template), $view);
            $slot = ob_get_clean();

            if ($res->layout && !$res->app->request->htmx->is) {
                $renderFile($resolveTemplate($res->layout), $view, $slot);
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


$app->use('log');

// --- ROUTES ---

$app->addRoute('GET', '/health', fn($app) => $app->response->json(['ok' => true, 'time' => date(DATE_ATOM)]));

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $app->dispatch();
}

return $app;