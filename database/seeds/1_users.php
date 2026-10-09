<?php

use App\Models\User;
use Rig\Auth;

// демо-пользователи, у обоих пароль rig
return function () {
    foreach ([
        ['name' => 'Администратор', 'email' => 'admin@rig.local', 'role' => 'admin'],
        ['name' => 'Анна', 'email' => 'anna@rig.local', 'role' => 'user'],
    ] as $u) {
        if (User::where('email', $u['email'])->exists()) {
            continue;
        }
        $user = new User($u + ['password' => Auth::hash('rig')]);
        $user->role = $u['role'];
        $user->save();
    }
};
