# Загрузка файлов

```php
use Rig\UploadedFile;

final class Profile extends Component
{
    public ?UploadedFile $photo = null;

    #[Action]
    public function saveAvatar(): void
    {
        $this->validate(['photo' => 'required|image|max:2048']);   // max — килобайты
        $url = $this->photo->store('avatars');                     // '/uploads/avatars/3f9c….jpg'
        $this->photo = null;
    }
}
?>

<template>
  <input type="file" accept="image/*" r-model="photo">
  <img r-if="photo.preview" :src="photo.preview" alt="">
  <button r-if="photo" @click="saveAvatar">Сохранить</button>
  <p r-if="$errors.photo">{{ $errors.photo }}</p>
</template>
```

## Как это работает

1. Файл уходит на сервер сразу после выбора (`/_rig/upload`) и лежит во временной папке `storage/uploads/tmp`. Пока он грузится, `$busy` равно true.
2. В свойство попадает подписанная ссылка: `name`, `size`, `type`, у картинки ещё `preview` для показа до сохранения. Подменить её из браузера нельзя.
3. Действие получает `UploadedFile` и сохраняет файл: `store('avatars')` кладёт его в `public/uploads/avatars` под случайным именем, `store('docs', public: false)` в `storage/app`, куда у браузера нет доступа.

Для нескольких файлов: `<input type="file" multiple r-model="photos">`, свойство `array` и `UploadedFile::many($this->photos)`.

## Проверки

| Правило | Что проверяет |
|---|---|
| `file` | это файл |
| `image` | картинка: JPG, PNG, GIF, WebP, AVIF |
| `mimes:jpg,png,pdf` | формат |
| `max:2048` | не больше 2048 КБ |

Тип определяется по содержимому, а не по имени файла. Файлы `.php` и подобные сохранить нельзя. Предел размера задаёт `'uploads' => ['max' => 10]` (МБ) в `config.php`. На сервере размер ограничивают ещё `client_max_body_size` в nginx и `upload_max_filesize` в PHP.

В API `$request->file('photo')` возвращает тот же `UploadedFile`.
