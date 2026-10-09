<?php

declare(strict_types=1);

namespace Rig;

/**
 * Следит за файлами проекта для HMR.
 * Версия: хеш содержимого всех файлов. Карта «файл: хеш» для каждой версии
 * хранится в storage/hmr, чтобы по версии из браузера понять, что именно поменялось.
 */
final class Hmr
{
    private const KEEP = 40;

    public function __construct(private readonly string $root, private readonly string $dir)
    {
    }

    /** @return array<string,string> относительный путь => md5 */
    public function files(): array
    {
        $map = [];
        foreach (['app' => '/\.(php|md)$/', 'rig/src' => '/\.php$/', 'rig/runtime' => '/\.js$/'] as $sub => $re) {
            $base = "{$this->root}/{$sub}";
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && preg_match($re, $f->getFilename())) {
                    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($this->root) + 1));
                    $map[$rel] = (string)@md5_file($f->getPathname());
                }
            }
        }
        ksort($map);
        return $map;
    }

    public function version(?array $map = null): string
    {
        $map ??= $this->files();
        $v = substr(md5(json_encode($map)), 0, 12);
        $file = "{$this->dir}/{$v}.json";
        if (!is_file($file)) {
            file_put_contents($file, json_encode($map), LOCK_EX);
            $this->prune();
        }
        return $v;
    }

    /** @return list<string> изменённые файлы или ['*'], если старая версия неизвестна */
    public function diff(string $since, array $map): array
    {
        $old = is_file("{$this->dir}/{$since}.json") ? json_decode((string)file_get_contents("{$this->dir}/{$since}.json"), true) : null;
        if (!is_array($old)) {
            return ['*'];
        }
        $changed = [];
        foreach ($map + $old as $f => $_) {
            if (($map[$f] ?? null) !== ($old[$f] ?? null)) {
                $changed[] = $f;
            }
        }
        return $changed;
    }

    private function prune(): void
    {
        $files = glob("{$this->dir}/*.json") ?: [];
        if (count($files) <= self::KEEP) {
            return;
        }
        usort($files, fn ($a, $b) => filemtime($a) <=> filemtime($b));
        foreach (array_slice($files, 0, count($files) - self::KEEP) as $f) {
            @unlink($f);
        }
    }
}
