<?php

declare(strict_types=1);

namespace Rig;

/**
 * Хаб: держит SSE-соединения браузеров и шлёт им два вида сообщений.
 *
 *   {"type":"hmr","v":…,"changed":[…]}  изменились файлы проекта (только в режиме разработки)
 *   {"type":"push","e":…,"d":…}         событие с сервера: Component::broadcast() / Push::publish()
 *
 * События приходят через storage/push.log: PHP-запросы дописывают строки, хаб читает новые.
 * Браузер подключается так: GET /?id=<страница>&t=<токен подписки>&v=<версия HMR>.
 * Токен подписан ключом приложения и содержит список событий, на которые есть #[On] у компонентов страницы.
 */
final class Hub
{
    private const ROTATE_AT = 1 << 20;

    /** @var resource */
    private $server;
    /** @var array<int,array{s:resource,id:string,topics:array<string,true>}> */
    private array $clients = [];
    private readonly string $key;
    private readonly string $pushFile;
    private int $offset;
    private ?Hmr $hmr = null;
    private array $map = [];
    private string $v = '';
    /** @var \Closure(string $tag, string $text):void метки: hmr, push, tab */
    private \Closure $log;

    public function __construct(string $root, int $port, bool $hmr, ?\Closure $log = null, string $host = '127.0.0.1')
    {
        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        if (!$server) {
            throw new \RuntimeException("Не удалось занять порт {$port}: {$errstr}");
        }
        stream_set_blocking($server, false);
        $this->server = $server;
        $this->log = $log ?? static function (string $tag, string $text): void {
        };

        $storage = "{$root}/storage";
        if (!is_dir("{$storage}/hmr")) {
            mkdir("{$storage}/hmr", 0777, true);
        }
        $this->key = App::key($storage);
        $this->pushFile = "{$storage}/push.log";
        clearstatcache();
        $this->offset = is_file($this->pushFile) ? (int)filesize($this->pushFile) : 0;
        if ($hmr) {
            $this->hmr = new Hmr($root, "{$storage}/hmr");
            $this->map = $this->hmr->files();
            $this->v = $this->hmr->version($this->map);
        }
    }

    /**
     * @param DevProxy|null $proxy в режиме разработки раздаёт запросы воркерам php -S в том же цикле
     * @param \Closure|null $tick  фоновая работа rig dev (очередь, планировщик), вызывается на каждом проходе цикла
     */
    public function run(?DevProxy $proxy = null, ?\Closure $tick = null): never
    {
        $lastScan = $lastPush = 0.0;
        $lastPing = time();
        while (true) {
            $read = [$this->server];
            foreach ($this->clients as $c) {
                $read[] = $c['s'];
            }
            $write = [];
            if ($proxy !== null) {
                [$pr, $pw] = $proxy->streams();
                array_push($read, ...$pr);
                array_push($write, ...$pw);
            }
            $except = null;
            $writeArg = $write ?: null;
            if (@stream_select($read, $writeArg, $except, 0, $write ? 10_000 : 50_000) === false) {
                usleep(50_000);
                $read = [];
                $writeArg = [];
            }
            $proxyRead = [];
            foreach ($read as $r) {
                if ($r === $this->server) {
                    $this->accept();
                } elseif (isset($this->clients[(int)$r])) {
                    if (($data = @fread($r, 1024)) === '' || $data === false || feof($r)) {
                        $this->drop((int)$r); // вкладку закрыли или перезагрузили
                    }
                } else {
                    $proxyRead[] = $r;
                }
            }
            if ($proxy !== null) {
                $proxy->handle($proxyRead, $writeArg ?? []);
            }
            if ($tick !== null) {
                $tick();
            }
            $now = microtime(true);
            if ($now - $lastPush >= 0.1) {
                $lastPush = $now;
                $this->tail();
            }
            if ($this->hmr !== null && $now - $lastScan >= 0.25) {
                $lastScan = $now;
                $this->scan();
            }
            if (time() - $lastPing >= 15) {
                $lastPing = time();
                foreach (array_keys($this->clients) as $id) {
                    $this->send($id, ": ping\n\n");
                }
            }
        }
    }

