<?php

declare(strict_types=1);

namespace Rig;

/**
 * Командная строка rig. Всё, что нужно для работы, rig запускает сам, от окружения нужен только PHP.
 *
 *   rig dev     разработка: свой сервер приложения + хаб (HMR и события с сервера)
 *   rig check   проверить окружение и все компоненты, прогнать тесты
 *   rig build   подготовка к выкладке
 *   rig push    хаб событий для продакшена
 */
final class Cli
{
    private Console $out;
    private bool $verbose = false;
    /** Вывод без оформления для процессов, которые запускает rig dev (очередь, планировщик). */
    private bool $plain = false;

    public function __construct(private readonly string $root)
    {
        $this->out = new Console();
    }

    public function run(array $argv): int
    {
        $cmd = $argv[1] ?? 'dev';
        $opts = $this->options(array_slice($argv, 2));
        $args = array_values(array_filter(array_slice($argv, 2), static fn (string $a) => !str_starts_with($a, '--')));
        $this->verbose = isset($opts['verbose']);
        $this->plain = isset($opts['plain']);
        Config::init($this->root, is_file("{$this->root}/config.php") ? (array)require "{$this->root}/config.php" : []);
        try {
            return match ($cmd) {
                'dev' => $this->dev($opts),
                'check' => $this->check(),
                'build' => $this->build(),
                'push' => $this->push($opts),
                'stop' => $this->stop(),
                'install' => $this->install(),
                'uninstall' => $this->uninstall(),
                'migrate' => $this->migrate($opts),
                'migrate:fresh' => $this->migrate(['fresh' => true] + $opts),
                'migrate:rollback' => $this->rollback($opts),
                'migrate:status' => $this->migrateStatus(),
                'make:migration' => $this->makeMigration($args),
                'db:seed' => $this->seed($opts),
                'db:start' => $this->dbStart(),
                'db:stop' => $this->dbStop(),
                'queue:work' => $this->queueWork($opts),
                'queue:failed' => $this->queueFailed(),
                'queue:retry' => $this->queueRetry($args),
                'queue:flush' => $this->queueFlush(),
                'schedule:run' => $this->scheduleRun(),
                'schedule:list' => $this->scheduleList(),
                'cache:clear' => $this->cacheClear(),
                'generate' => $this->generate($opts),
                'help', '-h', '--help' => $this->help(),
                default => $this->help("Неизвестная команда «{$cmd}»"),
            };
        } catch (\RuntimeException | \PDOException $e) {
            $this->out->line();
            $this->out->error($e->getMessage());
            $this->out->line();
            return 1;
        }
    }

    // ---------------------------------------------------------------- команды

    private function help(?string $error = null): int
    {
        $o = $this->out;
        $o->header('help');
        if ($error !== null) {
            $o->error($error);
            $o->line();
        }
        $cmd = fn (string $name, string $text) => $o->line('  ' . $o->cyan(str_pad($name, 12)) . $text);
        $flag = fn (string $name, string $text) => $o->line('  ' . str_repeat(' ', 12) . $o->dim(str_pad($name, 16) . $text));
        $cmd('rig dev', 'разработка: сервер приложения, HMR, события с сервера');
        $flag('--port=8000', 'порт приложения');
        $flag('--workers=4', 'сколько запросов обрабатывать одновременно');
        $flag('--host', 'открыть доступ из локальной сети (телефон, другой ПК)');
        $flag('--hub-only', 'только хаб: приложение отдаёт ваш nginx + PHP');
        $flag('--open', 'открыть браузер');
        $flag('--verbose', 'показывать подключения вкладок');
        $cmd('rig stop', 'остановить rig dev, запущенный в другом окне или в фоне');
        $cmd('rig check', 'проверить окружение и компоненты, прогнать тесты');
        $cmd('rig build', 'подготовка к выкладке: компиляция, размеры, проверка настроек');
        $cmd('rig push', 'хаб событий для продакшена   ' . $o->dim('--port=35730'));
        $cmd('rig install', 'команда rig в любом терминале и проекте (без .\\ и ./)');
        $cmd('rig uninstall', 'убрать глобальную команду');
        $o->line();
        $o->line('  ' . $o->bold('База данных'));
        $cmd('rig migrate', 'выполнить новые миграции database/migrations   ' . $o->dim('--fresh — всё заново'));
        $cmd('rig migrate:rollback', 'откатить последнюю пачку миграций   ' . $o->dim('--step=2'));
        $cmd('rig migrate:status', 'какие миграции выполнены');
        $cmd('rig make:migration', 'новая миграция: rig make:migration create_todos_table');
        $cmd('rig db:seed', 'начальные данные database/seeds   ' . $o->dim('--only=users'));
        $cmd('rig db:start', 'запустить PostgreSQL проекта (rig dev делает это сам)');
        $cmd('rig db:stop', 'остановить PostgreSQL проекта');
        $o->line();
        $o->line('  ' . $o->bold('Фоновые задачи'));
        $cmd('rig queue:work', 'обработчик очереди   ' . $o->dim('--once, --queue=имя'));
        $cmd('rig queue:failed', 'проваленные задачи');
        $cmd('rig queue:retry', 'вернуть проваленные в очередь: rig queue:retry [id|all]');
        $cmd('rig queue:flush', 'удалить проваленные задачи');
        $cmd('rig schedule:run', 'задачи по расписанию на эту минуту (cron раз в минуту)');
        $cmd('rig schedule:list', 'расписание из app/schedule.php');
        $cmd('rig cache:clear', 'очистить кеш данных (Cache::)');
        $o->line();
        $o->line('  ' . $o->bold('Статический сайт'));
        $cmd('rig generate', 'готовые HTML-страницы в dist/   ' . $o->dim('--out=dist'));
        $o->line();
        return $error === null ? 0 : 1;
    }

