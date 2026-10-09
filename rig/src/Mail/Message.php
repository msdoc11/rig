<?php

declare(strict_types=1);

namespace Rig\Mail;

use Rig\Config;
use Rig\Log;
use Rig\Mail;
use Rig\Queue;

/** Письмо: адресаты, тема, текст и/или HTML, вложения. */
final class Message
{
    /** @var list<array{0:string,1:?string}> */
    public array $to = [];
    public array $cc = [];
    public array $bcc = [];
    public ?array $from = null;
    public ?array $replyTo = null;
    public string $subject = '';
    public ?string $text = null;
    public ?string $html = null;
    /** @var list<array{path:string,name:string}> */
    public array $attachments = [];

    public function to(string $address, ?string $name = null): self
    {
        $this->to[] = [self::address($address), $name];
        return $this;
    }

    public function cc(string $address, ?string $name = null): self
    {
        $this->cc[] = [self::address($address), $name];
        return $this;
    }

    public function bcc(string $address, ?string $name = null): self
    {
        $this->bcc[] = [self::address($address), $name];
        return $this;
    }

    public function from(string $address, ?string $name = null): self
    {
        $this->from = [self::address($address), $name];
        return $this;
    }

    public function replyTo(string $address, ?string $name = null): self
    {
        $this->replyTo = [self::address($address), $name];
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = str_replace(["\r", "\n"], ' ', $subject);
        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;
        return $this;
    }

    /** HTML из шаблона app/emails/{имя}.php; текстовая версия строится из него же, без тегов. */
    public function view(string $name, array $vars = []): self
    {
        if (!preg_match('/^[\w\-\/]+$/', $name)) {
            throw new \InvalidArgumentException("Недопустимое имя шаблона письма «{$name}»");
        }
        $file = Config::path("app/emails/{$name}.php");
        if (!is_file($file)) {
            throw new \RuntimeException("Нет шаблона письма app/emails/{$name}.php");
        }
        $html = (static function (string $__file, array $__vars): string {
            extract($__vars);
            ob_start();
            include $__file;
            return (string)ob_get_clean();
        })($file, $vars);
        $this->html = $html;
        $this->text ??= trim(html_entity_decode(strip_tags((string)preg_replace(['~<br\s*/?>~i', '~</(p|div|h\d|li|tr)>~i'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return $this;
    }

    public function attach(string $path, ?string $name = null): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Нет файла для вложения: {$path}");
        }
        $this->attachments[] = ['path' => $path, 'name' => $name ?? basename($path)];
        return $this;
    }

    public function send(): void
    {
        if (!$this->to && !$this->cc && !$this->bcc) {
            throw new \LogicException('У письма нет адресатов');
        }
        $this->from ??= [self::address((string)Config::get('mail.from.address', 'noreply@example.com')), (string)Config::get('mail.from.name', '')];
        $driver = (string)Config::get('mail.driver', 'log');
        match ($driver) {
            'array' => Mail::$sent[] = $this,
            'log' => $this->toLog(),
            'smtp' => Smtp::fromConfig()->send($this->from[0], array_map(static fn ($a) => $a[0], [...$this->to, ...$this->cc, ...$this->bcc]), $this->toMime()),
            default => throw new \InvalidArgumentException("Неизвестный драйвер почты «{$driver}»: smtp, log или array"),
        };
    }

    /** Отправить в фоне через очередь. */
    public function queue(): string
    {
        return Queue::push(SendMail::class, $this->toArray());
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $a): self
    {
        $m = new self();
        foreach ($a as $k => $v) {
            if (property_exists($m, $k)) {
                $m->$k = $v;
            }
        }
        return $m;
    }

    /** Письмо целиком в формате MIME (RFC 5322). */
    public function toMime(): string
    {
        $eol = "\r\n";
        $h = [
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::domain($this->from[0] ?? 'rig.local') . '>',
            'From: ' . self::mailbox($this->from ?? ['noreply@example.com', null]),
        ];
        if ($this->to) {
            $h[] = 'To: ' . implode(', ', array_map(self::mailbox(...), $this->to));
        }
        if ($this->cc) {
            $h[] = 'Cc: ' . implode(', ', array_map(self::mailbox(...), $this->cc));
        }
        if ($this->replyTo) {
            $h[] = 'Reply-To: ' . self::mailbox($this->replyTo);
        }
        $h[] = 'Subject: ' . self::encode($this->subject);
        $h[] = 'MIME-Version: 1.0';

        $text = $this->text ?? ($this->html !== null ? trim(strip_tags($this->html)) : '');
        $body = $this->html !== null
            ? self::multipart('alternative', [self::part('text/plain; charset=utf-8', $text), self::part('text/html; charset=utf-8', $this->html)])
            : self::part('text/plain; charset=utf-8', $text);
        if ($this->attachments) {
            $parts = [$body];
            foreach ($this->attachments as $a) {
                $type = (new \finfo(FILEINFO_MIME_TYPE))->file($a['path']) ?: 'application/octet-stream';
                $parts[] = 'Content-Type: ' . $type . '; name="' . self::encode($a['name']) . '"' . $eol
                    . 'Content-Transfer-Encoding: base64' . $eol
                    . 'Content-Disposition: attachment; filename="' . self::encode($a['name']) . '"' . $eol . $eol
                    . rtrim(chunk_split(base64_encode((string)file_get_contents($a['path'])), 76, $eol));
            }
            $body = self::multipart('mixed', $parts);
        }
        return implode($eol, $h) . $eol . $body;
    }

    private function toLog(): void
    {
        $line = str_repeat('=', 72) . "\n" . date('Y-m-d H:i:s') . "\n" . $this->toMime() . "\n";
        @file_put_contents(Config::storage('logs') . '/mail.log', $line, FILE_APPEND | LOCK_EX);
        Log::info('Письмо записано в storage/logs/mail.log', ['to' => array_column($this->to, 0), 'subject' => $this->subject]);
    }

    private static function part(string $type, string $content): string
    {
        return "Content-Type: {$type}\r\nContent-Transfer-Encoding: base64\r\n\r\n" . rtrim(chunk_split(base64_encode($content), 76, "\r\n"));
    }

    private static function multipart(string $kind, array $parts): string
    {
        $b = 'rig-' . bin2hex(random_bytes(10));
        $out = "Content-Type: multipart/{$kind}; boundary=\"{$b}\"\r\n\r\n";
        foreach ($parts as $p) {
            $out .= "--{$b}\r\n{$p}\r\n";
        }
        return $out . "--{$b}--";
    }

    private static function mailbox(array $a): string
    {
        [$addr, $name] = $a;
        return $name !== null && $name !== '' ? self::encode($name) . " <{$addr}>" : $addr;
    }

    /** Заголовок не в ASCII кодируется как =?UTF-8?B?…?= */
    private static function encode(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function domain(string $address): string
    {
        return substr((string)strrchr($address, '@'), 1) ?: 'rig.local';
    }

    private static function address(string $address): string
    {
        $address = trim($address);
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException("Некорректный адрес почты «{$address}»");
        }
        return $address;
    }
}
