<?php

declare(strict_types=1);

namespace Rig;

use Rig\Compiler\CompileError;

/**
 * Страница ошибки в режиме разработки: что случилось (по-русски), как исправить, где (файл, строка,
 * подсвеченный код), путь вызова и запрос. Кнопки: открыть в редакторе, скопировать ошибку,
 * скопировать промпт для ИИ-агента. Сама перезагружается после правки.
 */
final class ErrorPage
{
    private const EDITORS = [
        'code' => 'VS Code', 'code-insiders' => 'VS Code', 'cursor' => 'Cursor', 'windsurf' => 'Windsurf',
        'codium' => 'VSCodium', 'phpstorm' => 'PhpStorm', 'subl' => 'Sublime Text', 'zed' => 'Zed',
    ];

    /**
     * Всё об ошибке: для страницы, для всплывающего окна в браузере (JSON) и для копирования.
     *
     * @return array{kind:string, hint:?string, hintText:?string, type:string, message:string, file:string, abs:string,
     *   line:int, origin:?array{file:string, abs:string, line:int}, excerpt:string, trace:list<array{fn:string, at:string, abs:?string, line:int, own:bool}>,
     *   text:string, prompt:string, label:string}
     */
    public static function details(\Throwable $ex, App $app): array
    {
        $root = str_replace('\\', '/', $app->root);
        $rel = static fn (string $f): string => str_starts_with(str_replace('\\', '/', $f), $root . '/')
            ? substr(str_replace('\\', '/', $f), strlen($root) + 1)
            : str_replace('\\', '/', $f);
        $own = static fn (string $r): bool => str_starts_with($r, 'app/') || str_starts_with($r, 'public/') || $r === 'config.php';

        [$kind, $hint] = self::explain($ex);
        $hintText = $hint !== null ? html_entity_decode(strip_tags($hint), ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;

        // главное место: где упало, а если упало внутри фреймворка, то первый кадр кода приложения
        $origin = null;
        if (!$own($rel($ex->getFile()))) {
            foreach ($ex->getTrace() as $f) {
                if (isset($f['file']) && $own($rel($f['file']))) {
                    $origin = ['file' => $rel($f['file']), 'abs' => $f['file'], 'line' => (int)($f['line'] ?? 0)];
                    break;
                }
            }
        }

        $trace = [];
        foreach ($ex->getTrace() as $f) {
            $at = isset($f['file']) ? $rel($f['file']) : '';
            $trace[] = [
                'fn' => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . '()',
                'at' => $at !== '' ? $at . ':' . ($f['line'] ?? '?') : '[внутренний вызов]',
                'abs' => $f['file'] ?? null,
                'line' => (int)($f['line'] ?? 0),
                'own' => $at !== '' && $own($at),
            ];
        }

        $main = $origin ?? ['file' => $rel($ex->getFile()), 'abs' => $ex->getFile(), 'line' => $ex->getLine()];
        $excerpt = self::excerptText($main['abs'], $main['line']);
        $label = match (true) {
            $ex instanceof CompileError => 'Ошибка в шаблоне',
            $ex instanceof HttpError => 'Ошибка ' . $ex->status,
            default => 'Ошибка PHP',
        };
        $d = [
            'kind' => $kind,
            'hint' => $hint,
            'hintText' => $hintText,
            'type' => (new \ReflectionClass($ex))->getShortName(),
            'message' => $ex->getMessage(),
            'file' => $rel($ex->getFile()),
            'abs' => $ex->getFile(),
            'line' => $ex->getLine(),
            'origin' => $origin,
            'excerpt' => $excerpt,
            'trace' => $trace,
            'label' => $label,
            'request' => ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . ($_SERVER['REQUEST_URI'] ?? '/'),
        ];
        $d['text'] = self::copyText($d);
        $d['prompt'] = self::aiPrompt($d);
        return $d;
    }

    public static function render(\Throwable $ex, string $relFile, string $title, App $app): string
    {
        $h = static fn (string $s): string => Renderer::e($s);
        $d = self::details($ex, $app);

        $where = $d['origin']
            ? self::codeBlock($d['origin']['abs'], $d['origin']['line'], $d['origin']['file'], $h, 'Вызвано из вашего кода')
                . self::codeBlock($d['abs'], $d['line'], $d['file'], $h, 'Где сработала ошибка')
            : self::codeBlock($d['abs'], $d['line'], $d['file'], $h);

        $frames = '';
        foreach ($d['trace'] as $i => $f) {
            $at = $f['abs'] !== null
                ? '<a href="' . $h(self::editorUrl($f['abs'], $f['line'])) . '" data-open="' . $h($f['abs']) . '" data-line="' . $f['line'] . '">' . $h($f['at']) . '</a>'
                : '<span class="at">' . $h($f['at']) . '</span>';
            $frames .= '<li class="' . ($f['own'] ? 'own' : 'lib') . '"><span class="n">' . ($i + 1) . '</span><code>' . $h($f['fn']) . '</code>' . $at . '</li>';
        }

        $main = $d['origin'] ?? ['file' => $d['file'], 'abs' => $d['abs'], 'line' => $d['line']];
        $editor = self::EDITORS[strtolower((string)(getenv('RIG_EDITOR') ?: $app->editor))] ?? 'редакторе';
        $openAttrs = 'href="' . $h(self::editorUrl($main['abs'], $main['line'])) . '" data-open="' . $h($main['abs']) . '" data-line="' . $main['line'] . '"';
        $hintHtml = $d['hint'] !== null ? '<section class="hint"><h2>Как исправить</h2><p>' . $d['hint'] . '</p></section>' : '';
        $copy = json_encode(['text' => $d['text'], 'prompt' => $d['prompt']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        $env = $h('PHP ' . PHP_VERSION . ' · rig ' . App::VERSION);
        $reload = self::reloadScript($app);

        return <<<HTML
        <!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>{$h($d['kind'])} — rig</title><link rel="icon" href="data:,">
        <style>
        :root{--bg:#f7f5f4;--card:#fff;--text:#1d1a1a;--muted:#6e6563;--line:#ece6e4;--accent:#d33a3a;--accent-bg:#fdecec;
          --hint:#1f6f43;--hint-bg:#e9f6ee;--code:#faf8f7;--hit:#fde3e3;--kw:#8a3ab9;--str:#2a7a3b;--var:#b5481b;--cm:#8a8180;--num:#1f5fb4;color-scheme:light}
        @media (prefers-color-scheme:dark){:root{--bg:#161313;--card:#1f1b1b;--text:#f1eaea;--muted:#a59a98;--line:#2e2828;--accent:#ff7a7a;--accent-bg:#3a1f20;
          --hint:#7fd6a2;--hint-bg:#17291f;--code:#141111;--hit:#4a2023;--kw:#d49bff;--str:#9ad7a4;--var:#ffb08a;--cm:#7c7270;--num:#8ab8ff;color-scheme:dark}}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;padding:0 16px}
        main{max-width:1040px;margin:0 auto;padding:40px 0 64px}
        .tag{display:inline-flex;gap:8px;align-items:center;font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--accent);background:var(--accent-bg);padding:4px 10px;border-radius:999px}
        h1{font-size:28px;line-height:1.25;margin:14px 0 6px;letter-spacing:-.01em}
        .msg{font:15px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace;color:var(--muted);margin:0 0 20px;white-space:pre-wrap;word-break:break-word}
        .hint{background:var(--hint-bg);border-radius:12px;padding:14px 18px;margin:0 0 24px}
        .hint h2{margin:0 0 4px;font-size:13px;color:var(--hint);text-transform:uppercase;letter-spacing:.04em}
        .hint p{margin:0}.hint code{font-family:ui-monospace,Consolas,monospace;font-size:.92em;background:rgba(127,127,127,.15);padding:1px 5px;border-radius:4px}
        .where{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;margin:0 0 10px}
        .where .file{font:14px ui-monospace,Consolas,monospace;margin-right:auto}
        .btn{display:inline-flex;align-items:center;gap:6px;min-height:36px;border:1px solid var(--line);background:var(--card);color:var(--text);text-decoration:none;
          font:600 13px system-ui,sans-serif;padding:7px 12px;border-radius:8px;cursor:pointer}
        .btn:hover{border-color:var(--muted)}.btn.primary{background:var(--text);color:var(--bg);border-color:var(--text)}
        .btn:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
        .block{background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden;margin:0 0 18px}
        .block h3{margin:0;padding:10px 16px;font-size:12px;font-weight:600;color:var(--muted);border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap}
        .block h3 a{color:inherit;font-family:ui-monospace,Consolas,monospace;font-weight:400}
        pre{margin:0;padding:10px 0;overflow-x:auto;background:var(--code);font:13px/1.65 ui-monospace,SFMono-Regular,Consolas,monospace}
        pre div{padding:0 16px 0 0;white-space:pre}pre b{display:inline-block;width:4.2em;padding-right:1em;text-align:right;color:var(--muted);font-weight:400;user-select:none}
        pre .hit{background:var(--hit)}pre .hit b{color:var(--accent);font-weight:700}
        .kw,.tag,.cmp{color:var(--kw)}.str,.expr{color:var(--str)}.var,.dir{color:var(--var)}.cm{color:var(--cm);font-style:italic}.num,.attr{color:var(--num)}
        details{background:var(--card);border:1px solid var(--line);border-radius:12px;margin:0 0 18px}
        summary{cursor:pointer;padding:12px 16px;font-weight:600;font-size:14px}
        ol{list-style:none;margin:0;padding:0 0 8px}ol li{display:flex;gap:12px;align-items:baseline;padding:6px 16px;flex-wrap:wrap;font-size:13px}
        ol li .n{color:var(--muted);width:2em;text-align:right;font-variant-numeric:tabular-nums}
        ol li code{font-family:ui-monospace,Consolas,monospace}ol li a,ol li .at{color:var(--muted);font-family:ui-monospace,Consolas,monospace;margin-left:auto}
        ol li.lib{opacity:.55}ol li.own code{font-weight:600}
        .meta{color:var(--muted);font-size:13px;display:flex;gap:16px;flex-wrap:wrap}
        .live{margin-top:28px;color:var(--muted);font-size:13px;display:flex;gap:8px;align-items:center}
        .dot{width:8px;height:8px;border-radius:50%;background:var(--hint);animation:p 1.6s ease-in-out infinite}
        @keyframes p{50%{opacity:.3}}@media (prefers-reduced-motion:reduce){.dot{animation:none}}
        .toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:var(--text);color:var(--bg);padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;opacity:0;transition:opacity .15s;pointer-events:none}
        .toast.on{opacity:1}
        </style>
        <main>
          <span class="tag">{$h($d['label'])} · {$h($d['type'])}</span>
          <h1>{$h($d['kind'])}</h1>
          <p class="msg">{$h($d['message'])}</p>
          {$hintHtml}
          <div class="where">
            <span class="file">{$h($main['file'] . ':' . $main['line'])}</span>
            <button class="btn" type="button" data-copy="text">Копировать ошибку</button>
            <button class="btn" type="button" data-copy="prompt" title="Промпт с ошибкой, кодом и задачей — вставьте в Claude, Cursor, Copilot">Для ИИ-агента</button>
            <a class="btn primary" {$openAttrs}>Открыть в {$h($editor)}</a>
          </div>
          {$where}
          <details><summary>Путь вызова</summary><ol>{$frames}</ol></details>
          <p class="meta"><span>{$h($d['request'])}</span><span>{$env}</span></p>
          <p class="live"><span class="dot"></span>Исправьте файл и сохраните — страница обновится сама</p>
        </main>
        <div class="toast" role="status" aria-live="polite"></div>
        <script type="application/json" id="rig-error">{$copy}</script>
        <script>
        (()=>{
          const data=JSON.parse(document.getElementById('rig-error').textContent);
          const toast=document.querySelector('.toast');let t=0;
          const say=(m)=>{toast.textContent=m;toast.classList.add('on');clearTimeout(t);t=setTimeout(()=>toast.classList.remove('on'),1800)};
          // буфер обмена: clipboard API есть только на https и localhost, иначе — через скрытое поле
          const copy=async(text)=>{try{await navigator.clipboard.writeText(text);return true}catch{const a=document.createElement('textarea');
            a.value=text;a.style.cssText='position:fixed;opacity:0';document.body.append(a);a.select();const ok=document.execCommand('copy');a.remove();return ok}};
          document.addEventListener('click',async(e)=>{
            const c=e.target.closest('[data-copy]');
            if(c){say(await copy(data[c.dataset.copy])?(c.dataset.copy==='prompt'?'Промпт для ИИ-агента скопирован':'Ошибка скопирована'):'Не удалось скопировать');return}
            const o=e.target.closest('[data-open]');
            if(!o)return;
            // редактор открывает сервер разработки; не вышло — пробуем ссылку vscode://
            e.preventDefault();
            try{const r=await fetch('/_rig/open',{method:'POST',headers:{'Content-Type':'application/json','X-Rig':'1'},
              body:JSON.stringify({file:o.dataset.open,line:+o.dataset.line})});const j=await r.json().catch(()=>({}));
              if(!r.ok)throw new Error(j.error&&j.error.message||'');say('Открываю в редакторе…')}
            catch(err){if(err&&err.message)say(err.message);location.href=o.getAttribute('href')}
          });
        })();
        </script>{$reload}</html>
        HTML;
    }

    /** Текст для «Копировать ошибку». */
    private static function copyText(array $d): string
    {
        $where = $d['origin'] ? "{$d['origin']['file']}:{$d['origin']['line']} (сработала в {$d['file']}:{$d['line']})" : "{$d['file']}:{$d['line']}";
        $trace = implode("\n", array_map(static fn (array $f, int $i) => '#' . ($i + 1) . " {$f['fn']} {$f['at']}", $d['trace'], array_keys($d['trace'])));
        return "{$d['kind']}\n{$d['type']}: {$d['message']}\nГде: {$where}\nЗапрос: {$d['request']}\n"
            . 'PHP ' . PHP_VERSION . ', rig ' . App::VERSION . "\n"
            . ($d['hintText'] ? "\nПодсказка: {$d['hintText']}\n" : '')
            . "\nКод:\n{$d['excerpt']}\n"
            . ($trace !== '' ? "\nПуть вызова:\n{$trace}\n" : '');
    }

    /** Промпт для ИИ-агента: контекст проекта, ошибка, код, задача. */
    private static function aiPrompt(array $d): string
    {
        $main = $d['origin'] ?? ['file' => $d['file'], 'line' => $d['line']];
        $own = array_values(array_filter($d['trace'], static fn (array $f) => $f['own']));
        $trace = implode("\n", array_map(static fn (array $f) => "- {$f['fn']} — {$f['at']}", $own ?: array_slice($d['trace'], 0, 8)));
        $isTemplate = $d['label'] === 'Ошибка в шаблоне';
        $context = $isTemplate
            ? "Ошибка в шаблоне компонента. Файл компонента: сначала PHP-класс, после ?> — блоки <template>, <style> и <skeleton> "
                . "(в старых компонентах шаблон может быть строкой из метода template()). В шаблоне не PHP и не JS, "
                . "а язык выражений rig: {{ выражение }}, :attr=\"…\", @click=\"действие\", r-if, r-for=\"t in list\", r-model. "
                . "Доступны публичные свойства компонента, #[Computed], переменные цикла, \$errors, \$slots, \$busy, \$pending и встроенные функции "
                . "(len, upper, lower, fixed, where, pluck, sum, plural и др.). Действия — методы с #[Action]."
            : 'Ошибка PHP при выполнении.';
        return <<<TXT
        Исправь ошибку в PHP-проекте на фреймворке rig.

        О проекте: компоненты — PHP-классы в app/components (namespace App\\Components, наследуют Rig\\Component),
        страницы — app/pages/*.php, layout — app/layouts, прочие классы — app/lib (namespace App\\).
        Каталог rig/ — сам фреймворк, его не меняй, если ошибка не в нём.
        {$context}

        Ошибка: {$d['kind']}
        {$d['type']}: {$d['message']}
        Файл: {$main['file']}, строка {$main['line']}
        Запрос: {$d['request']}

        Код (строка с ошибкой отмечена «>»):
        ```php
        {$d['excerpt']}
        ```

        Подсказка фреймворка: {$d['hintText']}

        Путь вызова:
        {$trace}

        Что сделать:
        1. Найди причину, а не только место, где ошибка проявилась.
        2. Исправь минимальным изменением, в стиле окружающего кода.
        3. Коротко объясни, что было не так и что ты изменил.
        TXT;
    }

    /** Код вокруг строки простым текстом: номера строк и «>» на строке ошибки. */
    private static function excerptText(string $file, int $line): string
    {
        if (!is_file($file)) {
            return '';
        }
        $lines = explode("\n", str_replace("\r\n", "\n", (string)file_get_contents($file)));
        $out = [];
        for ($i = max(1, $line - 7); $i <= min(count($lines), $line + 5); $i++) {
            $out[] = ($i === $line ? '> ' : '  ') . str_pad((string)$i, 4, ' ', STR_PAD_LEFT) . ' | ' . $lines[$i - 1];
        }
        return implode("\n", $out);
    }

    /** Понятный заголовок и подсказка по сообщению PHP или rig. Подсказка возвращается как экранированный HTML. */
    private static function explain(\Throwable $ex): array
    {
        $m = $ex->getMessage();
        $e = static fn (string $s): string => '<code>' . Renderer::e($s) . '</code>';

        if ($ex instanceof CompileError) {
            $name = basename($ex->getFile(), '.php');
            $hint = match (true) {
                str_contains($m, 'Неизвестная переменная') => 'В шаблоне доступны публичные свойства компонента, computed, переменные цикла '
                    . 'и служебные ' . $e('$errors') . ', ' . $e('$slots') . ', ' . $e('$busy') . ', ' . $e('$pending') . '. '
                    . 'Проверьте написание или объявите свойство в классе: ' . $e('public string $name = \'\';'),
                str_contains($m, 'Неизвестная функция') => 'В шаблоне можно вызывать встроенные функции (' . $e('len') . ', ' . $e('upper') . ', '
                    . $e('fixed') . ', ' . $e('where') . ' и др.), а в обработчиках событий — ещё и действия с ' . $e('#[Action]') . '.',
                str_contains($m, 'можно вызывать только в обработчиках') => 'Действия выполняются по событию: ' . $e('@click="save"') . '. '
                    . 'В ' . $e('{{ }}') . ' и ' . $e(':attr') . ' — только выражения без вызова действий.',
                str_contains($m, 'нельзя изменить') => 'Присваивать можно только обычным свойствам и ' . $e('#[Client]') . '. '
                    . 'Computed пересчитывается сам, ' . $e('#[Prop]') . ' задаёт родитель.',
                str_contains($m, 'не закрыт') || str_contains($m, 'Лишний закрывающий') => 'Проверьте парность тегов в шаблоне: '
                    . 'у каждого ' . $e('<div>') . ' должен быть ' . $e('</div>') . '.',
                default => 'Ошибка найдена при компиляции шаблона — до того, как страница ушла в браузер.',
            };
            return ["Ошибка в шаблоне компонента {$name}", $hint];
        }
        if ($ex instanceof \ParseError) {
            return ['Синтаксическая ошибка в PHP', 'Обычно на этой строке или строкой выше пропущены ' . $e(';') . ', '
                . $e(')') . ', ' . $e('}') . ' или закрывающая кавычка.'];
        }
        if (preg_match('/Call to undefined function (?:[\w\\\\]+\\\\)?(\w+)\(\)/', $m, $mm)) {
            $all = get_defined_functions();
            $s = self::closest($mm[1], [...$all['internal'], ...$all['user']]);
            return ["Функции {$mm[1]}() не существует", ($s ? 'Может быть, ' . $e($s . '()') . '? ' : '')
                . 'Проверьте имя функции или подключите файл, где она объявлена.'];
        }
        if (preg_match('/Call to undefined method ([\w\\\\]+)::(\w+)\(\)/', $m, $mm)) {
            $s = class_exists($mm[1]) ? self::closest($mm[2], get_class_methods($mm[1])) : null;
            return ["У класса нет метода {$mm[2]}()", ($s ? 'Может быть, ' . $e($s . '()') . '? ' : '')
                . 'Методы класса ' . $e($mm[1]) . ' — в его файле.'];
        }
        if (preg_match('/Class "([\w\\\\]+)" not found/', $m, $mm)) {
            $path = str_starts_with($mm[1], 'App\\Components\\')
                ? 'app/components/' . str_replace('\\', '/', substr($mm[1], 15)) . '.php'
                : (str_starts_with($mm[1], 'App\\') ? 'app/lib/' . str_replace('\\', '/', substr($mm[1], 4)) . '.php' : null);
            return ["Класс {$mm[1]} не найден", $path
                ? 'rig ищет его в файле ' . $e($path) . '. Проверьте, что файл есть, а namespace и имя класса совпадают с путём.'
                : 'Проверьте namespace, имя класса и ' . $e('use') . ' в начале файла.'];
        }
        if (preg_match('/Undefined variable \$(\w+)/', $m, $mm)) {
            return ["Переменная \${$mm[1]} не определена", 'В этом месте ей ещё не присвоено значение. Проверьте написание или задайте её выше.'];
        }
        if (preg_match('/Undefined array key "?([^"]+)"?/', $m, $mm)) {
            return ["В массиве нет ключа {$mm[1]}", 'Проверьте ключ или используйте значение по умолчанию: ' . $e('$a[\'key\'] ?? null') . '.'];
        }
        if (preg_match('/Attempt to read property "(\w+)" on (null|array|bool)/', $m, $mm)) {
            return ["Чтение свойства {$mm[1]} у {$mm[2]}", 'Значение оказалось ' . $e($mm[2]) . ', а не объектом. Проверьте, что оно есть: '
                . $e('$x?->' . $mm[1]) . '.'];
        }
        if ($ex instanceof \TypeError) {
            return ['Значение не того типа', 'Функция или свойство ожидает другой тип. Сравните объявленный тип с тем, что передаётся.'];
        }
        if ($ex instanceof \DivisionByZeroError) {
            return ['Деление на ноль', 'Проверьте делитель перед делением.'];
        }
        if ($ex instanceof HttpError) {
            return [$m, null];
        }
        return ['Ошибка при выполнении', null];
    }

    private static function closest(string $name, array $candidates): ?string
    {
        $best = null;
        $dist = 3;
        foreach ($candidates as $c) {
            $d = levenshtein(strtolower($name), strtolower((string)$c));
            if ($d < $dist) {
                $dist = $d;
                $best = (string)$c;
            }
        }
        return $best;
    }

    /** Код вокруг строки с подсветкой PHP. */
    private static function codeBlock(string $file, int $line, string $rel, \Closure $h, string $label = 'Код'): string
    {
        if (!is_file($file)) {
            return '';
        }
        $lines = Highlight::lines((string)file_get_contents($file), 'php');
        $from = max(1, $line - 7);
        $to = min(count($lines), $line + 5);
        $out = '';
        for ($i = $from; $i <= $to; $i++) {
            $cls = $i === $line ? ' class="hit"' : '';
            $out .= "<div{$cls}><b>{$i}</b>" . ($lines[$i - 1] ?? '') . '</div>';
        }
        $open = 'href="' . $h(self::editorUrl($file, $line)) . '" data-open="' . $h($file) . '" data-line="' . $line . '"';
        return "<section class=\"block\"><h3><span>{$h($label)}</span><a {$open}>{$h($rel)}:{$line}</a></h3><pre>{$out}</pre></section>";
    }

    /** Запасная ссылка, если сервер не смог запустить редактор. */
    private static function editorUrl(string $file, int $line): string
    {
        return 'vscode://file/' . str_replace('\\', '/', $file) . ':' . max(1, $line);
    }

    /** После исправления файла страница перезагружается сама: SSE от хаба или долгий запрос под FPM. */
    private static function reloadScript(App $app): string
    {
        $hub = $app->hubConfig();
        if ($hub['hmr'] === null) {
            return '';
        }
        $cfg = json_encode(['url' => $hub['url'], 'v' => $hub['hmr']['v'], 'poll' => $hub['hmr']['poll']], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        // go(): не чаще трёх перезагрузок за 5 с: пока файлы правятся, версии хаба и страницы могут расходиться
        return "<script>(()=>{const h={$cfg};const go=()=>{const k='rig-err-reload';let n=[];try{n=JSON.parse(sessionStorage.getItem(k)||'[]')"
            . ".filter(t=>Date.now()-t<5000)}catch{}if(n.length>=3)return void setTimeout(()=>location.reload(),2000);n.push(Date.now());"
            . "sessionStorage.setItem(k,JSON.stringify(n));location.reload()};const poll=async()=>{for(;;){try{"
            . "const j=await(await fetch('/_rig/hmr?v='+h.v,{cache:'no-store'})).json();if(j.v!==h.v)return go()}"
            . "catch{await new Promise(f=>setTimeout(f,3000))}}};if(!h.url)return h.poll&&poll();let ok=false;"
            . "const es=new EventSource(h.url+'/?v='+h.v);es.onopen=()=>ok=true;"
            . "es.onmessage=e=>{const m=JSON.parse(e.data);if(m.type==='hmr'&&m.v!==h.v)go()};"
            . "es.onerror=()=>{if(ok)return;es.close();if(h.poll)poll()}})()</script>";
    }
}
