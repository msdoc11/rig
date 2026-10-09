<?php

declare(strict_types=1);

namespace Rig\Mail;

use Rig\Config;

/** SMTP-клиент: SSL или STARTTLS, AUTH LOGIN. Без сторонних библиотек. */
final class Smtp
{
    /** @var resource|null */
    private $s = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 15,
    ) {
    }

    public static function fromConfig(): self
    {
        $host = (string)Config::get('mail.host', '');
        if ($host === '') {
            throw new \RuntimeException('Не указан SMTP-сервер: MAIL_HOST в .env');
        }
        return new self($host, (int)Config::get('mail.port', 587), strtolower((string)Config::get('mail.encryption', 'tls')),
            (string)Config::get('mail.username', ''), (string)Config::get('mail.password', ''));
    }

    /** @param list<string> $recipients */
    public function send(string $from, array $recipients, string $mime): void
    {
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $s = @stream_socket_client($remote, $errno, $errstr, $this->timeout);
        if ($s === false) {
            throw new \RuntimeException("SMTP: нет соединения с {$this->host}:{$this->port} — {$errstr}");
        }
        $this->s = $s;
        stream_set_timeout($s, $this->timeout);
        try {
            $this->expect(220);
            $helo = gethostname() ?: 'localhost';
            $this->cmd("EHLO {$helo}", 250);
            if ($this->encryption === 'tls') {
                $this->cmd('STARTTLS', 220);
                if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('SMTP: не удалось включить TLS');
                }
                $this->cmd("EHLO {$helo}", 250);
            }
            if ($this->username !== '') {
                $this->cmd('AUTH LOGIN', 334);
                $this->cmd(base64_encode($this->username), 334);
                $this->cmd(base64_encode($this->password), 235, true);
            }
            $this->cmd("MAIL FROM:<{$from}>", 250);
            foreach (array_unique($recipients) as $r) {
                $this->cmd("RCPT TO:<{$r}>", [250, 251]);
            }
            $this->cmd('DATA', 354);
            // строки, начинающиеся с точки, удваиваются (RFC 5321)
            $data = (string)preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $mime));
            $this->cmd($data . "\r\n.", 250);
            $this->cmd('QUIT', 221);
        } finally {
            fclose($s);
            $this->s = null;
        }
    }

    private function cmd(string $line, int|array $expect, bool $secret = false): void
    {
        fwrite($this->s, $line . "\r\n");
        $this->expect($expect, $secret ? '(скрыто)' : strtok($line, "\r\n"));
    }

    private function expect(int|array $codes, string $after = ''): void
    {
        $reply = '';
        while (($line = fgets($this->s, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break; // последняя строка многострочного ответа: «250 OK», а не «250-…»
            }
        }
        $code = (int)substr($reply, 0, 3);
        if (!in_array($code, (array)$codes, true)) {
            throw new \RuntimeException('SMTP: ' . ($after !== '' ? "на «{$after}» " : '') . 'сервер ответил ' . trim($reply));
        }
    }
}
