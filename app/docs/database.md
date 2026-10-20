# База данных

Основная база rig: PostgreSQL. В разработке её не нужно устанавливать и настраивать: `rig dev` сам создаёт кластер проекта в `storage/postgres`, запускает его на отдельном порту и выполняет новые миграции. Также работают MySQL/MariaDB и SQLite.

## Подключение

В `.env`:

```bash
DB_CONNECTION=pgsql
DB_MANAGED=true          # rig dev запускает PostgreSQL проекта сам; на сервере — false
DB_HOST=127.0.0.1
DB_PORT=54329
DB_DATABASE=rig
DB_USERNAME=rig
DB_PASSWORD=секрет
PG_BIN=D:/OSPanel/modules/PostgreSQL-18/bin   # папка с pg_ctl и initdb; без неё rig ищет сам
```

С `DB_MANAGED=true` при первом `rig dev` создаётся кластер (`initdb`), дальше он запускается за секунду. `rig stop` останавливает и его. Отдельно: `rig db:start` и `rig db:stop`.

На продакшене задайте `DB_MANAGED=false`, а в `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` укажите свой сервер PostgreSQL.

## Запросы

```php
use Rig\DB;

DB::table('todos')->where('done', false)->orderBy('id')->get();      // список строк-массивов
DB::table('todos')->where('id', '>', 10)->whereIn('user_id', [1, 2])->count();
DB::table('todos')->insert(['title' => 'Купить хлеб']);              // id новой строки
DB::table('todos')->where('id', 5)->update(['done' => true]);
DB::table('todos')->where('done', true)->delete();
DB::table('todos')->orderBy('id')->paginate(20, $page);              // items, total, page, pages; items массивами

DB::transaction(function () {
    // всё внутри — одной транзакцией: исключение откатывает изменения
});
```

Значения всегда уходят параметрами, а имена колонок и операторы проверяются, поэтому SQL-инъекция через них невозможна. Своё выражение можно передать через `DB::raw('COUNT(*) AS n')` или `whereRaw('lower(email) = ?', [$email])`.

## Модели

```php
// app/models/Todo.php
namespace App\Models;

use Rig\Db\Model;

final class Todo extends Model
{
    protected static array $fillable = ['title', 'done', 'user_id'];
    protected static array $casts = ['done' => 'bool'];
}
```

```php
$todo = Todo::create(['title' => 'Купить хлеб']);
$todo->done = true;
$todo->save();

Todo::find(5);
Todo::findOrFail(5);                 // RecordNotFound; на странице, в действии и API это ответ 404
Todo::where('done', false)->orderBy('id')->get();
Todo::where('done', false)->paginate(20, $page);   // в items модели, как у get()
$todo->update(['title' => 'Купить молоко']);
$todo->delete();
```

| Свойство | Что задаёт |
|---|---|
| `$table` | таблица; по умолчанию имя класса во множественном числе: для `TodoList` это `todo_lists` |
| `$fillable` | поля, которые можно заполнить из массива; если пусто, то все, кроме `$guarded` |
| `$hidden` | поля, которые не попадают в массив и состояние компонента: пароли, токены |
| `$casts` | типы: `int`, `float`, `bool`, `string`, `array` (JSON в базе) |
| `$timestamps` | `created_at` и `updated_at` заполняются сами |

Модели можно класть в состояние компонента как есть, rig превратит их в массивы: `$this->todos = Todo::all()`.

## Связи

Связь описывается методом модели:

```php
final class Post extends Model
{
    public function author(): ?Model
    {
        return $this->belongsTo(User::class);          // posts.user_id ссылается на users.id
    }

    public function comments(): Query
    {
        return $this->hasMany(Comment::class);         // comments.post_id = posts.id
    }

    public function visibleComments(): Query
    {
        return $this->hasMany(Comment::class)->where('hidden', false);
    }
}
```

Вызов метода сразу делает запрос: `$post->author()` вернёт автора, `$post->comments()->count()` посчитает комментарии. Для одной записи это удобно. В цикле по списку получится запрос на каждую запись, то есть N+1.

### Жадная загрузка: with()

`with()` загружает связи для всего списка заранее, одним запросом на каждую связь:

```php
$posts = Post::with('author', 'comments.author')->latest()->get();   // 4 запроса на любое число постов

foreach ($posts as $post) {
    echo $post->author->name;                 // свойство, без запроса
    foreach ($post->comments as $c) {
        echo $c->author->name;
    }
}
```

- Загруженная связь читается свойством: `$post->author`, `$post->comments`. Метод `$post->author()` по-прежнему делает отдельный запрос.
- Точка задаёт вложенную связь: `comments.author` загрузит комментарии и авторов комментариев.
- Условие на связь передаётся функцией: `Post::with(['comments' => fn (Query $q) => $q->where('hidden', false)])`. Условия из самого метода связи, как в `visibleComments()`, тоже сохраняются.
- Условие действует на весь запрос связи: `limit(5)` даст пять комментариев на все посты вместе, а не на каждый.
- Загруженные связи попадают в `toArray()`, `json_encode()` и состояние компонента вложенными массивами, `$hidden` у связанной модели тоже соблюдается.

Сколько запросов ушло, показывает `DB::connection()->queries`. Им удобно проверять в тестах, что N+1 не появился.

## Миграции

```bash
rig make:migration create_todos_table
```

```php
// database/migrations/2026_10_09_120000_create_todos_table.php
use Rig\Db\Schema;
use Rig\Db\Table;

return new class {
    public function up(): void
    {
        Schema::create('todos', function (Table $t) {
            $t->id();
            $t->string('title', 200);
            $t->boolean('done')->default(false);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index('done');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
```

Колонки: `id`, `string`, `text`, `integer`, `bigInteger`, `float`, `decimal`, `boolean`, `json`, `date`, `dateTime`, `timestamps`, `foreignId`. Модификаторы: `nullable()`, `default()`, `unique()`, `index()`, `constrained()`, `cascadeOnDelete()`, `nullOnDelete()`. Для изменения таблицы: `Schema::table('todos', fn (Table $t) => $t->string('note')->nullable())`, `dropColumn`, `renameColumn`.

| Команда | Что делает |
|---|---|
| `rig migrate` | выполнить новые миграции (`rig dev` делает это сам) |
| `rig migrate --seed` | и затем сиды |
| `rig migrate:rollback --step=1` | откатить последнюю пачку |
| `rig migrate:status` | что выполнено |
| `rig migrate:fresh` | удалить все таблицы и выполнить всё заново (только в разработке) |

## Начальные данные

```php
// database/seeds/1_users.php
use App\Models\User;
use Rig\Auth;

return function () {
    User::create(['name' => 'Админ', 'email' => 'admin@example.com', 'password' => Auth::hash('secret')]);
};
```

`rig db:seed` выполняет все файлы по порядку имён, `rig db:seed --only=1_users` выполняет один файл.
