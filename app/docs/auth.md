# Вход, роли, middleware

## Сессия

```php
use Rig\Session;

Session::put('cart', [1, 2]);
Session::get('cart', []);
Session::flash('message', 'Сохранено');   // доступно только в следующем запросе
Session::forget('cart');
```

Cookie `rig_session` ставится с флагами HttpOnly и SameSite=Lax, на https ещё и Secure. Данные хранятся в `storage/sessions` без блокировок, поэтому параллельные запросы не ждут друг друга. Сессия создаётся, только когда в неё что-то пишут. Срок без активности задаёт `'session' => ['lifetime' => 120]` (в минутах).

## CSRF

Действия компонентов, загрузки файлов и запросы к API из браузера защищены токеном сессии, rig.js отправляет его сам. Для обычной HTML-формы с `method="post"` добавьте поле:

```php
<form method="post"><?= csrf_field() ?> …</form>
```

Если сессия истекла, страница перезагрузится сама и получит свежий токен.

## Вход

Таблица пользователей задаётся в `'auth' => ['table' => 'users']`. Колонки: `email`, `password` (хеш), `role`.

```php
use Rig\Auth;

if (Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
    redirect('/account');
}
Auth::user();             // ['id' => 1, 'email' => …, 'role' => 'admin'] без пароля, или null
Auth::check();            // вошёл ли
Auth::hasRole('admin');   // роль из колонки role (или roles — JSON-список)
Auth::login($user);       // без пароля: после регистрации
Auth::logout();
Auth::hash('пароль');     // для сохранения в базу
```

При входе сессия получает новый id. Подбор пароля ограничен: 5 неудачных попыток в минуту для одного email и IP, затем 429. После входа или выхода страница загружается целиком, поэтому меню и имя пользователя в layout обновятся.

## Middleware страниц

Первой строкой файла страницы:

```php
<?php
Rig\Page::middleware('auth');            // гостя — на /login?next=…
Rig\Page::middleware('role:admin');      // не админу — 403
Rig\Page::middleware('guest');           // вошедшего — на 'auth.home'
```

Список middleware подписывается в снимки компонентов страницы, и их действия проверяются так же. Подделать этот список из браузера нельзя.

## #[Guard] на компоненте

```php
#[Guard('auth')]
final class Profile extends Component
{
    #[Action, Guard('role:admin')]
    public function deleteUser(int $id): void { … }
}
```

Атрибут на классе проверяется при показе компонента и перед каждым его действием, как бы компонент ни попал на страницу (в том числе если он создан в браузере). Атрибут на методе проверяется перед этим действием. Компонентам с важными действиями ставьте `#[Guard]` всегда.

## Встроенные и свои middleware

| Имя | Что делает |
|---|---|
| `auth` | только вошедшие; гостя со страницы и из действия отправляет на вход, API отвечает 401 |
| `guest` | только гости |
| `role:admin,editor` | одна из ролей, иначе 403 |
| `throttle:60,1` | не больше 60 запросов за 1 минуту на адрес (с IP или пользователя), иначе 429 |

Свой:

```php
// app/middleware/Verified.php, имя «verified»
namespace App\Middleware;

final class Verified implements \Rig\Middleware
{
    public function handle(\Rig\Request $request, string ...$args): void
    {
        if (!(\Rig\Auth::user()['verified'] ?? false)) {
            redirect('/verify');
        }
    }
}
```

Чтобы middleware работал для всех запросов, укажите `'middleware' => ['verified']` в `config.php`.

## Перенаправление и ошибки

`redirect('/login')` работает со страницы, из действия компонента (браузер перейдёт сам), из middleware и API. `abort(403)` отдаёт HTTP-ошибку со страницей `app/errors/403.php` или с общей страницей ошибки.
