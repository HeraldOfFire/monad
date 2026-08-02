<?php

/*
 * Monad Test Suite
 * ----------------
 * Tests are run exclusively via the Monad CLI:
 *
 *   ./monad test                        # runs this file (tests.php)
 *   ./monad test tests/                 # runs all *Test.php / *test.php files in a directory
 *   ./monad test src/UserTest.php       # runs a single specific file
 *   ./monad test tests.php --filter=csrf  # only tests whose description contains "csrf"
 *
 * $app and $test are automatically injected by the CLI before require —
 * no boilerplate needed in test files.
 *
 * Available API:
 *
 *   $test->it(string $desc, callable $fn)         Register a test case.
 *   $test->beforeEach(callable $fn)               Hook run before each test.
 *   $test->afterEach(callable $fn)                Hook run after each test.
 *   $test->beforeAll(callable $fn)                Hook run once, before the first test.
 *   $test->afterAll(callable $fn)                 Hook run once, after the last test.
 *   $test->expect(mixed $actual)                  Start a fluent assertion chain.
 *     ->toEqual(mixed $expected)                  Strict equality (===).
 *     ->toBeTrue()                                Value is exactly true.
 *     ->toBeFalse()                               Value is exactly false.
 *     ->toContain(mixed $needle)                  String or array contains the value.
 *     ->toBeInstanceOf(string $class)             Value is an instance of the given class.
 *   $test->assertThrows(callable $fn, $class)     Assert that an exception is thrown.
 *   $test->request(string $method, string $uri)   Simulate an in-process HTTP request.
 *
 * Lifecycle hooks accumulate: registering a second beforeEach adds to the first
 * rather than replacing it.
 *
 * Each test runs with a freshly reset container ($app->reset()),
 * ensuring full isolation between test cases.
 */

use Monad\MagicObject;
use Monad\MagicValue;
use Monad\RawHtml;
use Monad\ServiceNotFoundException;
use Monad\ViewContext;
use Monad\ViewProxy;

// Setup: reset DB config before each test
$test->beforeEach(function($app) {
    $app->config->db = ['path' => ':memory:'];
    $app->config->cache = ['path' => sys_get_temp_dir() . '/monad-test-cache'];
    $_SESSION = [];
});

/*
 * Helpers
 * -------
 * Templates and migrations live in a throwaway directory for the duration of one test.
 * The try/finally matters: with a bare file_put_contents/unlink pair, a failing
 * assertion aborts before the unlink and leaves stray .php files next to index.php.
 */

$useTemplates = function ($app, array $templates, callable $fn) {
    $dir = sys_get_temp_dir() . '/monad-views-' . bin2hex(random_bytes(5));
    mkdir($dir, 0775, true);
    foreach ($templates as $name => $content) {
        file_put_contents("$dir/$name.php", $content);
    }
    $app->config->views = ['path' => $dir];
    unset($app->response);   // the view root is captured when the service resolves
    try {
        return $fn($dir);
    } finally {
        array_map('unlink', glob("$dir/*") ?: []);
        @rmdir($dir);
    }
};

$useMigrations = function ($app, array $files, callable $fn) {
    $dir = sys_get_temp_dir() . '/monad-mig-' . bin2hex(random_bytes(5));
    mkdir($dir, 0775, true);
    foreach ($files as $name => $content) {
        file_put_contents("$dir/$name.php", $content);
    }
    $app->config->migrations = ['path' => $dir];
    try {
        return $fn($dir);
    } finally {
        array_map('unlink', glob("$dir/*") ?: []);
        @rmdir($dir);
    }
};

$migration = fn(string $up, string $down) => "<?php return ['up' => fn(\$db) => \$db->execute('$up'), 'down' => fn(\$db) => \$db->execute('$down')];";

$makeUsers = function ($app) {
    $app->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password TEXT, name TEXT)');
    $app->db->insert('users', [
        'email' => 'mario@example.com',
        'password' => $app->auth->hash('secret123'),
        'name' => 'Mario',
    ]);
};

$silence = function (callable $fn) {   // swallow CLI command output
    ob_start();
    try { return $fn(); } finally { ob_get_clean(); }
};

// --- 1. DI CONTAINER ---
$test->it("DI Container: binds and resolves a service", function($app, $test) {
    $app->bind('testService', fn() => "hello");
    $test->expect($app->testService)->toEqual("hello");
});

$test->it("DI Container: acts as a Singleton", function($app, $test) {
    $app->bind('rand', fn() => rand(1, 1000000));
    $test->expect($app->rand)->toEqual($app->rand);
});

$test->it("DI Container: handles factory resolution via get()", function($app, $test) {
    $res = $app->get('response');
    $test->expect($res instanceof \Monad\MagicObject)->toBeTrue();
});

$test->it("DI Container: Closure context can access other services via \$this", function($app, $test) {
    $app->bind('serviceA', fn() => "A");
    $app->bind('serviceB', function() { return $this->serviceA . "B"; });
    $test->expect($app->serviceB)->toEqual("AB");
});

$test->it("DI Container: reset() clears all cached services", function($app, $test) {
    $app->bind('counter', function() { static $n = 0; return ++$n; });
    $first = $app->counter;
    $app->reset();
    $second = $app->counter;
    $test->expect($first)->toEqual(1);
    $test->expect($second)->toEqual(2);
});

$test->it("DI Container: an unknown service throws instead of returning null", function($app, $test) {
    $test->assertThrows(fn() => $app->nopeNotAService, ServiceNotFoundException::class);
});

$test->it("DI Container: null coalescing on a missing property still works", function($app, $test) {
    // PHP consults __isset() before __get(), so ?? never reaches the throw
    $test->expect($app->config->definitelyMissing ?? 'fallback')->toEqual('fallback');
});

$test->it("DI Container: share() registers a lazy view global", function($app, $test) {
    $resolved = 0;
    $app->share('greeting', function ($app) use (&$resolved) { $resolved++; return 'ciao'; });
    $app->addRoute('GET', '/g', fn($app) => $app->response->json(['ok' => true]));
    $test->request('GET', '/g');
    $test->expect($resolved)->toEqual(0);   // never touched by a template => never resolved
});