    private function dev(array $opts): int
    {
        $o = $this->out;
        $started = microtime(true);
        $o->header('dev');

        $hubPort = (int)($opts['hub-port'] ?? App::HUB_PORT);
        putenv("RIG_HUB_PORT={$hubPort}"); // воркеры наследуют окружение и отдают браузеру этот порт хаба
        $port = (int)($opts['port'] ?? 8000);
        $workers = max(1, (int)($opts['workers'] ?? 4));
        $hubOnly = isset($opts['hub-only']);
        $bind = isset($opts['host']) ? '0.0.0.0' : '127.0.0.1';

        $o->task('Окружение', fn () => $this->environment());
        $pg = Postgres::managed() ? Postgres::fromConfig() : null;
        if ($pg !== null) {
            $o->task('База данных', fn () => 'PostgreSQL ' . $pg->version() . ' · порт ' . $pg->port() . ' · ' . $pg->start());
            $o->task('Миграции', function (): string {
                $ran = (new Db\Migrator())->migrate();
                return $ran ? '(выполнено: ' . count($ran) . ')' : '(новых нет)';
            });
        }
        $errors = $this->components('Компоненты');
        $hub = $o->task('Хаб: HMR и события с сервера', fn () => $this->busy(
            fn () => new Hub($this->root, $hubPort, true, $this->logger(), $bind),
            "порт {$hubPort} занят — rig dev уже запущен? Остановить: rig stop",
        ));
        $proxy = null;
        if (!$hubOnly) {
            $proxy = $o->task("Сервер приложения", fn (\Closure $tick) => $this->busy(
                fn () => new DevProxy($this->root, $port, $workers, $this->logger(), $bind, $tick),
                "порт {$port} занят — укажите другой: rig dev --port=8001",
            ));
        }

        $o->line();
        $local = "http://127.0.0.1:{$port}/";
        $lines = [$o->bold('rig dev') . $o->dim('  готово за ' . $o->ms(microtime(true) - $started)), ''];
        if ($hubOnly) {
            $lines[] = $o->green('➜') . '  ' . $o->bold('Приложение:') . '  ' . $o->dim('отдаёт ваш веб-сервер');
        } else {
            $lines[] = $o->green('➜') . '  ' . $o->bold('Local:') . '    ' . $o->cyan($local);
            $net = $bind === '0.0.0.0' ? $this->lanIp() : null;
            $lines[] = $o->green('➜') . '  ' . $o->bold('Network:') . '  '
                . ($net ? $o->cyan("http://{$net}:{$port}/") : $o->dim('добавьте --host, чтобы открыть с телефона'));
            $lines[] = $o->green('➜') . '  ' . $o->bold('Воркеры:') . '  ' . $o->dim("{$workers} параллельно");
        }
        $lines[] = $o->green('➜') . '  ' . $o->bold('Хаб:') . '      ' . $o->dim("http://127.0.0.1:{$hubPort}");
        if ($pg !== null) {
            $lines[] = $o->green('➜') . '  ' . $o->bold('База:') . '     ' . $o->dim('pgsql://127.0.0.1:' . $pg->port() . '/' . Config::get('db.connections.pgsql.database', 'rig'));
        }
        $o->box($lines);
        $o->line();
        if ($errors) {
            $o->warn("компоненты с ошибками: {$errors} — исправьте, страница обновится сама");
        }
        $o->line('  ' . $o->dim('Ctrl+C или rig stop — остановить') . '   '
            . $o->c('32', '[hmr]') . $o->dim(' правки  ')
            . $o->c('35', '[push]') . $o->dim(' события  ')
            . $o->c('36', '[queue]') . $o->dim(' очередь  ')
            . $o->c('34', '[cron]') . $o->dim(' расписание  ')
            . $o->c('33', '[http]') . $o->dim(' медленные ответы  ')
            . $o->c('31', '[error]') . $o->dim(' ошибки'));
        $o->line();

        // rig stop находит этот процесс по файлу; при остановке файл удаляется, воркеры завершаются
        $pidFile = $this->pidFile();
        file_put_contents($pidFile, json_encode(['pid' => getmypid(), 'port' => $hubOnly ? null : $port, 'hub' => $hubPort, 'started' => date('c')]));
        register_shutdown_function(static fn () => @unlink($pidFile));
        $this->onStop(function () use ($o, $pg): void {
            if ($pg !== null) {
                try {
                    $pg->stop();
                } catch (\Throwable) {
                    // база остановится вместе с системой; при следующем rig dev: «уже работал»
                }
            }
            $o->line();
            $o->line('  ' . $o->dim('остановлено'));
        });
        if (!$hubOnly && isset($opts['open'])) {
            $this->open($local);
        }
        $tasks = new DevTasks($this->root, $this->logger());
        $hub->run($proxy, $tasks->tick(...));
    }

