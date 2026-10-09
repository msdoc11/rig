<?php

declare(strict_types=1);

namespace Rig\Db;

/**
 * Описание таблицы для Schema::create() и Schema::table().
 *
 *   Schema::create('todos', function (Table $t) {
 *       $t->id();
 *       $t->string('title');
 *       $t->boolean('done')->default(false);
 *       $t->foreignId('user_id')->constrained()->cascadeOnDelete();
 *       $t->timestamps();
 *   });
 */
final class Table
{
    /** @var list<Column> */
    public array $columns = [];
    /** @var list<array{0:string,1:list<string>,2:bool}> [имя, колонки, unique] */
    public array $indexes = [];
    /** @var list<string> */
    public array $drops = [];
    /** @var list<array{0:string,1:string}> */
    public array $renames = [];

    public function __construct(public readonly string $name)
    {
    }

    /** Первичный ключ id с автоувеличением. */
    public function id(string $name = 'id'): Column
    {
        $c = $this->add($name, 'id');
        $c->primary = true;
        return $c;
    }

    public function string(string $name, int $length = 255): Column
    {
        return $this->add($name, 'string', [$length]);
    }

    public function text(string $name): Column
    {
        return $this->add($name, 'text');
    }

    public function integer(string $name): Column
    {
        return $this->add($name, 'integer');
    }

    public function bigInteger(string $name): Column
    {
        return $this->add($name, 'bigInteger');
    }

    public function float(string $name): Column
    {
        return $this->add($name, 'float');
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): Column
    {
        return $this->add($name, 'decimal', [$precision, $scale]);
    }

    public function boolean(string $name): Column
    {
        return $this->add($name, 'boolean');
    }

    public function json(string $name): Column
    {
        return $this->add($name, 'json');
    }

    public function date(string $name): Column
    {
        return $this->add($name, 'date');
    }

    public function dateTime(string $name): Column
    {
        return $this->add($name, 'datetime');
    }

    public function timestamp(string $name): Column
    {
        return $this->add($name, 'datetime');
    }

    /** created_at и updated_at. */
    public function timestamps(): void
    {
        $this->add('created_at', 'datetime')->nullable();
        $this->add('updated_at', 'datetime')->nullable();
    }

    /** Ссылка на другую таблицу: foreignId('user_id')->constrained(). */
    public function foreignId(string $name): Column
    {
        return $this->add($name, 'bigInteger')->unsigned();
    }

    /** @param string|list<string> $columns */
    public function index(string|array $columns, ?string $name = null): void
    {
        $cols = (array)$columns;
        $this->indexes[] = [$name ?? $this->name . '_' . implode('_', $cols) . '_index', $cols, false];
    }

    /** @param string|list<string> $columns */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $cols = (array)$columns;
        $this->indexes[] = [$name ?? $this->name . '_' . implode('_', $cols) . '_unique', $cols, true];
    }

    /** Для Schema::table(): удалить колонку. */
    public function dropColumn(string ...$names): void
    {
        array_push($this->drops, ...$names);
    }

    /** Для Schema::table(): переименовать колонку. */
    public function renameColumn(string $from, string $to): void
    {
        $this->renames[] = [$from, $to];
    }

    private function add(string $name, string $type, array $args = []): Column
    {
        return $this->columns[] = new Column($name, $type, $args);
    }
}