// --- 2. SESSION ---
$test->it("Session: stores and retrieves values", function($app, $test) {
    $app->session->set('user_id', 42);
    $test->expect($app->session->get('user_id'))->toEqual(42);
    $test->expect($app->session->has('user_id'))->toBeTrue();
});

$test->it("Session: destroy clears everything", function($app, $test) {
    $app->session->set('secret', 'data');
    $app->session->destroy();
    $test->expect($app->session->get('secret'))->toEqual(null);
});

// --- 3. CSRF ---
$test->it("CSRF: generates and verifies tokens", function($app, $test) {
    $token = $app->csrf->token('test');
    $test->expect(strlen($token))->toEqual(64);
    $test->expect($app->csrf->verify($token, 'test'))->toBeTrue();
});

$test->it("CSRF: rotate changes the token", function($app, $test) {
    $old = $app->csrf->token('rot');
    $new = $app->csrf->rotate('rot');
    $test->expect($old !== $new)->toBeTrue();
});

$test->it("CSRF: rejects wrong and missing tokens", function($app, $test) {
    $app->csrf->token('form');
    $test->expect($app->csrf->verify('wrong', 'form'))->toBeFalse();
    $test->expect($app->csrf->verify(null, 'form'))->toBeFalse();
});

$test->it("CSRF: the token store is capped", function($app, $test) {
    for ($i = 0; $i < 60; $i++) $app->csrf->token("key$i");
    $test->expect(count($_SESSION['_csrf_tokens']) <= 32)->toBeTrue();
});

$test->it("CSRF: field() emits a hidden input that verify() accepts", function($app, $test) {
    $field = (string) $app->csrf->field();
    $test->expect($field)->toContain('name="_csrf"');
    preg_match('/value="([a-f0-9]{64})"/', $field, $m);
    $test->expect($app->csrf->verify($m[1] ?? null))->toBeTrue();
});

$test->it("CSRF: htmxAttribute() emits valid hx-headers JSON", function($app, $test) {
    $attr = (string) $app->csrf->htmxAttribute();
    $test->expect($attr)->toContain('hx-headers=');
    preg_match('/hx-headers=\'(.*)\'/', $attr, $m);
    $decoded = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);
    $test->expect($app->csrf->verify($decoded['X-CSRF-Token'] ?? null))->toBeTrue();
});

$test->it("CSRF: verifyCsrf middleware rejects an unsafe request without a token", function($app, $test) {
    $app->addRoute('POST', '/save', fn($app) => $app->response->text('saved'), ['verifyCsrf']);
    $res = $test->request('POST', '/save', ['post' => ['data' => 'x']]);
    $test->expect($res->statusCode)->toEqual(403);
});

$test->it("CSRF: verifyCsrf middleware accepts the form field", function($app, $test) {
    $token = $app->csrf->token();
    $app->addRoute('POST', '/save', fn($app) => $app->response->text('saved'), ['verifyCsrf']);
    $res = $test->request('POST', '/save', ['post' => ['_csrf' => $token]]);
    $test->expect($res->statusCode)->toEqual(200);
});

$test->it("CSRF: verifyCsrf middleware accepts the HTMX header", function($app, $test) {
    $token = $app->csrf->token();
    $app->addRoute('POST', '/save', fn($app) => $app->response->text('saved'), ['verifyCsrf']);
    $res = $test->request('POST', '/save', ['headers' => ['X-CSRF-Token' => $token]]);
    $test->expect($res->statusCode)->toEqual(200);
});

// --- 4. ROUTER ---
$test->it("Router: registers simple route", function($app, $test) {
    $app->addRoute('GET', '/unit-test', 'handler');
    $routes = $app->props()['routes']['GET'] ?? [];
    $found = array_filter($routes, fn($r) => $r['path'] === '/unit-test');
    $test->expect(count($found) > 0)->toBeTrue();
});

$test->it("Router: handles groups and middleware", function($app, $test) {
    $app->group('/api/v1', ['mw1'], function($app) {
        $app->addRoute('POST', '/data', 'h');
    });
    $routes = $app->props()['routes']['POST'] ?? [];
    $r = array_values(array_filter($routes, fn($r) => $r['path'] === '/api/v1/data'))[0];
    $test->expect(in_array('mw1', $r['middleware']))->toBeTrue();
});

$test->it("Router: maintains middleware execution order (Onion pattern)", function($app, $test) {
    $trace = "";
    $mw1 = function($app, $next) use (&$trace) { $trace .= "1_in "; $next($app); $trace .= " 1_out"; };
    $mw2 = function($app, $next) use (&$trace) { $trace .= "2_in "; $next($app); $trace .= " 2_out"; };
    $app->addRoute('GET', '/onion-test', function() use (&$trace) { $trace .= "CORE"; }, [$mw1, $mw2]);
    $test->request('GET', '/onion-test');
    $test->expect($trace)->toEqual("1_in 2_in CORE 2_out 1_out");
});

$test->it("Router: resolves middleware registered by name", function($app, $test) {
    $order = [];
    $app->bind('mwA', function ($app, $next) use (&$order) { $order[] = 'A-in'; $next($app); $order[] = 'A-out'; });
    $app->bind('mwB', function ($app, $next) use (&$order) { $order[] = 'B-in'; $next($app); $order[] = 'B-out'; });
    $app->addRoute('GET', '/onion', function ($app) use (&$order) { $order[] = 'handler'; $app->response->text('ok'); }, ['mwA', 'mwB']);
    $test->request('GET', '/onion');
    $test->expect(implode(',', $order))->toEqual('A-in,B-in,handler,B-out,A-out');
});

