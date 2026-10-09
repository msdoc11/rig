<?php

declare(strict_types=1);

namespace Rig;

/**
 * Прокси для режима разработки: принимает запросы на основном порту и раздаёт их
 * нескольким воркерам php -S (по одному запросу на воркер).
 *
 * Встроенный сервер PHP однопоточный, а на Windows PHP_CLI_SERVER_WORKERS не работает:
 * без прокси медленный запрос (ленивая загрузка, тяжёлое действие) задерживает все остальные,
 * в том числе переход на другую страницу. С прокси запросы обрабатываются параллельно.
 */
final class DevProxy
{
    private const CHUNK = 65536;

    /** @var resource */
    private $server;
    /** @var list<array{port:int, busy:bool, proc:resource}> */
    private array $workers = [];
    /**
     * @var array<int, array{client:resource, up:?resource, worker:?int, head:string, need:?int,
     *   toUp:string, toClient:string, upDone:bool, clientGone:bool}>
     */
    private array $conns = [];
    /** @var array<int,int> id сокета воркера => id соединения */
    private array $upIndex = [];
    /** @var list<int> соединения с полным запросом, ждущие свободного воркера */
    private array $queue = [];

    /** @var \Closure(string $tag, string $text):void */
    private \Closure $log;

    public function __construct(string $root, int $port, int $workers, ?\Closure $log = null, string $host = '127.0.0.1', ?\Closure $tick = null)
    {
        $this->log = $log ?? static function (string $tag, string $text): void {
        };
        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        if (!$server) {
            throw new \RuntimeException("Не удалось занять порт {$port}: {$errstr}");
        }
        stream_set_blocking($server, false);
        $this->server = $server;

        for ($i = 0; $i < $workers; $i++) {
            $this->workers[] = $this->spawn($root);
        }
        register_shutdown_function(function (): void {
            foreach ($this->workers as $w) {
                @proc_terminate($w['proc']);
            }
        });

        // Ждём, пока воркеры откроют порты (примерно от 0,1 до 0,2 с). Свободный порт заранее не ищем: на Windows закрытие
        // пробного сокета стоит ~0,5 с на воркер. Берём случайный; если он занят, php -S сразу завершится,
        // и воркер перезапускается на другом.
        $deadline = microtime(true) + 8;
        $pending = array_keys($this->workers);
        while ($pending && microtime(true) < $deadline) {
            for ($i = 0; $i < 2; $i++) {
                $tick && $tick();
                usleep(40_000);
            }
            foreach ($pending as $k => $wi) {
                $w = $this->workers[$wi];
                if (!proc_get_status($w['proc'])['running']) {
                    $this->workers[$wi] = $this->spawn($root);
                    continue;
                }
                $s = @stream_socket_client("tcp://127.0.0.1:{$w['port']}", $e1, $e2, 0.05);
                if ($s) {
                    fclose($s);
                    unset($pending[$k]);
                }
            }
        }
        if ($pending) {
            throw new \RuntimeException('Воркеры PHP не запустились за 8 с');
        }
    }