    // ---------------------------------------------------------------- база данных

    /** Перед командами базы: PostgreSQL проекта должен работать (rig dev запускает его сам, здесь тоже). */
    private function ensureDb(): void
    {
        $this->bootApp(); // миграции и сиды пользуются моделями App\Models
        if (Postgres::managed()) {
            $pg = Postgres::fromConfig();
            if (!$pg->running()) {
                $this->out->task('PostgreSQL проекта', fn () => $pg->start());
            }
        }
    }

    private function migrate(array $opts): int
    {
        $o = $this->out;
        $o->header(isset($opts['fresh']) ? 'migrate:fresh' : 'migrate');
        $this->ensureDb();
        if (isset($opts['fresh']) && !Config::get('debug') && !isset($opts['force'])) {
            throw new \RuntimeException('migrate:fresh удаляет все таблицы. На продакшене — только с --force');
        }
        $m = new Db\Migrator(fn (string $name, float $sec) => $o->line('  ' . $o->green('✔') . ' ' . $name . '  ' . $o->dim($o->ms($sec))));
        $ran = isset($opts['fresh']) ? $m->fresh() : $m->migrate();
        if (!$ran) {
            $o->line('  ' . $o->dim('новых миграций нет'));
        }
        if (isset($opts['seed'])) {
            Db\Seeder::run(null, fn (string $name, float $sec) => $o->line('  ' . $o->green('✔') . ' сид ' . $name . '  ' . $o->dim($o->ms($sec))));
        }
        $o->line();
        return 0;
    }

    private function rollback(array $opts): int
    {
        $o = $this->out;
        $o->header('migrate:rollback');
        $this->ensureDb();
        $m = new Db\Migrator(fn (string $name, float $sec) => $o->line('  ' . $o->yellow('↺') . ' ' . $name . '  ' . $o->dim($o->ms($sec))));
        if (!$m->rollback(max(1, (int)($opts['step'] ?? 1)))) {
            $o->line('  ' . $o->dim('откатывать нечего'));
        }
        $o->line();
        return 0;
    }

    private function migrateStatus(): int
    {
        $o = $this->out;
        $o->header('migrate:status');
        $this->ensureDb();
        $rows = [];
        foreach ((new Db\Migrator())->status() as $s) {
            $rows[] = [$s['ran'] ? $o->green('✔ выполнена') : $o->yellow('• ждёт'), $s['name'], $s['batch'] !== null ? $o->dim('пачка ' . $s['batch']) : ''];
        }
        if ($rows) {
            $o->table($rows, ['l', 'l', 'l']);
        } else {
            $o->line('  ' . $o->dim('миграций нет: rig make:migration create_todos_table'));
        }
        $o->line();
        return 0;
    }

    private function makeMigration(array $args): int
    {
        $name = $args[0] ?? throw new \RuntimeException('Укажите имя: rig make:migration create_todos_table');
        $file = Db\Migrator::make($name);
        $this->out->header('make:migration');
        $this->out->ok('создана ' . str_replace('\\', '/', substr($file, strlen($this->root) + 1)));
        $this->out->line();
        return 0;
    }

    private function seed(array $opts): int
    {
        $o = $this->out;
        $o->header('db:seed');
        $this->ensureDb();
        $ran = Db\Seeder::run(isset($opts['only']) ? (string)$opts['only'] : null,
            fn (string $name, float $sec) => $o->line('  ' . $o->green('✔') . ' ' . $name . '  ' . $o->dim($o->ms($sec))));
        if (!$ran) {
            $o->line('  ' . $o->dim('сидов нет: database/seeds/*.php'));
        }
        $o->line();
        return 0;
    }

    private function dbStart(): int
    {
        $o = $this->out;
        $o->header('db:start');
        if (!Postgres::managed()) {
            throw new \RuntimeException('rig управляет только своим PostgreSQL: в .env нужны DB_CONNECTION=pgsql и DB_MANAGED=true');
        }
        $pg = Postgres::fromConfig();
        $o->task('PostgreSQL ' . $pg->version(), fn () => $pg->start() . ' · порт ' . $pg->port());
        $o->line();
        return 0;
    }

    private function dbStop(): int
    {
        $o = $this->out;
        $o->header('db:stop');
        if (!Postgres::managed()) {
            throw new \RuntimeException('rig управляет только своим PostgreSQL: в .env нужны DB_CONNECTION=pgsql и DB_MANAGED=true');
        }
        $pg = Postgres::fromConfig();
        $o->task('PostgreSQL', function () use ($pg): string {
            $was = $pg->running();
            $pg->stop();
            return $was ? 'остановлен' : 'не был запущен';
        });
        $o->line();
        return 0;
    }

    // ---------------------------------------------------------------- очередь, расписание, кеш