$test->it("Router: an unbound named middleware reports itself clearly", function($app, $test) {
    // this used to fall through to PHP: "Call to undefined function auth()"
    $app->addRoute('GET', '/guarded', fn($app) => $app->response->text('ok'), ['doesNotExist']);
    $res = $test->request('GET', '/guarded');
    $test->expect($res->statusCode)->toEqual(500);
    $test->expect($res->body)->toContain('is not bound');       // quotes are HTML-escaped
    $test->expect($res->body)->toContain('doesNotExist');
});

$test->it("Router: a service used as middleware is rejected, not silently aborted", function($app, $test) {
    $app->addRoute('GET', '/oops', fn($app) => $app->response->text('ok'), ['db']);
    $res = $test->request('GET', '/oops');
    $test->expect($res->body)->toContain('is bound as a service, not a middleware');
});

$test->it("Router: extracts URL parameters", function($app, $test) {
    $app->addRoute('GET', '/users/:id/posts/:postId', function($app) use ($test) {
        $test->expect($app->request->params['id'])->toEqual('42');
        $test->expect($app->request->params['postId'])->toEqual('7');
    });
    $test->request('GET', '/users/42/posts/7');
});

$test->it("Router: URL parameters are percent-decoded", function($app, $test) {
    $app->addRoute('GET', '/hello/:name', fn($app) => $app->response->json($app->request->params));
    $res = $test->request('GET', '/hello/Mario%20Rossi');
    $test->expect($res->body)->toContain('Mario Rossi');
});

$test->it("Router: an unknown path returns a 404 with a body", function($app, $test) {
    $res = $test->request('GET', '/no/such/path');
    $test->expect($res->statusCode)->toEqual(404);
    $test->expect($res->body)->toContain('404');
});

$test->it("Router: content negotiation copes with a realistic Accept header", function($app, $test) {
    // no real client sends exactly "application/json"
    $res = $test->request('GET', '/no/such/path', ['headers' => ['Accept' => 'application/json, text/plain, */*']]);
    $test->expect($res->statusCode)->toEqual(404);
    $test->expect($res->getHeader('content-type'))->toEqual('application/json; charset=utf-8');
    $test->expect($res->body)->toContain('Not Found');
});

$test->it("Router: a known path with the wrong verb returns 405 and Allow", function($app, $test) {
    $app->addRoute('POST', '/only-post', fn($app) => $app->response->text('ok'));
    $res = $test->request('GET', '/only-post');
    $test->expect($res->statusCode)->toEqual(405);
    $test->expect($res->getHeader('Allow'))->toEqual('POST');
});

// --- 5. DATABASE ---
$test->it("DB: can perform full CRUD on memory DB", function($app, $test) {
    $app->db->pdo->exec("CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)");
    $id = $app->db->insert('items', ['name' => 'Monad']);
    $test->expect($id)->toEqual(1);
    $row = $app->db->fetchOne("SELECT * FROM items WHERE id = ?", [$id]);
    $test->expect($row['name'])->toEqual('Monad');
    $app->db->update('items', ['name' => 'Monad Framework'], ['id' => $id]);
    $row = $app->db->fetchOne("SELECT * FROM items WHERE id = ?", [$id]);
    $test->expect($row['name'])->toEqual('Monad Framework');
    $app->db->delete('items', ['id' => $id]);
    $row = $app->db->fetchOne("SELECT * FROM items WHERE id = ?", [$id]);
    $test->expect($row)->toEqual(null);
});

$test->it("DB: protects against SQL Injection in column names", function($app, $test) {
    $test->assertThrows(function() use ($app) {
        $app->db->insert('users', ['id; DROP TABLE users' => 1]);
    }, \InvalidArgumentException::class);
});

$test->it("DB: protects against invalid table names in insert", function($app, $test) {
    $test->assertThrows(function() use ($app) {
        $app->db->insert('users; DROP TABLE users', ['name' => 'test']);
    }, \InvalidArgumentException::class);
});

$test->it("DB: protects against invalid table names in update", function($app, $test) {
    $test->assertThrows(function() use ($app) {
        $app->db->update('users; DROP', ['name' => 'test'], ['id' => 1]);
    }, \InvalidArgumentException::class);
});

$test->it("DB: protects against invalid table names in delete", function($app, $test) {
    $test->assertThrows(function() use ($app) {
        $app->db->delete('users; DROP', ['id' => 1]);
    }, \InvalidArgumentException::class);
});

$test->it("DB: transaction() commits on success", function($app, $test) use ($makeUsers) {
    $makeUsers($app);
    $app->db->transaction(fn($db) => $db->insert('users', ['email' => 'a@b.c', 'password' => 'x', 'name' => 'A']));
    $test->expect(count($app->db->fetchAll('SELECT id FROM users')))->toEqual(2);
});

$test->it("DB: transaction() rolls back and rethrows on failure", function($app, $test) use ($makeUsers) {
    $makeUsers($app);
    $test->assertThrows(function () use ($app) {
        $app->db->transaction(function ($db) {
            $db->insert('users', ['email' => 'a@b.c', 'password' => 'x', 'name' => 'A']);
            throw new \RuntimeException('abort');
        });
    }, \RuntimeException::class);
    $test->expect(count($app->db->fetchAll('SELECT id FROM users')))->toEqual(1);
});

$test->it("DB: a nested transaction() joins the outer one", function($app, $test) use ($makeUsers) {
    $makeUsers($app);
    $app->db->transaction(function ($db) {
        $db->transaction(fn($d) => $d->insert('users', ['email' => 'n@b.c', 'password' => 'x', 'name' => 'N']));
    });
    $test->expect(count($app->db->fetchAll('SELECT id FROM users')))->toEqual(2);
});

// --- 6. MIGRATIONS ---
$test->it("Migrations: files are ordered naturally, not lexically", function($app, $test) use ($useMigrations, $migration) {
    $useMigrations($app, [
        '002_posts' => $migration('CREATE TABLE posts (id INTEGER PRIMARY KEY)', 'DROP TABLE posts'),
        '010_tags'  => $migration('CREATE TABLE tags (id INTEGER PRIMARY KEY)', 'DROP TABLE tags'),
        '001_users' => $migration('CREATE TABLE mig_users (id INTEGER PRIMARY KEY)', 'DROP TABLE mig_users'),
    ], function () use ($app, $test) {
        // 010 sorts after 002, not between 001 and 002
        $test->expect(implode(',', array_keys($app->migrator->files())))->toEqual('001_users,002_posts,010_tags');
    });
});

