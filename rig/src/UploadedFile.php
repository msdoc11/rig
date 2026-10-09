<?php

declare(strict_types=1);

namespace Rig;

/**
 * Загруженный файл.
 *
 * В компоненте:  public ?UploadedFile $photo = null;   и в шаблоне  <input type="file" r-model="photo">
 * Файл уходит на сервер сразу после выбора и лежит во временной папке, пока действие его не сохранит:
 *
 *   #[Action]
 *   public function save(): void
 *   {
 *       $this->validate(['photo' => 'required|image|max:2048']);   // max в килобайтах
 *       $url = $this->photo->store('avatars');                     // '/uploads/avatars/3f9c….jpg'
 *   }
 *
 * В браузере у файла есть name, size, type, а у картинки ещё preview для <img :src="photo.preview">.
 * Ссылка на временный файл подписана ключом приложения: подменить её из браузера нельзя.
 */
final class UploadedFile implements \JsonSerializable
{
    private const MIME_EXT = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif',
        'image/svg+xml' => 'svg', 'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv',
        'application/zip' => 'zip', 'application/json' => 'json', 'video/mp4' => 'mp4', 'audio/mpeg' => 'mp3',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    private function __construct(
        private string $path,
        public readonly string $name,
        public readonly int $size,
        public readonly string $type,
        private readonly ?string $id = null,
    ) {
    }

