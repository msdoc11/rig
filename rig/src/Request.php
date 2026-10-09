<?php

declare(strict_types=1);

namespace Rig;

/**
 * Текущий HTTP-запрос: в API-маршрутах, middleware и страницах.
 *
 *   $r->method(); $r->query('page', 1); $r->input('title'); $r->file('photo');
 *   $data = $r->validate(['title' => 'required|min:3', 'photo' => 'image|max:2048']);
 */
final class Request
{
    private static ?self $current = null;
    private ?array $json = null;

    public function __construct(
        private readonly array $server,
        private readonly array $get,
        private readonly array $post,
        private readonly array $files,
        private readonly array $cookies,
        private readonly ?string $body = null,
    ) {
    }

    public static function current(): self
    {
        return self::$current ??= new self($_SERVER, $_GET, $_POST, $_FILES, $_COOKIE);
    }

    /** Для тестов: подменить текущий запрос. */
    public static function set(?self $request): void
    {
        self::$current = $request;
    }

    /** GET, POST, PUT, PATCH, DELETE. HTML-форма может прислать _method=PUT. */
    public function method(): string
    {
        $m = strtoupper((string)($this->server['REQUEST_METHOD'] ?? 'GET'));
        if ($m === 'POST' && is_string($this->post['_method'] ?? null)) {
            $o = strtoupper($this->post['_method']);
            if (in_array($o, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $o;
            }
        }
        return $m;
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    /** Безопасный метод, который не меняет данные: GET, HEAD, OPTIONS. */
    public function isSafe(): bool
    {
        return in_array($this->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function path(): string
    {
        return rawurldecode((string)(parse_url((string)($this->server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
    }

    public function url(): string
    {
        $https = ($this->server['HTTPS'] ?? '') === 'on' || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return ($https ? 'https' : 'http') . '://' . ($this->server['HTTP_HOST'] ?? 'localhost') . ($this->server['REQUEST_URI'] ?? '/');
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->get : ($this->get[$key] ?? $default);
    }

    /** Поле тела запроса: JSON или форма. Без ключа возвращает всё тело. */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        $all = $this->all();
        return $key === null ? $all : ($all[$key] ?? $default);
    }

    /** Тело запроса (JSON или форма) вместе с параметрами адреса; тело важнее. */
    public function all(): array
    {
        return $this->bodyData() + $this->get;
    }

    public function only(string ...$keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $v = $this->server[$key] ?? ($name === 'Content-Type' ? ($this->server['CONTENT_TYPE'] ?? null) : null);
        return is_string($v) ? $v : null;
    }

    public function cookie(string $name): ?string
    {
        $v = $this->cookies[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    /** Загруженный файл формы (multipart/form-data). Для name="photos[]" возвращает список. */
    public function file(string $name): UploadedFile|array|null
    {
        $f = $this->files[$name] ?? null;
        if (!is_array($f)) {
            return null;
        }
        if (is_array($f['name'] ?? null)) {
            $out = [];
            foreach (array_keys($f['name']) as $i) {
                $one = UploadedFile::fromPhp(['name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]]);
                if ($one !== null) {
                    $out[] = $one;
                }
            }
            return $out;
        }
        return UploadedFile::fromPhp($f);
    }

    public function ip(): string
    {
        return (string)($this->server['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    public function userAgent(): string
    {
        return (string)($this->server['HTTP_USER_AGENT'] ?? '');
    }

    public function isJson(): bool
    {
        return str_contains((string)$this->header('Content-Type'), 'json');
    }

    public function wantsJson(): bool
    {
        return str_contains((string)$this->header('Accept'), 'json');
    }

    /** Токен из заголовка Authorization: Bearer … */
    public function bearerToken(): ?string
    {
        $h = (string)$this->header('Authorization');
        return preg_match('/^Bearer\s+(.+)$/i', $h, $m) ? trim($m[1]) : null;
    }

    /**
     * Проверить поля по правилам (как $this->validate() в компоненте).
     * При ошибках ответ 422 с полями: {"message": "…", "errors": {"title": "Обязательное поле"}}.
     * Возвращает только проверенные поля.
     */
    public function validate(array $rules): array
    {
        $errors = [];
        $out = [];
        foreach ($rules as $field => $spec) {
            $value = $this->file((string)$field) ?? $this->input((string)$field);
            $message = Validator::check($value, is_array($spec) ? $spec : explode('|', $spec));
            if ($message !== null) {
                $errors[$field] = $message;
            } else {
                $out[$field] = $value;
            }
        }
        if ($errors) {
            throw new ValidationError($errors);
        }
        return $out;
    }

    private function bodyData(): array
    {
        if ($this->json === null) {
            $this->json = [];
            if ($this->isJson()) {
                $raw = $this->body ?? (string)file_get_contents('php://input');
                $d = json_decode($raw, true);
                $this->json = is_array($d) ? $d : [];
            } else {
                $this->json = $this->post;
            }
        }
        return $this->json;
    }
}
