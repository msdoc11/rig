// Вторая половина тестов: те же AST, что разобрал PHP, исполняет rig.js.
// Сверяется и значение, и его строковый вид (как он попадёт в HTML).
const fs = require('fs');
const path = require('path');
const Rig = require(path.join(__dirname, '..', 'runtime', 'rig.js'));

const data = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
let fail = 0;
const scopeOf = (o) => Object.assign(Object.create(null), JSON.parse(JSON.stringify(o)));

for (const [src, ast, scope, want, phpStr] of data.expr) {
  let got;
  try {
    got = Rig.ev(ast, scopeOf(scope));
  } catch (e) {
    fail++;
    console.log(`JS  ✗ ${src}: ${e.message}`);
    continue;
  }
  if (JSON.stringify(got) !== JSON.stringify(want)) {
    fail++;
    console.log(`JS  ✗ ${src}: получено ${JSON.stringify(got)}, ожидалось ${JSON.stringify(want)}`);
  } else if (Rig.str(got) !== phpStr) {
    fail++;
    console.log(`JS  ✗ ${src}: в HTML будет «${Rig.str(got)}», а PHP выводит «${phpStr}»`);
  }
}

for (const [src, ast, scope, want] of data.statements) {
  const s = scopeOf(scope);
  Rig.ev(ast, s);
  if (JSON.stringify({ ...s }) !== JSON.stringify(want)) {
    fail++;
    console.log(`JS  ✗ ${src}: получено ${JSON.stringify({ ...s })}, ожидалось ${JSON.stringify(want)}`);
  }
}

const total = data.expr.length + data.statements.length;
console.log(fail ? `JS: ${fail} из ${total} не прошли` : `JS: все ${total} проверок ✓ (значения и вывод совпадают с PHP)`);
process.exit(fail ? 1 : 0);