$test->it("Migrations: migrate applies everything pending as one batch", function($app, $test) use ($useMigrations, $migration, $silence) {
    $useMigrations($app, [
        '001_users' => $migration('CREATE TABLE mig_users (id INTEGER PRIMARY KEY)', 'DROP TABLE mig_users'),
        '002_posts' => $migration('CREATE TABLE posts (id INTEGER PRIMARY KEY)', 'DROP TABLE posts'),
    ], function () use ($app, $test, $silence) {
        $test->expect(count($app->migrator->pending()))->toEqual(2);
        $silence(fn() => $app->props()['commands']['migrate']($app));
        $test->expect(count($app->migrator->pending()))->toEqual(0);
        $test->expect($app->migrator->lastBatch())->toEqual(1);
        $tables = array_column($app->db->fetchAll("SELECT name FROM sqlite_master WHERE type='table'"), 'name');
        $test->expect($tables)->toContain('mig_users');
        $test->expect($tables)->toContain('posts');
    });
});

$test->it("Migrations: rollback reverses the last batch only", function($app, $test) use ($useMigrations, $migration, $silence) {
    $useMigrations($app, [
        '001_a' => $migration('CREATE TABLE ta (id INTEGER PRIMARY KEY)', 'DROP TABLE ta'),
    ], function ($dir) use ($app, $test, $silence, $migration) {
        $commands = $app->props()['commands'];
        $silence(fn() => $commands['migrate']($app));
        // a second batch
        file_put_contents("$dir/002_b.php", $migration('CREATE TABLE tb (id INTEGER PRIMARY KEY)', 'DROP TABLE tb'));
        $silence(fn() => $commands['migrate']($app));
        $test->expect($app->migrator->lastBatch())->toEqual(2);

        $silence(fn() => $commands['migrate:rollback']($app));
        $tables = array_column($app->db->fetchAll("SELECT name FROM sqlite_master WHERE type='table'"), 'name');
        $test->expect($tables)->toContain('ta');                    // batch 1 survives
        $test->expect(in_array('tb', $tables))->toBeFalse();
        $test->expect($app->migrator->lastBatch())->toEqual(1);
    });
});

$test->it("Migrations: a malformed file is reported, not silently skipped", function($app, $test) use ($useMigrations) {
    $useMigrations($app, ['001_bad' => '<?php return "not an array";'], function ($dir) use ($app, $test) {
        $test->assertThrows(fn() => $app->migrator->runFile("$dir/001_bad.php", 'up'), \RuntimeException::class);
    });
});

$test->it("Migrations: status reports pending and applied files", function($app, $test) use ($useMigrations, $migration, $silence) {
    $useMigrations($app, [
        '001_a' => $migration('CREATE TABLE sa (id INTEGER PRIMARY KEY)', 'DROP TABLE sa'),
    ], function () use ($app, $test, $silence) {
        ob_start(); $app->props()['commands']['migrate:status']($app); $before = ob_get_clean();
        $test->expect($before)->toContain('pending');
        $silence(fn() => $app->props()['commands']['migrate']($app));
        ob_start(); $app->props()['commands']['migrate:status']($app); $after = ob_get_clean();
        $test->expect($after)->toContain('ran');
    });
});

// --- 7. VIEW ---
$test->it("View: auto-escaping works in templates", function($app, $test) use ($useTemplates) {
    $useTemplates($app, ['test_view' => '<?= $view->data->html ?>'], function () use ($app, $test) {
        $test->expect($app->response->partial('test_view', ['html' => '<b>']))->toEqual('&lt;b&gt;');
    });
});

$test->it("View: fluent number() formats decimals", function($app, $test) use ($useTemplates) {
    $useTemplates($app, ['test_num' => '<?= $view->data->val->number(2) ?>'], function () use ($app, $test) {
        $test->expect($app->response->partial('test_num', ['val' => 10]))->toEqual('10.00');
    });
});

$test->it("View: layouts wrap templates correctly", function($app, $test) use ($useTemplates) {
    $useTemplates($app, ['layout' => '<html><?= $slot ?></html>', 'page' => 'content'], function () use ($app, $test) {
        $app->addRoute('GET', '/render-page', function($app) {
            $app->response->layout = 'layout';
            $app->response->render('page');
        });
        $test->expect($test->request('GET', '/render-page')->body)->toEqual('<html>content</html>');
    });
});

$test->it("View: MagicValue json() encodes and escapes values", function($app, $test) {
    $v = new MagicValue(['a' => 1, 'b' => 2]);
    $test->expect($v->json())->toEqual('{&quot;a&quot;:1,&quot;b&quot;:2}');
});

$test->it("View: MagicValue json() throws on non-serializable value", function($app, $test) {
    $test->assertThrows(function() {
        $v = new MagicValue(fopen('php://memory', 'r'));
        $v->json();
    }, \RuntimeException::class);
});

$test->it("View: MagicValue date() formats timestamps", function($app, $test) use ($useTemplates) {
    $useTemplates($app, ['test_date' => '<?= $view->data->ts->date("Y") ?>'], function () use ($app, $test) {
        $test->expect($app->response->partial('test_date', ['ts' => strtotime('2024-03-15')]))->toEqual('2024');
    });
});

$test->it("View: MagicValue date() returns an empty string on unparseable input", function($app, $test) {
    // date($f, false) used to silently render the Unix epoch
    $test->expect((new MagicValue('not a date'))->date())->toEqual('');
});

$test->it("View: an escaped value encodes to its raw form in JSON", function($app, $test) {
    $test->expect(json_encode(['t' => new MagicValue('abc')]))->toEqual('{"t":"abc"}');
});

