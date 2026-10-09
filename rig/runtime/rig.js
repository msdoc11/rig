/*!
 * rig.js: браузерная часть rig. Разработчик приложения её не пишет и не трогает.
 *
 *  1. исполняет AST выражений шаблона (тот же язык и те же правила, что Rig\Expr\Evaluator);
 *  2. рендерит компоненты (в том числе вложенные, со слотами) через виртуальный DOM;
 *  3. отправляет действия на сервер, применяя optimistic-изменения сразу;
 *  4. переходит между страницами без перезагрузки, layout и его компоненты остаются;
 *  5. держит соединение с хабом: HMR и события с сервера (broadcast).
 */
(function (root) {
  'use strict';

  // ------------------------------------------------------------ значения
  // Любое изменение правил здесь нужно повторить в Rig\Expr\Evaluator.

  const NUM_RE = /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/;
  const isArr = Array.isArray;
  const isObj = (v) => v !== null && typeof v === 'object';
  const hasOwn = (o, k) => Object.prototype.hasOwnProperty.call(o, k);
  const phpTrim = (s) => s.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
  const clone = (v) => (v === undefined ? null : JSON.parse(JSON.stringify(v)));

  function num(v) {
    if (typeof v === 'number') return v;
    if (typeof v === 'boolean') return v ? 1 : 0;
    if (typeof v === 'string') {
      const t = phpTrim(v);
      return NUM_RE.test(t) ? Number(t) : 0;
    }
    return 0;
  }

  const json = (v) => JSON.stringify(v === undefined ? null : v);

  function str(v) {
    if (v === null || v === undefined) return '';
    if (typeof v === 'boolean') return v ? 'true' : 'false';
    if (typeof v === 'number') return isFinite(v) ? String(v === 0 ? 0 : v) : '';
    if (typeof v === 'string') return v;
    return json(v);
  }

  function truthy(v) {
    if (v === null || v === undefined || v === false || v === '') return false;
    if (typeof v === 'number') return v !== 0 && !Number.isNaN(v);
    if (isArr(v)) return v.length > 0;
    if (isObj(v)) return Object.keys(v).length > 0;
    return true;
  }

  function eq(a, b) {
    if (typeof a === 'number' && typeof b === 'number') return a === b;
    if (isObj(a) && isObj(b)) return json(a) === json(b);
    return a === b || (a == null && b == null);
  }

  function cmp(a, b) {
    if (typeof a === 'string' && typeof b === 'string') return a < b ? -1 : a > b ? 1 : 0;
    const x = num(a), y = num(b);
    return x < y ? -1 : x > y ? 1 : 0;
  }

  function add(a, b) {
    if (isArr(a) && isArr(b)) return a.concat(b);
    if (typeof a === 'string' || typeof b === 'string') return str(a) + str(b);
    return num(a) + num(b);
  }

  function get(o, p) {
    if (!isObj(o) || (typeof p !== 'number' && typeof p !== 'string')) return null;
    if (isArr(o)) {
      if (!/^(0|[1-9]\d*)$/.test(String(p)) || !hasOwn(o, p)) return null;
      return o[p] === undefined ? null : o[p];
    }
    const proto = Object.getPrototypeOf(o);
    if (proto === Object.prototype || proto === null) {
      return hasOwn(o, p) && o[p] !== undefined ? o[p] : null;
    }
    // объекты браузера ($event.target.checked): читаем свойства, но не методы
    const v = o[p];
    return v === undefined || typeof v === 'function' ? null : v;
  }

  function rnd(v, d) {
    const f = 10 ** d;
    return (Math.sign(v) * Math.floor(Math.abs(v) * f + 0.5)) / f + 0;
  }

  const list = (v) => (isArr(v) ? v : isObj(v) ? Object.values(v) : null);

  function pick(args, better) {
    const l = args.length === 1 && isObj(args[0]) ? list(args[0]) : args;
    if (!l.length) return null;
    let r = num(l[0]);
    for (const v of l) {
      const x = num(v);
      if (better(x, r)) r = x;
    }
    return r;
  }

  /** Функции шаблонов. Тот же набор в Evaluator::HELPERS. */
  const H = {
    len: (v) => (isArr(v) ? v.length : isObj(v) ? Object.keys(v).length : [...str(v)].length),
    upper: (v) => str(v).toUpperCase(),
    lower: (v) => str(v).toLowerCase(),
    trim: (v) => phpTrim(str(v)),
    round: (v, d = 0) => rnd(num(v), Math.trunc(num(d))),
    fixed: (v, d = 2) => {
      d = Math.max(0, Math.min(20, Math.trunc(num(d))));
      return rnd(num(v), d).toFixed(d);
    },
    abs: (v) => Math.abs(num(v)),
    floor: (v) => Math.floor(num(v)),
    ceil: (v) => Math.ceil(num(v)),
    min: (...a) => pick(a, (x, r) => x < r),
    max: (...a) => pick(a, (x, r) => x > r),
    json: (v) => json(v),
    join: (v, sep = ', ') => (isObj(v) ? list(v).map(str).join(str(sep)) : str(v)),
    keys: (v) => (isArr(v) ? v.map((_, i) => i) : isObj(v) ? Object.keys(v) : []),
    values: (v) => (list(v) || []).slice(),
    includes: (h, n) => (isObj(h) ? list(h).some((x) => eq(x, n)) : str(h).includes(str(n))),
    push: (a, ...v) => [...(list(a) || []), ...v],
    without: (a, v) => (list(a) || []).filter((x) => !eq(x, v)),
    where: (a, k, v) => (list(a) || []).filter((x) => eq(get(x, k), v)),
    whereNot: (a, k, v) => (list(a) || []).filter((x) => !eq(get(x, k), v)),
    pluck: (a, k) => (list(a) || []).map((x) => get(x, k)),
    sum: (a, k) => (list(a) || []).reduce((s, x) => s + num(k == null ? x : get(x, k)), 0),
    slice: (v, s = 0, e = null) => {
      const arr = isObj(v);
      const items = arr ? list(v) : [...str(v)];
      const n = items.length;
      let a = Math.trunc(num(s));
      a = a < 0 ? Math.max(n + a, 0) : Math.min(a, n);
      let b = e == null ? n : Math.trunc(num(e));
      b = b < 0 ? Math.max(n + b, 0) : Math.min(b, n);
      const out = items.slice(a, Math.max(a, b));
      return arr ? out : out.join('');
    },
    plural: (n, ...forms) => {
      n = Math.abs(Math.trunc(num(n)));
      const m10 = n % 10, m100 = n % 100;
      const i = m10 === 1 && m100 !== 11 ? 0 : m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14) ? 1 : 2;
      return str(forms[i]);
    },
    string: (v) => str(v),
    number: (v) => num(v),
    t: (key, params) => translate(str(key), isObj(params) ? params : {}),
  };

  /** Перевод из словаря страницы (Rig\I18n::client). Правила те же, что в Rig\I18n::t. */
  function translate(key, params) {
    const i18n = (Rig.cfg && Rig.cfg.i18n) || null;
    let text = i18n && hasOwn(i18n.messages, key) ? i18n.messages[key] : key;
    const n = hasOwn(params, 'n') ? params.n : hasOwn(params, 'count') ? params.count : null;
    if (n !== null && text.includes('|')) {
      const forms = text.split('|');
      text = forms[Math.min(forms.length - 1, pluralForm(i18n ? i18n.locale : 'ru', num(n)))];
    }
    return text.replace(/\{(\w+)\}/g, (m, k) => (hasOwn(params, k) ? str(params[k]) : m));
  }

  function pluralForm(locale, n) {
    n = Math.abs(n);
    const i = Math.trunc(n);
    if (['ru', 'uk', 'be', 'sr', 'hr', 'bs'].includes(locale)) {
      if (i !== n) return 1;
      const m10 = i % 10, m100 = i % 100;
      return m10 === 1 && m100 !== 11 ? 0 : m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14) ? 1 : 2;
    }
    return n === 1 ? 0 : 1;
  }

  // ------------------------------------------------------------ интерпретатор

  function ev(n, s) {
    switch (n.k) {
      case 'lit':
        return n.v;
      case 'id': {
        const v = s[n.n];
        return v === undefined ? null : v;
      }
      case 'mem':
        return get(ev(n.o, s), ev(n.p, s));
      case 'un': {
        const a = ev(n.a, s);
        return n.op === '!' ? !truthy(a) : n.op === '-' ? -num(a) : num(a);
      }
      case 'bin':
        return bin(n, s);
      case 'cond':
        return truthy(ev(n.c, s)) ? ev(n.t, s) : ev(n.f, s);
      case 'arr':
        return n.i.map((x) => ev(x, s));
      case 'obj': {
        const o = {};
        for (const [k, v] of n.p) o[k] = ev(v, s);
        return o;
      }
      case 'call': {
        const args = n.a.map((x) => ev(x, s));
        if (hasOwn(H, n.n)) return H[n.n](...args);
        if (s.$$call) return s.$$call(n.n, args);
        throw new Error('Неизвестная функция ' + n.n);
      }
      case 'set':
        return assign(n, s);
      case 'seq': {
        let r = null;
        for (const x of n.l) r = ev(x, s);
        return r;
      }
    }
    throw new Error('Неизвестный узел ' + n.k);
  }

  function bin(n, s) {
    const l = ev(n.l, s);
    switch (n.op) {
      case '&&': return truthy(l) ? ev(n.r, s) : l;
      case '||': return truthy(l) ? l : ev(n.r, s);
      case '??': return l !== null ? l : ev(n.r, s);
    }
    const r = ev(n.r, s);
    switch (n.op) {
      case '+': return add(l, r);
      case '-': return num(l) - num(r);
      case '*': return num(l) * num(r);
      case '/': { const d = num(r); return d === 0 ? null : num(l) / d; }
      case '%': { const d = num(r); return d === 0 ? null : num(l) % d; }
      case '==': case '===': return eq(l, r);
      case '!=': case '!==': return !eq(l, r);
      case '<': return cmp(l, r) < 0;
      case '>': return cmp(l, r) > 0;
      case '<=': return cmp(l, r) <= 0;
      case '>=': return cmp(l, r) >= 0;
    }
    throw new Error('Неизвестный оператор ' + n.op);
  }

  /** Имя корневого свойства состояния, через которое идёт присваивание (с учётом переменных цикла). */
  function rootName(t, s) {
    while (t.k === 'mem') t = t.o;
    if (t.k !== 'id') return null;
    const alias = s.$$alias;
    return alias && hasOwn(alias, t.n) ? alias[t.n] : t.n;
  }

  function assign(n, s) {
    const t = n.t;
    let v = ev(n.v, s);
    if (n.op === '+=') v = add(ev(t, s), v);
    else if (n.op === '-=') v = num(ev(t, s)) - num(v);
    else if (n.op === '*=') v = num(ev(t, s)) * num(v);
    let target = null;
    if (t.k === 'id') {
      let o = s;
      while (!hasOwn(o, t.n) && Object.getPrototypeOf(o)) o = Object.getPrototypeOf(o);
      o[t.n] = v;
    } else {
      target = ev(t.o, s);
      if (!isObj(target)) throw new Error('Нельзя записать поле: значение пустое');
      target[ev(t.p, s)] = v;
    }
    // target: изменённый объект; по нему компонент найдёт свойство, если путь шёл через computed-список
    if (s.$$dirty) s.$$dirty(rootName(t, s), target);
    return v;
  }

  // ------------------------------------------------------------ виртуальный DOM
  // vnode: {t:1} элемент, {t:3} текст, {t:8} комментарий (пустой r-if), {t:'c'} вложенный компонент

  function renderList(nodes, s, out, kp, inst) {
    for (let i = 0; i < nodes.length; i++) renderNode(nodes[i], s, out, kp + i, inst);
    return out;
  }

  function renderNode(n, s, out, key, inst) {
    switch (n.t) {
      case 'tx':
        out.push({ t: 3, key, text: n.p.map((p) => (typeof p === 'string' ? p : str(ev(p, s)))).join('') });
        return;
      case 'if':
        for (let b = 0; b < n.br.length; b++) {
          const [cond, node] = n.br[b];
          if (cond === null || truthy(ev(cond, s))) {
            renderNode(node, s, out, key + '?' + b, inst);
            return;
          }
        }
        out.push({ t: 8, key: key + '?' });
        return;
      case 'for': {
        const src = ev(n.s, s);
        const entries = isArr(src) ? src.map((v, i) => [i, v]) : isObj(src) ? Object.entries(src) : [];
        const alias = Object.assign(Object.create(null), s.$$alias || null, { [n.v]: rootName(n.s, s) });
        const seen = new Set();
        for (const [i, item] of entries) {
          const cs = Object.create(s);
          cs[n.v] = item;
          if (n.i) cs[n.i] = i;
          cs.$$alias = alias;
          let k = n.n.key ? key + '#' + str(ev(n.n.key, cs)) : key + ':' + i;
          while (seen.has(k)) k += '~';
          seen.add(k);
          renderNode(n.n, cs, out, k, inst);
        }
        return;
      }
      case 'slot': {
        // содержимое слота принадлежит родителю: его состояние, его обработчики
        const slot = inst.slots[n.n];
        if (slot && slot.raw) out.push({ t: 'r', key: key + '$', node: slot.raw }); // статичный HTML со страницы
        else if (slot) renderList(slot.nodes, slot.scope, out, key + '$', slot.inst);
        else renderList(n.c, s, out, key + '$', inst);
        return;
      }
      case 'cmp':
        renderCmp(n, s, out, key, inst);
        return;
    }
    renderEl(n, s, out, key, inst);
  }

  const FORM_TAG = /^(input|textarea|select)$/i;

  function renderEl(n, s, out, key, inst) {
    const v = { t: 1, tag: n.tag, key, attrs: {}, props: {}, on: {}, ch: null };
    for (const k in n.a) v.attrs[k] = n.a[k];
    for (const k in n.b) {
      const val = ev(n.b[k], s);
      setAttr(v.attrs, k, val);
      if (k === 'checked') v.props.checked = truthy(val);
      else if (k === 'value' && FORM_TAG.test(n.tag)) v.props.value = str(val);
    }
    if (n.show && !truthy(ev(n.show, s))) v.attrs.style = join([v.attrs.style, 'display:none'], ';');
    if (n.m) bindModel(v, n.m, s, inst);
    for (const h of n.on) addListener(v, h.e, makeHandler(h, s, inst));
    if (n.tr) v.tr = n.tr;
    if (n.js) {
      // r-js: содержимым элемента управляет модуль app/js/{имя}.js
      v.js = n.js;
      v.jsProps = n.jp ? clone(ev(n.jp, s)) : null;
      v.owner = inst;
    }
    v.ch = renderList(n.c, s, [], key + '/', inst);
    out.push(v);
  }

  function renderCmp(n, s, out, key, inst) {
    const v = { t: 'c', key, name: n.name, props: {}, attrs: {}, slots: {}, on: {}, owner: inst };
    for (const k in n.p) v.props[k] = ev(n.p[k], s);
    for (const k in n.a) v.attrs[k] = n.a[k];
    for (const k in n.b) setAttr(v.attrs, k, ev(n.b[k], s));
    if (n.show && !truthy(ev(n.show, s))) v.attrs.style = join([v.attrs.style, 'display:none'], ';');
    for (const name in n.slots) v.slots[name] = { nodes: n.slots[name], scope: s, inst };
    for (const h of n.on) v.on[h.e] = (data) => inst.exec(h.h, s, data === undefined ? null : data, false);
    if (n.m) {
      // r-model на компоненте: когда дочерний присвоил свойству с #[Model], пишем значение в выражение родителя
      const set = (data) => inst.exec({ k: 'set', op: '=', t: n.m.p, v: { k: 'lit', v: data } }, s, null, false);
      const prev = v.on['update:' + n.m.prop];
      v.on['update:' + n.m.prop] = prev ? (d) => { set(d); prev(d); } : set;
    }
    if (n.tr) v.tr = n.tr;
    out.push(v);
  }

  /** Время из модификаторов: .debounce.300ms даёт 300, без указания времени тоже 300. Если модификатора нет, 0. */
  function timing(mods, kind) {
    const i = mods.indexOf(kind);
    if (i < 0) return 0;
    const next = mods[i + 1];
    return next !== undefined && /^\d+$/.test(next) ? Number(next) : 300;
  }

  const join = (parts, sep) => parts.filter((p) => p !== undefined && p !== null && p !== '').join(sep);

  function classes(v) {
    if (typeof v === 'string') return v.trim();
    if (isArr(v)) return v.map(classes).filter((x) => x !== '').join(' ');
    if (isObj(v)) return Object.keys(v).filter((k) => truthy(v[k])).join(' ');
    return str(v);
  }

  function styles(v) {
    if (isArr(v)) return v.map(styles).filter((x) => x !== '').join(';');
    if (isObj(v)) return Object.keys(v).filter((k) => v[k] !== null && v[k] !== false && v[k] !== '').map((k) => k + ':' + str(v[k])).join(';');
    return str(v).trim();
  }

  function setAttr(a, k, v) {
    if (k === 'class' || k === 'style') {
      const j = k === 'class' ? join([a.class, classes(v)], ' ') : join([a.style, styles(v)], ';');
      if (j === '') delete a[k];
      else a[k] = j;
      return;
    }
    if (v === null || v === false) delete a[k];
    else a[k] = v === true ? '' : str(v);
  }

  function addListener(v, name, fn) {
    const prev = v.on[name];
    v.on[name] = prev ? (e) => { prev(e); fn(e); } : fn;
  }

  function bindModel(v, m, s, inst) {
    const val = ev(m.p, s);
    const tag = v.tag.toLowerCase();
    const type = String(v.attrs.type || '').toLowerCase();
    // .live: на сервер через паузу 300 мс; с .debounce пауза уже была, поэтому сразу
    const live = m.m.includes('live') ? (m.m.includes('debounce') ? 1 : 300) : false;
    const write = (x) => inst.exec({ k: 'set', op: '=', t: m.p, v: { k: 'lit', v: x } }, s, null, live);
    if (tag === 'input' && type === 'file') {
      // файл уходит на сервер сразу после выбора; в состояние пишется подписанная ссылка (UploadedFile)
      addListener(v, 'change', async (e) => {
        const files = [...(e.target.files || [])];
        if (!files.length) return;
        inst.uploads++;
        inst.update();
        try {
          const refs = [];
          for (const f of files) refs.push(await uploadFile(f, inst));
          write('multiple' in v.attrs ? refs : refs[0]);
        } catch (err) {
          showError(err, 'action');
        } finally {
          inst.uploads--;
          inst.update();
        }
      });
    } else if (tag === 'input' && type === 'checkbox') {
      const own = str(v.attrs.value);
      v.props.checked = isArr(val) ? val.some((x) => str(x) === own) : truthy(val);
      addListener(v, 'change', (e) => {
        if (!isArr(val)) return write(e.target.checked);
        write(e.target.checked ? [...val, v.attrs.value === undefined ? null : v.attrs.value] : val.filter((x) => str(x) !== own));
      });
    } else if (tag === 'input' && type === 'radio') {
      v.props.checked = str(val) === str(v.attrs.value);
      addListener(v, 'change', () => write(v.attrs.value === undefined ? null : v.attrs.value));
    } else {
      v.props.value = str(val);
      const evt = tag === 'select' || m.m.includes('lazy') ? 'change' : 'input';
      const delay = timing(m.m, 'debounce');
      addListener(v, evt, (e) => {
        let x = e.target.value;
        if (m.m.includes('trim')) x = x.trim();
        if (m.m.includes('number')) x = x === '' ? null : num(x);
        if (!delay) return write(x);
        // r-model.debounce: состояние меняется после паузы в наборе; пока ждём, поле не перезаписывается
        const el = e.target;
        clearTimeout(el.$rigMT);
        el.$rigMT = setTimeout(() => {
          el.$rigMT = 0;
          write(x);
        }, delay);
      });
    }
  }

  const KEYS = { enter: 'Enter', esc: 'Escape', space: ' ', tab: 'Tab', up: 'ArrowUp', down: 'ArrowDown' };

  function makeHandler(h, s, inst) {
    const debounce = timing(h.m, 'debounce');
    const throttle = timing(h.m, 'throttle');
    return (e) => {
      for (const m of h.m) {
        if (KEYS[m] && e.key !== KEYS[m]) return;
        if (m === 'self' && e.target !== e.currentTarget) return;
      }
      if (h.m.includes('prevent')) e.preventDefault();
      if (h.m.includes('stop')) e.stopPropagation();
      if (!debounce && !throttle) return inst.exec(h.h, s, e, false);
      // таймеры живут на элементе: обработчик пересоздаётся при каждом рендере
      const el = e.currentTarget;
      const t = el.$rigT || (el.$rigT = {});
      if (debounce) {
        clearTimeout(t[h.e]);
        t[h.e] = setTimeout(() => inst.exec(h.h, s, e, false), debounce);
      } else if (!t[h.e] || Date.now() - t[h.e] >= throttle) {
        t[h.e] = Date.now();
        inst.exec(h.h, s, e, false);
      }
    };
  }

  // ------------------------------------------------------------ DOM

  const SVG_NS = 'http://www.w3.org/2000/svg';

  function createEl(v, svg) {
    if (v.t === 3) return (v.el = document.createTextNode(v.text));
    if (v.t === 8) return (v.el = document.createComment('r'));
    if (v.t === 'c') return createCmp(v);
    if (v.t === 'r') return (v.el = v.node);
    svg = svg || v.tag === 'svg';
    const el = (v.el = svg ? document.createElementNS(SVG_NS, v.tag) : document.createElement(v.tag));
    for (const k in v.attrs) el.setAttribute(k, v.attrs[k]);
    for (const c of v.ch) el.appendChild(createEl(c, svg && v.tag !== 'foreignObject'));
    setProps(el, v.props);
    bindEvents(el, v);
    if ('autofocus' in v.attrs) queueMicrotask(() => el.isConnected && el.focus());
    if (v.js) startIsland(v);
    return el;
  }

  // ------------------------------------------------------------ свой JS (r-js)

  /**
   * <div r-js="chart" r-props="{points: points}"> подключает модуль app/js/chart.js:
   *   export function mount(el, props, ctx) { …; return { update(props) {…}, unmount() {…} } }
   * ctx.call('действие', …аргументы), ctx.set('свойство', значение), ctx.get('свойство'),
   * ctx.emit('событие', данные): для @событие на элементе ($event.detail).
   */
  function startIsland(v) {
    const el = v.el, inst = v.owner;
    const st = { api: null, dead: false, props: v.jsProps };
    v.island = {
      update(p) {
        st.props = p;
        if (st.api && st.api.update) st.api.update(clone(p));
      },
      stop() {
        st.dead = true;
        if (st.api && st.api.unmount) st.api.unmount();
      },
    };
    const ctx = {
      el,
      call: (name, ...args) => inst.call(name, args),
      set: (prop, val) => inst.exec({ k: 'set', op: '=', t: { k: 'id', n: prop }, v: { k: 'lit', v: clone(val) } }, inst.base, null, false),
      get: (prop) => clone(inst.base[prop]),
      emit: (name, detail) => el.dispatchEvent(new CustomEvent(name, { detail: detail === undefined ? null : detail })),
    };
    import(Rig.cfg.base + '/js/' + v.js + '.js' + (Rig.cfg.jsv ? '?v=' + Rig.cfg.jsv : ''))
      .then((m) => {
        if (st.dead) return;
        const mount = m.mount || (m.default && m.default.mount) || m.default;
        if (typeof mount !== 'function') throw new Error('нужен export function mount(el, props, ctx)');
        st.api = mount(el, clone(st.props), ctx) || null;
      })
      .catch((err) => showError({ message: 'r-js «' + v.js + '» (app/js/' + v.js + '.js): ' + (err && err.message ? err.message : err) }, 'action'));
  }

  // ------------------------------------------------------------ анимации (r-transition)

  /** Сколько длится анимация элемента по его CSS (transition и animation), мс. */
  function cssDuration(el) {
    const cs = getComputedStyle(el);
    const ms = (s) => s.split(',').map((x) => parseFloat(x) * (x.trim().endsWith('ms') ? 1 : 1000) || 0);
    let max = 0;
    for (const [d, l] of [[cs.transitionDuration, cs.transitionDelay], [cs.animationDuration, cs.animationDelay]]) {
      const ds = ms(d), ls = ms(l);
      ds.forEach((x, i) => (max = Math.max(max, x + (ls[i % ls.length] || 0))));
    }
    return max;
  }

  /** Переход от {имя}-enter-from к {имя}-enter-to (с {имя}-enter-active), так же leave. */
  function transition(el, name, phase, done) {
    const c = (s) => name + '-' + phase + '-' + s;
    el.classList.add(c('from'), c('active'));
    requestAnimationFrame(() => requestAnimationFrame(() => {
      el.classList.remove(c('from'));
      el.classList.add(c('to'));
      const total = cssDuration(el);
      let fired = false;
      const end = () => {
        if (fired) return;
        fired = true;
        el.removeEventListener('transitionend', onEnd);
        el.removeEventListener('animationend', onEnd);
        el.classList.remove(c('active'), c('to'));
        if (done) done();
      };
      const onEnd = (e) => e.target === el && end();
      if (!total) return end();
      el.addEventListener('transitionend', onEnd);
      el.addEventListener('animationend', onEnd);
      setTimeout(end, total + 50);
    }));
  }

  /** Убрать узел; с r-transition после анимации исчезновения. */
  function removeNode(v) {
    unmount(v);
    const el = v.el;
    if (v.tr && el && el.nodeType === 1 && el.isConnected) {
      el.$rigLeaving = true;
      transition(el, v.tr, 'leave', () => el.remove());
    } else if (el) {
      el.remove();
    }
  }

  /** Компонент, которого не было в HTML с сервера (новый элемент списка и т.п.). */
  function createCmp(v) {
    const def = Rig.defs[v.name];
    if (!def) throw new Error('Нет описания компонента ' + v.name);
    const el = document.createElement(def.tag || 'div');
    el.setAttribute('r-c', v.name);
    for (const k in v.attrs) el.setAttribute(k, v.attrs[k]);
    const inst = new Instance(el, def, null);
    inst.setInputs(v);
    instances.push(inst);
    inst.fresh();
    v.inst = inst;
    return (v.el = el);
  }

  function setProps(el, p) {
    // пока ждём r-model.debounce, поле хранит то, что набрано, а не старое состояние
    if ('value' in p && el.value !== p.value && !el.$rigMT) el.value = p.value;
    if ('checked' in p && el.checked !== p.checked) el.checked = p.checked;
  }

  function bindEvents(el, v) {
    el.$rig = v.on;
    const reg = el.$rigReg || (el.$rigReg = new Set());
    for (const name in v.on) {
      if (reg.has(name)) continue;
      reg.add(name);
      el.addEventListener(name, (e) => {
        const f = el.$rig[name];
        if (f) f(e);
      });
    }
  }

  function unmount(v) {
    if (v.island) v.island.stop();
    if (v.t === 'c') {
      if (v.inst) v.inst.destroy();
    } else if (v.ch) {
      for (const c of v.ch) unmount(c);
    }
  }

  const hidden = (attrs) => /(^|;)\s*display\s*:\s*none/.test(attrs.style || '');

  const sameNode = (a, b) => a.t === b.t && a.key === b.key && (a.t !== 1 || a.tag === b.tag) && (a.t !== 'c' || a.name === b.name);

  function patchAttrs(el, a, b) {
    for (const k in a) if (!(k in b)) el.removeAttribute(k);
    for (const k in b) if (a[k] !== b[k]) el.setAttribute(k, b[k]);
  }

  function patch(a, b, svg) {
    const el = (b.el = a.el);
    if (b.t === 3) {
      if (a.text !== b.text) el.data = b.text;
      return;
    }
    if (b.t === 8 || b.t === 'r') return;
    const wasHidden = b.tr ? hidden(a.attrs) : false;
    patchAttrs(el, a.attrs, b.attrs);
    if (b.tr) {
      // r-show с r-transition: исчезает после анимации, появляется с анимацией
      const isHidden = hidden(b.attrs);
      el.$rigHidden = isHidden;
      if (!wasHidden && isHidden) {
        el.style.display = '';
        transition(el, b.tr, 'leave', () => {
          if (el.$rigHidden) el.style.display = 'none'; // за время анимации могли показать снова
        });
      } else if (wasHidden && !isHidden) {
        transition(el, b.tr, 'enter');
      }
    }
    if (b.t === 'c') {
      b.inst = a.inst;
      b.inst.setInputs(b);
      b.inst.render();
      return;
    }
    svg = svg || b.tag === 'svg';
    if (b.js) {
      // содержимым управляет JS-модуль: дети не трогаются, новые свойства уходят в update()
      b.island = a.island;
      b.ch = a.ch;
      if (b.island && json(a.jsProps) !== json(b.jsProps)) b.island.update(b.jsProps);
    } else if (b.tag.toLowerCase() !== 'textarea') {
      patchChildren(el, a.ch, b.ch, svg && b.tag !== 'foreignObject');
    }
    setProps(el, b.props);
    bindEvents(el, b);
  }

  function patchChildren(parent, oldCh, newCh, svg) {
    const byKey = new Map();
    for (const v of oldCh) byKey.set(v.key, v);
    const created = [];
    for (const v of newCh) {
      const old = byKey.get(v.key);
      if (old && sameNode(old, v)) {
        byKey.delete(v.key);
        patch(old, v, svg);
      } else {
        createEl(v, svg);
        if (v.tr) created.push(v);
      }
    }
    for (const old of byKey.values()) removeNode(old);
    let next = parent.firstChild;
    for (const v of newCh) {
      while (next && next.$rigLeaving) next = next.nextSibling; // исчезающие (r-transition) остаются на месте
      if (v.el === next) next = next.nextSibling;
      else parent.insertBefore(v.el, next);
    }
    for (const v of created) if (v.el.nodeType === 1) transition(v.el, v.tr, 'enter');
  }

  /** Привязать виртуальное дерево к HTML, который отрендерил сервер. false, если разметка не совпала. */
  function hydrate(parent, vs) {
    let node = parent.firstChild;
    for (const v of vs) {
      if (v.t === 3) {
        if (v.text === '') {
          v.el = document.createTextNode('');
          parent.insertBefore(v.el, node);
          continue;
        }
        if (!node || node.nodeType !== 3) return false;
        if (node.data !== v.text) {
          // браузер склеивает соседние текстовые узлы, разрезаем обратно
          if (!node.data.startsWith(v.text)) return false;
          node.splitText(v.text.length);
        }
        v.el = node;
      } else if (v.t === 8) {
        if (!node || node.nodeType !== 8) return false;
        v.el = node;
      } else if (v.t === 'r') {
        if (node !== v.node) return false;
        v.el = node;
      } else if (v.t === 'c') {
        if (!node || node.nodeType !== 1 || node.getAttribute('r-c') !== v.name || !node.hasAttribute('r-data')) return false;
        const inst = new Instance(node, Rig.defs[v.name], JSON.parse(node.getAttribute('r-data')));
        inst.setInputs(v);
        instances.push(inst);
        v.inst = inst;
        v.el = node;
        inst.mount();
      } else {
        if (!node || node.nodeType !== 1 || node.localName.toLowerCase() !== v.tag.toLowerCase()) return false;
        v.el = node;
        if (v.tag.toLowerCase() !== 'textarea' && !hydrate(node, v.ch)) return false;
        setProps(node, v.props);
        bindEvents(node, v);
        if (v.js) startIsland(v);
      }
      node = v.el.nextSibling;
    }
    return node === null;
  }

  // ------------------------------------------------------------ компоненты

  const instances = [];
  const PAGE_ID = Math.random().toString(36).slice(2, 12);
  const Rig = { cfg: { base: '/_rig', debug: false }, defs: {}, instances, ev, str, num, truthy, eq, H };

  class Instance {
    /** data: {snap, state} из HTML сервера; null, если компонент создан в браузере. */
    constructor(el, def, data) {
      if (!def) throw new Error('Нет описания компонента ' + el.getAttribute('r-c'));
      this.root = el;
      this.def = def;
      this.props = {};
      this.slots = {};
      this.on = {};
      this.parent = null;
      this.base = Object.create(null);
      // #[On('chat.{roomId}')] с подставленными значениями: [[имя, шаблон, подпись], …], присылает сервер
      this.tp = (data && data.tp) || [];
      if (this.tp.length) hubSync();
      if (data) {
        el.removeAttribute('r-data');
        this.snap = data.snap;
        Object.assign(this.base, data.state);
        // слоты со страницы (<Card>…</Card> в page.php) это статичный HTML, узлы сохраняем как есть
        for (const slot of el.querySelectorAll('[r-slot]')) {
          if (slot.parentElement.closest('[r-c]') === el) this.slots[slot.getAttribute('r-slot')] = { raw: slot };
        }
      } else {
        this.snap = null;
        Object.assign(this.base, clone(def.defaults));
        for (const k of def.cserver) this.base[k] = null;
        this.base.$errors = [];
      }
      for (const [k, v] of Object.entries(def.client)) if (!(k in this.base)) this.base[k] = clone(v);
      this.server = {};
      for (const k of [...def.server, ...def.cserver, '$errors']) if (k in this.base) this.server[k] = clone(this.base[k]);
      this.queue = [];
      this.dirty = new Set();
      this.inflight = false;
      this.scheduled = false;
      this.liveTimer = 0;
      this.uploads = 0;
      this.vnodes = [];
      this.mounted = false;
      this.dead = false;
      // #[Lazy]: пока данные грузятся, в корне скелетон, рендер не трогает его
      this.lazy = !!(data && data.lazy);
      this.abort = new AbortController();
    }

    /** #[Lazy]: запросить данные (mount() на сервере), скелетон уже на экране. */
    load() {
      this.queue.push({ action: null, args: [], updates: {}, opt: null, params: [], mount: true });
      this.flush();
    }

    /** Данные пришли: убрать скелетон, дальше обычный рендер. */
    loaded() {
      if (!this.lazy) return;
      this.lazy = false;
      for (const v of this.vnodes) unmount(v);
      this.vnodes = [];
      this.root.textContent = '';
      this.root.removeAttribute('aria-busy');
    }

    /** Свойства, слоты и обработчики событий от родителя. */
    setInputs(v) {
      const changed = json(this.props) !== json(v.props);
      this.props = v.props;
      this.slots = v.slots;
      this.on = v.on;
      this.parent = v.owner;
      for (const k in v.props) {
        this.base[k] = clone(v.props[k]);
        this.server[k] = clone(v.props[k]);
      }
      // серверные computed зависят от свойств, пересчитываем на сервере
      if (changed && this.mounted && this.def.cserver.length) this.refresh();
    }

    view(tpl) {
      const slots = {};
      for (const k in this.slots) slots[k] = true;
      this.base.$slots = slots;
      const pending = {};
      for (const item of this.queue) if (item.action) pending[item.action] = true;
      this.base.$pending = pending;
      this.base.$busy = this.lazy || this.queue.length > 0 || this.uploads > 0;
      for (const [k, ast] of Object.entries(this.def.cexpr)) {
        try {
          this.base[k] = ev(ast, this.base);
        } catch (e) {
          console.error('[rig] computed ' + k, e);
        }
      }
      return renderList(tpl || this.def.tpl, this.base, [], '', this);
    }

    mount() {
      if (this.lazy) {
        // скелетон отрендерил сервер: оставляем как есть и грузим данные
        this.mounted = true;
        shared.join(this);
        this.load();
        return;
      }
      const vs = this.view();
      if (!hydrate(this.root, vs)) {
        if (Rig.cfg.debug) console.warn('[rig] ' + this.def.name + ': HTML с сервера не совпал с шаблоном, компонент перерисован в браузере');
        for (const v of vs) unmount(v);
        this.root.textContent = '';
        this.vnodes = this.view();
        for (const v of this.vnodes) this.root.appendChild(createEl(v, false));
      } else {
        this.vnodes = vs;
      }
      this.mounted = true;
      // #[Shared]: сервер отрисовал значение по умолчанию, подставляем общее и перерисовываем
      if (shared.join(this)) this.render();
    }

    /** Отрисовать в браузере; если нужен mount() или серверные computed, спросить сервер. */
    fresh() {
      shared.join(this);
      if (this.def.lazy) {
        this.lazy = true;
        this.root.setAttribute('aria-busy', 'true');
        this.vnodes = this.view(this.def.skel);
        for (const v of this.vnodes) this.root.appendChild(createEl(v, false));
        this.mounted = true;
        this.load();
        return;
      }
      this.vnodes = this.view();
      for (const v of this.vnodes) this.root.appendChild(createEl(v, false));
      this.mounted = true;
      if (this.def.mounts || this.def.cserver.length) this.refresh();
    }

    /**
     * Страницу показали из кеша, а данные с тех пор могли поменяться: тихо обновить
     * без скелетона; текущее содержимое остаётся до ответа (на сервере revalidate() или mount()).
     */
    revalidate() {
      if (!this.def.mounts && !this.def.revalidates && !this.def.cserver.length) return;
      if (this.queue.some((i) => i.revalidate || i.mount)) return;
      this.queue.push({ action: null, args: [], updates: this.takeUpdates(), opt: null, params: [], revalidate: true });
      this.flush();
    }

    destroy() {
      if (this.dead) return;
      this.dead = true;
      this.abort.abort(); // незавершённые запросы этого компонента больше не нужны
      clearTimeout(this.liveTimer);
      shared.leave(this);
      for (const v of this.vnodes) unmount(v);
      const i = instances.indexOf(this);
      if (i >= 0) instances.splice(i, 1);
      if (this.tp.length) hubSync();
    }

    update() {
      if (this.scheduled || this.dead) return;
      this.scheduled = true;
      queueMicrotask(() => {
        this.scheduled = false;
        this.render();
      });
    }

    render() {
      if (this.dead || this.lazy) return;
      const vs = this.view();
      patchChildren(this.root, this.vnodes, vs, false);
      this.vnodes = vs;
    }

    writable(name) {
      return name !== null && this.def.server.includes(name) && !this.def.locked.includes(name);
    }

    /** Свойство хранит своё значение: серверное, #[Client] или #[Shared] (а не computed). */
    owns(name) {
      return this.def.server.includes(name) || hasOwn(this.def.client, name);
    }

    /** Свойства, внутри которых лежит объект (по ссылке, не глубже нескольких уровней). */
    holders(target) {
      const has = (v, depth) => v === target || (depth > 0 && (isArr(v) || isObj(v)) && Object.values(v).some((x) => has(x, depth - 1)));
      return [...this.def.server, ...Object.keys(this.def.client)].filter((k) => has(this.base[k], 6));
    }

    /** Обработчик события: присваивания меняют состояние, действия уходят на сервер, emit передаёт событие родителю. */
    exec(ast, s, e, live) {
      const hs = Object.create(s);
      hs.$event = e === undefined ? null : e;
      hs.$$call = (name, args) => {
        if (name === 'emit') this.emitUp(args[0], args.length > 1 ? args[1] : null);
        else if (name === 'copy') copyText(str(args[0]));
        else this.call(name, args);
        return null;
      };
      const changed = new Set();
      hs.$$dirty = (name, target) => {
        // r-for="t in visible" по computed-списку или по where(todos, …) прямо в шаблоне: элементы списка
        // те же объекты, что в todos, поэтому изменённым считается свойство, в котором лежит этот объект
        const names = target && (name === null || !this.owns(name)) ? this.holders(target) : [name];
        for (const n of names) {
          if (this.writable(n)) this.dirty.add(n);
          changed.add(n);
        }
      };
      try {
        ev(ast, hs);
      } catch (err) {
        showError({ message: err.message }, 'action');
        throw err;
      }
      for (const name of changed) {
        // #[Model]: значение уходит родителю (r-model на компоненте)
        if (name !== null && name === this.def.model) this.emitUp('update:' + name, clone(this.base[name]));
        // #[Shared]: то же значение получают все компоненты с этим ключом
        if (name !== null && this.def.shared && hasOwn(this.def.shared, name)) shared.set(this, name);
      }
      if (live) this.refreshSoon(live === true ? 300 : live);
      this.update();
    }

    emitUp(name, data) {
      const f = this.on[str(name)];
      if (f) f(data === undefined ? null : data);
    }

    takeUpdates() {
      const u = {};
      for (const k of this.dirty) u[k] = clone(this.base[k]);
      this.dirty.clear();
      return u;
    }

    call(name, args) {
      const a = this.def.actions[name];
      if (!a) throw new Error('Действие ' + name + ' не объявлено');
      const item = { action: name, args: clone(args), updates: this.takeUpdates(), opt: a.o, params: a.p };
      this.queue.push(item);
      this.optimistic(item);
      this.update();
      this.flush();
    }

    /** Запрос без действия: отправить изменения и получить свежее состояние. */
    refresh() {
      const last = this.queue[this.queue.length - 1];
      if (last && last.action === null && (this.queue.length > 1 || !this.inflight)) return; // такой запрос уже ждёт отправки
      this.queue.push({ action: null, args: [], updates: this.takeUpdates(), opt: null, params: [] });
      this.flush();
    }

    /** r-model.live: отправить изменения через паузу в наборе. */
    refreshSoon(ms = 300) {
      clearTimeout(this.liveTimer);
      this.liveTimer = setTimeout(() => {
        if (this.dirty.size) this.refresh();
      }, ms);
    }

    optimistic(item) {
      if (!item.opt) return;
      const ps = Object.create(this.base);
      item.params.forEach((p, i) => {
        ps[p] = i < item.args.length ? item.args[i] : null;
      });
      try {
        ev(item.opt, ps);
      } catch (err) {
        console.warn('[rig] optimistic ' + item.action, err);
      }
    }

    async flush() {
      if (this.inflight || !this.queue.length || this.dead) return;
      const item = this.queue[0];
      this.inflight = true;
      this.root.setAttribute('r-busy', '');
      try {
        const res = await post('/action', {
          c: this.def.name, snap: this.snap, props: this.props, updates: item.updates, action: item.action, args: item.args,
          mount: item.mount || undefined, revalidate: item.revalidate || undefined,
        }, this.abort.signal);
        if (res.redirect) {
          // redirect() в действии или middleware: очередь больше не нужна, уходим со страницы
          this.queue = [];
          goTo(res.redirect, res.reload);
          return;
        }
        this.queue.shift();
        this.snap = res.snap;
        this.server = clone(res.state);
        const tp = res.tp || [];
        if (json(tp) !== json(this.tp)) {
          // действие поменяло {roomId}: подписка в хабе меняется вместе с ним
          this.tp = tp;
          hubSync();
        }
        if (item.mount) this.loaded();
        if (item.action) {
          // данные поменялись: заранее загруженные страницы выбрасываем, страницы в кеше обновятся при показе
          speculation.clear();
          pageCache.stale();
        }
        this.restore();
        hideError('action');
        for (const [n, d] of res.emits || []) this.emitUp(n, d);
        dispatch(res.events || []);
      } catch (err) {
        if (this.dead) return; // компонент убран со страницы, запрос отменён; это не ошибка
        if (err.status === 419 && !sessionStorage.getItem('rig-419')) {
          // сессия истекла или страница из старой сессии: один раз перезагружаем, чтобы пришли свежие токен и состояние
          sessionStorage.setItem('rig-419', '1');
          location.reload();
          return;
        }
        this.queue.shift();
        if (item.mount) this.root.removeAttribute('aria-busy');
        this.restore();
        showError(err, 'action');
      } finally {
        this.inflight = false;
        if (!this.queue.length) this.root.removeAttribute('r-busy');
        this.flush();
      }
    }

    /** Подтверждённое сервером состояние + ещё не подтверждённые optimistic-изменения. */
    restore() {
      for (const k in this.server) {
        if (!this.dirty.has(k) && !(k in this.props)) this.base[k] = clone(this.server[k]);
      }
      for (const item of this.queue) this.optimistic(item);
      this.update();
    }

    /** HMR: новый шаблон и класс, состояние сохраняется. */
    hot(res) {
      const old = this.def;
      const def = res.def;
      const base = Object.create(null);
      for (const [k, v] of Object.entries(def.client)) base[k] = hasOwn(old.client, k) && k in this.base ? this.base[k] : clone(v);
      Object.assign(base, clone(res.state));
      for (const k in this.props) base[k] = clone(this.props[k]);
      shared.leave(this);
      this.def = def;
      this.base = base;
      this.snap = res.snap;
      this.server = clone(res.state);
      this.queue = [];
      shared.join(this);
      this.render();
    }
  }

  /**
   * #[Shared('ключ')]: одно значение на ключ для всех компонентов вкладки.
   * Первый компонент приносит значение по умолчанию (или сохранённое в localStorage, если задан persist),
   * дальше любое присваивание расходится остальным.
   */
  const shared = {
    map: new Map(),
    entries(inst) {
      return Object.entries((inst.def && inst.def.shared) || {});
    },
    /** Подписать компонент; true, если какое-то значение отличалось от того, что у него было. */
    join(inst) {
      let changed = false;
      for (const [prop, cfg] of this.entries(inst)) {
        let e = this.map.get(cfg.k);
        if (!e) {
          e = { v: undefined, subs: new Set(), persist: cfg.p };
          if (cfg.p) {
            try {
              const raw = localStorage.getItem('rig:' + cfg.k);
              if (raw !== null) e.v = JSON.parse(raw);
            } catch (err) {
              // хранилище недоступно (приватный режим), работаем без сохранения
            }
          }
          if (e.v === undefined) e.v = clone(inst.base[prop]);
          this.map.set(cfg.k, e);
        }
        e.subs.add(inst);
        if (json(inst.base[prop]) !== json(e.v)) {
          inst.base[prop] = clone(e.v);
          changed = true;
        }
      }
      return changed;
    },
    set(inst, prop) {
      const cfg = inst.def.shared[prop];
      const e = this.map.get(cfg.k);
      if (!e) return;
      e.v = clone(inst.base[prop]);
      if (e.persist) {
        try {
          localStorage.setItem('rig:' + cfg.k, json(e.v));
        } catch (err) {
          // нет места или доступа; значение всё равно общее в пределах вкладки
        }
      }
      for (const other of e.subs) {
        if (other === inst || other.dead) continue;
        for (const [p, c] of this.entries(other)) if (c.k === cfg.k) other.base[p] = clone(e.v);
        other.update();
      }
    },
    leave(inst) {
      for (const [, cfg] of this.entries(inst)) {
        const e = this.map.get(cfg.k);
        if (e) e.subs.delete(inst);
      }
    },
  };

  /** copy(текст) в шаблоне: clipboard API есть только на https и localhost, в остальных случаях копируем через скрытое поле. */
  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
    } catch (e) {
      const a = document.createElement('textarea');
      a.value = text;
      a.setAttribute('style', 'position:fixed;opacity:0');
      document.body.append(a);
      a.select();
      document.execCommand('copy');
      a.remove();
    }
  }

  /** События #[On]: от dispatch() на сервере и от broadcast() через хаб. */
  function dispatch(events) {
    for (const [name, data] of events) {
      for (const inst of instances.slice()) {
        const methods = [...(inst.def.on[name] || [])];
        // chat.5 для #[On('chat.{roomId}')], если у этого компонента roomId = 5
        for (const [topic, pattern] of inst.tp) if (topic === name) methods.push(...(inst.def.on[pattern] || []));
        for (const method of methods) inst.call(method, data === null ? [] : [data]);
      }
    }
  }

  async function post(path, body, signal) {
    let r = null, j = null;
    try {
      r = await fetch(Rig.cfg.base + path, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json', Accept: 'application/json', 'X-Rig': '1', 'X-Rig-Page': PAGE_ID,
          'X-CSRF-Token': Rig.cfg.csrf || '',
        },
        body: JSON.stringify(body),
        signal,
      });
      j = await r.json();
    } catch (e) {
      throw { message: r ? 'Сервер ответил не JSON (HTTP ' + r.status + ')' : 'Нет связи с сервером' };
    }
    if (!r.ok || j.error) throw Object.assign({ status: r.status }, j.error || { message: 'HTTP ' + r.status });
    sessionStorage.removeItem('rig-419');
    return j;
  }

  /** Файл из <input type="file" r-model> загружается во временную папку на сервере, в ответ приходит подписанная ссылка. */
  async function uploadFile(file, inst) {
    const fd = new FormData();
    fd.append('file', file);
    let r = null, j = null;
    try {
      r = await fetch(Rig.cfg.base + '/upload?c=' + encodeURIComponent(inst.def.name), {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-Rig': '1', 'X-CSRF-Token': Rig.cfg.csrf || '' },
        body: fd,
        signal: inst.abort.signal,
      });
      j = await r.json();
    } catch (e) {
      throw { message: r ? 'Загрузка не удалась (HTTP ' + r.status + ')' : 'Нет связи с сервером' };
    }
    if (!r.ok || j.error) throw Object.assign({ status: r.status }, j.error || { message: 'HTTP ' + r.status });
    if (j.redirect) {
      goTo(j.redirect);
      throw { message: 'Нужно войти' };
    }
    return j.file;
  }

  /** Перейти по адресу из redirect(): внутри своего приложения без перезагрузки, после входа/выхода (reload) с полной перезагрузкой. */
  function goTo(url, reload) {
    const u = new URL(url, location.href);
    if (reload) pageCache.clear();
    if (!reload && u.origin === location.origin && document.querySelector('[r-page]')) navigate(u.href, true, false, true);
    else location.assign(u.href);
  }

  function setStyle(name, css) {
    let el = document.getElementById('rig-css-' + name);
    if (!css) {
      if (el) el.textContent = '';
      return;
    }
    if (!el) {
      el = document.createElement('style');
      el.id = 'rig-css-' + name;
      document.head.appendChild(el);
    }
    if (el.textContent !== css) el.textContent = css;
  }

  /** Оживить компоненты верхнего уровня внутри scope; вложенные оживляет их родитель. */
  function bootIn(scope) {
    scope.querySelectorAll('[r-c][r-data]').forEach((el) => {
      // внутри шаблона компонента его оживит родитель; внутри слота со страницы (r-slot) оживляем отдельно
      const owner = el.parentElement && el.parentElement.closest('[r-c], [r-slot]');
      if (owner && owner.hasAttribute('r-c')) return;
      try {
        const inst = new Instance(el, Rig.defs[el.getAttribute('r-c')], JSON.parse(el.getAttribute('r-data')));
        instances.push(inst);
        inst.mount();
      } catch (err) {
        showError({ message: err.message }, 'action');
      }
    });
  }

  // ------------------------------------------------------------ переходы без перезагрузки

  let navSeq = 0;

  /** Содержимое страницы JSON-ом (без layout). Тяжёлые #[Lazy]-компоненты в нём приходят только скелетонами. */
  async function fetchPage(href, hops = 0) {
    const pageEl = document.querySelector('[r-page]');
    const keep = [...new Set(instances.filter((i) => !pageEl || !pageEl.contains(i.root)).map((i) => i.def.name))].join(',');
    // no-cache: браузер переспрашивает сервер с If-None-Match; если страница не менялась, приходит 304 без тела
    const r = await fetch(href, { headers: { 'X-Rig-Nav': '1', 'X-Rig-Keep': keep, Accept: 'application/json', 'X-Rig-Page': PAGE_ID }, cache: 'no-cache' });
    if (!(r.headers.get('content-type') || '').includes('application/json')) throw new Error('не JSON');
    const j = await r.json();
    if (j.error) throw j.error;
    if (j.redirect) {
      // middleware страницы отправил на другой адрес (например, на вход)
      if (hops > 4) throw new Error('слишком много перенаправлений');
      return fetchPage(new URL(j.redirect, location.href).href, hops + 1);
    }
    return { j, url: r.redirected ? r.url + new URL(href, location.href).hash : href };
  }

  /** Теги <head> страницы (Rig\Head): заменить помеченные data-rig-head. */
  function applyHead(html) {
    if (!html) return;
    for (const el of document.head.querySelectorAll('[data-rig-head]')) el.remove();
    const t = document.createElement('template');
    t.innerHTML = html;
    document.head.append(t.content);
  }

  const headNow = () => [...document.head.querySelectorAll('[data-rig-head]')].map((el) => el.outerHTML).join('');

  /**
   * Кеш показанных страниц. Уходя со страницы, её DOM и живые компоненты
   * откладываем, а не уничтожаем; при возврате вставляем обратно без запроса к серверу,
   * с тем же состоянием: фильтры, подгруженные товары, введённый текст.
   * Если за это время что-то менялось (действие на сервере, broadcast), страница всё равно показывается
   * сразу, а её компоненты тихо обновляют данные в фоне (revalidate). Компоненты в кеше получают события #[On].
   */
  const pageCache = {
    map: new Map(),
    max: 10,
    key(href) {
      const u = new URL(href, location.href);
      return u.pathname + u.search;
    },
    get(key) {
      const e = this.map.get(key);
      if (!e) return null;
      this.map.delete(key);
      if (Date.now() - e.at > (Rig.cfg.pageCache || 0) * 1000) {
        for (const i of e.instances) i.destroy();
        return null;
      }
      return e;
    },
    put(key, e) {
      this.drop(key);
      if (!Rig.cfg.pageCache) {
        for (const i of e.instances) i.destroy();
        return;
      }
      this.map.set(key, e);
      while (this.map.size > this.max) this.drop(this.map.keys().next().value);
    },
    drop(key) {
      const e = this.map.get(key);
      if (!e) return;
      for (const i of e.instances) i.destroy();
      this.map.delete(key);
    },
    stale() {
      for (const e of this.map.values()) e.stale = true;
    },
    clear() {
      for (const key of [...this.map.keys()]) this.drop(key);
    },
  };
  let currentKey = null;

  /** Убрать текущую страницу в кеш (или уничтожить, если её же сейчас перезагружаем). */
  function leavePage(page, keep) {
    const insts = instances.filter((i) => !i.parent && page.contains(i.root));
    if (!keep || currentKey === null) {
      for (const i of insts) i.destroy();
      page.textContent = '';
      return;
    }
    const frag = document.createDocumentFragment();
    while (page.firstChild) frag.appendChild(page.firstChild);
    pageCache.put(currentKey, { frag, instances: insts, title: document.title, head: headNow(), hub: hub.cfg, at: Date.now(), stale: false });
  }

  function scrollAfter(url, push) {
    const hash = new URL(url, location.href).hash;
    const target = hash && document.getElementById(decodeURIComponent(hash.slice(1)));
    if (target) target.scrollIntoView();
    else if (push) window.scrollTo(0, 0);
  }

  /** fresh: не брать из кеша (HMR страницы, явное обновление). */
  async function navigate(href, push, keepScroll, fresh) {
    const seq = ++navSeq;
    const doc = document.documentElement;
    const page = document.querySelector('[r-page]');
    const key = pageCache.key(href);
    const cached = page && !fresh && key !== currentKey ? pageCache.get(key) : null;
    if (cached) {
      doc.removeAttribute('r-loading');
      leavePage(page, true);
      page.appendChild(cached.frag);
      currentKey = key;
      if (push) history.pushState({ y: 0 }, '', href);
      applyHead(cached.head);
      document.title = cached.title;
      markLinks();
      speculation.observe();
      hubConnect(cached.hub);
      if (cached.stale) {
        for (const inst of instances.slice()) if (page.contains(inst.root)) inst.revalidate();
      }
      if (!keepScroll) scrollAfter(href, push);
      return;
    }

    doc.setAttribute('r-loading', '');
    let j, url;
    try {
      ({ j, url } = await ((!fresh && speculation.take(href)) || fetchPage(href)));
    } catch (e) {
      location.assign(href); // ошибку или чужую страницу показываем обычной загрузкой
      return;
    }
    if (seq !== navSeq) return;
    if (!page || j.layout !== Rig.cfg.layout) {
      location.assign(href);
      return;
    }
    Object.assign(Rig.defs, j.defs);
    for (const [name, css] of Object.entries(j.styles || {})) setStyle(name, css);
    leavePage(page, pageCache.key(url) !== currentKey);
    pageCache.drop(pageCache.key(url));
    if (push) history.pushState({ y: 0 }, '', url);
    page.innerHTML = j.html;
    currentKey = pageCache.key(url);
    if (j.csrf) Rig.cfg.csrf = j.csrf;
    if (j.i18n !== undefined) Rig.cfg.i18n = j.i18n;
    applyHead(j.head);
    if (j.title) document.title = j.title;
    bootIn(page);
    markLinks();
    speculation.observe();
    hubConnect(j.hub);
    doc.removeAttribute('r-loading');
    if (!keepScroll) scrollAfter(url, push);
  }

  /** Ссылка, которую rig откроет сам (без перезагрузки), или null. */
  function navLink(target) {
    const a = target && target.closest && target.closest('a[href]');
    if (!a || (a.target && a.target !== '_self') || a.hasAttribute('download') || a.hasAttribute('r-reload')) return null;
    const url = new URL(a.href, location.href);
    if (url.origin !== location.origin || url.pathname.startsWith('/_rig/')) return null;
    const same = url.pathname === location.pathname && url.search === location.search;
    if (same && url.hash) return null; // якорь на этой же странице
    return { a, url, same };
  }

  function onLinkClick(e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const link = navLink(e.target);
    if (!link) return;
    e.preventDefault();
    history.replaceState({ ...(history.state || {}), y: window.scrollY }, '');
    navigate(link.url.href, true, false);
  }

  /**
   * Спекулятивная загрузка: страницу запрашиваем заранее,
   * пока пользователь только целится в ссылку, чтобы к клику ответ был готов.
   *
   *   conservative: по нажатию (pointerdown / touchstart)
   *   moderate:     наведение дольше 200 мс + нажатие (по умолчанию)
   *   eager:        ссылки, попавшие в экран, + то же, что в moderate
   *
   * Не грузим: ссылки с ?параметрами (часто имеют побочные эффекты), r-no-prefetch, режим экономии трафика, 2G.
   * Ответ живёт 30 с; любое действие на сервере сбрасывает кеш, потому что данные могли поменяться.
   */
  const speculation = {
    cache: new Map(),
    ttl: 30000,
    take(href) {
      const e = this.cache.get(href);
      this.cache.delete(href);
      return e && Date.now() - e.at < this.ttl ? e.p : null;
    },
    clear() {
      this.cache.clear();
    },
    allowed(link) {
      const c = navigator.connection;
      if (c && (c.saveData || /(^|-)2g$/.test(c.effectiveType || ''))) return false;
      return link && !link.same && !link.url.search && !link.a.hasAttribute('r-no-prefetch');
    },
    prefetch(target) {
      const link = navLink(target);
      if (!this.allowed(link)) return;
      const href = link.url.href;
      if (pageCache.map.has(pageCache.key(href))) return; // страница уже в кеше и откроется без запроса
      const e = this.cache.get(href);
      if (e && Date.now() - e.at < this.ttl) return;
      const p = fetchPage(href);
      p.catch(() => this.cache.delete(href));
      this.cache.set(href, { at: Date.now(), p });
    },
    start(eagerness) {
      if (!eagerness) return;
      this.eagerAll = eagerness === 'eager';
      if ('IntersectionObserver' in window) {
        // eager: ссылки, попавшие на экран; r-prefetch="eager" включает то же для отдельных ссылок (меню) при любом режиме
        this.io = new IntersectionObserver((entries) => {
          for (const en of entries) {
            if (!en.isIntersecting) continue;
            this.io.unobserve(en.target);
            (window.requestIdleCallback || setTimeout)(() => this.prefetch(en.target));
          }
        });
        this.observe();
      }
      const now = (e) => this.prefetch(e.target);
      document.addEventListener('pointerdown', now, { capture: true, passive: true });
      document.addEventListener('touchstart', now, { capture: true, passive: true });
      if (eagerness === 'conservative') return;
      let timer = 0;
      document.addEventListener('mouseover', (e) => {
        const a = e.target.closest && e.target.closest('a[href]');
        if (!a) return;
        clearTimeout(timer);
        timer = setTimeout(() => this.prefetch(a), 200);
      });
      document.addEventListener('mouseout', (e) => {
        if (e.target.closest && e.target.closest('a[href]')) clearTimeout(timer);
      });
    },
    /** Следить за ссылками на текущей странице (после перехода заново). */
    observe() {
      if (!this.io) return;
      this.io.disconnect();
      const selector = this.eagerAll ? 'a[href]' : 'a[href][r-prefetch="eager"]';
      for (const a of document.querySelectorAll(selector)) if (navLink(a)) this.io.observe(a);
    },
  };

  /** aria-current="page" у ссылок на текущую страницу, для подсветки меню. */
  function markLinks() {
    for (const a of document.querySelectorAll('a[href]')) {
      const u = new URL(a.href, location.href);
      if (u.origin !== location.origin) continue;
      if (u.pathname === location.pathname) a.setAttribute('aria-current', 'page');
      else a.removeAttribute('aria-current');
    }
  }

  // ------------------------------------------------------------ ошибки (только debug)

  let overlay = null, overlayKind = null;

  function showError(err, kind) {
    console.error('[rig]', err);
    if (!Rig.cfg.debug) return;
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.setAttribute('style', 'position:fixed;left:16px;right:16px;bottom:16px;z-index:2147483647;max-width:880px;margin:0 auto;' +
        'background:#1d1214;color:#f3e6e6;border-radius:10px;padding:14px 44px 14px 16px;font:14px/1.5 system-ui,sans-serif;' +
        'box-shadow:0 10px 40px rgba(0,0,0,.35);white-space:pre-wrap');
      document.body.appendChild(overlay);
    }
    overlayKind = kind;
    overlay.textContent = '';
    const el = (tag, style, text) => {
      const n = document.createElement(tag);
      if (style) n.setAttribute('style', style);
      if (text !== undefined) n.textContent = text;
      return n;
    };
    overlay.append(el('div', 'font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#ff8f8f;margin-bottom:4px',
      kind === 'hmr' ? 'Ошибка после правки' : 'Ошибка действия'));
    if (err.kind) overlay.append(el('div', 'font-weight:600;font-size:16px;margin-bottom:2px', err.kind));
    overlay.append(el('div', err.kind ? 'color:#c9a9a9;font-family:ui-monospace,monospace;font-size:13px' : '', err.message || String(err)));
    if (err.hint) overlay.append(el('div', 'margin-top:8px;padding:8px 10px;border-radius:8px;background:#17291f;color:#bfe9cf;font-size:13px', err.hint));
    if (err.file) overlay.append(el('div', 'color:#c9a9a9;font-family:ui-monospace,monospace;font-size:13px;margin-top:8px', err.file + ':' + err.line));

    // кнопки: открыть в редакторе (через сервер разработки), копировать ошибку, промпт для ИИ-агента
    const bar = el('div', 'display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;white-space:normal');
    const btn = (label, fn) => {
      const b = el('button', 'min-height:32px;padding:5px 10px;border-radius:7px;border:1px solid #5a3a3c;background:#2a1a1c;color:inherit;font:600 13px system-ui,sans-serif;cursor:pointer', label);
      b.type = 'button';
      b.onclick = async () => {
        const old = b.textContent;
        b.textContent = (await fn()) ? '✓ готово' : 'не вышло';
        setTimeout(() => (b.textContent = old), 1600);
      };
      bar.append(b);
    };
    const copy = async (text) => {
      try {
        await navigator.clipboard.writeText(text);
        return true;
      } catch (e) {
        // clipboard API есть только на https и localhost, в остальных случаях копируем через скрытое поле
        const a = el('textarea', 'position:fixed;opacity:0');
        a.value = text;
        document.body.append(a);
        a.select();
        const ok = document.execCommand('copy');
        a.remove();
        return ok;
      }
    };
    if (err.abs) {
      btn('Открыть в редакторе', async () => {
        try {
          const r = await fetch(Rig.cfg.base + '/open', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Rig': '1' }, body: JSON.stringify({ file: err.abs, line: err.line }) });
          return r.ok;
        } catch (e) {
          return false;
        }
      });
    }
    btn('Копировать ошибку', () => copy(err.text || [err.message, err.file ? err.file + ':' + err.line : ''].join('\n')));
    if (err.prompt) btn('Для ИИ-агента', () => copy(err.prompt));
    overlay.append(bar);

    const close = document.createElement('button');
    close.textContent = '×';
    close.setAttribute('aria-label', 'Закрыть');
    close.setAttribute('style', 'position:absolute;top:6px;right:8px;width:32px;height:32px;border:0;background:none;color:inherit;font-size:22px;cursor:pointer');
    close.onclick = () => hideError();
    overlay.append(close);
  }

  function hideError(kind) {
    if (overlay && (!kind || overlayKind === kind)) {
      overlay.remove();
      overlay = null;
    }
  }

  // ------------------------------------------------------------ хаб: HMR и события с сервера

  const sleep = (ms) => new Promise((f) => setTimeout(f, ms));
  const hub = { es: null, cfg: null, token: null, opened: false, delay: 1000, v: null, polling: false };
  let hmrChain = Promise.resolve();

  /**
   * Одно SSE-соединение с хабом (rig dev или rig push): сообщения приходят
   * только когда меняются файлы или сервер делает broadcast(). После перехода на другую страницу
   * подписка меняется (у других компонентов другие #[On]), поэтому соединение пересоздаётся.
   */
  function hubConnect(cfg) {
    if (!cfg) return;
    if (cfg.hmr && hub.v === null) hub.v = cfg.hmr.v;
    const x = hubTopics();
    if (hub.es && hub.token === cfg.token && hub.x === x) return;
    hub.cfg = cfg;
    hub.token = cfg.token;
    hub.x = x;
    if (hub.es) {
      hub.es.close();
      hub.es = null;
    }
    if (!cfg.url) {
      if (cfg.hmr && cfg.hmr.poll) pollHmr();
      return;
    }
    const q = new URLSearchParams({ id: PAGE_ID, t: cfg.token || '' });
    if (x !== '[]') q.set('x', x);
    if (cfg.hmr) q.set('v', hub.v);
    const es = (hub.es = new EventSource(cfg.url.replace(/\/$/, '') + '/?' + q));
    es.onopen = () => {
      if (!hub.opened) console.info('[rig] хаб подключён' + (cfg.hmr ? ': HMR и события с сервера' : ''));
      hub.opened = true;
      hub.delay = 1000;
    };
    es.onmessage = (e) => {
      const m = JSON.parse(e.data);
      if (m.type === 'hmr') {
        speculation.clear();
        handleHmr(m);
      } else if (m.type === 'push') {
        speculation.clear(); // на сервере что-то поменялось
        pageCache.stale();
        dispatch([[m.e, m.d === undefined ? null : m.d]]);
      }
    };
    es.onerror = () => {
      if (hub.es !== es) return;
      es.close();
      hub.es = null;
      if (hub.opened) {
        // хаб перезапускается, переподключаемся всё реже
        setTimeout(() => {
          if (!hub.es) {
            hub.token = null;
            hubConnect(hub.cfg);
          }
        }, hub.delay);
        hub.delay = Math.min(hub.delay * 2, 30000);
      } else if (cfg.hmr && cfg.hmr.poll) {
        pollHmr();
      } else if (cfg.hmr) {
        console.info('[rig] хаб не запущен, HMR и события с сервера выключены. Запустите: rig dev');
      }
    };
  }

  /** Имена событий с параметрами от всех компонентов вкладки, с подписями сервера: JSON для параметра x. */
  function hubTopics() {
    const seen = new Map();
    for (const inst of instances) for (const [topic, , sig] of inst.tp || []) seen.set(topic, sig);
    return json([...seen].sort((a, b) => (a[0] < b[0] ? -1 : 1)));
  }

  /** Набор событий поменялся (компонент появился, исчез или сменил {roomId}): переподключиться к хабу. */
  let hubSyncing = false;
  function hubSync() {
    if (hubSyncing) return;
    hubSyncing = true;
    queueMicrotask(() => {
      hubSyncing = false;
      if (hub.cfg && hub.x !== hubTopics()) hubConnect(hub.cfg);
    });
  }

  function handleHmr(m) {
    hmrChain = hmrChain.then(async () => {
      if (m.v === hub.v) return;
      hub.v = m.v;
      await applyHmr(m.changed || []);
    });
    return hmrChain;
  }

  /** Запасной HMR без хаба (nginx/FPM): запрос висит на сервере до изменения файлов. */
  async function pollHmr() {
    if (hub.polling) return;
    hub.polling = true;
    for (;;) {
      const started = Date.now();
      try {
        const r = await fetch(Rig.cfg.base + '/hmr?v=' + encodeURIComponent(hub.v), { cache: 'no-store' });
        await handleHmr(await r.json());
      } catch (e) {
        await sleep(3000);
      }
      if (Date.now() - started < 1000) await sleep(2000); // сервер не держит запрос, не долбим его
    }
  }

  async function applyHmr(changed) {
    const names = [];
    let pages = false;
    for (const f of changed) {
      const m = /^app\/components\/(.+)\.php$/.exec(f);
      if (m) names.push(m[1].replace(/\//g, '\\'));
      else if (/^app\/(pages|docs|views)\//.test(f) && document.querySelector('[r-page]')) pages = true;
      else return location.reload(); // layout, app/lib или сам фреймворк
    }
    for (const name of names) {
      const list = instances.filter((i) => i.def.name === name);
      if (!list.length) continue;
      try {
        for (const inst of list) {
          const res = await post('/refresh', { c: name, snap: inst.snap, props: inst.props, updates: inst.takeUpdates() });
          Object.assign(Rig.defs, res.defs || {}, { [name]: res.def });
          for (const [n, d] of Object.entries(res.defs || {})) setStyle(n, d.css);
          inst.hot(res);
        }
        hideError('hmr');
        console.info('[rig] обновлён ' + name);
      } catch (err) {
        showError(err, 'hmr');
      }
    }
    if (pages) {
      pageCache.clear(); // в кеше старые версии страниц
      await navigate(location.href, false, true, true);
    }
  }

  // ------------------------------------------------------------ запуск

  function boot() {
    const cfg = document.getElementById('rig-boot');
    if (!cfg) return;
    Rig.cfg = JSON.parse(cfg.textContent);
    Rig.defs = Rig.cfg.defs;
    bootIn(document);
    // статическая сборка (rig generate): страницы лежат обычными файлами, переходы идут обычной загрузкой
    if (document.querySelector('[r-page]') && !Rig.cfg.static) {
      currentKey = pageCache.key(location.href);
      history.scrollRestoration = 'manual';
      document.addEventListener('click', onLinkClick);
      window.addEventListener('popstate', async () => {
        const y = (history.state && history.state.y) || 0;
        await navigate(location.href, false, true);
        window.scrollTo(0, y);
      });
      speculation.start(Rig.cfg.speculation);
    }
    markLinks();
    hubConnect(Rig.cfg.hub);
    // #[Shared(persist: true)]: значение, изменённое в другой вкладке, применяем и здесь
    window.addEventListener('storage', (e) => {
      if (!e.key || !e.key.startsWith('rig:')) return;
      const entry = shared.map.get(e.key.slice(4));
      if (!entry || e.newValue === null) return;
      entry.v = JSON.parse(e.newValue);
      for (const inst of entry.subs) {
        for (const [p, c] of shared.entries(inst)) if ('rig:' + c.k === e.key) inst.base[p] = clone(entry.v);
        inst.update();
      }
    });
  }

  Rig.navigate = (href) => navigate(new URL(href, location.href).href, true, false);
  Rig.hub = hub;
  Rig.speculation = speculation;
  Rig.pageCache = pageCache;
  Rig.Instance = Instance;
  Rig.dispatch = dispatch;
  Rig.hubTopics = hubTopics;

  if (typeof module === 'object' && module.exports) module.exports = Rig;
  else root.Rig = Rig;

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
  }
})(typeof window !== 'undefined' ? window : globalThis);
