<?php

declare(strict_types=1);

namespace Rig\Db;

/** Колонка в Schema::create(): $t->string('email')->unique(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(). */
final class Column
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public mixed $default = null;
    public bool $unique = false;
    public bool $index = false;
    public bool $unsigned = false;
    public bool $primary = false;
    /** @var array{table:string,column:string,onDelete:?string}|null */
    public ?array $references = null;

    public function __construct(public readonly string $name, public readonly string $type, public readonly array $args = [])
    {
    }

    public function nullable(bool $value = true): self
    {
        $this->nullable = $value;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;
        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;
        return $this;
    }

    public function index(): self
    {
        $this->index = true;
        return $this;
    }

    public function unsigned(): self
    {
        $this->unsigned = true;
        return $this;
    }

    /** Внешний ключ: $t->foreignId('user_id')->constrained() ссылается на users.id. */
    public function constrained(?string $table = null, string $column = 'id'): self
    {
        $table ??= preg_replace('/_id$/', '', $this->name) . 's';
        $this->references = ['table' => $table, 'column' => $column, 'onDelete' => null];
        return $this;
    }

    public function references(string $column): self
    {
        $this->references = ['table' => $this->references['table'] ?? '', 'column' => $column, 'onDelete' => $this->references['onDelete'] ?? null];
        return $this;
    }

    public function on(string $table): self
    {
        $this->references = ['table' => $table, 'column' => $this->references['column'] ?? 'id', 'onDelete' => $this->references['onDelete'] ?? null];
        return $this;
    }

    /** Удалить строки вместе с родительской. */
    public function cascadeOnDelete(): self
    {
        $this->references = ($this->references ?? ['table' => '', 'column' => 'id']) + ['onDelete' => null];
        $this->references['onDelete'] = 'CASCADE';
        return $this;
    }

    /** При удалении родительской строки записать NULL. */
    public function nullOnDelete(): self
    {
        $this->nullable = true;
        $this->references = ($this->references ?? ['table' => '', 'column' => 'id']) + ['onDelete' => null];
        $this->references['onDelete'] = 'SET NULL';
        return $this;
    }
}