$test->it("View: values read through service objects are escaped too", function($app, $test) {
    // $view->session->get('x') used to be handed over completely unescaped
    $service = new MagicObject(['get' => fn($_, $k) => '<script>bad</script>']);
    $view = new ViewContext(['session' => $service]);
    $test->expect((string) $view->session->get('username'))->toEqual('&lt;script&gt;bad&lt;/script&gt;');
});

$test->it("View: booleans from service objects stay real booleans", function($app, $test) {
    $service = new MagicObject(['is' => false, 'has' => fn($_, $k) => false]);
    $view = new ViewContext(['htmx' => $service]);
    $test->expect($view->htmx->is)->toBeFalse();          // a MagicValue would be truthy
    $test->expect($view->htmx->has('anything'))->toBeFalse();
});

$test->it("View: numbers and booleans from service methods stay usable", function($app, $test) {
    $service = new MagicObject(['n' => fn() => 41, 'ok' => fn() => true]);
    $view = new ViewProxy($service);
    $test->expect($view->n() + 1)->toEqual(42);           // a MagicValue would be a TypeError
    $test->expect($view->ok())->toBeTrue();
});

$test->it("View: strings from service methods are escaped", function($app, $test) {
    $service = new MagicObject(['name' => fn() => '<script>x</script>']);
    $view = new ViewProxy($service);
    $test->expect((string) $view->name())->toEqual('&lt;script&gt;x&lt;/script&gt;');
});

$test->it("View: RawHtml is emitted verbatim", function($app, $test) {
    $view = new ViewContext(['frag' => new RawHtml('<b>markup</b>')]);
    $test->expect((string) $view->frag)->toEqual('<b>markup</b>');
});

$test->it("View: missing keys stay lenient (null, not an exception)", function($app, $test) {
    $view = new ViewContext(['data' => []]);
    $test->expect($view->data->nothingHere)->toEqual(null);
});

$test->it("View: partials compose without double-escaping", function($app, $test) use ($useTemplates) {
    $useTemplates($app, [
        'outer' => '<div><?= $view->partial("inner", ["msg" => $view->data->msg->raw()]) ?></div>',
        'inner' => '<span><?= $view->data->msg ?></span>',
    ], function () use ($app, $test) {
        $out = $app->response->partial('outer', ['msg' => '<b>hi</b>']);
        $test->expect($out)->toEqual('<div><span>&lt;b&gt;hi&lt;/b&gt;</span></div>');
    });
});

$test->it("View: a template name cannot escape the view root", function($app, $test) use ($useTemplates) {
    $useTemplates($app, ['ok' => 'fine'], function () use ($app, $test) {
        $app->addRoute('GET', '/traverse', fn($app) => $app->response->render('../../../../etc/passwd'));
        $test->expect($test->request('GET', '/traverse')->statusCode)->toEqual(500);
    });
});

$test->it("View: a missing template reports itself clearly", function($app, $test) use ($useTemplates) {
    $useTemplates($app, ['ok' => 'fine'], function () use ($app, $test) {
        $app->addRoute('GET', '/missing-tpl', fn($app) => $app->response->render('no.such.template'));
        $test->expect($test->request('GET', '/missing-tpl')->body)->toContain('Template not found');
    });
});

$test->it("View: shared globals are reachable and escaped in templates", function($app, $test) use ($useTemplates) {
    $useTemplates($app, ['page' => '<p><?= $view->greeting ?></p><p><?= $view->data->x ?></p>'], function () use ($app, $test) {
        $app->share('greeting', fn($app) => '<b>ciao</b>');
        $app->addRoute('GET', '/p', fn($app) => $app->response->render('page', ['x' => '<i>hi</i>']));
        $body = $test->request('GET', '/p')->body;
        $test->expect($body)->toContain('&lt;b&gt;ciao&lt;/b&gt;');
        $test->expect($body)->toContain('&lt;i&gt;hi&lt;/i&gt;');
    });
});

/*
 * These two cover bugs that only a real render exposed: wrapping a service in a copy of
 * its props rebound its closures to the wrapper, so the service read its *own* state
 * through the escaping layer. ViewProxy delegates instead of copying.
 */
$test->it("View: a service method reading its own state works through the view layer", function($app, $test) use ($useTemplates, $makeUsers) {
    $makeUsers($app);
    $app->auth->attempt('mario@example.com', 'secret123');
    $useTemplates($app, ['who' => '<h1><?= $view->auth->user()["name"] ?></h1><p><?= $view->auth->check() ? "in" : "out" ?></p>'], function () use ($app, $test) {
        $app->addRoute('GET', '/who', fn($app) => $app->response->render('who'));
        $body = $test->request('GET', '/who')->body;
        $test->expect($body)->toContain('<h1>Mario</h1>');
        $test->expect($body)->toContain('<p>in</p>');
    });
});

$test->it("View: a helper calling a sibling method is not corrupted by escaping", function($app, $test) use ($useTemplates) {
    $useTemplates($app, ['head' => '<body <?= $view->csrf->htmxAttribute() ?>>'], function () use ($app, $test) {
        $app->addRoute('GET', '/head', fn($app) => $app->response->render('head'));
        preg_match('/hx-headers=\'(.*)\'>/', $test->request('GET', '/head')->body, $m);
        $decoded = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);
        // the token used to serialise as {} because it arrived wrapped in a MagicValue
        $test->expect(is_string($decoded['X-CSRF-Token'] ?? null))->toBeTrue();
        $test->expect($app->csrf->verify($decoded['X-CSRF-Token']))->toBeTrue();
    });
});

// --- 8. RESPONSE & HTMX ---
$test->it("Response: generates HTMX headers from props", function($app, $test) use ($useTemplates) {
    $useTemplates($app, ['dummy' => ''], function () use ($app, $test) {
        $app->addRoute('GET', '/htmx-headers', function($app) {
            $app->response->htmx->trigger = 'refresh';
            $app->response->render('dummy');
        });
        $test->expect($test->request('GET', '/htmx-headers')->getHeader('HX-Trigger'))->toEqual('refresh');
    });
});

