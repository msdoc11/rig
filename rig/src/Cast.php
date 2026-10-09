<?php

declare(strict_types=1);

namespace Rig;

use Rig\Expr\Evaluator;

/** Приведение данных из браузера к типам свойств и параметров. Если значение не подходит, ответ 422. */
final class Cast
{
    public static function value(?\ReflectionType $type, mixed $v, string $what): mixed
    {
        if (!$type instanceof \ReflectionNamedType) {
            return $v;
        }
        $name = $type->getName();
        if ($name === 'mixed') {
            return $v;
        }
        if (!$type->isBuiltin()) {
            if ($name === UploadedFile::class) {
                return UploadedFile::fromRef($v);
            }
            throw new HttpError(500, "{$what}: в состоянии компонента допустимы скаляры, массивы и UploadedFile, а не {$name}");
        }
        if (($v === null || $v === '') && $type->allowsNull()) {
            return null;
        }
        switch ($name) {
            case 'int':
                if (is_int($v)) {
                    return $v;
                }
                if (is_float($v) && floor($v) === $v) {
                    return (int)$v;
                }
                if (is_string($v) && preg_match('/^\s*-?\d+\s*$/', $v)) {
                    return (int)$v;
                }
                if ($v === null || $v === '') {
                    return 0;
                }
                break;
            case 'float':
                if (is_int($v) || is_float($v)) {
                    return (float)$v;
                }
                if (is_string($v) && is_numeric($v)) {
                    return (float)$v;
                }
                if ($v === null || $v === '') {
                    return 0.0;
                }
                break;
            case 'string':
                if (is_string($v)) {
                    return $v;
                }
                if (is_int($v) || is_float($v)) {
                    return Evaluator::str($v);
                }
                if ($v === null) {
                    return '';
                }
                break;
            case 'bool':
                if (is_bool($v)) {
                    return $v;
                }
                if (in_array($v, [0, 1, '0', '1', ''], true)) {
                    return (bool)$v;
                }
                break;
            case 'array':
                if (is_array($v)) {
                    return $v;
                }
                break;
        }
        throw new HttpError(422, "{$what}: значение не подходит к типу {$name}");
    }

    /** Приводит аргументы действия из JSON-списка к типам параметров метода. */
    public static function args(\ReflectionMethod $m, array $args): array
    {
        if (!array_is_list($args)) {
            throw new HttpError(400, 'Аргументы действия должны быть списком');
        }
        $out = [];
        foreach ($m->getParameters() as $i => $p) {
            if ($p->isVariadic()) {
                break;
            }
            if (array_key_exists($i, $args)) {
                $out[] = self::value($p->getType(), $args[$i], '$' . $p->getName());
            } elseif ($p->isDefaultValueAvailable()) {
                $out[] = $p->getDefaultValue();
            } elseif ($p->allowsNull()) {
                $out[] = null;
            } else {
                throw new HttpError(400, "Не передан аргумент \${$p->getName()} для {$m->getName()}()");
            }
        }
        return $out;
    }
}
