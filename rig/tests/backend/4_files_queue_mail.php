<?php

use Rig\Config;
use Rig\Mail;
use Rig\Queue;
use Rig\Request;
use Rig\Schedule;
use Rig\UploadedFile;
use Rig\ValidationError;
use Rig\Validator;

// --- загрузки: файл из «формы», временная папка, подписанная ссылка, store()
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$src = "{$tmp}/upload.png";
file_put_contents($src, $png);
$ref = UploadedFile::stash(['name' => '../../котик.php.png', 'type' => 'text/html', 'tmp_name' => $src, 'error' => UPLOAD_ERR_OK, 'size' => strlen($png)]);
check('stash: ссылка с подписью и превью', isset($ref['$file'], $ref['sig'], $ref['preview']) && $ref['type'] === 'image/png', $ref);
check('stash: имя очищено от пути', $ref['name'] === 'котик.php.png', $ref['name']);
$f = UploadedFile::fromRef($ref);
check('fromRef: тип по содержимому, а не от браузера', $f->isImage() && $f->mime() === 'image/png' && $f->extension() === 'png');
check('подменённое имя отвергается', throws(fn () => UploadedFile::fromRef(['name' => 'evil.php'] + $ref), 'Подпись'));
check('чужой id отвергается', throws(fn () => UploadedFile::fromRef(['$file' => str_repeat('0', 32)] + $ref), 'Подпись'));
check('правило image', Validator::check($ref, ['required', 'image']) === null);
check('правило max (КБ)', Validator::check($ref, ['max:0']) !== null && Validator::check($ref, ['max:1']) === null);
check('правило mimes', Validator::check($ref, ['mimes:jpg,pdf']) !== null && Validator::check($ref, ['mimes:png']) === null);
check('несколько файлов', Validator::check([$ref, $ref], ['image']) === null);
check('превью: верная подпись', UploadedFile::preview($ref['$file'], (string)preg_replace('/^.*sig=/', '', $ref['preview']))->status === 200);
check('превью: неверная подпись даёт 404', throws(fn () => UploadedFile::preview($ref['$file'], 'x')));
$url = $f->store('avatars');
check('store: адрес в public/uploads', (bool)preg_match('~^/uploads/avatars/[a-f0-9]{24}\.png$~', $url) && is_file(Config::path('public' . $url)), $url);
check('store: временный файл перенесён', throws(fn () => UploadedFile::fromRef($ref), 'устарел'));
check('нельзя сохранить .php', throws(fn () => UploadedFile::fromRef(UploadedFile::stash(['name' => 'a.txt', 'type' => '', 'tmp_name' => (function () use ($tmp) {
    file_put_contents("{$tmp}/a.txt", 'x');
    return "{$tmp}/a.txt";
})(), 'error' => 0, 'size' => 1]))->storeAs('x', 'shell.php'), 'нельзя'));
check('папка с .. запрещена', throws(fn () => $f->storeAs('../../etc', 'x.png')));

// --- валидация запроса API
Request::set(new Request(['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'], [], [], [], [], '{"title":"ab","email":"x@y.z"}'));
try {
    Request::current()->validate(['title' => 'required|min:3', 'email' => 'email']);
    check('validate: 422', false);
} catch (ValidationError $e) {
    check('validate: 422 с полями', $e->status === 422 && array_keys($e->errors) === ['title'], $e->errors);
}
check('правило in', Validator::check('b', ['in:a,b']) === null && Validator::check('c', ['in:a,b']) !== null);
check('правило url', Validator::check('https://rig.dev', ['url']) === null && Validator::check('javascript:alert(1)', ['url']) !== null);
check('правило unique по базе', Validator::check('admin@rig.local', ['unique:users,email']) === 'Уже занято' && Validator::check('new@rig.local', ['unique:users,email']) === null);

// --- очередь
final class TestJob implements Rig\Job
{
    public static array $done = [];
    public static int $fails = 0;

    public function handle(array $data): void
    {
        if (($data['fail'] ?? 0) > self::$fails) {
            self::$fails++;
            throw new RuntimeException('временная ошибка');
        }
        self::$done[] = $data['n'];
    }
}
Queue::push(TestJob::class, ['n' => 1]);
Queue::push(TestJob::class, ['n' => 2]);
Queue::push(TestJob::class, ['n' => 3], delay: 3600);
check('очередь: 3 задачи', Queue::size() === 3);
$r1 = Queue::runNext();
$r2 = Queue::runNext();
check('выполнены по порядку', TestJob::$done === [1, 2] && $r1['ok'] && $r2['ok']);
check('отложенная ждёт своего времени', Queue::runNext() === null && Queue::size() === 1);
Config::set('queue.tries', 2);
Queue::push(TestJob::class, ['n' => 4, 'fail' => 5]);
$r = Queue::runNext();
check('ошибка: повтор позже', !$r['ok'] && Queue::size() === 2);
// «промотать время»: перенести повтор на сейчас
foreach (glob(Config::path('storage/queue/default/pending') . '/*.json') as $file) {
    $item = json_decode(file_get_contents($file), true);
    if (($item['data']['n'] ?? 0) === 4) {
        rename($file, dirname($file) . '/00000000001-0-' . $item['id'] . '.json');
    }
}
Queue::runNext();
check('после tries — в проваленных', count(Queue::failed()) === 1 && Queue::failed()[0]['error'] === 'RuntimeException: временная ошибка');
check('retry возвращает в очередь', Queue::retry() === 1 && Queue::failed() === []);
check('не-Job не ставится', throws(fn () => Queue::push(stdClass::class)));
Queue::flushFailed();