$test->it("Response: redirect sets Location header and status 302", function($app, $test) {
    $app->addRoute('GET', '/redirect-me', function($app) {
        $app->response->redirect('/target');
    });
    $res = $test->request('GET', '/redirect-me');
    $test->expect($res->statusCode)->toEqual(302);
    $test->expect($res->getHeader('Location'))->toEqual('/target');
});

$test->it("Response: output after a redirect cannot corrupt the response", function($app, $test) {
    $app->addRoute('GET', '/leaky', function ($app) {
        $app->response->redirect('/target');
        $app->response->json(['should' => 'not appear']);   // missing return
    });
    $res = $test->request('GET', '/leaky');
    $test->expect($res->statusCode)->toEqual(302);
    $test->expect($res->body)->toEqual('');
});

$test->it("Response: json() sets Content-Type header", function($app, $test) {
    $app->addRoute('GET', '/api-data', function($app) {
        $app->response->json(['status' => 'ok']);
    });
    $res = $test->request('GET', '/api-data');
    $test->expect($res->getHeader('Content-Type'))->toEqual('application/json; charset=utf-8');
    $test->expect($res->body)->toEqual('{"status":"ok"}');
});

$test->it("Response: the built-in /health route answers", function($app, $test) {
    $res = $test->request('GET', '/health');
    $test->expect($res->statusCode)->toEqual(200);
    $test->expect($res->body)->toContain('"ok":true');
});

$test->it("Response: layout skipped during HTMX requests", function($app, $test) use ($useTemplates) {
    $useTemplates($app, ['layout_htmx' => '<wrap><?= $slot ?></wrap>', 'fragment' => 'partial'], function () use ($app, $test) {
        $app->addRoute('GET', '/htmx-fragment', function($app) {
            $app->response->layout = 'layout_htmx';
            $app->response->render('fragment');
        });
        $res = $test->request('GET', '/htmx-fragment', ['headers' => ['HX-Request' => 'true']]);
        $test->expect($res->body)->toEqual('partial');
    });
});

$test->it("Response: out-of-band fragments are appended after the main body", function($app, $test) use ($useTemplates) {
    $useTemplates($app, [
        'main'  => '<div id="main">MAIN</div>',
        'badge' => '<span id="badge" hx-swap-oob="true"><?= $view->data->count ?></span>',
    ], function () use ($app, $test) {
        $app->addRoute('GET', '/oob', function ($app) {
            $app->response->oob('badge', ['count' => 7]);
            $app->response->render('main');
        });
        $body = $test->request('GET', '/oob', ['headers' => ['HX-Request' => 'true']])->body;
        $test->expect($body)->toContain('MAIN');
        $test->expect($body)->toContain('hx-swap-oob');
        $test->expect(strpos($body, 'MAIN') < strpos($body, 'badge'))->toBeTrue();
    });
});

$test->it("Response: htmxRedirect sends a 200 with an empty body", function($app, $test) {
    $app->addRoute('POST', '/done', fn($app) => $app->response->htmxRedirect('/thanks'));
    $res = $test->request('POST', '/done');
    $test->expect($res->statusCode)->toEqual(200);          // HTMX only acts on 2xx
    $test->expect($res->getHeader('HX-Redirect'))->toEqual('/thanks');
    $test->expect($res->body)->toEqual('');
});

$test->it("Response: stream() emits well-formed server-sent events", function($app, $test) {
    $app->addRoute('GET', '/events', function ($app) {
        $app->response->stream(function ($emit) {
            $emit('first');
            $emit('two' . "\n" . 'lines', 'update', '42');
        });
    });
    $res = $test->request('GET', '/events');
    $test->expect($res->getHeader('Content-Type'))->toEqual('text/event-stream');
    $test->expect($res->body)->toContain("data: first\n\n");
    $test->expect($res->body)->toContain("event: update\n");
    $test->expect($res->body)->toContain("id: 42\n");
    $test->expect($res->body)->toContain("data: two\ndata: lines\n");   // multi-line framing
});

// --- 9. AUTHENTICATION ---
$test->it("Auth: attempt() succeeds with the right password and fails otherwise", function($app, $test) use ($makeUsers) {
    $makeUsers($app);
    $test->expect($app->auth->attempt('mario@example.com', 'wrong'))->toBeFalse();
    $test->expect($app->auth->check())->toBeFalse();
    $test->expect($app->auth->attempt('mario@example.com', 'secret123'))->toBeTrue();
    $test->expect($app->auth->check())->toBeTrue();
    $test->expect($app->auth->user()['name'])->toEqual('Mario');
});

$test->it("Auth: an unknown identifier does not blow up", function($app, $test) use ($makeUsers) {
    $makeUsers($app);
    $test->expect($app->auth->attempt('nobody@example.com', 'secret123'))->toBeFalse();
});

$test->it("Auth: the password hash is never exposed through user()", function($app, $test) use ($makeUsers) {
    $makeUsers($app);
    $app->auth->attempt('mario@example.com', 'secret123');
    $test->expect(array_key_exists('password', $app->auth->user()))->toBeFalse();
});

$test->it("Auth: logout() clears the identity", function($app, $test) use ($makeUsers) {
    $makeUsers($app);
    $app->auth->attempt('mario@example.com', 'secret123');
    $app->auth->logout();
    $test->expect($app->auth->check())->toBeFalse();
    $test->expect($app->auth->user())->toEqual(null);
});

$test->it("Auth: an invalid table name is rejected at configuration time", function($app, $test) {
    $app->config->auth = ['table' => 'users; DROP TABLE users'];
    $test->assertThrows(fn() => $app->auth, \InvalidArgumentException::class);
});

$test->it("Auth: requireAuth redirects an anonymous browser request", function($app, $test) use ($makeUsers) {
    $makeUsers($app);
    $app->addRoute('GET', '/dashboard', fn($app) => $app->response->text('secret'), ['requireAuth']);
    $res = $test->request('GET', '/dashboard');
    $test->expect($res->statusCode)->toEqual(302);
    $test->expect($res->getHeader('Location'))->toEqual('/login');
});

