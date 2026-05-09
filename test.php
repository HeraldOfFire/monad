<?php

ob_start();
$app = require __DIR__ . '/index.php';
$app->config->db = ['path' => ':memory:'];

$passed = 0;
$failed = 0;

function it(string $description, callable $test) {
    global $passed, $failed;
    try {
        $test();
        echo "\033[32m✔\033[0m $description\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "\033[31m✖ $description\033[0m\n";
        echo "  ↳ \033[33m" . $e->getMessage() . "\033[0m\n";
        if (!empty($e->getFile())) {
            echo "    " . basename($e->getFile()) . ":" . $e->getLine() . "\n";
        }
        $failed++;
    }
}

function expectEqual($actual, $expected) {
    if ($actual !== $expected) {
        throw new \Exception("Expected " . var_export($expected, true) . " but got " . var_export($actual, true));
    }
}

function expectException(callable $fn, string $expectedClass = \Exception::class) {
    try {
        $fn();
        throw new \Exception("Expected exception $expectedClass was not thrown");
    } catch (\Throwable $e) {
        if (!($e instanceof $expectedClass)) {
            throw new \Exception("Expected exception $expectedClass but got " . get_class($e));
        }
    }
}

echo str_repeat("-", 64) . "\n";
echo "Monad Test Suite\n";
echo str_repeat("-", 64) . "\n";

// --- 1. DI CONTAINER TESTS ---
it("DI Container: binds and resolves a service", function() use ($app) {
    $app->bind('testService', fn() => "hello");
    expectEqual($app->testService, "hello");
});

it("DI Container: acts as a Singleton", function() use ($app) {
    $app->bind('rand', fn() => rand(1, 1000000));
    expectEqual($app->rand, $app->rand);
});

it("DI Container: handles factory resolution via get()", function() use ($app) {
    $res = $app->get('response');
    expectEqual($res instanceof \Monad\MagicObject, true);
});

it("DI Container: Closure context can access other services via \$this", function() use ($app) {
    $app->bind('serviceA', fn() => "A");
    $app->bind('serviceB', function() { return $this->serviceA . "B"; });
    expectEqual($app->serviceB, "AB");
});

// --- 2. SESSION TESTS ---
it("Session: stores and retrieves values", function() use ($app) {
    $app->session->set('user_id', 42);
    expectEqual($app->session->get('user_id'), 42);
    expectEqual($app->session->has('user_id'), true);
});

it("Session: destroy clears everything", function() use ($app) {
    $app->session->set('secret', 'data');
    $app->session->destroy();
    expectEqual($app->session->get('secret'), null);
});

// --- 3. CSRF TESTS ---
it("CSRF: generates and verifies tokens", function() use ($app) {
    $token = $app->csrf->token('test');
    expectEqual(strlen($token), 64);
    expectEqual($app->csrf->verify($token, 'test'), true);
});

it("CSRF: rotate changes the token", function() use ($app) {
    $old = $app->csrf->token('rot');
    $new = $app->csrf->rotate('rot');
    expectEqual($old !== $new, true);
});

// --- 4. ROUTING TESTS ---
it("Router: registers simple route", function() use ($app) {
    $app->addRoute('GET', '/unit-test', 'handler');
    $routes = $app->props()['routes']['GET'] ?? [];
    $found = array_filter($routes, fn($r) => $r['path'] === '/unit-test');
    expectEqual(count($found) > 0, true);
});

it("Router: handles groups and middleware", function() use ($app) {
    $app->group('/api/v1', ['mw1'], function($app) {
        $app->addRoute('POST', '/data', 'h');
    });
    $routes = $app->props()['routes']['POST'] ?? [];
    $r = array_values(array_filter($routes, fn($r) => $r['path'] === '/api/v1/data'))[0];
    expectEqual(in_array('mw1', $r['middleware']), true);
});

it("Router: maintains middleware execution order (Onion pattern)", function() use ($app) {
    $trace = "";
    $mw1 = function($app, $next) use (&$trace) { $trace .= "1_in "; $next($app); $trace .= " 1_out"; };
    $mw2 = function($app, $next) use (&$trace) { $trace .= "2_in "; $next($app); $trace .= " 2_out"; };
    $app->addRoute('GET', '/onion-test', function() use (&$trace) { $trace .= "CORE"; }, [$mw1, $mw2]);
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/onion-test';
    $app->bind('request', $app->props()['registry']['request']);
    $app->dispatch();
    expectEqual($trace, "1_in 2_in CORE 2_out 1_out");
});

// --- 5. DATABASE WRAPPER TESTS ---
it("DB: can perform full CRUD on memory DB", function() use ($app) {
    $app->db->pdo->exec("CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)");
    $id = $app->db->insert('items', ['name' => 'Monad']);
    expectEqual($id, 1);
    $row = $app->db->fetchOne("SELECT * FROM items WHERE id = ?", [$id]);
    expectEqual($row['name'], 'Monad');
    $app->db->update('items', ['name' => 'Monad Framework'], ['id' => $id]);
    $row = $app->db->fetchOne("SELECT * FROM items WHERE id = ?", [$id]);
    expectEqual($row['name'], 'Monad Framework');
    $app->db->delete('items', ['id' => $id]);
    $row = $app->db->fetchOne("SELECT * FROM items WHERE id = ?", [$id]);
    expectEqual($row, null);
});

it("DB: protects against SQL Injection in column names", function() use ($app) {
    expectException(function() use ($app) {
        $app->db->insert('users', ['id; DROP TABLE users' => 1]);
    }, \InvalidArgumentException::class);
});

// --- 6. VIEW HELPER TESTS ---
it("View: auto-escaping works in templates", function() use ($app) {
    file_put_contents(__DIR__ . '/test_view.php', '<?= $view->data->html ?>');
    $out = $app->response->partial('test_view', ['html' => '<b>']);
    unlink(__DIR__ . '/test_view.php');
    expectEqual($out, '&lt;b&gt;');
});

it("View: fluent number() formats decimals", function() use ($app) {
    file_put_contents(__DIR__ . '/test_num.php', '<?= $view->data->val->number(2) ?>');
    $out = $app->response->partial('test_num', ['val' => 10]);
    unlink(__DIR__ . '/test_num.php');
    expectEqual($out, '10.00');
});

it("View: layouts wrap templates correctly", function() use ($app) {
    file_put_contents(__DIR__ . '/layout.php', '<html><?= $slot ?></html>');
    file_put_contents(__DIR__ . '/page.php', 'content');
    $app->response->layout = 'layout';
    ob_start();
    $app->response->render('page');
    $out = ob_get_clean();
    unlink(__DIR__ . '/layout.php');
    unlink(__DIR__ . '/page.php');
    $app->response->layout = null;
    expectEqual($out, '<html>content</html>');
});

// --- 7. RESPONSE & HTMX TESTS ---
it("Response: generates HTMX headers from props", function() use ($app) {
    $sentHeaders = [];
    $app->response->setHeader = function($res, $n, $v) use (&$sentHeaders) { $sentHeaders[$n] = $v; };
    $app->response->htmx->trigger = 'refresh';
    file_put_contents(__DIR__ . '/dummy.php', '');
    ob_start();
    $app->response->render('dummy');
    ob_end_clean();
    unlink(__DIR__ . '/dummy.php');
    expectEqual($sentHeaders['HX-Trigger'] ?? '', 'refresh');
});

// --- 8. CLI TESTS ---
it("CLI: registers commands", function() use ($app) {
    $app->addCommand('test:cmd', fn() => "done");
    expectEqual(isset($app->props()['commands']['test:cmd']), true);
});

// --- 9. REQUEST TESTS ---
it("Request: parses path correctly", function() use ($app) {
    $_SERVER['REQUEST_URI'] = '/some/path?query=1';
    $app->bind('request', $app->props()['registry']['request']); 
    expectEqual($app->request->path, '/some/path');
});

it("Request: detects HTMX headers", function() use ($app) {
    $_SERVER['HTTP_HX_REQUEST'] = 'true';
    $app->bind('request', $app->props()['registry']['request']);
    expectEqual($app->request->htmx->is, true);
});

// --- 10. ERROR HANDLING TESTS ---
it("Error Handling: renders JSON for API requests", function() use ($app) {
    $app->config->debug = false;
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    // Clear the cached request service to pick up new headers
    unset($app->request);
    ob_start();
    $app->renderError(new \Exception("Test Error"));
    $out = ob_get_clean();
    expectEqual(str_contains($out, '"error":"Internal Server Error"'), true);
});

echo str_repeat("-", 64) . "\n";
echo "   Total: " . ($passed + $failed) . " | \033[32mPassed: $passed\033[0m | " . ($failed > 0 ? "\033[31mFailed: $failed\033[0m" : "Failed: 0") . "\n";

exit($failed > 0 ? 1 : 0);
