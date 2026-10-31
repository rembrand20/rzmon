// Browserrondje (GitHub Actions): inloggen, elk tabblad openen, Ctrl+K, en geen fouten in de console.
// Gebruik: BASE=http://127.0.0.1:8099 RZ_USER=admin RZ_PASS=… node tests/e2e/smoke.js
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8099';
(async () => {
  const errs = [];
  const b = await chromium.launch();
  const pg = await (await b.newContext({ locale: 'nl-NL', viewport: { width: 1280, height: 900 } })).newPage();
  pg.on('pageerror', (e) => errs.push('pageerror: ' + e.message));
  pg.on('console', (m) => { if (m.type() === 'error' && !/favicon/.test(m.text())) errs.push('console: ' + m.text()); });
  await pg.goto(BASE + '/?login=1');
  await pg.fill('#login-form [name=username]', process.env.RZ_USER || 'admin');
  await pg.fill('#login-form [name=password]', process.env.RZ_PASS || '');
  await pg.click('#login-form button.primary');
  await pg.waitForSelector('#health .kpi', { state: 'attached', timeout: 60000 });
  console.log('✔ ingelogd, dashboard geladen');
  await pg.waitForSelector('#home .hm-sum .hm-tile', { timeout: 60000 });   // 1.134.0: het Dashboard is de startpagina
  console.log('✔ Dashboard (startpagina) geladen');
  for (const tab of await pg.$$eval('.main-tabs .tab', (t) => t.map((x) => x.dataset.tab))) {
    await pg.click(`.main-tabs .tab[data-tab="${tab}"]`);
    await pg.waitForTimeout(1500);
    console.log(`✔ tabblad ${tab}`);
  }
  await pg.keyboard.press('Control+k');
  await pg.waitForSelector('#cmdk:not([hidden])', { timeout: 5000 });
  await pg.fill('#cmdk-in', 'instellingen');
  const n = await pg.$$eval('.cmdk-item', (x) => x.length);
  console.log(`✔ Ctrl+K opent, ${n} resultaten`);
  await pg.keyboard.press('Escape');
  await b.close();
  if (errs.length) { console.log('✘ fouten:\n  ' + errs.join('\n  ')); process.exit(1); }
  console.log('✔ geen fouten in de browser');
})().catch((e) => { console.error('✘', e.message); process.exit(1); });
