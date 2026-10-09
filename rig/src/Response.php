<?php

declare(strict_types=1);

namespace Rig;

/**
 * Ответ API-маршрута. Обычно его не нужно создавать: массив, модель или список из обработчика
 * становятся JSON с кодом 200 (null даёт 204). Response нужен для своего кода и заголовков:
 *
 *   return Response::json($todo, 201);
 *   return Response::noContent();
 */
final class Response
{
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
        public ?string $file = null,
    ) {
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    /** Отдать файл: для скачивания ($download = true) или показа в браузере. */
    public static function file(string $path, ?string $name = null, bool $download = true): self
    {
        if (!is_file($path)) {
            throw new HttpError(404, 'Файл не найден');
        }
        $name ??= basename($path);
        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        return new self('', 200, [
            'Content-Type' => $type,
            'Content-Length' => (string)filesize($path),
            'Content-Disposition' => ($download ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
        ], $path);
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        Session::commit();
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header("{$k}: {$v}");
            }
            if (!isset($this->headers['Cache-Control'])) {
                header('Cache-Control: no-store');
            }
        }
        if ($this->file !== null) {
            readfile($this->file);
            return;
        }
        echo $this->body;
    }
}
