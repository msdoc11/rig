<?php

/**
 * /api/todos
 *   GET:  список задач (всем)
 *   POST: новая задача {"title": "…"} (только вошедшим)
 */

use App\Models\Todo;
use Rig\Auth;
use Rig\Request;
use Rig\Response;

return [
    'middleware' => ['throttle:60,1'],

    'GET' => fn () => Todo::query()->orderBy('id')->get(),

    'POST' => function (Request $request) {
        if (Auth::guest()) {
            abort(401);
        }
        $data = $request->validate(['title' => 'required|min:3|max:200']);
        $todo = Todo::create(['title' => trim($data['title']), 'user_id' => Auth::id()]);
        Rig\Push::publish('todos-changed');
        return Response::json($todo, 201);
    },
];
