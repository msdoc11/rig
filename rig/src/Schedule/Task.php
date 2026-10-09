<?php

declare(strict_types=1);

namespace Rig\Schedule;

use Rig\Config;
use Rig\Log;

/** Задача планировщика: когда выполнять (cron-выражение) и что. */
final class Task
{
    private string $cron = '* * * * *';
    private bool $exclusive = false;

    public function __construct(private readonly \Closure $fn, public string $name)
    {
    }

    public function cron(string $expression): self
    {
        if (count(preg_split('/\s+/', trim($expression)) ?: []) !== 5) {
            throw new \InvalidArgumentException("Cron-выражение из 5 полей: «минута час день месяц день_недели», а не «{$expression}»");
        }
        $this->cron = trim($expression);
        return $this;
    }

    public function expression(): string
    {
        return $this->cron;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): self
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->cron('*/30 * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function hourlyAt(int $minute): self
    {
        return $this->cron("{$minute} * * * *");
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    /** dailyAt('09:30') */
    public function dailyAt(string $time): self
    {
        [$h, $m] = array_map('intval', explode(':', $time . ':0'));
        return $this->cron("{$m} {$h} * * *");
    }

    /** Понедельник = 1 … воскресенье = 0 (или 7). */
    public function weeklyOn(int $day, string $time = '00:00'): self
    {
        [$h, $m] = array_map('intval', explode(':', $time . ':0'));
        return $this->cron("{$m} {$h} * * {$day}");
    }

    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    /** Только по будням (к уже заданному времени). */
    public function weekdays(): self
    {
        $p = preg_split('/\s+/', $this->cron);
        $p[4] = '1-5';
        $this->cron = implode(' ', $p);
        return $this;
    }

    /** Не запускать, пока не закончился прошлый запуск. */
    public function withoutOverlapping(): self
    {
        $this->exclusive = true;
        return $this;
    }

    public function isDue(\DateTimeInterface $now): bool
    {
        [$min, $hour, $dom, $mon, $dow] = preg_split('/\s+/', $this->cron);
        $w = (int)$now->format('w');
        return self::match($min, (int)$now->format('i'), 0, 59)
            && self::match($hour, (int)$now->format('G'), 0, 23)
            && self::match($dom, (int)$now->format('j'), 1, 31)
            && self::match($mon, (int)$now->format('n'), 1, 12)
            && (self::match($dow, $w, 0, 7) || ($w === 0 && self::match($dow, 7, 0, 7)));
    }

    /** @return array{name:string,ok:bool,error:?string,ms:int} */
    public function run(): array
    {
        $lock = null;
        if ($this->exclusive) {
            $lock = fopen(Config::storage('schedule') . '/' . sha1($this->name . $this->cron) . '.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                return ['name' => $this->name, 'ok' => true, 'error' => 'пропущено: прошлый запуск ещё идёт', 'ms' => 0];
            }
        }
        $started = microtime(true);
        try {
            ($this->fn)();
            return ['name' => $this->name, 'ok' => true, 'error' => null, 'ms' => (int)round((microtime(true) - $started) * 1000)];
        } catch (\Throwable $e) {
            Log::error($e, ['schedule' => $this->name]);
            return ['name' => $this->name, 'ok' => false, 'error' => $e->getMessage(), 'ms' => (int)round((microtime(true) - $started) * 1000)];
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Поле cron: *, 5, 1-5, 1,15, *\/10, 0-30/5 */
    private static function match(string $field, int $value, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $part) {
            [$range, $step] = array_pad(explode('/', $part, 2), 2, null);
            $step = $step === null ? 1 : max(1, (int)$step);
            if ($range === '*') {
                [$a, $b] = [$min, $max];
            } elseif (str_contains($range, '-')) {
                [$a, $b] = array_map('intval', explode('-', $range, 2));
            } else {
                $a = (int)$range;
                $b = $step > 1 ? $max : $a;
            }
            if ($value >= $a && $value <= $b && ($value - $a) % $step === 0) {
                return true;
            }
        }
        return false;
    }
}
