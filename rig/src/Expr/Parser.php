<?php

declare(strict_types=1);

namespace Rig\Expr;

/**
 * Парсер языка выражений шаблонов.
 *
 * Возвращает AST из простых массивов. Этот AST исполняют два интерпретатора
 * с одинаковыми правилами: Evaluator.php (SSR на сервере) и rig.js (браузер).
 *
 * Узлы: lit, id, mem, call, un, bin, cond, arr, obj, set (присваивание), seq (несколько через «;»).
 */
final class Parser
{
    private const OPS = [
        '===', '!==', '==', '!=', '<=', '>=', '&&', '||', '??', '+=', '-=', '*=',
        '(', ')', '[', ']', '{', '}', ',', '.', ':', '?', ';', '!', '+', '-', '*', '/', '%', '<', '>', '|', '=',
    ];

    private const LEVELS = [
        ['??'], ['||'], ['&&'], ['==', '!=', '===', '!=='], ['<', '>', '<=', '>='], ['+', '-'], ['*', '/', '%'],
    ];

    /** @var list<array{0:string,1:mixed,2:int}> */
    private array $t;
    private int $i = 0;

    private function __construct(private readonly string $src, private readonly bool $statements)
    {
        $this->t = self::tokenize($src);
    }

    /** Одно выражение без присваиваний: {{ ... }}, :attr, r-if, r-show, computed. */
    public static function expression(string $src): array
    {
        $p = new self($src, false);
        $e = $p->pipe();
        if ($p->is('=') || $p->is('+=') || $p->is('-=') || $p->is('*=')) {
            $p->fail('Присваивание допустимо только в обработчиках событий');
        }
        $p->expectEnd();
        return $e;
    }

    /** Обработчик события или optimistic: выражения через «;», можно присваивать. */
    public static function statements(string $src): array
    {
        $p = new self($src, true);
        $list = [];
        while (!$p->atEnd()) {
            if ($p->eat(';')) {
                continue;
            }
            $list[] = $p->assignment();
            if (!$p->atEnd() && !$p->is(';')) {
                $p->fail('Ожидалась «;» или конец выражения');
            }
        }
        return count($list) === 1 ? $list[0] : ['k' => 'seq', 'l' => $list];
    }

