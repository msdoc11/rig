<?php

declare(strict_types=1);

namespace Rig;

use Rig\Expr\Evaluator;

/**
 * Правила проверки полей: $this->validate(['email' => 'required|email|unique:users,email']).
 *
 *   required · min:N · max:N · email · int · numeric · url · date · in:a,b,c · regex:/…/
 *   unique:таблица,колонка · exists:таблица,колонка (проверка по базе)
 *   file · image · mimes:jpg,png,pdf · max:2048 (для файла в килобайтах)
 */
final class Validator
{
    public static function check(mixed $value, array $rules): ?string
    {
        // ссылку на загруженный файл из состояния компонента превращаем в файл
        if (is_array($value) && isset($value['$file'])) {
            $value = UploadedFile::fromRef($value);
        }
        // несколько файлов (input multiple): правила файла применяются к каждому
        if (is_array($value) && $value !== [] && array_is_list($value) && (is_array($value[0]) && isset($value[0]['$file']) || $value[0] instanceof UploadedFile)) {
            $each = array_values(array_filter($rules, static fn ($r) => !in_array(trim((string)$r), ['required', 'nullable'], true)));
            foreach (UploadedFile::many($value) as $f) {
                if (($m = self::check($f, $each)) !== null) {
                    return "{$f->name}: {$m}";
                }
            }
            return null;
        }
        $empty = $value === null || $value === '' || $value === [];
        foreach ($rules as $rule) {
            [$name, $arg] = array_pad(explode(':', trim((string)$rule), 2), 2, null);
            if ($name === 'required') {
                if ($empty) {
                    return 'Обязательное поле';
                }
                continue;
            }
            if ($name === 'nullable' || $empty) {
                continue;
            }
            $message = $value instanceof UploadedFile ? self::file($value, $name, (string)$arg) : self::value($value, $name, $arg);
            if ($message !== null) {
                return $message;
            }
        }
        return null;
    }

    private static function value(mixed $value, string $name, ?string $arg): ?string
    {
        $len = is_array($value) ? count($value) : (is_string($value) ? mb_strlen($value) : null);
        $limit = (float)$arg;
        return match ($name) {
            'min' => $len !== null
                ? ($len < $limit ? "Минимум {$arg} " . self::unit($value, (int)$arg) : null)
                : ((float)$value < $limit ? "Не меньше {$arg}" : null),
            'max' => $len !== null
                ? ($len > $limit ? "Максимум {$arg} " . self::unit($value, (int)$arg) : null)
                : ((float)$value > $limit ? "Не больше {$arg}" : null),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false ? 'Некорректный email' : null,
            'int' => filter_var($value, FILTER_VALIDATE_INT) === false ? 'Нужно целое число' : null,
            'numeric' => is_numeric($value) ? null : 'Нужно число',
            'url' => filter_var($value, FILTER_VALIDATE_URL) === false || !preg_match('~^https?://~i', (string)$value) ? 'Некорректная ссылка' : null,
            'date' => is_string($value) && strtotime($value) !== false ? null : 'Некорректная дата',
            'in' => in_array((string)$value, explode(',', (string)$arg), true) ? null : 'Недопустимое значение',
            'regex' => @preg_match((string)$arg, (string)$value) === 1 ? null : 'Неверный формат',
            'unique' => self::db($arg, $value) ? 'Уже занято' : null,
            'exists' => self::db($arg, $value) ? null : 'Не найдено',
            'file', 'image', 'mimes' => 'Нужен файл',
            default => throw new \InvalidArgumentException("Неизвестное правило валидации «{$name}»"),
        };
    }

    private static function file(UploadedFile $f, string $name, string $arg): ?string
    {
        return match ($name) {
            'file' => null,
            'image' => $f->isImage() ? null : 'Нужна картинка: JPG, PNG, GIF, WebP или AVIF',
            'mimes' => in_array($f->extension(), array_map('trim', explode(',', strtolower($arg))), true)
                ? null : 'Допустимые форматы: ' . str_replace(',', ', ', $arg),
            'max' => $f->size > (int)$arg * 1024 ? 'Файл больше ' . self::kb((int)$arg) : null,
            'min' => $f->size < (int)$arg * 1024 ? 'Файл меньше ' . self::kb((int)$arg) : null,
            'required', 'nullable' => null,
            default => throw new \InvalidArgumentException("Правило «{$name}» не применяется к файлу"),
        };
    }

    /** unique:users,email / exists:users,id. Если колонка не указана, проверяется id: имени поля здесь нет. */
    private static function db(?string $arg, mixed $value): bool
    {
        [$table, $column] = array_pad(explode(',', (string)$arg, 2), 2, null);
        if ($table === null || $table === '') {
            throw new \InvalidArgumentException('Правилам unique и exists нужна таблица: unique:users,email');
        }
        return DB::table(trim($table))->where(trim($column ?? 'id'), $value)->exists();
    }

    private static function kb(int $kb): string
    {
        return $kb >= 1024 ? rtrim(rtrim(number_format($kb / 1024, 1, ',', ''), '0'), ',') . ' МБ' : "{$kb} КБ";
    }

    private static function unit(mixed $value, int $n): string
    {
        return is_array($value)
            ? Evaluator::call('plural', [$n, 'элемент', 'элемента', 'элементов'])
            : Evaluator::call('plural', [$n, 'символ', 'символа', 'символов']);
    }
}
