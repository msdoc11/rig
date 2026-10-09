<?php

declare(strict_types=1);

namespace Rig;

/**
 * Фоновая работа rig dev в том же цикле, что хаб и сервер приложения:
 *
 *   очередь:      если есть готовые задачи, запускается отдельный процесс rig queue:work --until-empty
 *                 (каждая пачка идёт в новом процессе, поэтому правки в app/jobs подхватываются без перезапуска)
 *   планировщик:  в начале каждой минуты rig schedule:run, если есть app/schedule.php
 *
 * Вывод процессов попадает в консоль rig dev строками [queue] и [cron].
 */
final class DevTasks
{
    /** @var array<string,array{proc:resource,out:resource,buf:string}> */
    private array $procs = [];
    private float $lastQueueCheck = 0;
    private int $lastMinute;

    /** @param \Closure(string,string):void $log */
    public function __construct(private readonly string $root, private readonly \Closure $log)
    {
        $this->lastMinute = intdiv(time(), 60);
        register_shutdown_function(function (): void {
            foreach ($this->procs as $p) {
                @proc_terminate($p['proc']);
            }
        });
    }

    /** Вызывается из цикла хаба (~20 раз в секунду). */
    public function tick(): void
    {
        foreach ($this->procs as $name => $p) {
            $this->drain($name);
        }
        $now = microtime(true);
        if ($now - $this->lastQueueCheck >= 1.0) {
            $this->lastQueueCheck = $now;
            if (!isset($this->procs['queue']) && $this->queueReady()) {
                $this->spawn('queue', ['queue:work', '--until-empty']);
            }
        }
        $minute = intdiv(time(), 60);
        if ($minute !== $this->lastMinute) {
            $this->lastMinute = $minute;
            if (!isset($this->procs['cron']) && is_file("{$this->root}/app/schedule.php")) {
                $this->spawn('cron', ['schedule:run']);
            }
        }
    }

    /** Есть ли задача, которой пора выполняться (дешёвая проверка по именам файлов). */
    private function queueReady(): bool
    {
        foreach (glob("{$this->root}/storage/queue/*/pending/*.json") ?: [] as $f) {
            if ((int)basename($f) <= time()) {
                return true;
            }
        }
        return false;
    }

    private function spawn(string $name, array $args): void
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = proc_open([PHP_BINARY, "{$this->root}/rig/cli.php", ...$args, '--plain'],
            [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->root, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            ($this->log)('error', "не удалось запустить rig " . implode(' ', $args));
            return;
        }
        stream_set_blocking($pipes[1], false);
        $this->procs[$name] = ['proc' => $proc, 'out' => $pipes[1], 'buf' => ''];
    }

    /** Прочитать вывод процесса построчно; если процесс завершился, убрать его. */
    private function drain(string $name): void
    {
        $p = &$this->procs[$name];
        while (($chunk = fread($p['out'], 8192)) !== false && $chunk !== '') {
            $p['buf'] .= $chunk;
        }
        while (($pos = strpos($p['buf'], "\n")) !== false) {
            $line = rtrim(substr($p['buf'], 0, $pos));
            $p['buf'] = substr($p['buf'], $pos + 1);
            if ($line !== '') {
                ($this->log)($name, $line);
            }
        }
        $status = proc_get_status($p['proc']);
        if (!$status['running']) {
            if (trim($p['buf']) !== '') {
                ($this->log)($name, trim($p['buf']));
            }
            fclose($p['out']);
            proc_close($p['proc']);
            unset($this->procs[$name]);
        }
    }
}
