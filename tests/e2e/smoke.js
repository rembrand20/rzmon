// Browserrondje (GitHub Actions): inloggen, elk tabblad openen, Ctrl+K, en geen fouten in de console.
// Gebruik: BASE=http://127.0.0.1:8099 RZ_USER=admin RZ_PASS=… node tests/e2e/smoke.js
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8099';
(async () => {
  const errs = [];
  const b = await chromium.launch();
  const pg = await (await b.newContext({ locale: 'nl-NL', viewport: { width: 1280, height: 900 } })).newPage();
  pg.on('pageerror', (e) => errs.push('pageerror: ' + e.message + (e.stack ? ' @ ' + String(e.stack).split('\n').slice(1, 4).map((s) => s.trim()).join(' < ') : '')));
  pg.on('console', (m) => { if (m.type() === 'error' && !/favicon/.test(m.text())) errs.push('console: ' + m.text()); });
  await pg.goto(BASE + '/?login=1');
  await pg.fill('#login-form [name=username]', process.env.RZ_USER || 'admin');
  await pg.fill('#login-form [name=password]', process.env.RZ_PASS || '');
  await pg.click('#login-form button.primary');
  await pg.waitForSelector('#health .kpi', { state: 'attached', timeout: 60000 });
  console.log('✔ ingelogd, dashboard geladen');
  await pg.waitForSelector('#home .hm-sum .hm-tile', { timeout: 60000 });   // 1.134.0: het Dashboard is de startpagina
  console.log('✔ Dashboard (startpagina) geladen');
  for (const tab of await pg.$$eval('.main-tabs .tab', (t) => t.filter((x) => x.offsetParent !== null).map((x) => x.dataset.tab))) {   // 1.194.0: alleen zichtbare tabs (mDS is er alleen op een telefoon)
    await pg.click(`.main-tabs .tab[data-tab="${tab}"]`);
    await pg.waitForTimeout(1500);
    console.log(`✔ tabblad ${tab}`);
  }
  // 1.276.0: oude adressen (ook in meldingen en e-mails) komen op de nieuwe plek uit
  const moved = { 'settings/agents': 'tab-servers', 'settings/actlog': 'tab-logboek', 'settings/nis2': 'tab-compliance', 'vulns/patch': 'tab-updates',
    'ids/inc': 'tab-ids', 'ids/prot': 'tab-settings', 'settings/quiet': 'tab-settings', 'settings/ids_ti': 'tab-settings' };
  for (const [from, to] of Object.entries(moved)) {
    await pg.evaluate((h) => showTab(h), from);
    await pg.waitForTimeout(800);
    const at = await pg.evaluate(() => (document.querySelector('.tab-panel:not([hidden])') || {}).id);
    if (at !== to) errs.push(`oud adres #${from} komt uit op ${at}, verwacht ${to}`);
  }
  console.log(`✔ ${Object.keys(moved).length} oude adressen sturen door naar de nieuwe plek`);
  // 1.278.1: de lijst Aanvallen staat op de IDS-pagina én bij Incidenten (één lijst die meeverhuist)
  const incVis = async () => pg.evaluate(() => { const p = document.querySelector('.ids-pane[data-pane="inc"]'); return p && !p.hidden && p.offsetParent !== null ? p.closest('.tab-panel').id : null; });
  await pg.evaluate(() => showTab('ids/inc')); await pg.waitForTimeout(800);
  const atIds = await incVis();
  await pg.evaluate(() => showTab('incidents/ids')); await pg.waitForTimeout(800);
  const atInc = await incVis();
  await pg.evaluate(() => showTab('ids/inc')); await pg.waitForTimeout(800);
  const backIds = await incVis();
  if (atIds !== 'tab-ids' || atInc !== 'tab-incidents' || backIds !== 'tab-ids') errs.push(`Aanvallen: IDS ${atIds}, Incidenten ${atInc}, terug ${backIds}`);
  else console.log('✔ Aanvallen op de IDS-pagina én bij Incidenten');
  // 1.277.2: een onthouden onderdeel dat nu een eigen pagina is, mag Instellingen niet wegsturen
  await pg.evaluate(() => { localStorage.setItem('rzmon-set-section', 'agents'); });
  await pg.reload();
  await pg.waitForSelector('#health .kpi', { state: 'attached', timeout: 60000 });
  await pg.evaluate(() => showTab('settings'));
  await pg.waitForTimeout(800);
  const atSet = await pg.evaluate(() => (document.querySelector('.tab-panel:not([hidden])') || {}).id);
  if (atSet !== 'tab-settings') errs.push(`Instellingen met onthouden 'agents' komt uit op ${atSet}`);
  else console.log('✔ Instellingen opent ook met een oud onthouden onderdeel');
  // 1.277.0: elke subpagina in de zijbalk opent de juiste weergave
  let nSub = 0;
  await pg.setViewportSize({ width: 1600, height: 900 });   // breed genoeg voor de uitgeklapte zijbalk
  await pg.waitForTimeout(500);
  for (const page of ['dashboard', 'incidents', 'ids', 'vulns', 'net', 'logs', 'analytics']) {
    if (!(await pg.$(`#main-tabs .tab[data-tab="${page}"]`))) continue;
    await pg.evaluate((p) => showTab(p), page);
    await pg.waitForTimeout(1500);
    const subs = await pg.$$eval(`.side-sub[data-sub-of="${page}"] .side-sub-i`, (x) => x.map((b) => b.dataset.sub));
    for (const k of subs) {
      await pg.click(`.side-sub[data-sub-of="${page}"] .side-sub-i[data-sub="${k}"]`);
      await pg.waitForTimeout(700);
      const ok = await pg.evaluate(([p, kk]) => { const b = document.querySelector(`.side-sub[data-sub-of="${p}"] .side-sub-i[data-sub="${kk}"]`); return !!b && b.classList.contains('active'); }, [page, k]);
      if (!ok) errs.push(`zijbalk: ${page} › ${k} wordt niet actief`);
      nSub++;
    }
  }
  console.log(`✔ ${nSub} subpagina's in de zijbalk geopend`);
  await pg.setViewportSize({ width: 1280, height: 900 });
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