$test->it("Auth: requireAuth answers 401 for JSON clients", function($app, $test) use ($makeUsers) {
    $makeUsers($app);
    $app->addRoute('GET', '/api/me', fn($app) => $app->response->json(['ok' => true]), ['requireAuth']);
    $res = $test->request('GET', '/api/me', ['headers' => ['Accept' => 'application/json']]);
    $test->expect($res->statusCode)->toEqual(401);
});

$test->it("Auth: requireAuth uses HX-Redirect for HTMX requests", function($app, $test) use ($makeUsers) {
    $makeUsers($app);
    $app->addRoute('GET', '/frag', fn($app) => $app->response->text('secret'), ['requireAuth']);
    $res = $test->request('GET', '/frag', ['headers' => ['HX-Request' => 'true']]);
    $test->expect($res->statusCode)->toEqual(200);
    $test->expect($res->getHeader('HX-Redirect'))->toEqual('/login');
    $test->expect($res->body)->toEqual('');
});

$test->it("Auth: requireAuth lets an authenticated request through", function($app, $test) use ($makeUsers) {
    $makeUsers($app);
    $app->auth->attempt('mario@example.com', 'secret123');
    $app->addRoute('GET', '/dashboard', fn($app) => $app->response->text('secret'), ['requireAuth']);
    $res = $test->request('GET', '/dashboard');
    $test->expect($res->statusCode)->toEqual(200);
    $test->expect($res->body)->toContain('secret');
});

// --- 10. FLASH ---
$test->it("Flash: a value survives one read and then vanishes", function($app, $test) {
    $app->flash->set('notice', 'Saved!');
    $test->expect($app->flash->has('notice'))->toBeTrue();
    $test->expect($app->flash->peek('notice'))->toEqual('Saved!');   // peek does not consume
    $test->expect($app->flash->get('notice'))->toEqual('Saved!');
    $test->expect($app->flash->has('notice'))->toBeFalse();
    $test->expect($app->flash->get('notice', 'default'))->toEqual('default');
});

$test->it("Flash: all() drains everything", function($app, $test) {
    $app->flash->set('a', 1);
    $app->flash->set('b', 2);
    $test->expect($app->flash->all())->toEqual(['a' => 1, 'b' => 2]);
    $test->expect($app->flash->all())->toEqual([]);
});

// --- 11. CACHE ---
$test->it("Cache: round-trips values, including null and false", function($app, $test) {
    $app->cache->flush();
    $app->cache->set('k', ['a' => 1]);
    $test->expect($app->cache->get('k'))->toEqual(['a' => 1]);
    $app->cache->set('nullish', null);
    $test->expect($app->cache->has('nullish'))->toBeTrue();          // a cached null is still a hit
    $test->expect($app->cache->get('nullish', 'fallback'))->toEqual(null);
    $app->cache->set('falsy', false);
    $test->expect($app->cache->get('falsy'))->toBeFalse();
});

$test->it("Cache: entries expire", function($app, $test) {
    $app->cache->set('short', 'value', 60);
    $test->expect($app->cache->get('short'))->toEqual('value');
    // rewrite with an expiry in the past rather than sleeping
    file_put_contents($app->cache->path('short'), serialize(['expires' => time() - 10, 'value' => 'value']));
    $test->expect($app->cache->get('short', 'gone'))->toEqual('gone');
    $test->expect($app->cache->has('short'))->toBeFalse();
});

$test->it("Cache: remember() computes once then serves from cache", function($app, $test) {
    $app->cache->flush();
    $calls = 0;
    $producer = function () use (&$calls) { $calls++; return 'computed'; };
    $test->expect($app->cache->remember('memo', 60, $producer))->toEqual('computed');
    $test->expect($app->cache->remember('memo', 60, $producer))->toEqual('computed');
    $test->expect($calls)->toEqual(1);
});

$test->it("Cache: forget() and flush() remove entries", function($app, $test) {
    $app->cache->set('x', 1);
    $app->cache->forget('x');
    $test->expect($app->cache->has('x'))->toBeFalse();
    $app->cache->set('y', 1);
    $app->cache->flush();
    $test->expect($app->cache->has('y'))->toBeFalse();
});

$test->it("Cache: a corrupt file is treated as a miss, not an error", function($app, $test) {
    $app->cache->set('bad', 'value');
    file_put_contents($app->cache->path('bad'), 'this is not serialized data');
    $test->expect($app->cache->get('bad', 'fallback'))->toEqual('fallback');
});

// --- 12. CLI ---
$test->it("CLI: registers commands", function($app, $test) {
    $app->addCommand('test:cmd', fn() => "done");
    $test->expect(isset($app->props()['commands']['test:cmd']))->toBeTrue();
});

$test->it("CLI: routes command lists registered routes", function($app, $test) {
    $app->addRoute('GET', '/listed', 'handler');
    ob_start(); $app->props()['commands']['routes']($app); $out = ob_get_clean();
    $test->expect($out)->toContain('/listed');
});

// --- 13. REQUEST ---
$test->it("Request: parses path correctly", function($app, $test) {
    $app->addRoute('GET', '/some/path', function($app) use ($test) {
        $test->expect($app->request->path)->toEqual('/some/path');
    });
    $test->request('GET', '/some/path?query=1');
});

$test->it("Request: detects HTMX headers", function($app, $test) {
    $app->addRoute('GET', '/htmx-route', function($app) use ($test) {
        $test->expect($app->request->htmx->is)->toBeTrue();
    });
    $test->request('GET', '/htmx-route', ['headers' => ['HX-Request' => 'true']]);
});

$test->it("Request: getQueryVar retrieves query string parameters", function($app, $test) {
    $app->addRoute('GET', '/q', function($app) use ($test) {
        $test->expect($app->request->getQueryVar('page'))->toEqual('2');
        $test->expect($app->request->getQueryVar('missing', 'fallback'))->toEqual('fallback');
    });
    $test->request('GET', '/q?page=2');
});

