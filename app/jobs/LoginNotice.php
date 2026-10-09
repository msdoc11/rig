<?php

declare(strict_types=1);

namespace App\Jobs;

use Rig\Job;
use Rig\Mail;

/** Письмо «Выполнен вход». Отправляется в фоне, чтобы вход не ждал почтовый сервер. */
final class LoginNotice implements Job
{
    public function handle(array $data): void
    {
        Mail::to($data['email'], $data['name'])
            ->subject('Вход в rig')
            ->view('login', $data)
            ->send();
    }
}
