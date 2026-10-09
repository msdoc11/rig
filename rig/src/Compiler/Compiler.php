<?php

declare(strict_types=1);

namespace Rig\Compiler;

use Rig\Attr;
use Rig\Component;
use Rig\HttpError;

/**
 * Читает класс компонента и собирает его описание (def):
 * какие свойства серверные, какие клиентские, какие computed, какие действия,
 * плюс скомпилированный шаблон и стили. Результат кешируется по хешу файла класса.
 *
 * Сам PHP-код компонента не трогается: это обычный класс с автозагрузкой.
 */
final class Compiler
{
    public const NS = 'App\\Components\\';

    /** @var array<string,array> */
    private array $loaded = [];
    /** @var array<string,true> */
    private array $compiling = [];
    private static ?string $fingerprint = null;

    public function __construct(private readonly string $cacheDir)
    {
    }

    /** Полное имя класса по короткому имени компонента. Только классы из App\Components. */
    public static function classOf(string $name): string
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)*$/', $name)) {
            throw new HttpError(404, "Некорректное имя компонента «{$name}»");
        }
        $class = self::NS . $name;
        if (!class_exists($class)) {
            throw new HttpError(404, "Компонент {$name} не найден: ожидается класс {$class} в app/components/" . str_replace('\\', '/', $name) . '.php');
        }
        if (!is_subclass_of($class, Component::class)) {
            throw new HttpError(404, "Класс {$class} должен наследовать " . Component::class);
        }
        return $class;
    }

    public function load(string $name): array
    {
        if (isset($this->loaded[$name])) {
            return $this->loaded[$name];
        }
        $class = self::classOf($name);
        $rc = new \ReflectionClass($class);
        $file = (string)$rc->getFileName();
        $hash = substr(md5((string)file_get_contents($file) . self::fingerprint()), 0, 12);
        $key = str_replace('\\', '.', $name);
        $cache = "{$this->cacheDir}/{$key}_{$hash}.json";
        if (is_file($cache)) {
            $def = json_decode((string)file_get_contents($cache), true);
            if (is_array($def) && $this->depsFresh($def['deps'] ?? [])) {
                return $this->loaded[$name] = $def;
            }
        }
        $this->compiling[$name] = true;
        try {
            $def = $this->compile($name, $rc);
        } finally {
            unset($this->compiling[$name]);
        }
        foreach (glob("{$this->cacheDir}/{$key}_*.json") ?: [] as $old) {
            @unlink($old);
        }
        file_put_contents($cache, json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        return $this->loaded[$name] = $def;
    }

    /** Часть def, которая уходит в браузер. */
    public static function clientDef(array $def): array
    {
        return array_intersect_key($def, array_flip([
            'name', 'tag', 'scope', 'css', 'tpl', 'server', 'locked', 'props', 'defaults', 'client',
            'cexpr', 'cserver', 'actions', 'on', 'mounts', 'revalidates', 'lazy', 'skel', 'model', 'shared',
        ]));
    }

    /** Шаблон родителя зависит от списка #[Prop] дочерних компонентов. */
    private function depsFresh(array $deps): bool
    {
        foreach ($deps as $child => $hash) {
            try {
                $file = (string)(new \ReflectionClass(self::classOf((string)$child)))->getFileName();
            } catch (\Throwable) {
                return false;
            }
            if (md5((string)file_get_contents($file)) !== $hash) {
                return false;
            }
        }
        return true;
    }

    /** Свойства дочернего компонента для шаблона родителя. null, если компонент сейчас компилируется (рекурсия). */
    private function childInfo(string $name): ?array
    {
        if (isset($this->compiling[$name])) {
            return null;
        }
        $def = $this->load($name);
        return ['props' => $def['props'], 'model' => $def['model'] ?? null];
    }

    private function compile(string $name, \ReflectionClass $rc): array
    {
        $file = (string)$rc->getFileName();
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $server = $locked = $cserver = $props = [];
        $client = $cexprSrc = $actions = $on = $defaults = $aguard = $shared = [];
        $model = null;
        $seen = [];
        $guard = ($g = $rc->getAttributes(Attr\Guard::class))
            ? $this->guardList($g[0]->newInstance()->middleware, $file, (int)$rc->getStartLine())
            : [];

        foreach ($rc->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if ($p->isStatic() || $p->getDeclaringClass()->getName() === Component::class) {
                continue;
            }
            $n = $p->getName();
            $seen[$n] = 'свойство';
            if ($at = $p->getAttributes(Attr\Computed::class)) {
                $expr = $at[0]->newInstance()->expr;
                if ($expr === null) {
                    throw new CompileError("Свойство \${$n}: у #[Computed] на свойстве нужно выражение, например #[Computed('count * 2')]. Для серверного вычисления используйте метод.", $file, $this->propLine($lines, $n));
                }
                $cexprSrc[$n] = $expr;
                continue;
            }
            if ($sh = $p->getAttributes(Attr\Shared::class)) {
                // общее состояние в браузере: как #[Client], но одно значение на ключ для всех компонентов
                $s = $sh[0]->newInstance();
                if (!preg_match('/^[\w.\-:]+$/', $s->key)) {
                    throw new CompileError("#[Shared]: ключ из букв, цифр, точки и дефиса, а не «{$s->key}»", $file, $this->propLine($lines, $n));
                }
                $client[$n] = $p->hasDefaultValue() ? $p->getDefaultValue() : null;
                $shared[$n] = ['k' => $s->key, 'p' => $s->persist];
                continue;
            }
            if ($p->getAttributes(Attr\Client::class)) {
                $client[$n] = $p->hasDefaultValue() ? $p->getDefaultValue() : null;
                continue;
            }
            $server[] = $n;
            $defaults[$n] = $p->hasDefaultValue() ? $p->getDefaultValue() : null;
            if ($p->getAttributes(Attr\Model::class)) {
                if (!$p->getAttributes(Attr\Prop::class)) {
                    throw new CompileError("Свойство \${$n}: #[Model] ставится вместе с #[Prop] — значение задаёт родитель через r-model", $file, $this->propLine($lines, $n));
                }
                if ($model !== null) {
                    throw new CompileError("#[Model] может быть только у одного свойства (уже есть \${$model})", $file, $this->propLine($lines, $n));
                }
                $model = $n;
            }
            if ($p->getAttributes(Attr\Prop::class)) {
                // значение задаёт родитель и присылает с каждым запросом, через updates его не поменять
                $props[] = $n;
                $locked[] = $n;
            } elseif ($p->getAttributes(Attr\Locked::class)) {
                $locked[] = $n;
            }
        }

        foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->isStatic() || $m->getDeclaringClass()->getName() === Component::class) {
                continue;
            }
            $n = $m->getName();
            if ($m->getAttributes(Attr\Computed::class)) {
                if ($m->getNumberOfRequiredParameters() > 0) {
                    throw new CompileError("Метод {$n}(): у #[Computed] не должно быть параметров", $file, (int)$m->getStartLine());
                }
                if (isset($seen[$n])) {
                    throw new CompileError("Имя «{$n}» уже занято свойством", $file, (int)$m->getStartLine());
                }
                $cserver[] = $n;
                $seen[$n] = 'computed';
                continue;
            }
            $act = $m->getAttributes(Attr\Action::class);
            $ons = $m->getAttributes(Attr\On::class);
            if (!$act && !$ons) {
                continue;
            }
            if (isset($seen[$n])) {
                throw new CompileError("Имя действия «{$n}» совпадает со свойством", $file, (int)$m->getStartLine());
            }
            if ($n === 'emit' || $n === 'copy') {
                throw new CompileError("Имя «{$n}» зарезервировано: в шаблоне emit('событие', данные) отправляет событие родителю, copy(текст) — копирует в буфер обмена", $file, (int)$m->getStartLine());
            }
            $actions[$n] = [
                'p' => array_map(fn (\ReflectionParameter $x) => $x->getName(), $m->getParameters()),
                'o' => $act ? $act[0]->newInstance()->optimistic : null,
                'line' => (int)$m->getStartLine(),
            ];
            if ($g = $m->getAttributes(Attr\Guard::class)) {
                $aguard[$n] = $this->guardList($g[0]->newInstance()->middleware, $file, (int)$m->getStartLine());
            }
            foreach ($ons as $o) {
                $event = $o->newInstance()->event;
                $this->eventParams($event, $locked, $file, (int)$m->getStartLine());
                $on[$event][] = $n;
            }
        }

        // переменные шаблона: имя => можно ли присваивать
        // $busy: идут запросы компонента; $pending.имя: выполняется действие «имя» (для скелетонов и спиннеров)
        $vars = ['$errors' => false, '$slots' => false, '$busy' => false, '$pending' => false];
        foreach ($server as $n) {
            // #[Prop] менять нельзя, кроме #[Model]: присваивание уходит родителю (r-model)
            $vars[$n] = !in_array($n, $props, true) || $n === $model;
        }
        foreach (array_keys($client) as $n) {
            $vars[$n] = true;
        }
        foreach ([...array_keys($cexprSrc), ...$cserver] as $n) {
            $vars[$n] = false;
        }

        $blocks = ComponentFile::blocks($file);
        $instance = $rc->newInstanceWithoutConstructor();
        $style = $this->overrides($rc, 'style') ? (string)$instance->style() : ($blocks['style'][0] ?? '');
        $scope = $style !== '' ? 'r-s-' . substr(md5($name), 0, 6) : null;
        $tc = new TemplateCompiler($file, $vars, array_keys($actions), $scope, fn (string $child) => $this->childInfo($child));

        $cexpr = [];
        foreach ($cexprSrc as $n => $src) {
            $cexpr[$n] = $tc->expression($src, $vars, $this->propLine($lines, $n));
        }
        foreach ($actions as $n => &$a) {
            if ($a['o'] !== null) {
                $params = array_fill_keys($a['p'], false);
                $a['o'] = $tc->statements($a['o'], $params + $vars, $a['line'], false);
            }
            unset($a['line']);
        }
        unset($a);

        if ($this->overrides($rc, 'template')) {
            if (isset($blocks['template'])) {
                throw new CompileError('Шаблон задан дважды: методом template() и блоком <template>. Оставьте что-то одно', $file, $blocks['template'][1]);
            }
            $tpl = $tc->compile((string)$instance->template(), $this->templateLine($lines, $rc->getMethod('template')));
        } elseif (isset($blocks['template'])) {
            $tpl = $tc->compile($blocks['template'][0], $blocks['template'][1]);
        } else {
            throw new CompileError("У компонента {$name} нет шаблона: добавьте после класса блок <template>…</template>", $file, (int)$rc->getEndLine());
        }

        $lazy = null;
        $skel = [];
        if ($at = $rc->getAttributes(Attr\Lazy::class)) {
            $lazy = ['ssr' => $at[0]->newInstance()->ssr];
            $skc = new TemplateCompiler($file, $vars, [], $scope, fn (string $child) => $this->childInfo($child));
            if ($this->overrides($rc, 'skeleton')) {
                $skel = $skc->compile((string)$instance->skeleton(), $this->templateLine($lines, $rc->getMethod('skeleton')));
            } elseif (isset($blocks['skeleton'])) {
                $skel = $skc->compile($blocks['skeleton'][0], $blocks['skeleton'][1]);
            } else {
                $skel = (new TemplateCompiler((string)(new \ReflectionClass(Component::class))->getFileName(), $vars, [], $scope))
                    ->compile((string)$instance->skeleton(), 1);
            }
        } elseif (isset($blocks['skeleton'])) {
            throw new CompileError('Блок <skeleton> нужен только компоненту с #[Lazy]: это заглушка, пока грузятся данные', $file, $blocks['skeleton'][1]);
        }

        $deps = [];
        foreach ($tc->uses() as $child) {
            $deps[$child] = md5((string)file_get_contents((string)(new \ReflectionClass(self::classOf($child)))->getFileName()));
        }

        return [
            'name' => $name,
            'tag' => (string)$rc->getConstant('TAG'),
            'scope' => $scope,
            'css' => $scope !== null ? CssScoper::scope($style, $scope) : '',
            'tpl' => $tpl,
            'server' => $server,
            'locked' => $locked,
            'props' => $props,
            'defaults' => $defaults,
            'client' => $client,
            'cexpr' => $cexpr,
            'cserver' => $cserver,
            'actions' => $actions,
            'on' => $on,
            'mounts' => $rc->hasMethod('mount'),
            'revalidates' => $rc->hasMethod('revalidate'),
            'lazy' => $lazy,
            'skel' => $skel,
            'uses' => $tc->uses(),
            'deps' => $deps,
            'guard' => $guard,
            'aguard' => $aguard,
            'model' => $model,
            'shared' => $shared,
        ];
    }

    /**
     * #[On('chat.{roomId}')]: в фигурных скобках свойство компонента (или путь вроде {room.id}).
     * Свойство должно быть #[Locked] или #[Prop]: иначе браузер прислал бы через r-model другое значение
     * и подписался на чужие события.
     */
    private function eventParams(string $event, array $locked, string $file, int $line): void
    {
        if (!str_contains($event, '{') && !str_contains($event, '}')) {
            return;
        }
        if (strpbrk((string)preg_replace('/\{[^{}]*\}/', '', $event), '{}') !== false) {
            throw new CompileError("#[On('{$event}')]: непарные фигурные скобки", $file, $line);
        }
        preg_match_all('/\{([^{}]*)\}/', $event, $m);
        foreach ($m[1] as $path) {
            if (!preg_match('/^([A-Za-z_]\w*)(\.\w+)*$/', $path, $pm)) {
                throw new CompileError("#[On('{$event}')]: в скобках нужно имя свойства, например {roomId} или {room.id}, а не «{$path}»", $file, $line);
            }
            if (!in_array($pm[1], $locked, true)) {
                throw new CompileError("#[On('{$event}')]: свойство \${$pm[1]} должно быть #[Locked] или #[Prop], иначе браузер сможет подписаться на чужие события", $file, $line);
            }
        }
    }

    /** #[Guard]: проверяет, что middleware с такими именами существуют. Опечатка всплывает при компиляции, до первого запроса. */
    private function guardList(array $names, string $file, int $line): array
    {
        foreach ($names as $n) {
            if (!\Rig\Guards::exists((string)$n)) {
                throw new CompileError("#[Guard]: неизвестный middleware «{$n}». Встроенные: auth, guest, role:…, throttle:…; свои — классы в app/middleware/", $file, $line);
            }
        }
        return array_values(array_map('strval', $names));
    }

    /** Метод переопределён в классе компонента, а не взят из Rig\Component. */
    private function overrides(\ReflectionClass $rc, string $method): bool
    {
        return $rc->getMethod($method)->getDeclaringClass()->getName() !== Component::class;
    }

    /** Строка, с которой начинается текст шаблона: следующая после «<<<». */
    private function templateLine(array $lines, \ReflectionMethod $m): int
    {
        for ($i = (int)$m->getStartLine(); $i <= (int)$m->getEndLine(); $i++) {
            if (str_contains($lines[$i - 1] ?? '', '<<<')) {
                return $i + 1;
            }
        }
        return (int)$m->getStartLine();
    }

    private function propLine(array $lines, string $name): int
    {
        foreach ($lines as $i => $l) {
            if (preg_match('/\$' . preg_quote($name, '/') . '\b/', $l)) {
                return $i + 1;
            }
        }
        return 1;
    }

    /** Меняется вместе с кодом компилятора, и тогда кеш собирается заново. */
    private static function fingerprint(): string
    {
        if (self::$fingerprint === null) {
            $files = [__FILE__, __DIR__ . '/ComponentFile.php', __DIR__ . '/TemplateCompiler.php', __DIR__ . '/CssScoper.php', dirname(__DIR__) . '/Expr/Parser.php'];
            self::$fingerprint = implode(',', array_map(fn ($f) => (string)@filemtime($f), $files));
        }
        return self::$fingerprint;
    }
}