$test->it("Request: getPostVar retrieves POST body parameters", function($app, $test) {
    $app->addRoute('POST', '/submit', function($app) use ($test) {
        $test->expect($app->request->getPostVar('name'))->toEqual('Mario');
    });
    $test->request('POST', '/submit', ['post' => ['name' => 'Mario']]);
});

// --- 14. ERROR HANDLING ---
$test->it("Error Handling: renders JSON for API requests", function($app, $test) {
    $app->config->debug = false;
    $app->addRoute('GET', '/error-route', function($app) {
        throw new \Exception("Test Error");
    });
    $res = $test->request('GET', '/error-route', ['headers' => ['Accept' => 'application/json']]);
    $test->expect($res->statusCode)->toEqual(500);
    $test->expect(str_contains($res->body, '"error":"Internal Server Error"'))->toBeTrue();
});

$test->it("Error Handling: production HTML errors are not a blank page", function($app, $test) {
    $app->config->debug = false;
    $app->addRoute('GET', '/error-html', fn($app) => throw new \Exception("Test Error"));
    $res = $test->request('GET', '/error-html');
    $test->expect($res->statusCode)->toEqual(500);
    $test->expect($res->body)->toContain('500');
    $test->expect($res->body)->toContain('Something went wrong');
    $test->expect(str_contains($res->body, 'Test Error'))->toBeFalse();   // no leaking internals
});

$test->it("Error Handling: debug mode shows the exception", function($app, $test) {
    $app->addRoute('GET', '/boom', fn($app) => throw new \RuntimeException('kaboom'));
    $res = $test->request('GET', '/boom', ['headers' => ['Accept' => 'application/json']]);
    $test->expect($res->statusCode)->toEqual(500);
    $test->expect($res->body)->toContain('kaboom');
});

$test->it("Error Handling: an error after output starts keeps the original exception", function($app, $test) {
    // setStatusCode() would warn about sent headers, that warning became an exception
    // inside the exception handler, and the real error was lost
    $app->addRoute('GET', '/half', function ($app) {
        echo "partial output";
        throw new \RuntimeException('late failure');
    });
    $test->expect($test->request('GET', '/half')->body)->toContain('late failure');
});

$test->it("Error Handling: deprecations are not promoted to exceptions", function($app, $test) {
    $log = tempnam(sys_get_temp_dir(), 'monaddep');
    $previous = ini_get('error_log');
    ini_set('error_log', $log);
    try {
        trigger_error('an old habit', E_USER_DEPRECATED);   // must not throw
        $test->expect(str_contains(file_get_contents($log), 'an old habit'))->toBeTrue();
    } finally {
        ini_set('error_log', $previous ?: '');
        unlink($log);
    }
});

// --- 15. LOGGING & DEBUG HELPERS ---
$test->it("Logger: logger() actually writes a log line", function($app, $test) {
    $log = tempnam(sys_get_temp_dir(), 'monadlog');
    $previous = ini_get('error_log');
    ini_set('error_log', $log);
    try {
        $app->logger('hello world');
        $test->expect(str_contains(file_get_contents($log), 'hello world'))->toBeTrue();
    } finally {
        ini_set('error_log', $previous ?: '');
        unlink($log);
    }
});

$test->it("Logger: logger() survives being read as a property first", function($app, $test) {
    $fn = $app->logger;   // this used to poison the next call
    $test->expect(is_callable($fn))->toBeTrue();
    $log = tempnam(sys_get_temp_dir(), 'monadlog');
    $previous = ini_get('error_log');
    ini_set('error_log', $log);
    try {
        $app->logger('second call');
        $test->expect(str_contains(file_get_contents($log), 'second call'))->toBeTrue();
    } finally {
        ini_set('error_log', $previous ?: '');
        unlink($log);
    }
});

$test->it("Logger: logger is overridable via bind()", function($app, $test) {
    $seen = null;
    $app->bind('logger', function ($app, string $msg) use (&$seen) { $seen = $msg; });
    $app->logger('custom sink');
    $test->expect($seen)->toEqual('custom sink');
});

$test->it("Debug: dumpHtml() renders inspectable, escaped HTML", function($app, $test) {
    $html = $app->dumpHtml(['<script>' => 1]);
    $test->expect($html)->toContain('&lt;script&gt;');
    $test->expect($html)->toContain('<pre');
});

$test->it("Debug: \$view->dump() is emitted as trusted markup", function($app, $test) use ($useTemplates) {
    $useTemplates($app, ['d' => '<?= $view->dump($view->data->x->raw()) ?>'], function () use ($app, $test) {
        $app->addRoute('GET', '/d', fn($app) => $app->response->render('d', ['x' => 'inspect-me']));
        $body = $test->request('GET', '/d')->body;
        $test->expect($body)->toContain('<pre');
        $test->expect($body)->toContain('inspect-me');
    });
});

// --- 16. THE TEST RUNNER ITSELF ---
$test->it("Runner: lifecycle hooks accumulate instead of overwriting", function($app, $test) {
    $suite = new \Monad\TestSuite($app);
    $calls = [];
    $suite->beforeEach(function () use (&$calls) { $calls[] = 'first'; });
    $suite->beforeEach(function () use (&$calls) { $calls[] = 'second'; });
    $suite->beforeAll(function () use (&$calls) { $calls[] = 'all'; });
    $suite->it('inner', fn() => null);
    ob_start(); $suite->run(); ob_get_clean();
    $test->expect(implode(',', $calls))->toEqual('all,first,second');
});

$test->it("Runner: --filter selects tests by description", function($app, $test) {
    $suite = new \Monad\TestSuite($app);
    $ran = [];
    $suite->it('alpha case', function () use (&$ran) { $ran[] = 'alpha'; });
    $suite->it('beta case', function () use (&$ran) { $ran[] = 'beta'; });
    ob_start(); $suite->run('beta'); $out = ob_get_clean();
    $test->expect($ran)->toEqual(['beta']);
    $test->expect($out)->toContain('Skipped: 1');
});
