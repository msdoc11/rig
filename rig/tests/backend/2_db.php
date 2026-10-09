<?php

use Rig\DB;
use Rig\Db\Migrator;
use Rig\Db\Model;
use Rig\Db\Schema;
use Rig\Db\Table;

Schema::create('users', function (Table $t) {
    $t->id();
    $t->string('email')->unique();
    $t->string('password');
    $t->string('role')->default('user');
    $t->timestamps();
});
Schema::create('todos', function (Table $t) {
    $t->id();
    $t->string('title');
    $t->boolean('done')->default(false);
    $t->json('tags')->nullable();
    $t->integer('views')->default(0);
    $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
    $t->timestamps();
});
check('hasTable', Schema::hasTable('todos') && !Schema::hasTable('nope'));
check('hasColumn', Schema::hasColumn('todos', 'done') && !Schema::hasColumn('todos', 'nope'));

$uid = DB::table('users')->insert(['email' => 'a@b.c', 'password' => 'x']);
check('insert возвращает id', $uid === 1, $uid);
DB::table('todos')->insert([
    ['title' => 'Первая', 'done' => true, 'user_id' => $uid],
    ['title' => 'Вторая', 'done' => false, 'user_id' => $uid],
    ['title' => 'Третья', 'done' => false, 'user_id' => null],
]);
check('count', DB::table('todos')->count() === 3);
check('where с bool', DB::table('todos')->where('done', false)->count() === 2);
check('where с оператором', DB::table('todos')->where('id', '>', 1)->count() === 2);
check('where массивом', DB::table('todos')->where(['done' => false, 'user_id' => $uid])->value('title') === 'Вторая');
check('where null', DB::table('todos')->where('user_id', null)->value('title') === 'Третья');
check('whereIn', DB::table('todos')->whereIn('id', [1, 3])->pluck('title') === ['Первая', 'Третья']);
check('whereIn пустой', DB::table('todos')->whereIn('id', [])->count() === 0);
check('условие в скобках', DB::table('todos')->where('done', true)->orWhere(fn ($q) => $q->where('id', 3)->where('done', false))->count() === 2);
check('orderBy + limit + offset', DB::table('todos')->orderBy('id', 'desc')->limit(1)->offset(1)->value('title') === 'Вторая');
check('pluck с ключом', DB::table('todos')->pluck('title', 'id') === [1 => 'Первая', 2 => 'Вторая', 3 => 'Третья']);
check('exists', DB::table('todos')->where('title', 'Вторая')->exists() && !DB::table('todos')->where('title', 'нет')->exists());
check('max / sum', DB::table('todos')->max('id') === 3 && DB::table('todos')->sum('id') === 6);
check('update', DB::table('todos')->where('id', 2)->update(['done' => true]) === 1 && DB::table('todos')->where('done', true)->count() === 2);
check('increment', DB::table('todos')->where('id', 1)->increment('views', 2) === 1 && DB::table('todos')->where('id', 1)->value('views') === 2);
check('delete', DB::table('todos')->where('id', 3)->delete() === 1 && DB::table('todos')->count() === 2);
$p = DB::table('todos')->orderBy('id')->paginate(1, 2);
check('paginate', $p['total'] === 2 && $p['pages'] === 2 && $p['page'] === 2 && $p['items'][0]['title'] === 'Вторая', $p);
check('инъекция через имя колонки запрещена', throws(fn () => DB::table('todos')->where('id; DROP TABLE todos', 1)->get(), 'Недопустимое имя'));
check('инъекция через оператор запрещена', throws(fn () => DB::table('todos')->where('id', '= 1 OR 1=1 --', 1)->get(), 'оператор'));
check('значение с кавычками — просто строка', DB::table('todos')->where('title', "' OR '1'='1")->count() === 0);
check('транзакция откатывается', throws(fn () => DB::transaction(function () {
    DB::table('todos')->insert(['title' => 'временная']);
    throw new RuntimeException('стоп');
})) && DB::table('todos')->where('title', 'временная')->count() === 0);
check('внешний ключ cascade', (function () use ($uid) {
    $u2 = DB::table('users')->insert(['email' => 'z@z.z', 'password' => 'x']);
    DB::table('todos')->insert(['title' => 'чужая', 'user_id' => $u2]);
    DB::table('users')->where('id', $u2)->delete();
    return DB::table('todos')->where('title', 'чужая')->count() === 0;
})());

require_once __DIR__ . '/models.inc';

check('имя таблицы по классу', Todo::table() === 'todos');
$t = Todo::create(['title' => 'Модель', 'tags' => ['a', 'b'], 'user_id' => $uid]);
check('create: id и метки времени', is_int($t->id) && $t->created_at !== null && $t->exists, $t->toArray());
$f = Todo::find($t->id);
check('find + приведение типов', $f instanceof Todo && $f->done === false && $f->tags === ['a', 'b'], $f?->toArray());
$f->done = true;
check('dirty', $f->isDirty('done') && !$f->isDirty('title'));
$f->save();
check('save обновляет', Todo::find($t->id)->done === true);
check('where на модели — объекты', Todo::where('done', true)->get()[0] instanceof Todo);
check('all', count(Todo::all()) === 3);
check('hidden не попадает в массив', !array_key_exists('password', TestUser::find($uid)->toArray()));
check('fillable: лишние поля не заполняются', (new TestUser(['email' => 'q', 'role' => 'admin']))->role === null);
check('belongsTo', $f->owner()?->email === 'a@b.c');
check('hasMany', TestUser::find($uid)->todos()->count() === 3);
check('json_encode модели', json_decode(json_encode($f), true)['title'] === 'Модель');
check('delete модели', $f->delete() && Todo::find($t->id) === null);
check('findOrFail: 404', throws(fn () => Todo::findOrFail(999), 'не найдена'));

// миграции во временной папке проекта
rrmdir("{$tmp}/database");
@mkdir("{$tmp}/database/migrations", 0777, true);
file_put_contents("{$tmp}/database/migrations/2026_01_01_000000_create_notes_table.php", <<<'PHP'
<?php
use Rig\Db\Schema;
use Rig\Db\Table;
return new class {
    public function up(): void { Schema::create('notes', function (Table $t) { $t->id(); $t->text('body'); }); }
    public function down(): void { Schema::dropIfExists('notes'); }
};
PHP);
$m = new Migrator();
check('migrate выполняет новые', $m->migrate() === ['2026_01_01_000000_create_notes_table'] && Schema::hasTable('notes'));
check('migrate повторно — ничего', $m->migrate() === []);
check('status', $m->status()[0]['ran'] === true);
$made = Migrator::make('add_color_to_notes');
check('make:migration для изменения таблицы', str_contains((string)file_get_contents($made), "Schema::table('notes'"));
file_put_contents($made, <<<'PHP'
<?php
use Rig\Db\Schema;
use Rig\Db\Table;
return new class {
    public function up(): void { Schema::table('notes', function (Table $t) { $t->string('color')->default('жёлтый'); }); }
    public function down(): void { Schema::table('notes', function (Table $t) { $t->dropColumn('color'); }); }
};
PHP);
check('вторая пачка', count($m->migrate()) === 1 && Schema::hasColumn('notes', 'color'));
DB::table('notes')->insert(['body' => 'x']);
check('default колонки', DB::table('notes')->value('color') === 'жёлтый');
check('rollback последней пачки', count($m->rollback()) === 1 && !Schema::hasColumn('notes', 'color') && Schema::hasTable('notes'));
check('fresh', count($m->fresh()) === 2 && !Schema::hasTable('todos') && Schema::hasTable('notes'));
