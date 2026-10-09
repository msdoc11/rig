<?php

use Rig\Auth;
use Rig\Csrf;
use Rig\DB;
use Rig\Db\Schema;
use Rig\Db\Table;
use Rig\Guards;
use Rig\HttpError;
use Rig\Redirect;
use Rig\Request;
use Rig\Session;

/** Следующий «запрос» того же браузера: cookie сессии сохраняется, а память процесса нет. */
function nextRequest(): void
{
    Session::commit();
    $id = Session::id();
    Session::reset();
    Auth::forgetUser();
    if ($id !== null) {
        $_COOKIE[Session::COOKIE] = $id;
    }
}

$_COOKIE = [];
Session::reset();
check('без записи сессия не создаётся', Session::id() === null && Session::get('x', 'нет') === 'нет');
Session::put('cart', [1, 2]);
$sid = Session::id();
check('put создаёт сессию', is_string($sid) && strlen($sid) === 40);
nextRequest();
check('данные переживают запрос', Session::get('cart') === [1, 2] && Session::id() === $sid);
Session::flash('msg', 'Сохранено');
nextRequest();
check('flash виден в следующем запросе', Session::get('msg') === 'Сохранено');
nextRequest();
check('flash исчезает после', Session::get('msg') === null);
$_COOKIE[Session::COOKIE] = str_repeat('a', 40);
Session::reset();
check('чужой/несуществующий id не принимается', Session::get('cart') === null);
$_COOKIE[Session::COOKIE] = '../../etc/passwd';
Session::reset();
check('id с путём отвергается', Session::id() === null);
$_COOKIE[Session::COOKIE] = $sid;
Session::reset();

$token = Csrf::token();
check('CSRF-токен стабилен в сессии', strlen($token) === 64 && Csrf::token() === $token);
check('CSRF: верный токен', Csrf::valid($token));
check('CSRF: неверный токен', !Csrf::valid('x') && !Csrf::valid(null));
Request::set(new Request(['REQUEST_METHOD' => 'POST', 'HTTP_X_CSRF_TOKEN' => 'плохой'], [], [], [], []));
check('CSRF: verify даёт 419', throws(fn () => Csrf::verify(Request::current()), 'устарела'));
Request::set(new Request(['REQUEST_METHOD' => 'POST'], [], ['_token' => $token], [], []));
check('CSRF: поле формы _token', !throws(fn () => Csrf::verify(Request::current())));

// пользователи
DB::disconnect();
Schema::create('users', function (Table $t) {
    $t->id();
    $t->string('email')->unique();
    $t->string('password');
    $t->string('role')->default('user');
});
DB::table('users')->insert(['email' => 'admin@rig.local', 'password' => Auth::hash('secret'), 'role' => 'admin']);
DB::table('users')->insert(['email' => 'user@rig.local', 'password' => Auth::hash('qwerty')]);
Request::set(new Request(['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '10.0.0.1', 'REQUEST_URI' => '/admin'], [], [], [], []));

check('гость', Auth::guest() && Auth::user() === null);
check('неверный пароль', !Auth::attempt(['email' => 'admin@rig.local', 'password' => 'нет']));
check('неизвестный email', !Auth::attempt(['email' => 'nobody@rig.local', 'password' => 'secret']));
$before = Session::id();
check('верный пароль — вход', Auth::attempt(['email' => 'admin@rig.local', 'password' => 'secret']));
check('после входа новый id сессии', Session::id() !== $before);
check('user() без пароля', Auth::user()['email'] === 'admin@rig.local' && !isset(Auth::user()['password']));
check('hasRole', Auth::hasRole('admin') && !Auth::hasRole('editor') && Auth::hasRole('editor', 'admin'));
nextRequest();
check('вход переживает запрос', Auth::check() && Auth::id() === 1);
check('CSRF-токен после входа сохранился', Csrf::valid($token));

Guards::$context = 'page';
check('middleware auth пропускает вошедшего', !throws(fn () => Guards::run(['auth'])));
check('middleware role:admin', !throws(fn () => Guards::run(['role:admin'])));
check('middleware role:editor: 403', throws(fn () => Guards::run(['role:editor']), 'Нет доступа'));
check('middleware guest: на главную', (function () {
    try {
        Guards::run(['guest']);
    } catch (Redirect $r) {
        return $r->to === '/';
    }
    return false;
})());

Auth::logout();
nextRequest();
check('выход', Auth::guest());
check('после выхода старый CSRF-токен недействителен', !Csrf::valid($token));
check('auth на странице: /login?next=/admin', (function () {
    try {
        Guards::run(['auth']);
    } catch (Redirect $r) {
        return $r->to === '/login?next=%2Fadmin';
    }
    return false;
})());
Guards::$context = 'api';
check('auth в API: 401', (function () {
    try {
        Guards::run(['auth']);
    } catch (HttpError $e) {
        return $e->status === 401;
    }
    return false;
})());
Guards::$context = 'page';
check('неизвестный middleware — понятная ошибка', throws(fn () => Guards::run(['nope']), 'app/middleware'));

for ($i = 0; $i < 5; $i++) {
    Auth::attempt(['email' => 'user@rig.local', 'password' => 'неверный']);
}
check('подбор пароля ограничен (429)', throws(fn () => Auth::attempt(['email' => 'user@rig.local', 'password' => 'qwerty']), 'Слишком много'));

Request::set(new Request(['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '10.0.0.2', 'REQUEST_URI' => '/api/x'], [], [], [], []));
check('throttle:3,1 — четвёртый запрос 429', (function () {
    for ($i = 0; $i < 3; $i++) {
        Guards::run(['throttle:3,1']);
    }
    return throws(fn () => Guards::run(['throttle:3,1']), 'Слишком много');
})());
