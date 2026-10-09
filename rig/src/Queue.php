<?php

declare(strict_types=1);

namespace Rig;

/**
 * Очередь задач в файлах storage/queue, без Redis и других служб.
 *
 *   Queue::push(SendWelcome::class, ['email' => $email]);          // как можно скорее
 *   Queue::push(Cleanup::class, [], delay: 3600);                  // через час
 *
 * Выполняет rig queue:work (rig dev запускает его сам). Задача, упавшая с ошибкой, повторяется
 * ('queue.tries' раз, с паузой 10, 20, 30… с), затем попадает в проваленные: rig queue:failed, rig queue:retry.
 * При 'queue.driver' => 'sync' задачи выполняются сразу, без очереди (тесты, отладка).
 */
final class Queue
{
    /** @param class-string<Job> $job */
    public static function push(string $job, array $data = [], int $delay = 0, string $queue = 'default'): string
    {
        if (!is_subclass_of($job, Job::class)) {
            throw new \InvalidArgumentException("{$job} должен реализовать Rig\\Job");
        }
        $id = bin2hex(random_bytes(8));
        $item = ['id' => $id, 'job' => $job, 'data' => $data, 'queue' => $queue, 'attempts' => 0, 'created' => time(), 'error' => null];
        if (Config::get('queue.driver', 'file') === 'sync') {
            (new $job())->handle($data);
            return $id;
        }
        json_encode($data, JSON_THROW_ON_ERROR); // в данных задачи только то, что переживёт JSON
        self::write(self::dir($queue, 'pending'), time() + max(0, $delay), $item);
        return $id;
    }

    /**
     * Выполнить следующую готовую задачу. null, если очередь пуста.
     * @return array{id:string,job:string,ok:bool,error:?string,ms:int}|null
     */
    public static function runNext(string $queue = 'default'): ?array
    {
        $pending = self::dir($queue, 'pending');
        $files = glob("{$pending}/*.json") ?: [];
        sort($files, SORT_STRING); // имя начинается с времени готовности
        foreach ($files as $file) {
            if ((int)basename($file) > time()) {
                break; // дальше только отложенные
            }
            $running = self::dir($queue, 'running') . '/' . basename($file);
            if (!@rename($file, $running)) {
                continue; // задачу уже взял другой обработчик
            }
            $item = json_decode((string)file_get_contents($running), true);
            if (!is_array($item)) {
                @unlink($running);
                continue;
            }
            $item['attempts']++;
            $started = microtime(true);
            try {
                $class = (string)$item['job'];
                if (!is_subclass_of($class, Job::class)) {
                    throw new \RuntimeException("Класс задачи {$class} не найден или не реализует Rig\\Job");
                }
                (new $class())->handle((array)$item['data']);
                @unlink($running);
                return ['id' => $item['id'], 'job' => $class, 'ok' => true, 'error' => null, 'ms' => (int)round((microtime(true) - $started) * 1000)];
            } catch (\Throwable $e) {
                @unlink($running);
                $item['error'] = get_class($e) . ': ' . $e->getMessage();
                Log::error($e, ['job' => $item['job'], 'attempt' => $item['attempts']]);
                if ($item['attempts'] < max(1, (int)Config::get('queue.tries', 3))) {
                    self::write($pending, time() + 10 * $item['attempts'], $item);
                } else {
                    $item['failed'] = time();
                    self::write(self::dir($queue, 'failed'), time(), $item);
                }
                return ['id' => $item['id'], 'job' => (string)$item['job'], 'ok' => false, 'error' => $item['error'], 'ms' => (int)round((microtime(true) - $started) * 1000)];
            } finally {
                DB::disconnect(); // долгий процесс: не держим подключение между задачами
                Auth::forgetUser();
                Session::reset();
            }
        }
        return null;
    }

    /**
     * Обработчик очереди: выполняет задачи, пока $stop() не вернёт true.
     * @param \Closure(array):void $report о каждой задаче
     */
    public static function work(\Closure $report, string $queue = 'default', ?\Closure $stop = null, bool $once = false): void
    {
        self::recover($queue);
        while (true) {
            $r = self::runNext($queue);
            if ($r !== null) {
                $report($r);
                if ($once) {
                    return;
                }
                continue;
            }
            if ($once || ($stop !== null && $stop())) {
                return;
            }
            usleep(500_000);
        }
    }

    /** Сколько задач ждёт выполнения. */
    public static function size(string $queue = 'default'): int
    {
        return count(glob(self::dir($queue, 'pending') . '/*.json') ?: []);
    }

    /** @return list<array> проваленные задачи, новые первыми */
    public static function failed(string $queue = 'default'): array
    {
        $out = [];
        foreach (glob(self::dir($queue, 'failed') . '/*.json') ?: [] as $f) {
            $item = json_decode((string)file_get_contents($f), true);
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        usort($out, static fn ($a, $b) => ($b['failed'] ?? 0) <=> ($a['failed'] ?? 0));
        return $out;
    }

    /** Вернуть проваленную задачу (или все при 'all') в очередь. Возвращает число возвращённых. */
    public static function retry(string $id = 'all', string $queue = 'default'): int
    {
        $n = 0;
        foreach (glob(self::dir($queue, 'failed') . '/*.json') ?: [] as $f) {
            $item = json_decode((string)file_get_contents($f), true);
            if (!is_array($item) || ($id !== 'all' && $item['id'] !== $id)) {
                continue;
            }
            unlink($f);
            $item['attempts'] = 0;
            unset($item['failed']);
            self::write(self::dir($queue, 'pending'), time(), $item);
            $n++;
        }
        return $n;
    }

    public static function flushFailed(string $queue = 'default'): int
    {
        $n = 0;
        foreach (glob(self::dir($queue, 'failed') . '/*.json') ?: [] as $f) {
            $n += @unlink($f) ? 1 : 0;
        }
        return $n;
    }

    /** Вернуть в очередь задачи, которые выполнял упавший обработчик (дольше 10 минут в running). */
    private static function recover(string $queue): void
    {
        foreach (glob(self::dir($queue, 'running') . '/*.json') ?: [] as $f) {
            if (@filemtime($f) < time() - 600) {
                @rename($f, self::dir($queue, 'pending') . '/' . basename($f));
            }
        }
    }

    private static function write(string $dir, int $at, array $item): void
    {
        // имя: время готовности + момент постановки (микросекунды), сортировка по имени даёт порядок очереди
        $file = $dir . '/' . str_pad((string)$at, 11, '0', STR_PAD_LEFT) . '-' . sprintf('%017.6f', microtime(true)) . '-' . $item['id'] . '.json';
        file_put_contents($file . '.tmp', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($file . '.tmp', $file);
    }

    private static function dir(string $queue, string $state): string
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $queue)) {
            throw new \InvalidArgumentException("Недопустимое имя очереди «{$queue}»");
        }
        return Config::storage("queue/{$queue}/{$state}");
    }
}