    /** Файл из $_FILES (обычная форма или API). */
    public static function fromPhp(array $f): ?self
    {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            throw new HttpError(422, self::uploadError((int)$f['error']));
        }
        $path = (string)$f['tmp_name'];
        return new self($path, self::cleanName((string)$f['name']), (int)$f['size'], self::detect($path));
    }

    /**
     * @internal /_rig/upload: сохранить файл из запроса во временную папку и вернуть подписанную ссылку.
     * @return array{'$file':string,name:string,size:int,type:string,sig:string,preview?:string}
     */
    public static function stash(array $f): array
    {
        $file = self::fromPhp($f) ?? throw new HttpError(422, 'Файл не выбран');
        $max = (int)Config::get('uploads.max', 10) * 1024 * 1024;
        if ($file->size > $max) {
            throw new HttpError(413, 'Файл больше ' . Config::get('uploads.max', 10) . ' МБ');
        }
        $id = bin2hex(random_bytes(16));
        $dir = Config::storage('uploads/tmp');
        $dest = "{$dir}/{$id}";
        if (!(is_uploaded_file($file->path) ? move_uploaded_file($file->path, $dest) : rename($file->path, $dest))) {
            throw new HttpError(500, 'Не удалось сохранить загруженный файл');
        }
        if (random_int(1, 50) === 1) {
            self::gc();
        }
        return (new self($dest, $file->name, $file->size, $file->type, $id))->jsonSerialize();
    }

    /** Файл по ссылке из состояния компонента. Для пустой ссылки null. */
    public static function fromRef(mixed $ref): ?self
    {
        if ($ref instanceof self || $ref === null || $ref === '') {
            return $ref === '' ? null : $ref;
        }
        if (!is_array($ref) || !is_string($ref['$file'] ?? null) || !preg_match('/^[a-f0-9]{32}$/', $ref['$file'])) {
            throw new HttpError(422, 'Некорректная ссылка на загруженный файл');
        }
        $id = $ref['$file'];
        $name = (string)($ref['name'] ?? '');
        $size = (int)($ref['size'] ?? 0);
        $type = (string)($ref['type'] ?? '');
        if (!hash_equals(self::sign($id, $name, $size, $type), (string)($ref['sig'] ?? ''))) {
            throw new HttpError(422, 'Подпись загруженного файла не совпала');
        }
        $path = Config::path("storage/uploads/tmp/{$id}");
        if (!is_file($path)) {
            throw new HttpError(410, "Загруженный файл «{$name}» устарел, выберите его снова");
        }
        return new self($path, $name, $size, $type, $id);
    }

    /** Список файлов по списку ссылок (input multiple в свойстве array). @return list<self> */
    public static function many(mixed $refs): array
    {
        return array_values(array_filter(array_map(self::fromRef(...), is_array($refs) && array_is_list($refs) ? $refs : [])));
    }

    /** @internal просмотр временной картинки: /_rig/upload?id=…&sig=… */
    public static function preview(string $id, string $sig): Response
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || !hash_equals(hash_hmac('sha256', "preview|{$id}", Config::key()), $sig)) {
            throw new HttpError(404, 'Не найдено');
        }
        $path = Config::path("storage/uploads/tmp/{$id}");
        $type = is_file($path) ? self::detect($path) : '';
        if (!str_starts_with($type, 'image/') || $type === 'image/svg+xml') {
            throw new HttpError(404, 'Не найдено');
        }
        return Response::file($path, 'preview', false)->header('Cache-Control', 'private, max-age=3600');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function size(): int
    {
        return $this->size;
    }

    /** MIME-тип по содержимому файла, а не по имени. */
    public function mime(): string
    {
        return $this->type;
    }

    public function extension(): string
    {
        return self::MIME_EXT[$this->type] ?? (strtolower((string)pathinfo($this->name, PATHINFO_EXTENSION)) ?: 'bin');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->type, 'image/') && $this->type !== 'image/svg+xml';
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Сохранить в public/uploads/{папка} под случайным именем. Возвращает адрес для браузера: /uploads/avatars/….jpg.
     * При $public = false сохраняет в storage/app/{папка} (закрыто от браузера) и возвращает путь к файлу.
     */
    public function store(string $dir = '', bool $public = true): string
    {
        return $this->storeAs($dir, bin2hex(random_bytes(12)) . '.' . $this->extension(), $public);
    }

    public function storeAs(string $dir, string $filename, bool $public = true): string
    {
        $dir = trim(str_replace('\\', '/', $dir), '/');
        if (str_contains($dir, '..') || !preg_match('/^[\w\-\/]*$/', $dir)) {
            throw new \InvalidArgumentException("Недопустимая папка «{$dir}»");
        }
        $filename = self::cleanName($filename);
        if (in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['php', 'phtml', 'phar', 'php5', 'php7', 'phps', 'htaccess'], true)) {
            throw new HttpError(422, 'Такие файлы сохранять нельзя');
        }
        $base = $public ? Config::path('public/uploads') : Config::path('storage/app');
        $target = rtrim("{$base}/{$dir}", '/');
        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }
        $dest = "{$target}/{$filename}";
        if (!(is_uploaded_file($this->path) ? move_uploaded_file($this->path, $dest) : rename($this->path, $dest))) {
            throw new \RuntimeException("Не удалось сохранить файл в {$dest}");
        }
        $this->path = $dest;
        return $public ? '/uploads/' . ltrim("{$dir}/{$filename}", '/') : $dest;
    }

    /** Ссылка для состояния компонента и браузера. */
    public function jsonSerialize(): array
    {
        if ($this->id === null) {
            return ['name' => $this->name, 'size' => $this->size, 'type' => $this->type];
        }
        $ref = ['$file' => $this->id, 'name' => $this->name, 'size' => $this->size, 'type' => $this->type,
            'sig' => self::sign($this->id, $this->name, $this->size, $this->type)];
        if ($this->isImage()) {
            $ref['preview'] = '/_rig/upload?id=' . $this->id . '&sig=' . hash_hmac('sha256', "preview|{$this->id}", Config::key());
        }
        return $ref;
    }

    private static function sign(string $id, string $name, int $size, string $type): string
    {
        return hash_hmac('sha256', "upload|{$id}|{$name}|{$size}|{$type}", Config::key());
    }

    private static function detect(string $path): string
    {
        return is_file($path) ? ((new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream') : 'application/octet-stream';
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string)preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '_', $name);
        return mb_substr($name !== '' ? $name : 'file', 0, 180);
    }

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл больше, чем разрешено на сервере (upload_max_filesize)',
            UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Сервер не смог сохранить файл',
            default => 'Ошибка загрузки файла',
        };
    }

    /** Временные файлы старше суток. */
    private static function gc(): void
    {
        foreach (glob(Config::path('storage/uploads/tmp') . '/*') ?: [] as $f) {
            if (@filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }
}
