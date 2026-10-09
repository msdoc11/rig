<?php

/** /api/me: текущий пользователь (401 для гостя). */

return [
    'middleware' => ['auth'],
    'GET' => fn () => Rig\Auth::user(),
];
