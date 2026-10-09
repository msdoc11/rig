<?php

declare(strict_types=1);

namespace Rig;

/**
 * Открыть файл на строке в редакторе разработчика. Редактор запускает сервер разработки, браузер не участвует.
 *
 * Windows: путь к редактору берётся из реестра, из того же обработчика ссылок (vscode://, cursor://…),
 * которым пользуется браузер, и редактор запускается напрямую. Переменные Electron/VS Code из окружения
 * убираются: если сервер запущен из терминала VS Code, ELECTRON_RUN_AS_NODE=1 заставил бы редактор
 * стартовать как Node без окна.
 */
final class Editor
{
    /** имя в config.php и протокол, под которым редактор зарегистрирован в Windows */
    private const PROTOCOLS = [
        'code' => 'vscode', 'code-insiders' => 'vscode-insiders', 'codium' => 'vscodium',
        'cursor' => 'cursor', 'windsurf' => 'windsurf',
    ];

    /** @return string как был запущен редактор (для ответа и отладки) */
    public static function open(string $editor, string $path, int $line): string
    {
        self::cleanEnv();
        $jetbrains = (bool)preg_match('/storm|idea/i', $editor);
        $args = match (true) {
            $jetbrains => ['--line', (string)$line, $path],
            (bool)preg_match('/subl/i', $editor) => ["{$path}:{$line}"],
            default => ['--goto', "{$path}:{$line}"],
        };

        if (PHP_OS_FAMILY === 'Windows') {
            $exe = self::windowsExe($editor);
            if ($exe === null) {
                throw new HttpError(500, "Редактор «{$editor}» не найден: нет ни обработчика ссылок в реестре, ни команды {$editor} в PATH. "
                    . "Укажите другой в config.php ('editor') или в переменной RIG_EDITOR.");
            }
            // start: не ждём, пока редактор закроется. Кавычки безопасны, в путях Windows их не бывает
            $cmd = 'start "" "' . $exe . '" ' . implode(' ', array_map(static fn (string $a) => '"' . $a . '"', $args));
            pclose(popen($cmd, 'r'));
            return $exe;
        }

        $bin = trim((string)shell_exec('command -v ' . escapeshellarg($editor) . ' 2>/dev/null'));
        if ($bin === '') {
            throw new HttpError(500, "Редактор «{$editor}» не найден в PATH. Укажите другой в config.php ('editor') или в RIG_EDITOR.");
        }
        exec(escapeshellarg($bin) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' > /dev/null 2>&1 &');
        return $bin;
    }

    private static function windowsExe(string $editor): ?string
    {
        $proto = self::PROTOCOLS[strtolower($editor)] ?? strtolower($editor);
        foreach (['HKCU\\Software\\Classes', 'HKLM\\Software\\Classes'] as $hive) {
            $out = (string)shell_exec("reg query \"{$hive}\\{$proto}\\shell\\open\\command\" /ve 2>NUL");
            if (preg_match('/REG_SZ\s+"([^"]+\.exe)"/i', $out, $m) && is_file($m[1])) {
                return $m[1];
            }
        }
        // запасной путь: команда в PATH (code.cmd лежит в bin\ рядом с Code.exe)
        $where = trim((string)strtok((string)shell_exec('where ' . escapeshellarg($editor) . ' 2>NUL'), "\r\n"));
        if ($where !== '' && is_file($where)) {
            $exe = glob(dirname($where, 2) . '\\*.exe') ?: [];
            foreach ($exe as $e) {
                if (!preg_match('/unins/i', basename($e))) {
                    return $e;
                }
            }
            return $where;
        }
        return null;
    }

    /** Убрать переменные, из-за которых Electron-редактор стартует как Node без окна. */
    private static function cleanEnv(): void
    {
        foreach (array_keys(getenv()) as $k) {
            if ($k === 'ELECTRON_RUN_AS_NODE' || $k === 'ELECTRON_NO_ATTACH_CONSOLE' || str_starts_with((string)$k, 'VSCODE_')) {
                putenv((string)$k);
            }
        }
    }
}
