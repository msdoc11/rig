<?php

declare(strict_types=1);

namespace Rig;

/**
 * Задача для очереди: выполняется в фоне (rig queue:work), а не во время запроса.
 *
 *   // app/jobs/SendWelcome.php
 *   namespace App\Jobs;
 *
 *   final class SendWelcome implements \Rig\Job
 *   {
 *       public function handle(array $data): void
 *       {
 *           \Rig\Mail::to($data['email'])->subject('Добро пожаловать')->text('…')->send();
 *       }
 *   }
 *
 *   Queue::push(SendWelcome::class, ['email' => $user['email']]);
 */
interface Job
{
    public function handle(array $data): void;
}