    /** @return array{port:int, busy:bool, proc:resource} */
    private function spawn(string $root): array
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $port = random_int(20000, 60000);
        $proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', "{$root}/public", "{$root}/public/index.php"],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            $root,
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException('Не удалось запустить php -S');
        }
        return ['port' => $port, 'busy' => false, 'proc' => $proc];
    }

    /** @return array{0: list<resource>, 1: list<resource>} что ждать на чтение и на запись */
    public function streams(): array
    {
        $read = [$this->server];
        $write = [];
        foreach ($this->conns as $c) {
            if (!$c['clientGone']) {
                $read[] = $c['client'];
            }
            if ($c['toClient'] !== '' && !$c['clientGone']) {
                $write[] = $c['client'];
            }
            if ($c['up'] !== null) {
                if (!$c['upDone']) {
                    $read[] = $c['up'];
                }
                if ($c['toUp'] !== '') {
                    $write[] = $c['up'];
                }
            }
        }
        return [$read, $write];
    }

    /** @param list<resource> $read @param list<resource> $write */
    public function handle(array $read, array $write): void
    {
        foreach ($read as $s) {
            $id = (int)$s;
            if ($s === $this->server) {
                $this->accept();
            } elseif (isset($this->upIndex[$id])) {
                $this->readUp($this->upIndex[$id]);
            } elseif (isset($this->conns[$id])) {
                $this->readClient($id);
            }
        }
        foreach ($write as $s) {
            $id = (int)$s;
            if (isset($this->upIndex[$id])) {
                $cid = $this->upIndex[$id];
                $n = @fwrite($s, $this->conns[$cid]['toUp']);
                if ($n) {
                    $this->conns[$cid]['toUp'] = (string)substr($this->conns[$cid]['toUp'], $n);
                }
            } elseif (isset($this->conns[$id])) {
                $n = @fwrite($s, $this->conns[$id]['toClient']);
                if ($n === false) {
                    $this->conns[$id]['clientGone'] = true;
                } elseif ($n) {
                    $this->conns[$id]['toClient'] = (string)substr($this->conns[$id]['toClient'], $n);
                }
            }
        }
        $this->dispatch();
        $this->finish();
    }

    private function accept(): void
    {
        $s = @stream_socket_accept($this->server, 0);
        if (!$s) {
            return;
        }
        stream_set_blocking($s, false);
        $this->conns[(int)$s] = [
            'client' => $s, 'up' => null, 'worker' => null, 'head' => '', 'need' => null,
            'toUp' => '', 'toClient' => '', 'upDone' => false, 'clientGone' => false,
            'req' => '', 'start' => 0.0, 'status' => 0,
        ];
    }

    private function readClient(int $id): void
    {
        $c = &$this->conns[$id];
        $data = @fread($c['client'], self::CHUNK);
        if ($data === '' || $data === false) {
            if (feof($c['client'])) {
                // браузер закрыл соединение (ушли со страницы): ответ воркера дочитаем и выбросим
                $c['clientGone'] = true;
                if ($c['up'] === null) {
                    $this->queue = array_values(array_diff($this->queue, [$id]));
                }
            }
            return;
        }
        if ($c['up'] !== null || in_array($id, $this->queue, true)) {
            return; // запрос уже отправлен; новые данные по keep-alive не ждём, отвечаем с Connection: close
        }
        $c['head'] .= $data;
        if ($c['need'] === null) {
            $end = strpos($c['head'], "\r\n\r\n");
            if ($end === false) {
                return;
            }
            $headers = substr($c['head'], 0, $end);
            $length = preg_match('/^content-length:\s*(\d+)/im', $headers, $m) ? (int)$m[1] : 0;
            $c['req'] = (string)preg_replace('~ HTTP/[\d.]+$~', '', strtok($headers, "\r\n"));
            $c['start'] = microtime(true);
            // один запрос на соединение: воркер закроет его после ответа, браузер переподключится
            $headers = (string)preg_replace('/^connection:.*\r\n?/im', '', $headers . "\r\n");
            $c['head'] = rtrim($headers, "\r\n") . "\r\nConnection: close\r\n\r\n" . substr($c['head'], $end + 4);
            $c['need'] = (int)strpos($c['head'], "\r\n\r\n") + 4 + $length;
        }
        if (strlen($c['head']) >= $c['need']) {
            $this->queue[] = $id;
        }
    }

    private function dispatch(): void
    {
        while ($this->queue) {
            $w = null;
            foreach ($this->workers as $i => $worker) {
                if (!$worker['busy']) {
                    $w = $i;
                    break;
                }
            }
            if ($w === null) {
                return; // все воркеры заняты, запрос подождёт
            }
            $id = array_shift($this->queue);
            $up = @stream_socket_client("tcp://127.0.0.1:{$this->workers[$w]['port']}", $errno, $errstr, 2);
            if (!$up) {
                $this->conns[$id]['toClient'] = "HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
                $this->conns[$id]['upDone'] = true;
                continue;
            }
            stream_set_blocking($up, false);
            $this->workers[$w]['busy'] = true;
            $this->conns[$id]['up'] = $up;
            $this->conns[$id]['worker'] = $w;
            $this->conns[$id]['toUp'] = $this->conns[$id]['head'];
            $this->upIndex[(int)$up] = $id;
        }
    }

    private function readUp(int $id): void
    {
        $c = &$this->conns[$id];
        $data = @fread($c['up'], self::CHUNK);
        if ($data !== '' && $data !== false) {
            if ($c['status'] === 0 && preg_match('~^HTTP/[\d.]+ (\d{3})~', $data, $m)) {
                $c['status'] = (int)$m[1];
            }
            if (!$c['clientGone']) {
                $c['toClient'] .= $data;
            }
            return;
        }
        if (feof($c['up'])) {
            // воркер ответил и закрыл соединение, значит, он свободен
            unset($this->upIndex[(int)$c['up']]);
            @fclose($c['up']);
            $c['up'] = null;
            $c['upDone'] = true;
            $this->workers[$c['worker']]['busy'] = false;
            // в лог пишем только ошибки сервера и медленные ответы
            $time = microtime(true) - $c['start'];
            if ($c['status'] >= 500 || $time >= 1.0) {
                $t = $time < 1 ? round($time * 1000) . ' ms' : number_format($time, 2, '.', '') . ' s';
                ($this->log)($c['status'] >= 500 ? 'error' : 'http', "{$c['req']} {$c['status']} · {$t}");
            }
        }
    }

    /** Закрыть соединения, по которым всё отправлено. */
    private function finish(): void
    {
        foreach ($this->conns as $id => $c) {
            $done = $c['upDone'] && $c['up'] === null && ($c['toClient'] === '' || $c['clientGone']);
            $abandoned = $c['clientGone'] && $c['up'] === null && !$c['upDone'] && !in_array($id, $this->queue, true);
            if ($done || $abandoned) {
                @fclose($c['client']);
                unset($this->conns[$id]);
            }
        }
    }
}
