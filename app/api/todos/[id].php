<?php

/**
 * /api/todos/{id} (только вошедшим)
 *   GET:    задача
 *   PATCH:  изменить {"title": "…", "done": true}
 *   DELETE: удалить
 */

use App\Models\Todo;
use Rig\Push;
use Rig\Request;

return [
    'middleware' => ['auth'],

    'GET' => fn (int $id) => Todo::findOrFail($id),

    'PATCH' => function (int $id, Request $request) {
        $todo = Todo::findOrFail($id);
        $data = $request->validate(['title' => 'min:3|max:200', 'done' => 'in:0,1,true,false']);
        $todo->update(array_filter([
            'title' => isset($data['title']) ? trim((string)$data['title']) : null,
            'done' => array_key_exists('done', $request->all()) ? filter_var($request->input('done'), FILTER_VALIDATE_BOOL) : null,
        ], static fn ($v) => $v !== null));
        Push::publish('todos-changed');
        return $todo;
    },

    'DELETE' => function (int $id) {
        Todo::findOrFail($id)->delete();
        Push::publish('todos-changed');
        return null; // 204
    },
];
