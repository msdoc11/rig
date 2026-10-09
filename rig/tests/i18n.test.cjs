// t() в rig.js должна давать то же, что Rig\I18n::t в PHP: формы числа, подстановки, отсутствующие ключи.
const fs = require('fs');
const path = require('path');
const Rig = require(path.join(__dirname, '..', 'runtime', 'rig.js'));

const cases = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
let fail = 0;
for (const [client, key, params, want] of cases) {
  Rig.cfg.i18n = client;
  const got = Rig.H.t(key, params);
  if (got !== want) {
    fail++;
    console.log(`JS ✗ t('${key}', ${JSON.stringify(params)}) [${client.locale}]: «${got}», в PHP «${want}»`);
  }
}
process.exit(fail ? 1 : 0);
