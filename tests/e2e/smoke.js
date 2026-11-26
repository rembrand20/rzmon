// Browserrondje (GitHub Actions): inloggen, elk tabblad openen, Ctrl+K, en geen fouten in de console.
// Gebruik: BASE=http://127.0.0.1:8099 RZ_USER=admin RZ_PASS=… node tests/e2e/smoke.js
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8099';
(async () => {
  const errs = [];
  const b = await chromium.launch();
  const pg = await (await b.newContext({ locale: 'nl-NL', viewport: { width: 1280, height: 900 } })).newPage();
  // 1.323.0: op een verse database opent de wizard Aan de slag vanzelf; die test staat apart hieronder, de rest zonder
  await pg.addInitScript(() => { try { sessionStorage.setItem('rzs-wz-later', '1'); } catch (e) { /* geen opslag */ } });
  pg.on('pageerror', (e) => errs.push('pageerror: ' + e.message + (e.stack ? ' @ ' + String(e.stack).split('\n').slice(1, 4).map((s) => s.trim()).join(' < ') : '')));
  pg.on('console', (m) => { if (m.type() === 'error' && !/favicon/.test(m.text())) errs.push('console: ' + m.text()); });
  await pg.goto(BASE + '/?login=1');
  await pg.fill('#login-form [name=username]', process.env.RZ_USER || 'admin');
  await pg.fill('#login-form [name=password]', process.env.RZ_PASS || '');
  await pg.click('#login-form button.primary');
  await pg.waitForSelector('#health .kpi', { state: 'attached', timeout: 60000 });
  console.log('✔ ingelogd, dashboard geladen');
  await pg.waitForSelector('#home .hd-strip .hd-num', { timeout: 60000 });   // 1.134.0: het Dashboard is de startpagina; 1.322.0: in Default de strook met cijfers
  console.log('✔ Dashboard (startpagina) geladen');
  for (const tab of await pg.$$eval('.main-tabs .tab', (t) => t.filter((x) => x.offsetParent !== null).map((x) => x.dataset.tab))) {   // 1.194.0: alleen zichtbare tabs (mDS is er alleen op een telefoon)
    await pg.click(`.main-tabs .tab[data-tab="${tab}"]`);
    await pg.waitForTimeout(1500);
    console.log(`✔ tabblad ${tab}`);
  }
  // 1.276.0: oude adressen (ook in meldingen en e-mails) komen op de nieuwe plek uit
  const moved = { 'settings/agents': 'tab-servers', 'settings/actlog': 'tab-logboek', 'settings/nis2': 'tab-compliance', 'vulns/patch': 'tab-updates',
    'ids/inc': 'tab-ids', 'ids/prot': 'tab-settings', 'settings/quiet': 'tab-settings', 'settings/ids_ti': 'tab-settings',
    // 1.330.0: nieuwe indeling
    'dashboard/paths': 'tab-net', 'incidents/ids': 'tab-ids', 'settings/allchecks': 'tab-dashboard', 'settings/nis2set': 'tab-compliance', 'settings/ids_hunt': 'tab-ids', 'settings/unifi': 'tab-settings' };
  for (const [from, to] of Object.entries(moved)) {
    await pg.evaluate((h) => showTab(h), from);
    await pg.waitForTimeout(800);
    const at = await pg.evaluate(() => (document.querySelector('.tab-panel:not([hidden])') || {}).id);
    if (at !== to) errs.push(`oud adres #${from} komt uit op ${at}, verwacht ${to}`);
  }
  console.log(`✔ ${Object.keys(moved).length} oude adressen sturen door naar de nieuwe plek`);
  // 1.330.0: de lijst Aanvallen staat alleen op de IDS-pagina; een oud adres bij Incidenten komt daar uit
  const incVis = async () => pg.evaluate(() => { const p = document.querySelector('.ids-pane[data-pane="inc"]'); return p && !p.hidden && p.offsetParent !== null ? p.closest('.tab-panel').id : null; });
  await pg.evaluate(() => showTab('incidents/ids')); await pg.waitForTimeout(800);
  const atIds = await incVis();
  if (atIds !== 'tab-ids' || await pg.$('#inc-src [data-isrc="ids"]')) errs.push(`Aanvallen: via Incidenten op ${atIds}, of nog een tabblad Aanvallen bij Incidenten`);
  else console.log('✔ Aanvallen alleen op de IDS-pagina (een oud adres bij Incidenten komt daar uit)');
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
  // 1.322.4: linkjes naar een andere pagina werken op elke pagina (data-goto, href="#…" en het adres zelf)
  const linkTo = async (html, want) => {
    await pg.evaluate(() => showTab('proxy'));
    await pg.waitForTimeout(400);
    await pg.evaluate((h) => { const d = document.createElement('div'); d.id = 'smoke-link'; d.innerHTML = h; (document.querySelector('#tab-proxy') || document.body).append(d); }, html);
    await pg.click('#smoke-link a');
    await pg.waitForTimeout(500);
    const vis = await pg.evaluate((w) => !document.querySelector('#tab-' + w).hidden, want);
    await pg.evaluate(() => document.querySelector('#smoke-link')?.remove());
    return vis;
  };
  if (!(await linkTo('<a href="#settings/outside" data-goto="settings/outside">x</a>', 'settings'))) throw new Error('linkje met data-goto op Reverse proxy reageert niet');
  if (!(await linkTo('<a href="#alerts">x</a>', 'alerts'))) throw new Error('linkje met href="#alerts" reageert niet');
  await pg.evaluate(() => { location.hash = 'logboek'; });
  await pg.waitForTimeout(500);
  if (await pg.evaluate(() => document.querySelector('#tab-logboek').hidden)) throw new Error('het adres #logboek opent de pagina niet');
  console.log('✔ linkjes naar een andere pagina werken overal (ook op Reverse proxy en via het adres)');
  // 1.323.0: Aan de slag: de wizard (alleen advies), het knopje om verder te gaan, de checklist en de rondleiding
  await pg.evaluate(() => { sessionStorage.removeItem('rzs-wz-later'); wzOpen(1); });
  await pg.waitForSelector('#wz:not([hidden]) .wz-prof', { timeout: 10000 });
  await pg.click('.wz-prof[data-wz-prof="thuis"]');
  await pg.waitForSelector('.wz-prof.on[data-wz-prof="thuis"]');
  await pg.click('.wz-foot .btn.primary[data-wz-next]');
  await pg.click('.wz-foot .btn.primary[data-wz-next]');
  await pg.click('[data-wz-go="settings/channels"]');
  await pg.waitForSelector('#wz-pill');
  if (await pg.evaluate(() => document.querySelector('#tab-settings').hidden)) throw new Error('wizard: Open Instellingen → Kanalen opent de pagina niet');
  await pg.click('#wz-pill .wz-pill-go');
  if (await pg.evaluate(() => document.querySelector('#wz').hidden || wzStep !== 3)) throw new Error('wizard: Verder gaat niet terug naar stap 3');
  await pg.click('[data-wz-later]');
  await pg.evaluate(() => showTab('settings/overview'));
  await pg.waitForSelector('#set-overview .ads .ads-row', { timeout: 15000 });
  await pg.click('#acct-btn');
  await pg.click('[data-ob-open="tour"]');
  for (let i = 0; i < 5; i++) { await pg.waitForSelector('#tour:not([hidden]) [data-tour="next"]'); await pg.click('#tour [data-tour="next"]'); }
  if (!(await pg.evaluate(() => document.querySelector('#tour').hidden))) throw new Error('rondleiding gaat niet dicht na de laatste stap');
  console.log('✔ Aan de slag: wizard, verder-knopje, checklist en rondleiding');
  await b.close();
  if (errs.length) { console.log('✘ fouten:\n  ' + errs.join('\n  ')); process.exit(1); }
  console.log('✔ geen fouten in de browser');
})().catch((e) => { console.error('✘', e.message); process.exit(1); });
