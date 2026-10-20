<?php

declare(strict_types=1);

namespace Rig;

use Rig\Compiler\CompileError;
use Rig\Compiler\Compiler;
use Rig\Expr\Evaluator;

/**
 * Фронт-контроллер: страницы из app/pages, API из app/api и служебные адреса /_rig/*.
 *
 *   /_rig/runtime  rig.js
 *   /_rig/action   вызов действия компонента
 *   /_rig/upload   загрузка файла из <input type="file" r-model> (и просмотр временной картинки)
 *   /_rig/refresh  HMR: пересобрать компонент с текущим состоянием (только debug)
 *   /_rig/hmr      HMR без dev-сервера: долгий запрос до изменения файлов (только debug)
 *   /api/…         app/api/….php, ответ JSON
 *
 * Страница, запрошенная с заголовком X-Rig-Nav, отдаётся JSON-ом без layout.
 * Так rig.js переходит между страницами без перезагрузки.
 */
final class App
{
    public const VERSION = '0.6.0';

    /** Порт хаба (rig dev / rig push): HMR и события с сервера. */
    public const HUB_PORT = 35730;

    private const API_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public readonly Compiler $compiler;
    public readonly Hmr $hmr;
    public readonly bool $debug;
    public readonly string $appDir;
    private readonly string $key;
    private readonly ?string $hubUrl;
    /** Спекулятивная загрузка страниц: 'conservative' | 'moderate' | 'eager' | false. */
    public readonly string|false $speculation;
    /** Сколько секунд браузер держит показанные страницы и открывает их мгновенно; 0: не держит. */
    public readonly int $pageCache;
    /** Команда редактора для «Открыть в редакторе»: code, cursor, windsurf, phpstorm, subl… */
    public readonly string $editor;
    private int $seq = 0;
    /** Запрос пришёл от перехода без перезагрузки (X-Rig-Nav). */
    private bool $nav = false;
    /** @var array<string,array> компоненты, отрендеренные на странице */
    private array $used = [];
    private string $layout = 'default';
    /** @var list<string> middleware страницы (Page::middleware), подписываются в снимки её компонентов */
    private array $pageGuards = [];
    /** Уровень буферов вывода до запроса: ошибки и перенаправления чистят только свои (rig generate держит внешний). */
    private int $obBase = 0;