    private function queueWork(array $opts): int
    {
        $this->bootApp();
        $queue = (string)($opts['queue'] ?? 'default');
        $o = $this->out;
        if (!$this->plain) {
            $o->header('queue:work');
            $o->line('  ' . $o->dim("очередь «{$queue}» · Ctrl+C — остановить"));
            $o->line();
        }
        $report = function (array $r) use ($o): void {
            $name = basename(str_replace('\\', '/', $r['job']));
            $line = ($r['ok'] ? '✔ ' : '✖ ') . $name . ' ' . $r['ms'] . ' ms' . ($r['ok'] ? '' : ' — ' . $r['error']);
            if ($this->plain) {
                echo $line, "\n";
            } else {
                $o->line('  ' . ($r['ok'] ? $o->green($line) : $o->red($line)));
            }
        };
        $untilEmpty = isset($opts['until-empty']);
        Queue::work($report, $queue, $untilEmpty ? static fn () => true : null, isset($opts['once']));
        return 0;
    }

    private function queueFailed(): int
    {
        $o = $this->out;
        $o->header('queue:failed');
        $rows = [];
        foreach (Queue::failed() as $f) {
            $rows[] = [$o->dim($f['id']), basename(str_replace('\\', '/', $f['job'])), $o->red((string)$f['error']), $o->dim(date('d.m H:i', (int)($f['failed'] ?? 0)))];
        }
        $rows ? $o->table($rows, ['l', 'l', 'l', 'l']) : $o->line('  ' . $o->dim('проваленных задач нет'));
        $o->line();
        return 0;
    }

    private function queueRetry(array $args): int
    {
        $n = Queue::retry($args[0] ?? 'all');
        $this->out->header('queue:retry');
        $this->out->ok("возвращено в очередь: {$n}");
        $this->out->line();
        return 0;
    }

    private function queueFlush(): int
    {
        $n = Queue::flushFailed();
        $this->out->header('queue:flush');
        $this->out->ok("удалено проваленных: {$n}");
        $this->out->line();
        return 0;
    }

    private function scheduleRun(): int
    {
        $this->bootApp();
        foreach (Schedule::runDue() as $r) {
            $line = ($r['ok'] ? '✔ ' : '✖ ') . $r['name'] . ' ' . $r['ms'] . ' ms' . ($r['error'] !== null ? ' — ' . $r['error'] : '');
            echo $this->plain ? $line : '  ' . ($r['ok'] ? $this->out->green($line) : $this->out->red($line)), "\n";
        }
        return 0;
    }

    private function scheduleList(): int
    {
        $this->bootApp();
        $o = $this->out;
        $o->header('schedule:list');
        $rows = [];
        foreach (Schedule::tasks() as $t) {
            $rows[] = [$o->cyan($t->expression()), $t->name];
        }
        $rows ? $o->table($rows, ['l', 'l']) : $o->line('  ' . $o->dim('расписание пусто: app/schedule.php'));
        $o->line();
        return 0;
    }

    private function cacheClear(): int
    {
        $n = Cache::flush();
        $this->out->header('cache:clear');
        $this->out->ok("удалено записей кеша: {$n}");
        $this->out->line();
        return 0;
    }

    // ---------------------------------------------------------------- статический сайт

    /**
     * rig generate: каждая страница app/pages сохраняется в dist/{адрес}/index.html, плюс public/, рантайм и app/js.
     * Динамическая страница pages/docs/[slug].php собирается для значений из pages/docs/[slug].paths.php
     * (файл возвращает список: ['start', 'components', …]). Страницы с middleware (вход) пропускаются.
     * Компоненты с действиями на сервере в статике не работают, rig предупредит, где они есть.
     */
    private function generate(array $opts): int
    {
        $o = $this->out;
        $started = microtime(true);
        $o->header('generate');
        $out = rtrim(str_replace('\\', '/', (string)($opts['out'] ?? "{$this->root}/dist")), '/');
        if (!preg_match('~^([a-z]:)?/~i', $out)) {
            $out = "{$this->root}/{$out}";
        }
        if (realpath(dirname($out)) === false || str_starts_with(realpath($this->root) ?: '', realpath($out) ?: "\0")) {
            throw new \RuntimeException("Недопустимая папка для сборки: {$out}");
        }
        $this->ensureDb();
        $config = (array)require "{$this->root}/config.php";
        $config = ['debug' => false, 'static' => true, 'hub' => false, 'speculation' => false, 'page_cache' => false] + $config;
        $config['debug'] = false;

        $routes = [];
        $o->task('Страницы', function () use (&$routes): string {
            $routes = $this->staticRoutes("{$this->root}/app/pages", '');
            return '(' . count($routes) . ')';
        });
        if (is_dir($out)) {
            $this->rmTree($out);
        }
        mkdir($out, 0777, true);

        $done = $skipped = [];
        $needServer = [];
        $o->task('HTML', function () use ($routes, $config, $out, &$done, &$skipped, &$needServer): string {
            foreach ($routes as $route) {
                [$html, , $app] = $this->renderStatic($route, $config);
                if ($html === '') {
                    $skipped[] = $route;
                    continue;
                }
                $file = $out . ($route === '/' ? '' : $route) . '/index.html';
                if (!is_dir(dirname($file))) {
                    mkdir(dirname($file), 0777, true);
                }
                file_put_contents($file, $html);
                $done[] = $route;
                foreach ($app->usedComponents() as $name => $def) {
                    if ($def['actions'] || $def['mounts'] || $def['lazy'] || $def['cserver']) {
                        $needServer[$name][] = $route;
                    }
                }
            }
            // страница 404 для статического хостинга
            [$html] = $this->renderStatic('/__rig_generate_404__', $config);
            if ($html !== '') {
                file_put_contents("{$out}/404.html", $html);
            }
            return '(' . count($done) . ')';
        });
        $o->task('public/, рантайм, app/js', function () use ($out): string {
            $n = $this->copyTree("{$this->root}/public", $out, ['index.php']);
            if (!is_dir("{$out}/_rig")) {
                mkdir("{$out}/_rig", 0777, true);
            }
            copy("{$this->root}/rig/runtime/rig.js", "{$out}/_rig/runtime.js");
            if (is_dir("{$this->root}/app/js")) {
                $n += $this->copyTree("{$this->root}/app/js", "{$out}/_rig/js");
            }
            return "(файлов: " . ($n + 1) . ')';
        });

        $o->line();
        foreach ($skipped as $s) {
            $o->warn("пропущено {$s} — страница перенаправляет (например, только для вошедших)");
        }
        foreach ($needServer as $name => $pages) {
            $o->warn("{$name}: действиям нужен сервер — в статике не работают " . $o->dim('(' . implode(', ', array_slice(array_unique($pages), 0, 3)) . ')'));
        }
        $o->line('  ' . $o->c('1;32', '✔ Готово') . $o->dim('  за ' . $o->ms(microtime(true) - $started) . '  ·  ' . str_replace($this->root . '/', '', $out) . '/'));
        $o->line();
        return 0;
    }

