# AGENTS.md

- Monad is a single-file PHP micro-framework. `index.php` is the entire engine. No `src/`, no build step, no Composer required.
- `index.php` returns `$app` when required. It auto-dispatches only when executed directly.
- PHP 8.0+, PDO extension. Dev server: `php -S localhost:8000 -t .`
- `cp monad.example.ini monad.ini` on first clone — `monad.ini` is gitignored. Config is loaded into `$app->config` and env vars.
- Tests run via `./monad test` (not PHPUnit). Container resets between cases. Set `$app->config->db = ['path' => ':memory:']` in `beforeEach` for DB tests.
- `MagicObject` caches factory results as singletons. Use `$app->reset()` to force re-resolution.
- Templates use dot-notation: `html.foo.bar` → `./html/foo/bar.php`. Layout is skipped during HTMX requests.
- Autoloader: `Monad\Foo\Bar` → `./Monad/Foo/Bar.php`. Composer autoloader auto-detected if present.