    private function accept(): void
    {
        $s = @stream_socket_accept($this->server, 0);
        if (!$s) {
            return;
        }
        // принятый сокет наследует неблокирующий режим: запрос браузера может прийти чуть позже
        stream_set_blocking($s, true);
        stream_set_timeout($s, 2);
        $line = (string)fgets($s);
        while (($h = fgets($s)) !== false && trim($h) !== '') {
        }
        parse_str((string)parse_url(explode(' ', $line)[1] ?? '/', PHP_URL_QUERY), $q);
        $topics = [];
        try {
            foreach (Snapshot::verify('hub', (string)($q['t'] ?? ''), $this->key)['topics'] ?? [] as $t) {
                $topics[(string)$t] = true;
            }
        } catch (\Throwable) {
            // без токена: только HMR
        }
        // события с параметрами (#[On('chat.{roomId}')]): каждое имя со своей подписью, x=[["chat.5","подпись"],…]
        foreach ((array)json_decode((string)($q['x'] ?? ''), true) as $pair) {
            if (is_array($pair) && is_string($pair[0] ?? null) && is_string($pair[1] ?? null)
                && hash_equals(Snapshot::topic($pair[0], $this->key), $pair[1])) {
                $topics[$pair[0]] = true;
            }
        }
        stream_set_blocking($s, false);
        $id = (int)$s;
        $this->clients[$id] = ['s' => $s, 'id' => (string)($q['id'] ?? ''), 'topics' => $topics];
        ($this->log)('tab', 'вкладка подключилась, всего: ' . count($this->clients) . ($topics ? ' · события: ' . implode(', ', array_keys($topics)) : ''));
        $this->send($id, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-cache\r\n"
            . "Connection: keep-alive\r\nAccess-Control-Allow-Origin: *\r\n\r\nretry: 2000\n\n");
        $since = (string)($q['v'] ?? '');
        if ($this->hmr !== null && $since !== '' && $since !== $this->v) {
            // файлы поменялись, пока вкладка переподключалась
            $this->send($id, self::msg(['type' => 'hmr', 'v' => $this->v, 'changed' => $this->hmr->diff($since, $this->map)]));
        }
    }

    private function scan(): void
    {
        $now = $this->hmr->files();
        if ($now === $this->map) {
            return;
        }
        usleep(60_000); // редактор мог ещё не дописать файл
        $now = $this->hmr->files();
        $changed = $this->hmr->diff($this->v, $now);
        $this->map = $now;
        $this->v = $this->hmr->version($now);
        $msg = self::msg(['type' => 'hmr', 'v' => $this->v, 'changed' => $changed]);
        foreach (array_keys($this->clients) as $id) {
            $this->send($id, $msg);
        }
        $what = [];
        foreach ($changed as $f) {
            $what[] = match (true) {
                (bool)preg_match('~^app/components/(.+)\.php$~', $f, $m) => "компонент {$m[1]}",
                (bool)preg_match('~^app/pages/(.+)\.php$~', $f, $m) => 'страница /' . ($m[1] === 'index' ? '' : $m[1]),
                default => "{$f}, перезагрузка",
            };
        }
        $tabs = count($this->clients);
        ($this->log)('hmr', implode(', ', $what) . " · вкладок: {$tabs}");
    }

    /** Разослать новые строки storage/push.log подписанным страницам, кроме отправителя. */
    private function tail(): void
    {
        clearstatcache(true, $this->pushFile);
        $size = is_file($this->pushFile) ? (int)filesize($this->pushFile) : 0;
        if ($size < $this->offset) {
            $this->offset = 0;
        }
        if ($size === $this->offset) {
            if ($size > self::ROTATE_AT) {
                $this->rotate();
            }
            return;
        }
        $f = @fopen($this->pushFile, 'rb');
        if (!$f) {
            return;
        }
        fseek($f, $this->offset);
        $chunk = (string)stream_get_contents($f);
        fclose($f);
        $end = strrpos($chunk, "\n");
        if ($end === false) {
            return; // строка ещё дописывается
        }
        $this->offset += $end + 1;
        foreach (explode("\n", substr($chunk, 0, $end)) as $line) {
            $m = json_decode($line, true);
            if (!is_array($m) || !isset($m['e'])) {
                continue;
            }
            $msg = self::msg(['type' => 'push', 'e' => $m['e'], 'd' => $m['d'] ?? null]);
            $sent = 0;
            foreach ($this->clients as $id => $c) {
                if (isset($c['topics'][$m['e']]) && $c['id'] !== ($m['from'] ?? null)) {
                    $this->send($id, $msg);
                    $sent++;
                }
            }
            ($this->log)('push', "{$m['e']} · вкладок: {$sent}");
        }
    }

    private function rotate(): void
    {
        $f = @fopen($this->pushFile, 'c+');
        if (!$f) {
            return;
        }
        if (flock($f, LOCK_EX)) {
            clearstatcache(true, $this->pushFile);
            if ((int)filesize($this->pushFile) === $this->offset) {
                ftruncate($f, 0);
                $this->offset = 0;
            }
            flock($f, LOCK_UN);
        }
        fclose($f);
    }

    private function send(int $id, string $chunk): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        if (@fwrite($this->clients[$id]['s'], $chunk) === false) {
            $this->drop($id);
        }
    }

    private function drop(int $id): void
    {
        if (isset($this->clients[$id])) {
            @fclose($this->clients[$id]['s']);
            unset($this->clients[$id]);
            ($this->log)('tab', 'вкладка отключилась, всего: ' . count($this->clients));
        }
    }

    private static function msg(array $data): string
    {
        return 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }
}
