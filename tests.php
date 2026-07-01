<?php

/*
 * Monad Test Suite
 * ----------------
 * Tests are run exclusively via the Monad CLI:
 *
 *   ./monad test                  # runs this file (tests.php)
 *   ./monad test tests/           # runs all *Test.php / *test.php files in a directory
 *   ./monad test src/UserTest.php # runs a single specific file
 *
 * $app and $test are automatically injected by the CLI before require —
 * no boilerplate needed in test files.
 *
 * Available API:
 *
 *   $test->it(string $desc, callable $fn)         Register a test case.
 *   $test->beforeEach(callable $fn)               Hook run before each test.
 *   $test->afterEach(callable $fn)                Hook run after each test.
 *   $test->expect(mixed $actual)                  Start a fluent assertion chain.
 *     ->toEqual(mixed $expected)                  Strict equality (===).
 *     ->toBeTrue()                                Value is exactly true.
 *     ->toBeFalse()                               Value is exactly false.
 *     ->toContain(mixed $needle)                  String or array contains the value.
 *     ->toBeInstanceOf(string $class)             Value is an instance of the given class.
 *   $test->assertThrows(callable $fn, $class)     Assert that an exception is thrown.
 *   $test->request(string $method, string $uri)   Simulate an in-process HTTP request.
 *
 * Each test runs with a freshly reset container ($app->reset()),
 * ensuring full isolation between test cases.
 */

// Setup: reset DB config before each test
$test->beforeEach(function($app) {
    $app->config->db = ['path' => ':memory:'];
});

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

$test->it("Router: extracts URL parameters", function($app, $test) {
    $app->addRoute('GET', '/users/:id/posts/:postId', function($app) use ($test) {
        $test->expect($app->request->params['id'])->toEqual('42');
        $test->expect($app->request->params['postId'])->toEqual('7');
    });
    $test->request('GET', '/users/42/posts/7');
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

// --- 6. VIEW ---
$test->it("View: auto-escaping works in templates", function($app, $test) {
    file_put_contents(__DIR__ . '/test_view.php', '<?= $view->data->html ?>');
    $out = $app->response->partial('test_view', ['html' => '<b>']);
    unlink(__DIR__ . '/test_view.php');
    $test->expect($out)->toEqual('&lt;b&gt;');
});

$test->it("View: fluent number() formats decimals", function($app, $test) {
    file_put_contents(__DIR__ . '/test_num.php', '<?= $view->data->val->number(2) ?>');
    $out = $app->response->partial('test_num', ['val' => 10]);
    unlink(__DIR__ . '/test_num.php');
    $test->expect($out)->toEqual('10.00');
});

$test->it("View: layouts wrap templates correctly", function($app, $test) {
    file_put_contents(__DIR__ . '/layout.php', '<html><?= $slot ?></html>');
    file_put_contents(__DIR__ . '/page.php', 'content');
    $app->addRoute('GET', '/render-page', function($app) {
        $app->response->layout = 'layout';
        $app->response->render('page');
    });
    $res = $test->request('GET', '/render-page');
    unlink(__DIR__ . '/layout.php');
    unlink(__DIR__ . '/page.php');
    $test->expect($res->body)->toEqual('<html>content</html>');
});

$test->it("View: MagicValue json() encodes and escapes values", function($app, $test) {
    $v = new \Monad\MagicValue(['a' => 1, 'b' => 2]);
    $test->expect($v->json())->toEqual('{&quot;a&quot;:1,&quot;b&quot;:2}');
});

$test->it("View: MagicValue json() throws on non-serializable value", function($app, $test) {
    $test->assertThrows(function() {
        $v = new \Monad\MagicValue(fopen('php://memory', 'r'));
        $v->json();
    }, \RuntimeException::class);
});

$test->it("View: MagicValue date() formats timestamps", function($app, $test) {
    file_put_contents(__DIR__ . '/test_date.php', '<?= $view->data->ts->date("Y") ?>');
    $out = $app->response->partial('test_date', ['ts' => strtotime('2024-03-15')]);
    unlink(__DIR__ . '/test_date.php');
    $test->expect($out)->toEqual('2024');
});

// --- 7. RESPONSE & HTMX ---
$test->it("Response: generates HTMX headers from props", function($app, $test) {
    $app->addRoute('GET', '/htmx-headers', function($app) {
        $app->response->htmx->trigger = 'refresh';
        file_put_contents(__DIR__ . '/dummy.php', '');
        $app->response->render('dummy');
        unlink(__DIR__ . '/dummy.php');
    });
    $res = $test->request('GET', '/htmx-headers');
    $test->expect($res->getHeader('HX-Trigger'))->toEqual('refresh');
});

$test->it("Response: redirect sets Location header and status 302", function($app, $test) {
    $app->addRoute('GET', '/redirect-me', function($app) {
        $app->response->redirect('/target');
    });
    $res = $test->request('GET', '/redirect-me');
    $test->expect($res->statusCode)->toEqual(302);
    $test->expect($res->getHeader('Location'))->toEqual('/target');
});

$test->it("Response: json() sets Content-Type header", function($app, $test) {
    $app->addRoute('GET', '/api-data', function($app) {
        $app->response->json(['status' => 'ok']);
    });
    $res = $test->request('GET', '/api-data');
    $test->expect($res->getHeader('Content-Type'))->toEqual('application/json');
    $test->expect($res->body)->toEqual('{"status":"ok"}');
});

$test->it("Response: layout skipped during HTMX requests", function($app, $test) {
    file_put_contents(__DIR__ . '/layout_htmx.php', '<wrap><?= $slot ?></wrap>');
    file_put_contents(__DIR__ . '/fragment.php', 'partial');
    $app->addRoute('GET', '/htmx-fragment', function($app) {
        $app->response->layout = 'layout_htmx';
        $app->response->render('fragment');
    });
    $res = $test->request('GET', '/htmx-fragment', ['headers' => ['HX-Request' => 'true']]);
    unlink(__DIR__ . '/layout_htmx.php');
    unlink(__DIR__ . '/fragment.php');
    $test->expect($res->body)->toEqual('partial');
});

// --- 8. CLI ---
$test->it("CLI: registers commands", function($app, $test) {
    $app->addCommand('test:cmd', fn() => "done");
    $test->expect(isset($app->props()['commands']['test:cmd']))->toBeTrue();
});

// --- 9. REQUEST ---
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

// --- 10. ERROR HANDLING ---
$test->it("Error Handling: renders JSON for API requests", function($app, $test) {
    $app->config->debug = false;
    $app->addRoute('GET', '/error-route', function($app) {
        throw new \Exception("Test Error");
    });
    $res = $test->request('GET', '/error-route', ['headers' => ['Accept' => 'application/json']]);
    $test->expect($res->statusCode)->toEqual(500);
    $test->expect(str_contains($res->body, '"error":"Internal Server Error"'))->toBeTrue();
});