    /** Адреса страниц: обычные файлы и [параметр].php со списком значений в [параметр].paths.php. @return list<string> */
    private function staticRoutes(string $dir, string $prefix): array
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name[0] === '.' || $name[0] === '_') {
                continue;
            }
            $path = "{$dir}/{$name}";
            if (is_dir($path)) {
                array_push($out, ...$this->staticRoutes($path, "{$prefix}/{$name}"));
                continue;
            }
            if (!str_ends_with($name, '.php') || str_ends_with($name, '.paths.php')) {
                continue;
            }
            $base = substr($name, 0, -4);
            if (preg_match('/^\[(\w+)\]$/', $base, $m)) {
                $paths = "{$dir}/{$base}.paths.php";
                if (is_file($paths)) {
                    foreach ((array)require $paths as $value) {
                        $out[] = "{$prefix}/" . rawurlencode((string)$value);
                    }
                }
                continue;
            }
            $out[] = $base === 'index' ? ($prefix === '' ? '/' : $prefix) : "{$prefix}/{$base}";
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /** @return array{0:string,1:int,2:App} HTML, код ответа, приложение (для списка компонентов) */
    private function renderStatic(string $route, array $config): array
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $route, 'HTTP_HOST' => parse_url((string)Env::get('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'] + $_SERVER;
        unset($_SERVER['HTTP_X_RIG_NAV'], $_SERVER['HTTP_COOKIE']);
        $_COOKIE = [];
        Request::set(null);
        Session::reset();
        Auth::forgetUser();
        I18n::reset();
        $app = new App($this->root, $config);
        ob_start();
        try {
            $app->handle();
        } finally {
            $html = (string)ob_get_clean();
        }
        return [$html, $route === '/__rig_generate_404__' ? 404 : 200, $app];
    }

    private function copyTree(string $from, string $to, array $skip = []): int
    {
        $n = 0;
        if (!is_dir($to)) {
            mkdir($to, 0777, true);
        }
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..' || in_array($name, $skip, true)) {
                continue;
            }
            if (is_dir("{$from}/{$name}")) {
                $n += $this->copyTree("{$from}/{$name}", "{$to}/{$name}");
            } else {
                copy("{$from}/{$name}", "{$to}/{$name}");
                $n++;
            }
        }
        return $n;
    }

    private function rmTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            is_dir("{$dir}/{$name}") ? $this->rmTree("{$dir}/{$name}") : unlink("{$dir}/{$name}");
        }
        rmdir($dir);
    }

    /** Приложение для фоновых команд: автозагрузка App\…, настройки. */
    private function bootApp(): void
    {
        new App($this->root, (array)require "{$this->root}/config.php");
    }

    /**
     * Перед выкладкой (и на сервере после неё): кеш компонентов собирается заново, выводятся размеры.
     * Ошибка в шаблоне или debug на продакшене останавливают выкладку.
     */
    private function build(): int
    {
        $o = $this->out;
        $started = microtime(true);
        $o->header('build');

        $o->task('Окружение', fn () => $this->environment());
        $o->task('Кеш компонентов очищен', function (): ?string {
            $old = glob("{$this->root}/storage/cache/*.json") ?: [];
            foreach ($old as $f) {
                @unlink($f);
            }
            return '(' . count($old) . ' файлов)';
        });
        $errors = $this->components('Компоненты скомпилированы');
        if ($errors) {
            $o->line();
            $o->error("Компоненты с ошибками: {$errors} — выкладывать нельзя");
            $o->line();
            return 1;
        }

        // таблица размеров: описание компонента уходит в браузер, gzip показывает, сколько реально передаётся
        $o->line();
        $rows = [];
        $total = $totalGz = 0;
        foreach (glob("{$this->root}/storage/cache/*.json") ?: [] as $f) {
            $json = (string)file_get_contents($f);
            $name = (string)preg_replace('/_[0-9a-f]+\.json$/', '', basename($f));
            $gz = strlen((string)gzencode($json, 9));
            $total += strlen($json);
            $totalGz += $gz;
            $rows[] = [$o->dim('компонент  ') . $o->cyan($name), $o->size(strlen($json)), $o->dim('│ gzip ' . $o->size($gz))];
        }
        $js = (string)file_get_contents("{$this->root}/rig/runtime/rig.js");
        $jsGz = strlen((string)gzencode($js, 9));
        $rows[] = [$o->dim('рантайм    ') . $o->magenta('rig.js'), $o->size(strlen($js)), $o->dim('│ gzip ' . $o->size($jsGz))];
        $rows[] = [$o->bold('Σ всего'), $o->bold($o->size($total + strlen($js))), $o->dim('│ gzip ') . $o->bold($o->size($totalGz + $jsGz))];
        $o->table($rows, ['l', 'r', 'l']);
        $o->line();

        $config = require "{$this->root}/config.php";
        $env = $_SERVER['RIG_ENV'] ?? getenv('RIG_ENV') ?: 'development';
        if ($env === 'production') {
            if (!empty($config['debug'])) {
                $o->error("config.php: 'debug' => true на продакшене раскроет код и стек ошибок");
                return 1;
            }
            $o->ok('RIG_ENV=production, debug выключен');
            $o->ok('хаб событий: ' . ($config['hub'] ? $config['hub'] . $o->dim('  (на сервере нужен rig push)') : 'выключен'));
        } else {
            $o->warn('RIG_ENV не production — настройки проверены как для разработки ' . $o->dim('(на сервере: RIG_ENV=production rig build)'));
        }
        if (!ini_get('opcache.enable')) {
            $o->warn('OPcache выключен — на продакшене включите ' . $o->dim('opcache.enable=1, opcache.validate_timestamps=0'));
        }
        $o->line();
        $o->line('  ' . $o->c('1;32', '✔ Готово к выкладке') . $o->dim('  за ' . $o->ms(microtime(true) - $started) . '  ·  конфиги сервера: deploy/'));
        $o->line();
        return 0;
    }

    private function check(): int
    {
        $o = $this->out;
        $o->header('check');
        $o->task('Окружение', fn () => $this->environment());
        $errors = $this->components('Компоненты');
        $o->task('Выражения: PHP и JS считают одинаково', fn () => $this->tests('run.php'));
        $o->task('Блоки файлов компонентов', fn () => $this->tests('blocks.php'));
        $o->task('Бэкенд: база, сессии, вход, очередь, почта', fn () => $this->tests('backend.php'));
        $o->task('Интерфейс: директивы, переводы, <head>', fn () => $this->tests('interface.php'));
        $o->line();
        if ($errors) {
            $o->error("компоненты с ошибками: {$errors}");
            return 1;
        }
        $o->line('  ' . $o->c('1;32', '✔ Всё в порядке'));
        $o->line();
        return 0;
    }

    /** Прогнать rig/tests/{файл}; возвращает число проверок для строки отчёта. */
    private function tests(string $file): string
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$this->root}/rig/tests/{$file}") . ' 2>&1', $lines, $code);
        $report = implode("\n", $lines);
        if ($code !== 0) {
            throw new \RuntimeException("тесты не прошли:\n{$report}");
        }
        return preg_match('/все (\d+) проверок/u', $report, $m)
            ? "({$m[1]} " . Expr\Evaluator::call('plural', [(int)$m[1], 'проверка', 'проверки', 'проверок']) . ')'
            : '';
    }

    /** Остановить rig dev из другого окна: процесс и все его воркеры. */
    private function stop(): int
    {
        $o = $this->out;
        $o->header('stop');
        $file = $this->pidFile();
        $info = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $pid = (int)($info['pid'] ?? 0);
        if ($pid <= 0 || !$this->alive($pid)) {
            @unlink($file);
            $o->line('  ' . $o->dim('rig dev не запущен'));
            $o->line();
            return 0;
        }
        if (Postgres::managed() && Postgres::fromConfig()->running()) {
            // базу останавливаем своей командой, а не вместе с деревом процессов
            $o->task('PostgreSQL проекта', function (): string {
                Postgres::fromConfig()->stop();
                return 'остановлен';
            });
        }
        $o->task("Останавливаю rig dev (PID {$pid})", function (\Closure $tick) use ($pid, $file): string {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /PID {$pid} /T /F 2>NUL"); // /T: вместе с воркерами php -S
            } elseif (function_exists('posix_kill')) {
                posix_kill($pid, 15); // SIGTERM: rig dev сам завершит воркеры
            } else {
                exec("kill {$pid}");
            }
            $deadline = microtime(true) + 5;
            while ($this->alive($pid) && microtime(true) < $deadline) {
                $tick();
                usleep(100_000);
            }
            if ($this->alive($pid)) {
                throw new \RuntimeException("процесс {$pid} не остановился");
            }
            @unlink($file);
            return '';
        });
        $ports = array_filter([$info['port'] ?? null, $info['hub'] ?? null]);
        $o->line();
        $o->line('  ' . $o->dim('порты свободны: ' . implode(', ', $ports)));
        $o->line();
        return 0;
    }

    /**
     * Глобальная команда rig. Обёртка ищет проект (rig/cli.php) в текущей папке и выше,
     * поэтому `rig dev` работает в любом проекте. Windows: %USERPROFILE%\.rig\bin в PATH
     * пользователя; Linux/macOS: ~/.local/bin/rig.
     */
    private function install(): int
    {
        $o = $this->out;
        $o->header('install');
        if (PHP_OS_FAMILY === 'Windows') {
            $dir = getenv('USERPROFILE') . '\\.rig\\bin';
            $o->task('Обёртка ' . $dir . '\\rig.cmd', function () use ($dir): string {
                if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
                    throw new \RuntimeException("не удалось создать {$dir}");
                }
                file_put_contents("{$dir}\\rig.cmd", str_replace("\n", "\r\n", self::WINDOWS_SHIM)); // cmd надёжен только с CRLF
                return '';
            });
            $added = $o->task('Папка в PATH пользователя', fn (): string => $this->userPath($dir, true) ? '(добавлена)' : '(уже была)');
            $o->line();
            $o->line('  ' . $o->c('1;32', '✔ Готово.') . ' В новом окне терминала: ' . $o->cyan('rig dev'));
            if ($added === '(добавлена)') {
                $o->line('  ' . $o->dim('В уже открытом окне PowerShell обновите PATH один раз:'));
                $o->line('    ' . $o->cyan('$env:Path += ";$env:USERPROFILE\.rig\bin"'));
            }
        } else {
            $dir = rtrim((string)getenv('HOME'), '/') . '/.local/bin';
            $o->task("Обёртка {$dir}/rig", function () use ($dir): string {
                if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                    throw new \RuntimeException("не удалось создать {$dir}");
                }
                file_put_contents("{$dir}/rig", self::POSIX_SHIM);
                chmod("{$dir}/rig", 0755);
                return '';
            });
            $o->line();
            $o->line('  ' . $o->c('1;32', '✔ Готово.') . ' Команда: ' . $o->cyan('rig dev')
                . (str_contains((string)getenv('PATH'), $dir) ? '' : $o->dim("  (добавьте {$dir} в PATH)")));
        }
        $o->line();
        return 0;
    }

    private function uninstall(): int
    {
        $o = $this->out;
        $o->header('uninstall');
        if (PHP_OS_FAMILY === 'Windows') {
            $dir = getenv('USERPROFILE') . '\\.rig\\bin';
            @unlink("{$dir}\\rig.cmd");
            @rmdir($dir);
            @rmdir(dirname($dir));
            $this->userPath($dir, false);
            $o->ok('обёртка удалена, папка убрана из PATH');
        } else {
            @unlink(rtrim((string)getenv('HOME'), '/') . '/.local/bin/rig');
            $o->ok('обёртка удалена');
        }
        $o->line('  ' . $o->dim('В проекте команда по-прежнему доступна: .\rig dev (PowerShell), rig dev (cmd), ./rig.sh dev'));
        $o->line();
        return 0;
    }

    /** Добавить/убрать папку в PATH пользователя Windows. Возвращает true, если PATH изменён. */
    private function userPath(string $dir, bool $add): bool
    {
        $d = str_replace("'", "''", $dir);
        $script = $add
            ? "\$p=[Environment]::GetEnvironmentVariable('Path','User'); if(\$p -eq \$null){\$p=''}; "
                . "if(\$p.Split(';') -icontains '{$d}'){ 'same' } else { [Environment]::SetEnvironmentVariable('Path', ((\$p.TrimEnd(';') + ';{$d}').TrimStart(';')), 'User'); 'changed' }"
            : "\$p=[Environment]::GetEnvironmentVariable('Path','User'); if(\$p -ne \$null){ "
                . "[Environment]::SetEnvironmentVariable('Path', ((\$p.Split(';') | Where-Object { \$_ -and \$_ -ine '{$d}' }) -join ';'), 'User') }; 'changed'";
        // -EncodedCommand: без проблем с кавычками в аргументах cmd
        $encoded = base64_encode((string)mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
        $out = (string)shell_exec("powershell -NoProfile -NonInteractive -EncodedCommand {$encoded}");
        return str_contains($out, 'changed');
    }

    private const WINDOWS_SHIM = <<<'CMD'
    @echo off
    rem rig: global command. Finds rig\cli.php in the current folder or its parents.
    setlocal
    set "RIG_DIR=%CD%"
    :up
    if exist "%RIG_DIR%\rig\cli.php" goto found
    for %%I in ("%RIG_DIR%\..") do set "RIG_PARENT=%%~fI"
    if /i "%RIG_PARENT%"=="%RIG_DIR%" goto none
    set "RIG_DIR=%RIG_PARENT%"
    goto up
    :found
    if not defined RIG_PHP set "RIG_PHP=php"
    chcp 65001 >nul
    "%RIG_PHP%" "%RIG_DIR%\rig\cli.php" %*
    exit /b %errorlevel%
    :none
    echo rig: no rig project here (rig\cli.php not found in this folder or above)
    exit /b 1

    CMD;

    private const POSIX_SHIM = <<<'SH'
    #!/usr/bin/env sh
    # rig: global command. Finds rig/cli.php in the current folder or its parents.
    dir=$(pwd)
    while [ "$dir" != "/" ]; do
      if [ -f "$dir/rig/cli.php" ]; then exec "${RIG_PHP:-php}" "$dir/rig/cli.php" "$@"; fi
      dir=$(dirname "$dir")
    done
    echo "rig: no rig project here (rig/cli.php not found in this folder or above)" >&2
    exit 1

    SH;

    private function pidFile(): string
    {
        return "{$this->root}/storage/rig-dev.json";
    }

    private function alive(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return str_contains((string)shell_exec("tasklist /FI \"PID eq {$pid}\" /NH 2>NUL"), (string)$pid);
        }
        return function_exists('posix_kill') ? posix_kill($pid, 0) : is_dir("/proc/{$pid}");
    }

    private function push(array $opts): int
    {
        $o = $this->out;
        $port = (int)($opts['port'] ?? App::HUB_PORT);
        $o->header('push');
        $hub = $o->task('Хаб событий', fn () => $this->busy(
            fn () => new Hub($this->root, $port, false, $this->logger()),
            "порт {$port} занят — хаб уже запущен?",
        ));
        $o->line();
        $o->box([$o->bold('rig push'), '', $o->green('➜') . '  ' . $o->bold('Хаб:') . '  ' . $o->cyan("http://127.0.0.1:{$port}")]);
        $o->line();
        $hub->run();
    }

    // ---------------------------------------------------------------- шаги

    /** PHP, расширения, storage, ключ. */
    private function environment(): string
    {
        if (PHP_VERSION_ID < 80100) {
            throw new \RuntimeException('Нужен PHP 8.1 или новее, сейчас ' . PHP_VERSION);
        }
        foreach (['mbstring', 'json', 'hash'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new \RuntimeException("Нужно расширение PHP {$ext}");
            }
        }
        $storage = "{$this->root}/storage";
        foreach (["{$storage}/cache", "{$storage}/hmr"] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
                throw new \RuntimeException("Не удалось создать {$dir}");
            }
        }
        if (!is_writable($storage)) {
            throw new \RuntimeException("Нет прав на запись в {$storage}");
        }
        App::key($storage);
        return '(PHP ' . PHP_VERSION . ')';
    }

    /** Шаг «Компоненты»: скомпилировать все, ошибки вывести под строкой шага. Возвращает число ошибок. */
    private function components(string $label): int
    {
        $errors = [];
        $this->out->task($label, function () use (&$errors): string {
            $app = new App($this->root, ['debug' => true]);
            $all = glob("{$this->root}/app/components/*.php") ?: [];
            foreach ($all as $file) {
                $name = basename($file, '.php');
                try {
                    $app->compiler->load($name);
                } catch (\Throwable $e) {
                    $at = str_replace('\\', '/', substr($e->getFile(), strlen($this->root) + 1));
                    $errors[] = [$name, $e->getMessage(), "{$at}:{$e->getLine()}"];
                }
            }
            $ok = count($all) - count($errors);
            return $errors ? "({$ok} из " . count($all) . ')' : "({$ok})";
        });
        $o = $this->out;
        foreach ($errors as [$name, $message, $at]) {
            $o->line('    ' . $o->red('✖ ' . $name) . '  ' . $message);
            $o->line('      ' . $o->dim($at));
        }
        return count($errors);
    }

    // ---------------------------------------------------------------- мелочи

    private function logger(): \Closure
    {
        return function (string $tag, string $text): void {
            if ($tag === 'tab' && !$this->verbose) {
                return; // подключения вкладок показываем только с --verbose
            }
            $this->out->log($tag, $text);
        };
    }

    /** Создать сервер; ошибку «порт занят» показать понятным текстом. */
    private function busy(\Closure $make, string $message): object
    {
        try {
            return $make();
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($message);
        }
    }

    /** IP в локальной сети: UDP-«подключение» ничего не отправляет, но показывает свой адрес. */
    private function lanIp(): ?string
    {
        $s = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
        if (!$s) {
            return null;
        }
        $name = (string)stream_socket_get_name($s, false);
        fclose($s);
        $ip = substr($name, 0, (int)strrpos($name, ':'));
        return $ip !== '' && $ip !== '0.0.0.0' ? $ip : null;
    }

    private function onStop(\Closure $fn): void
    {
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(static function () use ($fn): void {
                $fn();
                exit(0);
            });
        } elseif (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) { // Ctrl+C и rig stop
                pcntl_signal($signal, static function () use ($fn): void {
                    $fn();
                    exit(0);
                });
            }
        }
    }

    private function open(string $url): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" ' . escapeshellarg($url), 'r'));
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            exec('open ' . escapeshellarg($url));
        } else {
            exec('xdg-open ' . escapeshellarg($url) . ' > /dev/null 2>&1 &');
        }
    }

    private function options(array $args): array
    {
        $opts = [];
        foreach ($args as $a) {
            if (preg_match('/^--([\w-]+)(?:=(.*))?$/', $a, $m)) {
                $opts[$m[1]] = $m[2] ?? true;
            }
        }
        return $opts;
    }
}