// --- почта
Config::set('mail', ['driver' => 'array', 'from' => ['address' => 'noreply@rig.local', 'name' => 'rig']]);
@mkdir("{$tmp}/app/emails", 0777, true);
file_put_contents("{$tmp}/app/emails/welcome.php", '<p>Привет, <?= htmlspecialchars($name) ?>!</p>');
Mail::to('anna@example.com', 'Анна')->subject('Добро пожаловать в rig')->view('welcome', ['name' => 'Анна'])->send();
$m = Mail::$sent[0] ?? null;
check('письмо отправлено (array)', $m !== null && $m->to[0][0] === 'anna@example.com');
check('шаблон письма и текстовая версия', str_contains((string)$m->html, 'Привет, Анна!') && $m->text === 'Привет, Анна!', [$m->html, $m->text]);
$mime = $m->toMime();
check('MIME: тема в UTF-8', str_contains($mime, 'Subject: =?UTF-8?B?' . base64_encode('Добро пожаловать в rig') . '?='));
check('MIME: multipart/alternative', str_contains($mime, 'multipart/alternative') && str_contains($mime, 'From: rig <noreply@rig.local>'));
check('некорректный адрес', throws(fn () => Mail::to('не адрес')));
check('нет перевода строки в теме (инъекция заголовков)', !str_contains(Mail::to('a@b.c')->subject("x\r\nBcc: evil@x.y")->toMime(), "\r\nBcc:"));
Config::set('mail.driver', 'log');
Mail::to('log@example.com')->subject('В журнал')->text('Текст')->send();
check('драйвер log пишет в mail.log', str_contains((string)file_get_contents(Config::path('storage/logs/mail.log')), 'log@example.com'));
Config::set('queue.driver', 'sync');
Config::set('mail.driver', 'array');
Mail::$sent = [];
Mail::to('q@example.com')->subject('фон')->text('x')->queue();
check('->queue() через очередь', count(Mail::$sent) === 1 && Mail::$sent[0]->subject === 'фон');
Config::set('queue.driver', 'file');

// --- планировщик
Schedule::reset();
$ran = [];
@mkdir("{$tmp}/app", 0777, true);
file_put_contents("{$tmp}/app/schedule.php", '<?php');
Schedule::call(function () use (&$ran) { $ran[] = 'каждую минуту'; }, 'каждую минуту');
Schedule::call(function () use (&$ran) { $ran[] = 'в 9:30'; }, 'в 9:30')->dailyAt('09:30');
Schedule::call(function () use (&$ran) { $ran[] = 'по будням каждые 15'; }, 'будни')->cron('*/15 9-18 * * 1-5');
Schedule::call(function () { throw new RuntimeException('сломалось'); }, 'с ошибкой')->hourly();
$mon0930 = new DateTimeImmutable('2026-10-05 09:30'); // понедельник
$res = Schedule::runDue($mon0930);
check('в 09:30 понедельника — три задачи', $ran === ['каждую минуту', 'в 9:30', 'по будням каждые 15'], $ran);
$ran = [];
Schedule::runDue(new DateTimeImmutable('2026-10-04 09:30')); // воскресенье
check('в воскресенье будничная не запускается', $ran === ['каждую минуту', 'в 9:30'], $ran);
$res = Schedule::runDue(new DateTimeImmutable('2026-10-05 10:00'));
check('ошибка задачи не мешает остальным', count($res) === 3 && !end($res)['ok'] && end($res)['error'] === 'сломалось', $res);
check('неверное cron-выражение', throws(fn () => Schedule::call(fn () => null)->cron('* *')));
$t = (new Rig\Schedule\Task(fn () => null, 'x'))->cron('0-30/10 * * * *');
check('cron: диапазон с шагом', $t->isDue(new DateTimeImmutable('2026-10-05 10:20')) && !$t->isDue(new DateTimeImmutable('2026-10-05 10:40')));
$t = (new Rig\Schedule\Task(fn () => null, 'x'))->weeklyOn(7, '08:00');
check('cron: воскресенье = 7', $t->isDue(new DateTimeImmutable('2026-10-04 08:00')));
Schedule::reset();