    public function __construct(public readonly string $root, array $config = [])
    {
        Config::init($root, $config);
        $this->debug = (bool)($config['debug'] ?? false);
        $this->appDir = "{$root}/app";
        $storage = "{$root}/storage";
        foreach (["{$storage}/cache", "{$storage}/hmr"] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }
        $this->key = (string)($config['key'] ?? Config::key());
        // адрес хаба для браузера; в продакшене, например, '/_rig/hub' за nginx (proxy_buffering off)
        $hub = $config['hub'] ?? ($this->debug ? 'http://' . self::hubHost() . ':' . ((int)getenv('RIG_HUB_PORT') ?: self::HUB_PORT) : null);
        $this->hubUrl = is_string($hub) && $hub !== '' ? $hub : null; // false: хаб выключен
        $spec = $config['speculation'] ?? 'moderate';
        $this->speculation = in_array($spec, ['conservative', 'moderate', 'eager'], true) ? $spec : false;
        $this->pageCache = ($config['page_cache'] ?? 600) === false ? 0 : (int)($config['page_cache'] ?? 600);
        $this->editor = (string)($config['editor'] ?? 'code');
        $this->compiler = new Compiler("{$storage}/cache");
        $this->hmr = new Hmr($root, "{$storage}/hmr");

        $page = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_SERVER['HTTP_X_RIG_PAGE'] ?? ''))) ?: null;
        Push::init("{$storage}/push.log", $page);

        spl_autoload_register(function (string $class): void {
            if (!str_starts_with($class, 'App\\')) {
                return;
            }
            $file = $this->classFile(substr($class, 4));
            if ($file === null) {
                return;
            }
            if ($this->debug && function_exists('opcache_invalidate')) {
                opcache_invalidate($file);
            }
            // после класса компонента идут блоки <template> и <style>, при подключении их не выводим
            ob_start();
            try {
                require $file;
            } finally {
                ob_end_clean();
            }
        });
        Page::boot($this);
    }

    /**
     * Файл класса App\…: Components\X в app/components/X.php, Models\X в app/models/X.php,
     * Middleware\X в app/middleware/X.php, Jobs\X в app/jobs/X.php; остальное в app/lib/….
     */
    private function classFile(string $rel): ?string
    {
        $parts = explode('\\', $rel);
        if (count($parts) > 1) {
            $dir = strtolower($parts[0]);
            $f = "{$this->appDir}/{$dir}/" . implode('/', array_slice($parts, 1)) . '.php';
            if (is_file($f)) {
                return $f;
            }
        }
        $f = "{$this->appDir}/lib/" . str_replace('\\', '/', $rel) . '.php';
        return is_file($f) ? $f : null;
    }

    /**
     * Хост хаба для браузера в разработке: тот же, по которому открыт сайт (телефон в сети с rig dev --host),
     * но для https и localhost берётся 127.0.0.1: браузеры пускают на него http-запросы и с https-страниц.
     */
    private static function hubHost(): string
    {
        $host = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return $https || $host === '' || $host === 'localhost' || str_contains($host, ':') || !preg_match('/^[a-z0-9.-]+$/', $host)
            ? '127.0.0.1'
            : $host;
    }

    /** Ключ подписи из storage/app.key (создаётся при первом запуске). Его же читает хаб. */
    public static function key(string $storage): string
    {
        $file = "{$storage}/app.key";
        if (!is_file($file)) {
            if (!is_dir($storage)) {
                mkdir($storage, 0777, true);
            }
            file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
        }
        return trim((string)file_get_contents($file));
    }

    public function handle(): void
    {
        $this->obBase = ob_get_level();
        $req = Request::current();
        $path = $req->path();
        $this->nav = isset($_SERVER['HTTP_X_RIG_NAV']);
        $api = $path === '/api' || str_starts_with($path, '/api/');
        $json = $api || $this->nav || (str_starts_with($path, '/_rig/') && $path !== '/_rig/runtime' && !($path === '/_rig/upload' && $req->isSafe()));
        try {
            match (true) {
                $path === '/_rig/runtime' => $this->runtime(),
                $path === '/_rig/action' => $this->action(false),
                $path === '/_rig/refresh' => $this->action(true),
                $path === '/_rig/upload' => $this->upload(),
                $path === '/_rig/hmr' => $this->watch(),
                $path === '/_rig/open' => $this->openInEditor(),
                str_starts_with($path, '/_rig/js/') => $this->jsModule(substr($path, 9)),
                $api => $this->api($path),
                default => $this->page($path),
            };
        } catch (Redirect $r) {
            $this->redirect($r, $json && !$api);
        } catch (\Throwable $e) {
            $this->fail($e, $json, $api);
        }
    }

    // ---------------------------------------------------------------- компоненты

    /**
     * SSR компонента: rig('Name', [...]) на странице или <Name …> в шаблоне другого компонента.
     *
     * @param array<string,string> $slots HTML слотов от родителя
     * @param array<string,string> $attrs атрибуты элемента-обёртки
     */
    public function mount(string $name, array $props = [], array $slots = [], array $attrs = []): string
    {
        $def = $this->compiler->load($name);
        if ($def['guard'] ?? []) {
            Guards::run($def['guard']); // #[Guard] на классе: не показывать компонент, если нельзя
        }
        $class = Compiler::classOf($name);
        $c = new $class();
        $rc = new \ReflectionClass($c);
        $client = $def['client'] ?: [];
        foreach ($props as $k => $v) {
            if (in_array($k, $def['server'], true)) {
                $c->$k = Cast::value($rc->getProperty($k)->getType(), $v, "{$name}::\${$k}");
            } elseif (array_key_exists($k, $client)) {
                $client[$k] = $v;
            } else {
                throw new \InvalidArgumentException("У компонента {$name} нет публичного свойства \${$k}");
            }
        }
        $this->used[$name] = $def;
        $renderer = new Renderer(fn (string $n, array $p, array $s, array $a) => $this->mount($n, $p, $s, $a), $slots);
        $lazy = $def['lazy'] ?? null;

        if ($lazy !== null && !($lazy['ssr'] && !$this->nav)) {
            // #[Lazy]: mount() не вызываем, страница уходит сразу со скелетоном,
            // данные браузер запросит отдельно (refresh с mount: true)
            $view = $this->serverState($def, $c) + array_fill_keys($def['cserver'], null) + ['$errors' => []] + $client;
            $view += ['$slots' => array_fill_keys(array_keys($slots), true), '$busy' => true, '$pending' => []];
            $view = $this->withComputed($def, $view);
            $html = $renderer->render($def['skel'] ?: [], $view);
            if ($slots) {
                // HTML слотов со страницы понадобится после загрузки, поэтому держим его рядом, скрытым
                $html .= '<div hidden r-lazy-slots>' . implode('', $slots) . '</div>';
            }
            $data = $this->data($def, $c, $view, true);
            $tag = $def['tag'] ?? 'div';
            $attrs = $attrs + ['r-c' => $name, 'r-id' => 'r' . (++$this->seq), 'aria-busy' => 'true', 'r-data' => $data];
            return "<{$tag}" . Renderer::attrs($attrs) . ">{$html}</{$tag}>";
        }

        if (method_exists($c, 'mount')) {
            $c->mount();
        }
        $view = $this->serverView($def, $c) + $client;
        $view += ['$slots' => array_fill_keys(array_keys($slots), true), '$busy' => false, '$pending' => []];
        $view = $this->withComputed($def, $view);
        $html = $renderer->render($def['tpl'], $view);
        $data = $this->data($def, $c, $view);
        $tag = $def['tag'] ?? 'div';
        $attrs = $attrs + ['r-c' => $name, 'r-id' => 'r' . (++$this->seq), 'r-data' => $data];
        return "<{$tag}" . Renderer::attrs($attrs) . ">{$html}</{$tag}>";
    }

    /**
     * Page::middleware('auth', 'role:admin') в начале страницы: проверить сразу и запомнить,
     * потому что действия компонентов этой страницы проверяются теми же middleware.
     */
    public function guardPage(array $names): void
    {
        Guards::$context = 'page';
        Guards::run($names);
        array_push($this->pageGuards, ...array_values(array_map('strval', $names)));
    }

    /** Компоненты страницы вместе с теми, что могут появиться внутри них позже (r-if, r-for). */
    public function usedComponents(): array
    {
        $all = [];
        $queue = array_keys($this->used);
        while ($queue) {
            $name = array_shift($queue);
            if (isset($all[$name])) {
                continue;
            }
            $all[$name] = $this->compiler->load($name);
            array_push($queue, ...($all[$name]['uses'] ?? []));
        }
        return $all;
    }

    public function layoutName(): string
    {
        return $this->layout;
    }

    public function runtimeUrl(): string
    {
        $v = substr((string)md5_file(dirname(__DIR__) . '/runtime/rig.js'), 0, 8);
        // rig generate: рантайм лежит файлом dist/_rig/runtime.js
        return Config::get('static') ? "/_rig/runtime.js?v={$v}" : "/_rig/runtime?v={$v}";
    }

    /**
     * Подключение браузера к хабу.
     * token: подписанный список событий (#[On]) компонентов страницы, хаб присылает только их.
     * hmr:   только в debug; poll: запасной HMR без хаба (под php -S его нет, там нельзя держать запрос).
     */
    public function hubConfig(): array
    {
        $topics = [];
        // при переходе без перезагрузки компоненты layout остаются в браузере, поэтому их события тоже нужны
        $keep = [];
        foreach (explode(',', (string)($_SERVER['HTTP_X_RIG_KEEP'] ?? '')) as $name) {
            try {
                $keep[] = $this->compiler->load(trim($name));
            } catch (\Throwable) {
                // неизвестное имя пропускаем
            }
        }
        foreach ([...array_values($this->usedComponents()), ...$keep] as $def) {
            foreach (array_keys($def['on'] ?: []) as $event) {
                // события с {параметрами} подписываются отдельно, по значениям конкретного компонента (см. topics())
                if (!str_contains((string)$event, '{')) {
                    $topics[(string)$event] = true;
                }
            }
        }
        return [
            'url' => $this->hubUrl,
            'token' => Snapshot::sign('hub', ['topics' => array_keys($topics)], $this->key),
            'hmr' => $this->debug ? ['v' => $this->hmr->version(), 'poll' => PHP_SAPI !== 'cli-server'] : null,
        ];
    }

    private function serverState(array $def, Component $c): array
    {
        $s = [];
        foreach ($def['server'] as $p) {
            $rp = new \ReflectionProperty($c, $p);
            $s[$p] = self::plain($rp->isInitialized($c) ? $rp->getValue($c) : null, "{$def['name']}::\${$p}");
        }
        return $s;
    }

    /**
     * Значение для состояния: скаляры и массивы как есть; модели, UploadedFile и всё JsonSerializable приводятся к массивам.
     */
    private static function plain(mixed $v, string $what): mixed
    {
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                if (is_object($x) || is_array($x)) {
                    $v[$k] = self::plain($x, $what);
                }
            }
            return $v;
        }
        if (!is_object($v)) {
            return $v;
        }
        if ($v instanceof \JsonSerializable) {
            return json_decode(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true);
        }
        if ($v instanceof \BackedEnum) {
            return $v->value;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }
        throw new \LogicException("{$what}: в состоянии допустимы скаляры, массивы, модели и UploadedFile, а не " . get_class($v) . '. Преобразуйте объект в массив.');
    }

    /** Серверные свойства + серверные computed + ошибки валидации. */
    private function serverView(array $def, Component $c): array
    {
        $s = $this->serverState($def, $c);
        foreach ($def['cserver'] as $m) {
            $s[$m] = self::plain($c->$m(), "{$def['name']}::{$m}()");
        }
        $s['$errors'] = $c->rigErrors();
        return $s;
    }

    private function withComputed(array $def, array $view): array
    {
        foreach ($def['cexpr'] ?: [] as $k => $ast) {
            $view[$k] = Evaluator::run($ast, $view);
        }
        return $view;
    }

    private function sign(array $def, Component $c, array $middleware = []): string
    {
        return Snapshot::sign($def['name'], $this->serverState($def, $c), $this->key, $middleware);
    }

    /**
     * #[On('chat.{roomId}')] с подставленными значениями свойств: [[chat.5, 'chat.{roomId}', подпись], …].
     * По этому списку браузер подписывается в хабе и узнаёт, какому методу отдать событие.
     * Если значение пустое или не скаляр, подписки нет.
     */
    private function topics(array $def, Component $c): array
    {
        $out = [];
        foreach (array_keys($def['on'] ?: []) as $pattern) {
            $pattern = (string)$pattern;
            if (!str_contains($pattern, '{')) {
                continue;
            }
            $ok = true;
            $topic = preg_replace_callback('/\{([^{}]+)\}/', function (array $m) use ($c, &$ok): string {
                $parts = explode('.', $m[1]);
                $root = array_shift($parts);
                $rp = new \ReflectionProperty($c, $root);
                $v = $rp->isInitialized($c) ? self::plain($rp->getValue($c), $root) : null;
                foreach ($parts as $k) {
                    $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null;
                }
                if (!is_int($v) && !is_float($v) && !(is_string($v) && $v !== '')) {
                    $ok = false;
                    return '';
                }
                return (string)$v;
            }, $pattern);
            if ($ok) {
                $out[] = [$topic, $pattern, Snapshot::topic((string)$topic, $this->key)];
            }
        }
        return $out;
    }

    /** Данные компонента для браузера (атрибут r-data). */
    private function data(array $def, Component $c, array $view, bool $lazy = false): string
    {
        $data = ['snap' => $this->sign($def, $c, $this->pageGuards), 'state' => $view];
        if ($lazy) {
            $data['lazy'] = true;
        }
        if ($tp = $this->topics($def, $c)) {
            $data['tp'] = $tp;
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    // ---------------------------------------------------------------- HTTP

    private function action(bool $refresh): void
    {
        $req = Request::current();
        if ($req->method() !== 'POST' || $req->header('X-Rig') !== '1') {
            throw new HttpError(400, 'Ожидается POST-запрос от rig.js');
        }
        if ($refresh && !$this->debug) {
            throw new HttpError(404, 'Не найдено');
        }
        Guards::$context = 'action';
        Csrf::verify($req);
        Guards::run((array)Config::get('middleware', []));
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) {
            throw new HttpError(400, 'Некорректный JSON');
        }
        $name = (string)($in['c'] ?? '');
        $def = $this->compiler->load($name);
        $class = Compiler::classOf($name);
        $c = new $class();
        $rc = new \ReflectionClass($c);
        $action = $in['action'] ?? null;

        // snap = null: компонент появился в браузере (новый элемент r-for и т.п.), берём свежий экземпляр
        $fresh = ($in['snap'] ?? null) === null;
        $pageMw = [];
        $state = [];
        if (!$fresh) {
            [$state, $pageMw] = Snapshot::open($name, (string)$in['snap'], $this->key);
        }
        // middleware страницы, на которой показан компонент, + #[Guard] класса и действия
        Guards::run([...$pageMw, ...($def['guard'] ?? []), ...(is_string($action) ? ($def['aguard'][$action] ?? []) : [])]);
        foreach ($def['server'] as $p) {
            if (array_key_exists($p, $state)) {
                try {
                    $c->$p = Cast::value($rc->getProperty($p)->getType(), $state[$p], "\${$p}");
                } catch (\Throwable) {
                    // тип свойства поменяли в коде (HMR) или временный файл устарел: остаётся значение по умолчанию
                }
            }
        }
        foreach ((array)($in['props'] ?? []) as $p => $v) {
            $p = (string)$p;
            if (!in_array($p, $def['props'] ?? [], true)) {
                if ($refresh) {
                    continue;
                }
                throw new HttpError(403, "У компонента {$name} нет #[Prop] «{$p}»");
            }
            $c->$p = Cast::value($rc->getProperty($p)->getType(), $v, "\${$p}");
        }
        // revalidate: страницу показали из кеша браузера, нужно обновить данные, не сбрасывая состояние;
        // mount(): компонент создан в браузере (fresh) или это догрузка #[Lazy]
        if (!$fresh && !empty($in['revalidate']) && method_exists($c, 'revalidate')) {
            $c->revalidate();
        } elseif (($fresh || !empty($in['mount']) || !empty($in['revalidate'])) && method_exists($c, 'mount')) {
            $c->mount();
        }
        foreach ((array)($in['updates'] ?? []) as $p => $v) {
            $p = (string)$p;
            if (!in_array($p, $def['server'], true) || in_array($p, $def['locked'], true)) {
                if ($refresh) {
                    continue;
                }
                throw new HttpError(403, "Свойство «{$p}» нельзя менять из браузера");
            }
            $c->$p = Cast::value($rc->getProperty($p)->getType(), $v, "\${$p}");
        }

        if (!$refresh && $action !== null) {
            if (!is_string($action) || !isset($def['actions'][$action])) {
                throw new HttpError(403, 'Действие «' . (is_string($action) ? $action : '?') . '» не объявлено через #[Action]');
            }
            $args = Cast::args(new \ReflectionMethod($c, $action), (array)($in['args'] ?? []));
            try {
                $c->$action(...$args);
            } catch (ValidationFailed) {
                // ошибки уже в $errors
            }
        }

        $view = $this->serverView($def, $c); // до подписи: серверные #[Computed] могли поменять состояние
        $out = [
            'snap' => $this->sign($def, $c, $pageMw),
            'state' => $view,
            'events' => $c->rigPullEvents(),
            'emits' => $c->rigPullEmits(),
            'tp' => $this->topics($def, $c),
        ];
        if ($refresh) {
            $this->used[$name] = $def;
            $out['def'] = Compiler::clientDef($def);
            $out['defs'] = $this->clientDefs();
        }
        $this->json($out);
    }

    /**
     * POST: файл из <input type="file" r-model> во временную папку, в ответе подписанная ссылка.
     * GET ?id=…&sig=…: просмотр временной картинки (превью до сохранения).
     */
    private function upload(): void
    {
        $req = Request::current();
        if ($req->isSafe()) {
            UploadedFile::preview((string)$req->query('id', ''), (string)$req->query('sig', ''))->send();
            return;
        }
        if ($req->header('X-Rig') !== '1') {
            throw new HttpError(400, 'Ожидается запрос от rig.js');
        }
        Guards::$context = 'action';
        Csrf::verify($req);
        Guards::run((array)Config::get('middleware', []));
        $name = (string)$req->query('c', '');
        if ($name !== '') {
            Guards::run($this->compiler->load($name)['guard'] ?? []);
        }
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || is_array($f['name'] ?? null)) {
            throw new HttpError(400, 'Ожидается один файл в поле file');
        }
        $this->json(['file' => UploadedFile::stash($f)]);
    }

    /** @return array<string,array> */
    public function clientDefs(): array
    {
        $defs = [];
        foreach ($this->usedComponents() as $name => $def) {
            $defs[$name] = Compiler::clientDef($def);
        }
        return $defs;
    }

    /**
     * Запасной HMR без хаба: запрос висит до изменения файлов (не дольше 20 с).
     * Под php -S держать запрос нельзя (один поток), поэтому там ответ уходит сразу, и браузер этот путь не использует.
     */
    private function watch(): void
    {
        if (!$this->debug) {
            throw new HttpError(404, 'Не найдено');
        }
        $since = (string)($_GET['v'] ?? '');
        set_time_limit(60);
        $deadline = microtime(true) + (PHP_SAPI === 'cli-server' ? 0 : 20);
        do {
            $map = $this->hmr->files();
            $v = $this->hmr->version($map);
            if ($v !== $since) {
                usleep(60_000); // редактор мог ещё не дописать файл
                $map = $this->hmr->files();
                $v = $this->hmr->version($map);
                $this->json(['v' => $v, 'changed' => $this->hmr->diff($since, $map)]);
                return;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);
        $this->json(['v' => $v, 'changed' => []]);
    }

    /**
     * «Открыть в редакторе» со страницы ошибки: сервер разработки сам запускает редактор
     * (code --goto файл:строка), поэтому неважно, пускает ли браузер ссылки vscode://.
     * Только debug, только POST от rig.js (заголовок X-Rig не отправит чужой сайт), только файлы проекта.
     */
    private function openInEditor(): void
    {
        if (!$this->debug || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_RIG'] ?? '') !== '1') {
            throw new HttpError(404, 'Не найдено');
        }
        $in = json_decode((string)file_get_contents('php://input'), true);
        $file = (string)($in['file'] ?? '');
        $line = max(1, (int)($in['line'] ?? 1));
        $root = (string)realpath($this->root);
        $path = realpath(preg_match('~^([a-z]:)?[\\\\/]~i', $file) ? $file : "{$root}/{$file}");
        if ($path === false || !is_file($path) || !str_starts_with(strtolower($path), strtolower($root) . DIRECTORY_SEPARATOR)) {
            throw new HttpError(403, 'Открывать можно только файлы проекта');
        }
        $editor = (string)(getenv('RIG_EDITOR') ?: $this->editor);
        if (!preg_match('/^[\w.\-]+$/', $editor)) {
            throw new HttpError(400, 'Некорректное имя редактора');
        }
        $this->json(['ok' => true, 'editor' => $editor, 'via' => Editor::open($editor, $path, $line)]);
    }

    /** Модуль для r-js: app/js/{имя}.js как ES-модуль. */
    private function jsModule(string $rel): void
    {
        if (!preg_match('~^[a-z0-9][\w\-/]*\.js$~i', $rel) || str_contains($rel, '..')) {
            throw new HttpError(404, 'Не найдено');
        }
        $file = "{$this->appDir}/js/{$rel}";
        if (!is_file($file)) {
            throw new HttpError(404, "Нет модуля app/js/{$rel}");
        }
        header('Content-Type: text/javascript; charset=utf-8');
        header('Cache-Control: ' . ($this->debug ? 'no-cache' : 'public, max-age=31536000, immutable'));
        readfile($file);
    }

    /** Версия модулей app/js. Меняется вместе с файлами (браузер не держит старые). */
    public function jsVersion(): string
    {
        $files = glob("{$this->appDir}/js/*.js") ?: [];
        return $files ? substr(md5(implode('|', array_map(static fn ($f) => $f . filemtime($f), $files))), 0, 8) : '';
    }

    private function runtime(): void
    {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: ' . ($this->debug ? 'no-cache' : 'public, max-age=31536000, immutable'));
        readfile(dirname(__DIR__) . '/runtime/rig.js');
    }

    /**
     * API-маршрут: app/api/todos.php отвечает на /api/todos, app/api/todos/[id].php на /api/todos/5.
     * Файл возвращает обработчики по методам, одну функцию на все методы или просто данные для GET:
     *
     *   return [
     *       'middleware' => ['auth'],
     *       'GET' => fn () => Todo::all(),
     *       'POST' => function (Request $request) { … return Response::json($todo, 201); },
     *   ];
     *
     * Аргументы обработчика подставляются по типу и имени: Request $request, array $params, int $id (из [id]).
     * Массив, модель или список отдаются как JSON 200, null как 204, Response как есть.
     */
    private function api(string $path): void
    {
        Guards::$context = 'api';
        $req = Request::current();
        $route = $this->route("{$this->appDir}/api", trim(substr($path, 4), '/'));
        if ($route === null) {
            throw new HttpError(404, 'Нет такого API-маршрута');
        }
        [$file, $params] = $route;
        // CSRF проверяется для запросов браузера с cookie сессии; внешним клиентам (без cookie) он не нужен
        if (!$req->isSafe() && Session::present()) {
            Csrf::verify($req);
        }
        Guards::run((array)Config::get('middleware', []));
        $def = (static fn (string $__file) => require $__file)($file);
        $method = $req->method() === 'HEAD' ? 'GET' : $req->method();
        $isMap = is_array($def) && $def !== [] && array_diff(array_keys($def), [...self::API_METHODS, 'middleware']) === []
            && array_intersect(array_keys($def), self::API_METHODS) !== [];
        if ($def instanceof \Closure) {
            $handler = $def;
        } elseif ($isMap) {
            Guards::run((array)($def['middleware'] ?? []));
            $handler = $def[$method] ?? null;
            if (!$handler instanceof \Closure) {
                $allow = implode(', ', array_values(array_intersect(self::API_METHODS, array_keys($def))));
                header("Allow: {$allow}");
                throw new HttpError(405, "Метод {$method} не поддерживается. Можно: {$allow}");
            }
        } else {
            if ($method !== 'GET') {
                header('Allow: GET');
                throw new HttpError(405, "Метод {$method} не поддерживается. Можно: GET");
            }
            $handler = static fn () => $def;
        }
        $result = $handler(...$this->handlerArgs($handler, $req, $params));
        $response = match (true) {
            $result instanceof Response => $result,
            $result === null => Response::noContent(),
            default => Response::json($result),
        };
        $response->send();
    }

    private function handlerArgs(\Closure $fn, Request $req, array $params): array
    {
        $args = [];
        foreach ((new \ReflectionFunction($fn))->getParameters() as $p) {
            $type = $p->getType();
            $tn = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            $n = $p->getName();
            $args[] = match (true) {
                $tn === Request::class => $req,
                $n === 'params' && $tn === 'array' => $params,
                array_key_exists($n, $params) => Cast::value($type, $params[$n], "параметр {$n}"),
                $p->isDefaultValueAvailable() => $p->getDefaultValue(),
                $p->allowsNull() => null,
                default => throw new \LogicException("Обработчику API нечего передать в \${$n}: ожидаются Request \$request, array \$params или параметр из адреса"),
            };
        }
        return $args;
    }

    private function page(string $path): void
    {
        Guards::$context = 'page';
        $req = Request::current();
        $route = $this->route("{$this->appDir}/pages", trim($path, '/'));
        if ($route === null) {
            throw new HttpError(404, 'Страница не найдена');
        }
        if (!$req->isSafe()) {
            Csrf::verify($req); // обычная HTML-форма с method="post" и csrf_field()
        }
        Guards::run((array)Config::get('middleware', []));
        [$file, $params] = $route;
        $this->renderPage($file, ['params' => $params, 'request' => $req]);
    }

    /**
     * Файл маршрута в папке: точное имя, затем index.php в папке, затем [параметр].php или папка [параметр].
     * @return array{0:string,1:array<string,string>}|null
     */
    private function route(string $base, string $route): ?array
    {
        $segs = $route === '' ? [] : explode('/', $route);
        foreach ($segs as $s) {
            if (!preg_match('/^[\p{L}\p{N}_\-][\p{L}\p{N}_.\-]*$/u', $s)) {
                return null;
            }
        }
        return is_dir($base) ? $this->walk($base, $segs, []) : null;
    }

    private function walk(string $dir, array $segs, array $params): ?array
    {
        if (!$segs) {
            return is_file("{$dir}/index.php") ? ["{$dir}/index.php", $params] : null;
        }
        $s = array_shift($segs);
        if (!$segs && is_file("{$dir}/{$s}.php")) {
            return ["{$dir}/{$s}.php", $params];
        }
        if (is_dir("{$dir}/{$s}") && ($r = $this->walk("{$dir}/{$s}", $segs, $params))) {
            return $r;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if (!preg_match('/^\[(\w+)\](\.php)?$/', $name, $m)) {
                continue;
            }
            $p = $params + [$m[1] => $s];
            if (($m[2] ?? '') !== '') {
                if (!$segs) {
                    return ["{$dir}/{$name}", $p];
                }
            } elseif (is_dir("{$dir}/{$name}") && ($r = $this->walk("{$dir}/{$name}", $segs, $p))) {
                return $r;
            }
        }
        return null;
    }

    /** Страница (или страница ошибки) внутри layout; при переходе без перезагрузки отдаётся JSON. */
    private function renderPage(string $file, array $extra = []): void
    {
        $this->pageGuards = [];
        Head::reset();
        [$content, $vars] = self::capture($file, $extra + ['layout' => 'default', 'title' => '']);
        $this->layout = (string)$vars['layout'];
        Head::title((string)$vars['title']);
        $markup = new Markup($this);
        $content = $markup->expand($content);

        if (isset($_SERVER['HTTP_X_RIG_NAV'])) {
            // переход без перезагрузки: только содержимое страницы, layout остаётся в браузере
            $styles = [];
            foreach ($this->usedComponents() as $name => $def) {
                $styles[$name] = $def['css'];
            }
            $this->sendCached((string)json_encode([
                'title' => Head::fullTitle(),
                'head' => Head::render(),
                'layout' => $this->layout,
                'html' => $content,
                'styles' => $styles,
                'defs' => $this->clientDefs(),
                'hub' => $this->hubConfig(),
                'csrf' => Csrf::token(),
                'i18n' => I18n::client(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 'application/json');
            return;
        }

        $html = $content;
        if ($this->layout !== '') {
            // страница уже отрендерена; в layout она попадает меткой, чтобы не разбирать её второй раз
            $mark = "\x02rig:page\x02";
            [$html] = self::capture("{$this->appDir}/layouts/{$this->layout}.php", ['content' => $mark, 'title' => $vars['title']]);
            $html = str_replace($mark, "<div r-page style=\"display:contents\">{$content}</div>", $markup->expand($html));
        }
        // стили и описания компонентов подставляем, когда известны все компоненты, в том числе из layout
        $html = str_replace([Page::STYLES, Page::SCRIPTS, Page::HEAD], [Page::renderStyles(), Page::renderScripts(), Head::render()], $html);
        $this->sendCached($html, 'text/html');
    }

    /**
     * Ответ с ETag: если у браузера уже есть ровно такая же страница, отвечаем пустым 304:
     * это экономит трафик и разбор в браузере. Рендер детерминирован (подписи, id, токены), поэтому
     * пока данные не менялись, ETag тот же. С private персональные страницы не попадут в общие кеши.
     */
    private function sendCached(string $body, string $type): void
    {
        Session::commit();
        header("Content-Type: {$type}; charset=utf-8");
        header('Vary: X-Rig-Nav, X-Rig-Keep, Cookie');
        if (http_response_code() !== 200) {
            header('Cache-Control: no-store');
            echo $body;
            return;
        }
        $etag = '"' . substr(hash('xxh128', $body), 0, 20) . '"';
        header("ETag: {$etag}");
        header('Cache-Control: private, no-cache');
        $match = array_map('trim', explode(',', (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')));
        if (in_array($etag, $match, true) || in_array("W/{$etag}", $match, true)) {
            http_response_code(304);
            return;
        }
        echo $body;
    }

    private static function capture(string $__file, array $__vars): array
    {
        extract($__vars);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return [(string)ob_get_clean(), ['layout' => $layout ?? '', 'title' => $title ?? '']];
    }

    private function json(array $data, int $status = 0): void
    {
        Session::commit();
        if (!headers_sent()) {
            if ($status > 0) {
                http_response_code($status);
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** redirect(): обычный запрос получает 302 Location, rig.js (переход, действие) получает JSON, и браузер переходит сам. */
    private function redirect(Redirect $r, bool $json): void
    {
        while (ob_get_level() > $this->obBase) {
            ob_end_clean();
        }
        if ($json) {
            // после входа или выхода меняется и layout (меню, имя), поэтому браузер загрузит страницу целиком
            $this->json(['redirect' => $r->to] + (Auth::changed() || I18n::changed() ? ['reload' => true] : []));
            return;
        }
        Session::commit();
        if (!headers_sent()) {
            http_response_code($r->status);
            header('Location: ' . $r->to);
            header('Cache-Control: no-store');
        }
    }

    private function fail(\Throwable $e, bool $json, bool $api = false): void
    {
        if ($e instanceof Db\RecordNotFound) {
            $e = new HttpError(404, $e->getMessage(), $e); // findOrFail() в странице, действии или API
        }
        $status = $e instanceof HttpError ? $e->status : 500;
        if (!headers_sent()) {
            http_response_code($status);
        }
        while (ob_get_level() > $this->obBase) {
            ob_end_clean();
        }
        if ($status >= 500) {
            error_log('[rig] ' . $e);
            Log::error($e, ['url' => (string)($_SERVER['REQUEST_URI'] ?? ''), 'method' => (string)($_SERVER['REQUEST_METHOD'] ?? '')]);
        }

        if ($api) {
            // API всегда отвечает JSON; подробности 5xx только в debug
            $error = ['message' => $status >= 500 && !$this->debug ? 'Ошибка сервера' : $e->getMessage()];
            if ($this->debug && $status >= 500) {
                $error += ['type' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine()];
            }
            $body = ['error' => $error];
            if ($e instanceof ValidationError) {
                $body['errors'] = $e->errors;
            }
            $this->json($body, $status);
            return;
        }

        // своя страница ошибки приложения: app/errors/404.php, app/errors/500.php или общая app/errors/error.php.
        // В debug ошибки 5xx показывает страница разработчика (с кодом и стеком), остальные показывает страница приложения.
        $page = $this->errorPage($status);
        if ($page !== null && (!$json || $this->nav) && ($status < 500 || !$this->debug)) {
            try {
                $this->used = [];
                $this->renderPage($page, [
                    'status' => $status,
                    'message' => $e instanceof HttpError ? $e->getMessage() : null,
                    'title' => (string)$status,
                ]);
                return;
            } catch (\Throwable $inner) {
                while (ob_get_level() > $this->obBase) {
                    ob_end_clean();
                }
                error_log('[rig] ошибка в странице ошибки: ' . $inner);
                if ($this->debug) {
                    $e = $inner;
                    $status = 500;
                }
            }
        }

        $file = str_replace('\\', '/', $e->getFile());
        $rel = str_starts_with($file, str_replace('\\', '/', $this->root) . '/') ? substr($file, strlen($this->root) + 1) : $file;
        if ($json) {
            $error = $this->debug || $e instanceof HttpError
                ? ['message' => $e->getMessage(), 'type' => (new \ReflectionClass($e))->getShortName(), 'file' => $this->debug ? $rel : null, 'line' => $this->debug ? $e->getLine() : null]
                : ['message' => 'Ошибка сервера'];
            $error['status'] = $status;
            if ($this->debug && !$e instanceof HttpError) {
                // для окна ошибки в браузере: заголовок, подсказка, открыть в редакторе, копирование и промпт для ИИ
                try {
                    $d = ErrorPage::details($e, $this);
                    $main = $d['origin'] ?? ['file' => $d['file'], 'abs' => $d['abs'], 'line' => $d['line']];
                    $error += ['kind' => $d['kind'], 'hint' => $d['hintText'], 'abs' => $main['abs'], 'text' => $d['text'], 'prompt' => $d['prompt']];
                    $error['file'] = $main['file'];
                    $error['line'] = $main['line'];
                } catch (\Throwable) {
                    // без подробностей окно покажет сообщение
                }
            }
            $this->json(['error' => $error]);
            return;
        }
        Session::commit();
        if (!$this->debug) {
            header('Content-Type: text/html; charset=utf-8');
            echo $status >= 500 ? 'Ошибка сервера' : Renderer::e($e->getMessage());
            return;
        }
        try {
            echo ErrorPage::render($e, $rel, $e instanceof CompileError ? 'Ошибка в компоненте' : (new \ReflectionClass($e))->getShortName(), $this);
        } catch (\Throwable $inner) {
            // упала сама страница ошибки, показываем исходную ошибку простым текстом
            header('Content-Type: text/plain; charset=utf-8');
            echo "{$e->getMessage()}\n{$rel}:{$e->getLine()}\n\n(страница ошибки не отрисовалась: {$inner->getMessage()})";
        }
    }

    private function errorPage(int $status): ?string
    {
        foreach (["{$this->appDir}/errors/{$status}.php", "{$this->appDir}/errors/error.php"] as $f) {
            if (is_file($f)) {
                return $f;
            }
        }
        return null;
    }
}
