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
