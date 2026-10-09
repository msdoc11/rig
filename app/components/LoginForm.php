<?php

declare(strict_types=1);

namespace App\Components;

use App\ActivityLog;
use App\Jobs\LoginNotice;
use Rig\Attr\Action;
use Rig\Attr\Locked;
use Rig\Auth;
use Rig\Component;
use Rig\Queue;

/** Вход: пароль проверяется на сервере, письмо о входе уходит в фоне через очередь. */
final class LoginForm extends Component
{
    public string $email = '';
    public string $password = '';

    /** Куда вернуться после входа: ?next= со страницы, только адреса этого сайта. */
    #[Locked]
    public string $next = '/demo/account';

    #[Action]
    public function login(): void
    {
        $this->validate(['email' => 'required|email', 'password' => 'required']);
        $ok = Auth::attempt(['email' => trim($this->email), 'password' => $this->password]);
        $this->password = ''; // пароль не возвращаем в браузер и не кладём в снимок состояния
        if (!$ok) {
            $this->addError('password', 'Неверный email или пароль');
            return;
        }
        $user = Auth::user();
        Queue::push(LoginNotice::class, ['email' => $user['email'], 'name' => $user['name'], 'at' => date('d.m.Y H:i')]);
        ActivityLog::add("Вход: {$user['name']}");
        $this->broadcast('activity');
        redirect(str_starts_with($this->next, '/') && !str_starts_with($this->next, '//') ? $this->next : '/demo/account');
    }
}
?>

<template>
  <form class="login" @submit.prevent="login">
    <label>Email
      <input type="email" r-model="email" autocomplete="username" autofocus :aria-invalid="$errors.email ? 'true' : null">
    </label>
    <p r-if="$errors.email" class="error">{{ $errors.email }}</p>
    <label>Пароль
      <input type="password" r-model="password" autocomplete="current-password" :aria-invalid="$errors.password ? 'true' : null">
    </label>
    <p r-if="$errors.password" class="error">{{ $errors.password }}</p>
    <button :disabled="$busy">{{ $pending.login ? 'Проверяем…' : 'Войти' }}</button>
  </form>
</template>

<style>
  .login { display: grid; gap: 10px; max-width: 360px; }
  label { display: grid; gap: 4px; color: var(--muted); font-size: 14px; }
  input { min-height: 40px; padding: 6px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: var(--text); font: inherit; }
  input[aria-invalid="true"] { border-color: var(--danger); }
  .error { color: var(--danger); margin: -4px 0 0; font-size: 14px; }
  button { margin-top: 6px; }
</style>