    /** @return list<array{0:string,1:mixed,2:int}> */
    public static function tokenize(string $s): array
    {
        $out = [];
        $n = strlen($s);
        $i = 0;
        while ($i < $n) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if (ctype_digit($c)) {
                preg_match('/\G\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/', $s, $m, 0, $i);
                $out[] = ['n', strpbrk($m[0], '.eE') !== false ? (float)$m[0] : (int)$m[0], $i];
                $i += strlen($m[0]);
                continue;
            }
            if ($c === '"' || $c === "'") {
                $j = $i + 1;
                $buf = '';
                while (true) {
                    if ($j >= $n) {
                        throw new SyntaxError("Незакрытая строка в `{$s}`");
                    }
                    $d = $s[$j];
                    if ($d === $c) {
                        break;
                    }
                    if ($d === '\\' && $j + 1 < $n) {
                        $buf .= match ($s[$j + 1]) { 'n' => "\n", 't' => "\t", default => $s[$j + 1] };
                        $j += 2;
                        continue;
                    }
                    $buf .= $d;
                    $j++;
                }
                $out[] = ['s', $buf, $i];
                $i = $j + 1;
                continue;
            }
            if (preg_match('/\G[A-Za-z_$][A-Za-z0-9_$]*/', $s, $m, 0, $i)) {
                $out[] = ['id', $m[0], $i];
                $i += strlen($m[0]);
                continue;
            }
            foreach (self::OPS as $op) {
                if (substr($s, $i, strlen($op)) === $op) {
                    $out[] = ['op', $op, $i];
                    $i += strlen($op);
                    continue 2;
                }
            }
            throw new SyntaxError("Неожиданный символ «{$c}» в `{$s}`");
        }
        $out[] = ['end', null, $n];
        return $out;
    }

    private function assignment(): array
    {
        $left = $this->pipe();
        foreach (['=', '+=', '-=', '*='] as $op) {
            if ($this->is($op)) {
                if ($left['k'] !== 'id' && $left['k'] !== 'mem') {
                    $this->fail('Присвоить можно только переменной или полю');
                }
                $this->i++;
                return ['k' => 'set', 'op' => $op, 't' => $left, 'v' => $this->assignment()];
            }
        }
        return $left;
    }

    /** value | helper(args) равносильно helper(value, args). */
    private function pipe(): array
    {
        $e = $this->ternary();
        while ($this->eat('|')) {
            $name = $this->ident();
            $args = [$e];
            if ($this->eat('(')) {
                array_push($args, ...$this->list(')'));
            }
            $e = ['k' => 'call', 'n' => $name, 'a' => $args];
        }
        return $e;
    }

    private function ternary(): array
    {
        $c = $this->binary(0);
        if (!$this->eat('?')) {
            return $c;
        }
        $t = $this->ternary();
        $this->expect(':');
        return ['k' => 'cond', 'c' => $c, 't' => $t, 'f' => $this->ternary()];
    }

    private function binary(int $level): array
    {
        if ($level === count(self::LEVELS)) {
            return $this->unary();
        }
        $l = $this->binary($level + 1);
        while (true) {
            $t = $this->t[$this->i];
            if ($t[0] !== 'op' || !in_array($t[1], self::LEVELS[$level], true)) {
                return $l;
            }
            $this->i++;
            $l = ['k' => 'bin', 'op' => $t[1], 'l' => $l, 'r' => $this->binary($level + 1)];
        }
    }

    private function unary(): array
    {
        foreach (['!', '-', '+'] as $op) {
            if ($this->eat($op)) {
                return ['k' => 'un', 'op' => $op, 'a' => $this->unary()];
            }
        }
        return $this->postfix();
    }

    private function postfix(): array
    {
        $e = $this->primary();
        while (true) {
            if ($this->eat('.')) {
                $t = $this->t[$this->i];
                if ($t[0] !== 'id' && !($t[0] === 'n' && is_int($t[1]))) {
                    $this->fail('Ожидалось имя поля');
                }
                $this->i++;
                $e = ['k' => 'mem', 'o' => $e, 'p' => ['k' => 'lit', 'v' => $t[1]]];
            } elseif ($this->eat('[')) {
                $p = $this->pipe();
                $this->expect(']');
                $e = ['k' => 'mem', 'o' => $e, 'p' => $p];
            } elseif ($this->is('(')) {
                if ($e['k'] !== 'id') {
                    $this->fail('Вызывать можно только функции по имени');
                }
                $this->i++;
                $e = ['k' => 'call', 'n' => $e['n'], 'a' => $this->list(')')];
            } else {
                return $e;
            }
        }
    }

    private function primary(): array
    {
        $t = $this->t[$this->i];
        if ($t[0] === 'n' || $t[0] === 's') {
            $this->i++;
            return ['k' => 'lit', 'v' => $t[1]];
        }
        if ($t[0] === 'id') {
            $this->i++;
            return match ($t[1]) {
                'true' => ['k' => 'lit', 'v' => true],
                'false' => ['k' => 'lit', 'v' => false],
                'null' => ['k' => 'lit', 'v' => null],
                default => ['k' => 'id', 'n' => $t[1]],
            };
        }
        if ($this->eat('(')) {
            $e = $this->pipe();
            $this->expect(')');
            return $e;
        }
        if ($this->eat('[')) {
            return ['k' => 'arr', 'i' => $this->list(']')];
        }
        if ($this->eat('{')) {
            $props = [];
            while (!$this->eat('}')) {
                $k = $this->t[$this->i];
                if (!in_array($k[0], ['id', 's', 'n'], true)) {
                    $this->fail('Ожидался ключ объекта');
                }
                $this->i++;
                $key = (string)$k[1];
                if ($this->eat(':')) {
                    $v = $this->pipe();
                } elseif ($k[0] === 'id') {
                    $v = ['k' => 'id', 'n' => $key];
                } else {
                    $this->fail('Ожидалось «:»');
                }
                $props[] = [$key, $v];
                if (!$this->eat(',')) {
                    $this->expect('}');
                    break;
                }
            }
            return ['k' => 'obj', 'p' => $props];
        }
        $this->fail('Неожиданный символ');
    }

    /** @return list<array> */
    private function list(string $close): array
    {
        $items = [];
        while (!$this->eat($close)) {
            $items[] = $this->pipe();
            if (!$this->eat(',')) {
                $this->expect($close);
                break;
            }
        }
        return $items;
    }

    private function ident(): string
    {
        $t = $this->t[$this->i];
        if ($t[0] !== 'id') {
            $this->fail('Ожидалось имя функции');
        }
        $this->i++;
        return $t[1];
    }

    private function is(string $op): bool
    {
        $t = $this->t[$this->i];
        return $t[0] === 'op' && $t[1] === $op;
    }

    private function eat(string $op): bool
    {
        if ($this->is($op)) {
            $this->i++;
            return true;
        }
        return false;
    }

    private function expect(string $op): void
    {
        if (!$this->eat($op)) {
            $this->fail("Ожидалось «{$op}»");
        }
    }

    private function atEnd(): bool
    {
        return $this->t[$this->i][0] === 'end';
    }

    private function expectEnd(): void
    {
        if (!$this->atEnd()) {
            $this->fail('Лишний текст');
        }
    }

    private function fail(string $message): never
    {
        $t = $this->t[$this->i];
        $near = $t[0] === 'end' ? 'конец выражения' : '«' . substr($this->src, $t[2], 16) . '»';
        throw new SyntaxError("{$message}: {$near} в `{$this->src}`");
    }
}
