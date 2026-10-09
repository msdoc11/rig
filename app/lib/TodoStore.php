<?php

declare(strict_types=1);

namespace App;

use App\Models\Todo;

/** Задачи демо хранятся в PostgreSQL (таблица todos, модель App\Models\Todo). */
final class TodoStore
{
    /** @return list<array{id:int,title:string,done:bool}> */
    public static function all(): array
    {
        return array_map(
            static fn (Todo $t) => ['id' => $t->id, 'title' => $t->title, 'done' => $t->done],
            Todo::query()->orderBy('id')->get(),
        );
    }

    public static function find(int $id): ?array
    {
        $t = Todo::find($id);
        return $t === null ? null : ['id' => $t->id, 'title' => $t->title, 'done' => $t->done];
    }

    public static function add(string $title): void
    {
        Todo::create(['title' => $title, 'user_id' => \Rig\Auth::id()]);
    }

    public static function setDone(int $id, bool $done): void
    {
        Todo::find($id)?->update(['done' => $done]);
    }

    public static function rename(int $id, string $title): void
    {
        Todo::find($id)?->update(['title' => $title]);
    }

    public static function remove(int $id): void
    {
        Todo::find($id)?->delete();
    }

    public static function clearDone(): void
    {
        Todo::where('done', true)->delete();
    }
}
