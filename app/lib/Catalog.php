<?php

declare(strict_types=1);

namespace App;

/** Каталог для демо. В настоящем проекте здесь будет запрос в БД. */
final class Catalog
{
    public const PER_PAGE = 9;

    private const NAMES = [
        'Кофе' => ['Эфиопия Иргачефф', 'Колумбия Супремо', 'Бразилия Сантос', 'Кения АА', 'Гватемала Антигуа', 'Эспрессо-смесь', 'Суматра Манделинг', 'Коста-Рика Тарразу', 'Руанда Бурбон', 'Декаф Мексика', 'Йемен Мокка', 'Панама Гейша'],
        'Чай' => ['Сенча', 'Да Хун Пао', 'Ассам', 'Дарджилинг', 'Те Гуань Инь', 'Пуэр шу', 'Лапсанг Сушонг', 'Бай Хао Инь Чжэнь', 'Матча', 'Габа', 'Эрл Грей', 'Ройбуш'],
        'Посуда' => ['Воронка V60', 'Кемекс', 'Френч-пресс', 'Гейзерная кофеварка', 'Чайник гунфу', 'Гайвань', 'Ручная кофемолка', 'Весы с таймером', 'Чашка 250 мл', 'Питчер', 'Аэропресс', 'Термос'],
    ];

    /** @return array{0: list<array>, 1: bool} товары страницы и есть ли ещё */
    public static function page(int $page): array
    {
        $all = self::all();
        $items = array_slice($all, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        return [$items, $page * self::PER_PAGE < count($all)];
    }

    /** Поиск по названию во всём каталоге. */
    public static function search(string $q, int $limit = 5): array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') {
            return [];
        }
        return array_slice(array_values(array_filter(self::all(), static fn (array $p) => str_contains(mb_strtolower($p['name']), $q))), 0, $limit);
    }

    private static function all(): array
    {
        $out = [];
        $id = 0;
        foreach (self::NAMES as $category => $names) {
            foreach ($names as $i => $name) {
                $id++;
                $out[] = [
                    'id' => $id,
                    'name' => $name,
                    'category' => $category,
                    'price' => 390 + ($id * 137) % 2600,
                    'hue' => ($id * 47) % 360,
                ];
            }
        }
        // перемешиваем детерминированно, чтобы категории шли вперемешку
        usort($out, fn (array $a, array $b) => ($a['id'] * 7919) % 101 <=> ($b['id'] * 7919) % 101);
        return $out;
    }
}
