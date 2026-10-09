<?php

use App\Models\Todo;

return function () {
    if (Todo::query()->exists()) {
        return;
    }
    Todo::create(['title' => 'Открыть app/components/Counter.php', 'done' => true]);
    Todo::create(['title' => 'Поменять шаблон и сохранить — увидеть HMR']);
};
