// rig.js: доставка событий #[On('chat.{roomId}')] по подставленным именам и r-model через computed-список.
const path = require('path');
const Rig = require(path.join(__dirname, '..', 'runtime', 'rig.js'));

let fail = 0;
const ok = (name, cond, got) => {
  if (!cond) {
    fail++;
    console.log('JS ✗ ' + name + (got !== undefined ? '  получено: ' + JSON.stringify(got) : ''));
  }
};

// корень компонента без DOM: тесту нужны только состояние и обработка событий
const el = { setAttribute() {}, removeAttribute() {}, querySelectorAll: () => [] };
const def = (over) => Object.assign({
  name: 'T', tag: '', tpl: [], server: [], locked: [], props: [], defaults: {}, client: {},
  cexpr: {}, cserver: [], actions: {}, on: {}, mounts: false, lazy: null, model: null, shared: [],
}, over);
const id = (n) => ({ k: 'id', n });
const lit = (v) => ({ k: 'lit', v });
const setField = (obj, field, v) => ({ k: 'set', op: '=', t: { k: 'mem', o: id(obj), p: lit(field) }, v: lit(v) });

/** Присваивание t.done = true внутри r-for="t in <list>", как это делает r-model на чекбоксе. */
function toggle(inst, list, index) {
  inst.view(); // пересчитать computed
  const s = Object.create(inst.base);
  s.t = inst.base[list][index];
  s.$$alias = { t: list };
  inst.exec(setField('t', 'done', true), s, null, false);
}

// --- r-model через computed-список
const todos = () => [{ id: 1, done: false }, { id: 2, done: false }, { id: 3, done: true }];
const open = { k: 'call', n: 'where', a: [id('todos'), lit('done'), lit(false)] };

let inst = new Rig.Instance(el, def({ server: ['todos'], defaults: { todos: todos() }, cexpr: { visible: open } }), null);
inst.dead = true; // без перерисовки DOM
toggle(inst, 'visible', 1);
ok('computed-список: изменённым считается todos', inst.dirty.has('todos') && !inst.dirty.has('visible'), [...inst.dirty]);
const u = inst.takeUpdates();
ok('computed-список: на сервер уходит todos с новым значением', u.todos && u.todos[1].done === true && u.todos[0].done === false, u);

// r-for="t in where(todos, 'done', false)" прямо в шаблоне: у списка нет имени
inst = new Rig.Instance(el, def({ server: ['todos'], defaults: { todos: todos() } }), null);
inst.dead = true;
{
  const s = Object.create(inst.base);
  s.t = Rig.ev(open, inst.base)[1];
  s.$$alias = { t: null };
  inst.exec(setField('t', 'done', true), s, null, false);
}
ok('where() в шаблоне: изменённым считается todos', [...inst.dirty].join() === 'todos' && inst.base.todos[1].done === true, [...inst.dirty]);

inst = new Rig.Instance(el, def({ server: ['todos'], defaults: { todos: todos() } }), null);
inst.dead = true;
toggle(inst, 'todos', 0);
ok('обычный список: как раньше', [...inst.dirty].join() === 'todos', [...inst.dirty]);

inst = new Rig.Instance(el, def({ server: ['todos'], locked: ['todos'], defaults: { todos: todos() }, cexpr: { visible: open } }), null);
inst.dead = true;
toggle(inst, 'visible', 0);
ok('#[Locked] через computed-список не отправляется', inst.dirty.size === 0, [...inst.dirty]);

inst = new Rig.Instance(el, def({ client: { rows: todos() }, cexpr: { visible: { k: 'call', n: 'where', a: [id('rows'), lit('done'), lit(false)] } } }), null);
inst.dead = true;
toggle(inst, 'visible', 0);
ok('#[Client]-список меняется в браузере без запроса', inst.dirty.size === 0 && inst.base.rows[0].done === true, inst.base.rows);

// --- события с параметрами
const chatDef = def({
  server: ['roomId'], locked: ['roomId'], defaults: { roomId: 0 },
  actions: { message: { p: ['d'], o: null }, ping: { p: [], o: null } },
  on: { 'chat.{roomId}': ['message'], ping: ['ping'] },
});
const room5 = new Rig.Instance(el, chatDef, { snap: 's', state: { roomId: 5 }, tp: [['chat.5', 'chat.{roomId}', 'sig5']] });
const room6 = new Rig.Instance(el, chatDef, { snap: 's', state: { roomId: 6 }, tp: [['chat.6', 'chat.{roomId}', 'sig6']] });
const calls = [];
for (const [name, i] of [['5', room5], ['6', room6]]) {
  i.call = (method, args) => calls.push(name + ':' + method + ':' + JSON.stringify(args));
  Rig.instances.push(i);
}
Rig.dispatch([['chat.5', { text: 'привет' }], ['chat.7', null], ['ping', null]]);
ok('chat.5 получает только комната 5, ping получают обе', calls.join(' | ') === '5:message:[{"text":"привет"}] | 5:ping:[] | 6:ping:[]', calls);
ok('подписка в хабе: имена с подписями', Rig.hubTopics() === '[["chat.5","sig5"],["chat.6","sig6"]]', Rig.hubTopics());
room6.destroy();
ok('компонент убран: его имя из подписки ушло', Rig.hubTopics() === '[["chat.5","sig5"]]', Rig.hubTopics());

process.exit(fail ? 1 : 0);
