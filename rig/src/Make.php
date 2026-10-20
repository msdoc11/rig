<?php

declare(strict_types=1);

namespace Rig;

/**
 * Генераторы заготовок: rig make:component, make:page, make:model.
 * Каждый метод создаёт файл и возвращает его путь. Существующий файл не перезаписывается.
 */
final class Make
{
    /** app/components/Name.php или app/components/Admin/UserCard.php для имени Admin/UserCard. */
    public static function component(string $name): string
    {
        $name = str_replace('\\', '/', trim($name, '/\\'));
        if (!preg_match('~^[A-Z][A-Za-z0-9]*(/[A-Z][A-Za-z0-9]*)*$~', $name)) {
            throw new \RuntimeException("Имя компонента пишется с большой буквы латиницей: Counter или Admin/UserCard, а не «{$name}»");
        }
        $parts = explode('/', $name);
        $class = array_pop($parts);
        $ns = 'App\\Components' . ($parts ? '\\' . implode('\\', $parts) : '');
        $css = strtolower((string)preg_replace('/(?<=[a-z0-9])([A-Z])/', '-$1', $class));
        return self::write("app/components/{$name}.php", <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            use Rig\\Attr\\Action;
            use Rig\\Component;

            final class {$class} extends Component
            {
                public int \$count = 0;

                #[Action]
                public function increment(): void
                {
                    \$this->count++;
                }
            }
            ?>

            <template>
              <div class="{$css}">
                <p>{{ count }}</p>
                <button @click="increment">+</button>
              </div>
            </template>

            <style>
              .{$css} { display: flex; align-items: center; gap: 12px; }
            </style>

            PHP);
    }

    /** app/pages/about.php для about, app/pages/blog/[slug].php для blog/[slug]. */
    public static function page(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $path = (string)preg_replace('/\.php$/', '', $path);
        $segs = $path === '' ? [] : explode('/', $path);
        foreach ($segs as $s) {
            if (!preg_match('/^(\[\w+\]|[\p{L}\p{N}_][\p{L}\p{N}_.\-]*)$/u', $s)) {
                throw new \RuntimeException("Адрес страницы: части из букв, цифр, точки, дефиса или [параметр], например blog/[slug], а не «{$path}»");
            }
        }
        if (!$segs) {
            throw new \RuntimeException('Укажите адрес: rig make:page about или rig make:page blog/[slug]');
        }
        $last = end($segs);
        if (preg_match('/^\[(\w+)\]$/', $last, $m)) {
            $body = <<<PHP
                <?php
                // адрес вида /{$path}: значение из адреса лежит в \$params['{$m[1]}']
                \${$m[1]} = (string)\$params['{$m[1]}'];
                \$title = \${$m[1]};
                ?>
                <h1><?= htmlspecialchars(\${$m[1]}) ?></h1>

                PHP;
        } else {
            $title = mb_strtoupper(mb_substr($last, 0, 1)) . str_replace(['-', '_'], ' ', mb_substr($last, 1));
            $body = <<<PHP
                <?php
                \$title = '{$title}';
                ?>
                <h1>{$title}</h1>

                PHP;
        }
        return self::write("app/pages/{$path}.php", $body);
    }

    /** app/models/Name.php. */
    public static function model(string $name): string
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            throw new \RuntimeException("Имя модели пишется с большой буквы латиницей в единственном числе: Order, а не «{$name}»");
        }
        $table = Db\Model::tableFor($name);
        return self::write("app/models/{$name}.php", <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Models;

            use Rig\\Db\\Model;

            /** Таблица по умолчанию: {$table}. Другую задайте через protected static ?string \$table. */
            final class {$name} extends Model
            {
                /** Поля, которые можно заполнять из create() и update(). */
                protected static array \$fillable = [];

                protected static array \$casts = ['id' => 'int'];
            }

            PHP);
    }

    private static function write(string $rel, string $content): string
    {
        $file = Config::path($rel);
        if (is_file($file)) {
            throw new \RuntimeException("Файл {$rel} уже есть, перезаписывать его не буду");
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            throw new \RuntimeException('Не удалось создать папку ' . dirname($rel));
        }
        file_put_contents($file, $content);
        return $file;
    }
}
