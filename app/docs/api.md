# API

Файл `app/api/todos.php` отвечает на `/api/todos`, а `app/api/todos/[id].php` на `/api/todos/5`. Ответы отдаются в JSON.

```php
// app/api/todos.php
use App\Models\Todo;
use Rig\Auth;
use Rig\Request;
use Rig\Response;

return [
    'middleware' => ['throttle:60,1'],

    'GET' => fn () => Todo::query()->orderBy('id')->get(),

    'POST' => function (Request $request) {
        if (Auth::guest()) {
            abort(401);
        }
        $data = $request->validate(['title' => 'required|min:3|max:200']);
        return Response::json(Todo::create($data), 201);
    },
];
```

```php
// app/api/todos/[id].php
return [
    'middleware' => ['auth'],
    'GET' => fn (int $id) => Todo::findOrFail($id),
    'DELETE' => function (int $id) {
        Todo::findOrFail($id)->delete();
        return null;   // 204
    },
];
```

## Обработчики

- Файл возвращает обработчики по методам (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`), одну функцию на все методы или просто данные для GET.
- Аргументы подставляются по типу и имени: `Request $request`, `array $params`. Параметры адреса подставляются по имени с приведением типа: `int $id` из `[id].php`.
- Массив, модель или список отдаются как JSON с кодом 200, `null` как 204, а `Response` как есть: `Response::json($data, 201)`, `Response::noContent()`, `Response::file($path)`.
- Если такого метода нет, ответ будет 405 с заголовком `Allow`; если нет маршрута, 404.

## Request

```php
$request->method();               // GET, POST…
$request->query('page', 1);       // ?page=
$request->input('title');         // поле JSON или формы
$request->all();
$request->file('photo');          // UploadedFile
$request->header('Accept');
$request->bearerToken();
$request->ip();
$data = $request->validate(['email' => 'required|email|unique:users,email']);
```

При ошибке проверки ответ 422: `{"error": {"message": "…"}, "errors": {"email": "Уже занято"}}`. Другие ошибки приходят как `{"error": {"message": "…"}}` с кодом ответа. Подробности ошибок 5xx видны только в режиме разработки.

## Из браузера и снаружи

Запросы из браузера с cookie сессии проверяются CSRF-токеном (`X-CSRF-Token`, он есть в `Rig.cfg.csrf`). Внешним клиентам без cookie токен не нужен. Для них используйте свой middleware с `bearerToken()`.
