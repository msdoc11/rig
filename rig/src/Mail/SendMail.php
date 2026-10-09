<?php

declare(strict_types=1);

namespace Rig\Mail;

use Rig\Job;

/** Задача очереди для Mail::…->queue(). */
final class SendMail implements Job
{
    public function handle(array $data): void
    {
        Message::fromArray($data)->send();
    }
}
