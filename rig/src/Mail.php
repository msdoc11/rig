<?php

declare(strict_types=1);

namespace Rig;

use Rig\Mail\Message;

/**
 * Почта.
 *
 *   Mail::to('anna@example.com', 'Анна')
 *       ->subject('Заказ №42 оформлен')
 *       ->view('order', ['order' => $order])    // шаблон app/emails/order.php
 *       ->attach('/path/invoice.pdf')
 *       ->send();                                // или ->queue(), чтобы отправить в фоне
 *
 * Драйвер задаётся в 'mail.driver': smtp отправляет письма на самом деле (MAIL_HOST, MAIL_PORT, MAIL_USERNAME…),
 * log пишет их в storage/logs/mail.log (разработка), array только складывает в Mail::$sent (тесты).
 */
final class Mail
{
    /** Отправленные письма при драйвере array. @var list<Message> */
    public static array $sent = [];

    public static function to(string $address, ?string $name = null): Message
    {
        return (new Message())->to($address, $name);
    }
}
