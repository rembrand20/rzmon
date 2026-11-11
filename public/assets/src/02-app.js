// ---------------------------------------------------------------- App
function init() {
  if ($('#vuln-search')) initVulns();
  if ($('#net-search')) initNet();
  if ($('#an-body')) initAnalytics();
  if ($('#leak-list')) initLeaks();
  initEol();   // 1.227.0
  // 1.224.0: UniFi en AdGuard Home elk op een eigen pagina, met een eigen testknop
  $$('[data-netlinks-test]').forEach((b) => (b.onclick = async (e) => {
    const btn = e.currentTarget, which = btn.dataset.netlinksTest, box = $(`[data-netlinks-result="${which}"]`);
    btn.disabled = true;
    box.innerHTML = `<span class="muted">⏳ ${L('Testen…')}</span>`;
    try {
      const r = await api('net_links_test', {});
      const line = (x, lbl) => `<div class="${x.ok ? 'good-txt' : 'bad'}">${x.ok ? '✔' : '✘'} <b>${lbl}</b>: ${esc(x.msg)}</div>`;
      const rows = which === 'unifi' ? (r.unifi ? [line(r.unifi, 'UniFi')] : []) : (r.adguard || []).map((a) => line(a, 'AdGuard Home'));
      box.innerHTML = rows.join('') || `<span class="muted">${L('Nog niets ingevuld (sla eerst op).')}</span>`;
    } catch (ex) { box.innerHTML = `<span class="bad">${esc(ex.message)}</span>`; }
    btn.disabled = false;
  }));
  $('#vuln-scan-now').onclick = async (e) => {
    const b = e.currentTarget;
    b.disabled = true;
    try {
      const r = await api('vuln_scan', {});
      $('#vuln-scan-info').textContent = r.message || (r.pending ? L('Scan gestart: nog {n} server(s) te gaan, de rest volgt binnen een paar minuten', { n: r.pending }) : L('Scan klaar'));
    } catch (ex) { $('#vuln-scan-info').textContent = ex.message; }
    b.disabled = false;
  };
  $$('.tab').forEach((t) => t.addEventListener('click', () => { if (t.dataset.tab) showTab(t.dataset.tab); }));   // 1.196.0: "Meer" heeft geen eigen pagina
  initMainTabs();
  // 1.194.0: op een telefoon altijd de mDS als startpagina (behalve een diepe link als #alerts?server=… of #ids/inc)
{ const h = location.hash.slice(1); showTab(MDS_MQ.matches && (!h || /^[a-z]+$/.test(h)) ? 'mds' : (h || 'home')); }

  initAccountMenu();
  initSideToggle(); initSideResize(); initSideAdmin();
  initCC();   // 1.154.0
  initSettingsNav();
  initCmdk();
  if ($('#advice-run')) $('#advice-run').onclick = async (e) => {
    e.target.disabled = true;
    try { const r = await api('advice_run', {}); toast(L('{n} nieuwe voorstel(len)', { n: r.n })); loadAdvice(); } catch (ex) { toast(ex.message, 'err'); }
    e.target.disabled = false;
  };
  if ($('#logout')) $('#logout').onclick = async () => { try { sessionStorage.removeItem('rzs-ids-cache'); } catch (ex) { /* geen opslag */ } await api('logout', {}); location.reload(); };
  $('#add-host').onclick = () => hostForm();
  $('#run-all').onclick = async (e) => {
    e.target.disabled = true;
    try { const r = await api('run_all', {}); toast(L('{n} checks uitgevoerd', { n: r.count })); await refresh(); }
    catch (ex) { toast(ex.message, 'err'); }
    e.target.disabled = false;
  };
  let filterT = null;   // 1.224.4: pas na 200 ms stil typen (niet de hele pagina bij elke toets)
  $('#filter').addEventListener('input', () => { clearTimeout(filterT); filterT = setTimeout(render, 200); });
  const setDashFilter = (k) => { dashFilter = k; render(); };   // 1.171.4: alleen zolang je op de pagina blijft
  $('#dash-chips').addEventListener('click', (e) => {
    const b = e.target.closest('[data-dfilter]');
    if (b) { statusFilter = null; setDashFilter(b.dataset.dfilter); }
  });
  $('#dash-sort').onchange = (e) => { dashSort = e.target.value; uiSet('dash_sort', 'rzmon-dash-sort', dashSort); render(); };
  // 1.199.0: klik op CPU, RAM of disk in de tellerbalk = daarop sorteren (nog eens = terug naar Ergste eerst)
  $('#mini-res').onclick = (e) => {
    const b = e.target.closest('[data-mres]');
    if (!b) return;
    dashSort = dashSort === b.dataset.mres ? 'status' : b.dataset.mres;
    uiSet('dash_sort', 'rzmon-dash-sort', dashSort);
    render();
  };
  $('#health-counts').addEventListener('click', (e) => {
    const b = e.target.closest('[data-st]');
    if (!b) return;
    const st = +b.dataset.st;
    statusFilter = statusFilter === st ? null : st;
    if (statusFilter !== null) dashFilter = 'all';
    render();
    if (statusFilter !== null) $('#hosts').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  initAvail();   // 1.169.0
  initContainers();   // 1.176.0
  initNetPaths();   // 1.177.0
  $('#health').addEventListener('click', (e) => {
    const b = e.target.closest('[data-host]');
    if (b) return jumpToHost(b.dataset.host);
    if (e.target.closest('[data-kpi=problems]')) { statusFilter = null; setDashFilter(dashFilter === 'problems' ? 'all' : 'problems'); }
  });
  $('#dash-attn').addEventListener('click', (e) => {
    const host = e.target.closest('[data-host]');
    if (host) return jumpToHost(host.dataset.host);
    const ab = e.target.closest('[data-attn-bundle]');
    if (ab) { const id = +ab.dataset.attnBundle; if (attnBundles.has(id)) attnBundles.delete(id); else attnBundles.add(id); renderAttention(); return; }
    if (e.target.closest('[data-attn-more]')) { attnOpen = !attnOpen; return renderAttention(); }
    const axf = e.target.closest('[data-ax-f]');
    if (axf) { axFilter = axf.dataset.axF; return renderAttention(); }   // 1.176.0
    const axo = e.target.closest('[data-ax-open]');
    if (axo) return checkInfo(+axo.dataset.axOpen);
    if (e.target.closest('[data-attn-acked]')) { statusFilter = null; return setDashFilter('problems'); }
    onHostsClick(e);
  });
  // Menu's ⋯ (dashboard: onderhoud, alles nu checken, alles openen; andere pagina's: alles openen e.d.)
  // Menu ⋯: standaard rechts uitgelijnd (klapt naar links uit); past dat niet op het scherm (de knop staat links,
  // bv. als de balk op een smal scherm doorloopt), dan naar rechts uitklappen
  const menuOpen = (btn, on) => {
    const m = btn.nextElementSibling;
    m.hidden = !on;
    btn.setAttribute('aria-expanded', on);
    if (!on) return;
    m.classList.remove('to-right');
    if (m.getBoundingClientRect().left < 8) m.classList.add('to-right');
  };
  $$('[data-menu-btn]').forEach((btn) => {
    btn.onclick = (e) => { e.stopPropagation(); const on = btn.nextElementSibling.hidden; $$('[data-menu-btn]').forEach((x) => menuOpen(x, false)); menuOpen(btn, on); };
    btn.nextElementSibling.addEventListener('click', () => menuOpen(btn, false));
  });
  document.addEventListener('click', (e) => { if (!e.target.closest('.dash-more')) $$('[data-menu-btn]').forEach((x) => menuOpen(x, false)); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') $$('[data-menu-btn]').forEach((x) => menuOpen(x, false)); });
  // De tellerbalk blijft onder de header staan: hoe hoog is de header?
  // 1.135.0: in de zijbalk (breed scherm) staat er bovenaan niets meer: dan plakken de tellers tegen de bovenrand
  const topH = () => document.documentElement.style.setProperty('--top-h', ($('.topbar') && getComputedStyle($('.topbar')).position !== 'fixed' ? $('.topbar').offsetHeight : 0) + 'px');
  topH(); window.addEventListener('resize', topH);
  $('#hosts').addEventListener('click', onHostsClick);
  $('#hosts').addEventListener('keydown', (e) => {
    const head = e.target.closest('.host-head[data-act=toggle], .line-row[data-act=toggle], .ok-row[data-act=toggle]');
    if (head && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); toggleHost(head.dataset.id); }
    const t = e.target.closest('.hb-tile[data-act=cc-open]');
    if (t && e.target === t && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); ccToggle(t.dataset.id, !ccOpen.has(+t.dataset.id)); }
    const ck = e.target.closest('tr.hb-ck[data-cid], .ccx-row[data-cid], .ccx-ok[data-cid]');
    if (ck && e.target === ck && e.key === 'Enter') checkInfo(ck.dataset.cid);
  });
  $('#toggle-all').onclick = () => {
    if (isCC()) {   // 1.164.0: in CC alle blokken open of dicht
      const allCc = state.hosts.every((h) => ccOpen.has(+h.id));
      ccOpen.clear();
      if (!allCc) state.hosts.forEach((h) => ccOpen.add(+h.id));
      return render();
    }
    const all = state.hosts.every((h) => openHosts.has(+h.id));
    openHosts.clear();
    if (!all) state.hosts.forEach((h) => openHosts.add(+h.id));
    saveOpen();
    render();
  };

  $('#settings-form').addEventListener('submit', saveSettings);
  $('#backup-now').onclick = backupNow;
  $('#update-start').onclick = () => openUpdateDialog();
  $('#update-pick').onclick = () => openUpdateDialog(true);
  // Kwetsbaarheden aan/uit: meteen opslaan (zoals het IDS); het tabblad komt erbij of gaat weg, dus de pagina herladen
  $('#vuln-enabled').onchange = async (e) => {
    try {
      await api('vuln_set', { enabled: e.target.checked });
      toast(e.target.checked ? L('Kwetsbaarheden aangezet') : L('Kwetsbaarheden uitgezet'));
      setTimeout(() => location.reload(), 700);
    } catch (ex) { toast(ex.message, 'err'); e.target.checked = !e.target.checked; }
  };
  // Bekende aanvallers en land per IP (1.82.0)
  const ipintelShow = (s) => {
    $('#ipintel-status').innerHTML = [
      s.bl_at ? `☠️ ${L('{n} bereiken met bekende aanvallers, bijgewerkt {t}', { n: (+s.bl_n).toLocaleString(LOCALE), t: esc(fmtDateTime(s.bl_at)) })}` : `☠️ ${L('nog niet opgehaald')}`,
      s.bl_error ? `<span class="warn-txt">${esc(s.bl_error)}</span>` : '',
      s.geo_at ? `🌍 ${L('{n} bereiken met een land, bijgewerkt {t}', { n: (+s.geo_n).toLocaleString(LOCALE), t: esc(fmtDateTime(s.geo_at)) })}` : `🌍 ${L('nog niet opgehaald')}`,
      s.geo_error ? `<span class="warn-txt">${esc(s.geo_error)}</span>` : '',
    ].filter(Boolean).join(' · ');
  };
  if (isAdmin()) api('ipintel_status').then(ipintelShow).catch(() => {});
  // Dreigingsinformatie (1.112.0): stand, sleutels testen, lijsten bijwerken
  const tiShow = (s) => {
    const prov = { abuse: 'AbuseIPDB', greynoise: 'GreyNoise', shodan: 'Shodan', circl: 'CIRCL', bazaar: 'MalwareBazaar', report: L('gemeld') };
    $('#ti-status').innerHTML = [
      s.lists_at ? L('Lijsten bijgewerkt {t}: {list}', { t: esc(fmtDateTime(s.lists_at)), list: esc(Object.entries(s.lists).map(([k, n]) => `${k} ${(+n).toLocaleString(LOCALE)}`).join(', ') || '–') }) : L('Lijsten nog niet opgehaald'),
      s.lists_error ? `<span class="warn-txt">${esc(s.lists_error)}</span>` : '',
      Object.keys(s.used).length ? L('Vandaag: {list}', { list: esc(Object.entries(s.used).map(([k, n]) => `${prov[k] || k} ${n}/${s.limits[k]}`).join(', ')) }) : '',
      s.malicious ? L('{n} bekende aanvallers in 7 dagen', { n: s.malicious }) : '',
      s.reported ? L('{n} aanvallers gemeld bij AbuseIPDB', { n: s.reported }) : '',
      ...Object.entries(s.errors || {}).map(([k, e]) => `<span class="warn-txt">${esc(prov[k] || k)}: ${esc(e.msg)} (${esc(fmtDateTime(e.at))})</span>`),
    ].filter(Boolean).join(' · ');
    const dn = s.dns || {};
    $('#dns-status').innerHTML = !dn.adguard ? `<span class="warn-txt">${L('Nog geen AdGuard gekoppeld (Instellingen → Koppelingen → AdGuard).')}</span>`
      : [dn.at ? L('Laatst gekeken {t}', { t: esc(fmtDateTime(dn.at)) }) : L('Nog niet gekeken'), L('vandaag {n} DNS-vragen, {h} verdacht', { n: (+dn.n).toLocaleString(LOCALE), h: +dn.hits }),
        dn.error ? `<span class="warn-txt">${esc(dn.error)}</span>` : ''].filter(Boolean).join(' · ');
  };
  const tiLoad = () => api('ti_status').then(tiShow).catch(() => {});
  if (isAdmin()) tiLoad();
  $('#ids-ti-card').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-ti-test]');
    if (!b) return;
    const res = $(`[data-ti-res="${b.dataset.tiTest}"]`);
    b.disabled = true;
    res.textContent = '⏳';
    try { const r = await api('ti_test', { p: b.dataset.tiTest }); res.innerHTML = r.ok ? `<span class="good">✔ ${esc(r.msg)}</span>` : `<span class="bad">✘ ${esc(r.msg)}</span>`; } catch (ex) { res.textContent = ex.message; }
    b.disabled = false;
  });
  $('#ti-lists').onclick = async (e) => {
    e.target.disabled = true;
    toast(L('Bijwerken… (kan een minuut duren)'));
    try { const r = await api('ti_lists', {}); toast(r.msg); tiLoad(); } catch (ex) { toast(ex.message, 'err'); }
    e.target.disabled = false;
  };
  $('#ipintel-update').onclick = async (e) => {
    e.target.disabled = true;
    toast(L('Bijwerken… (het land kan een minuut duren)'));
    try { const r = await api('ipintel_update', { geo: true }); toast(r.result); ipintelShow(r); } catch (ex) { toast(ex.message, 'err'); }
    e.target.disabled = false;
  };
  $('#ids-enabled').onchange = async (e) => {
    try { await api('ids_set', { enabled: e.target.checked }); toast(e.target.checked ? L('IDS aangezet') : L('IDS uitgezet')); }
    catch (ex) { toast(ex.message, 'err'); e.target.checked = !e.target.checked; }
    idsModeInfo();
  };
  $('#ids-mode').onchange = (e) => {
    const block = e.target.checked;
    e.target.checked = !block;   // pas omzetten als het gelukt is
    if (!block) {
      api('ids_set', { mode: 'learn' }).then(() => { $('#ids-mode').checked = false; idsModeInfo(); toast(L('IDS: leren (alleen melden)')); }).catch((ex) => toast(ex.message, 'err'));
      return;
    }
    modal({
      title: '⛔ ' + L('IDS: blokkeren aanzetten'),
      body: `<p style="margin-top:0">${L('RzSense gaat dan echt IP-adressen blokkeren op je servers (eigen nftables-tabel van de agent), bij brute force en als jij op ⛔ klikt. Je LAN, RzSense zelf en de lijst <i>Nooit blokkeren</i> worden nooit automatisch geblokkeerd; op TrueNAS wordt alleen gedetecteerd.')}</p>
        ${xpHtml(L('Blokkeren kun je op een server zelf altijd uitzetten.'), L('Op een server zelf zet je blokkeren altijd uit met <code>IDS_BLOCK=0</code> in de instellingen van de agent. <b>Tip:</b> zet het de eerste keer aan terwijl je ook via de console kunt inloggen, voor het geval je jezelf buitensluit. Deze wijziging komt in het logboek.'))}
        ${confirmField('BLOKKEREN')}`,
      buttons: [{ label: L('Blokkeren aanzetten'), cls: 'primary', submit: true }],
      onSubmit: async (d) => {
        await api('ids_set', { mode: 'block', confirm: d.confirm });
        toast(L('IDS: blokkeren staat aan'));
        setTimeout(() => { showTab('settings/ids'); }, 50);
      },
    });
  };
  $('#agent-commands').onchange = (e) => {
    const sel = e.target;
    setAgentCommands(null, sel.value, sel.dataset.current || 'off', L('alle agents'), () => { showTab('settings/agents'); loadAgentCommands(); });
  };
  $('#ids-filter').onclick = (e) => {
    const b = e.target.closest('[data-if], [data-icat], [data-icard-off]');
    if (!b) return;
    if (b.dataset.icardOff) idsCard = '';
    else if (b.dataset.if) { idsFilter = b.dataset.if; idsCat = ''; } else idsCat = idsCat === b.dataset.icat ? '' : b.dataset.icat;
    renderIds();
  };
  let idsSearchT;
  $('#ids-search').addEventListener('input', () => { clearTimeout(idsSearchT); idsSearchT = setTimeout(renderIds, 200); });
  $('#acc-search').addEventListener('input', () => { clearTimeout(idsSearchT); idsSearchT = setTimeout(renderAccess, 200); });
  const idsGo = (e) => {
    const b = e.target.closest('[data-igo]');
    if (!b) return;
    idsTab = b.dataset.igo;
    if (idsTab === 'acc') { accFilter = b.dataset.fail ? 'fail' : 'all'; $$('#acc-filter .seg-btn').forEach((x) => { x.classList.toggle('active', x.dataset.ac === accFilter); x.classList.toggle('on', x.dataset.ac === accFilter); }); renderAccess(); }
    if (idsTab === 'inc') { idsFilter = 'open'; idsCat = ''; renderIds(); }
    showIdsTab();
  };
  $('#ids-counts').addEventListener('click', idsGo);
  $('#ids-kpis').addEventListener('click', idsGo);
  $('#ids-toggle').onclick = () => {
    const days = $$('#ids-incidents .alert-day');
    const all = days.every((x) => x.open);
    days.forEach((x) => { x.open = !all; });
  };
  $('#ids-block-add').onclick = () => idsBlockDialog();
  $('#anom-host').onchange = () => loadAnom();
  $('#anom-days').onclick = (e) => {
    const b = e.target.closest('[data-ad]');
    if (!b) return;
    anomDays = +b.dataset.ad;
    $$('#anom-days .seg-btn').forEach((x) => x.classList.toggle('active', x === b));
    loadAnom();
  };
  $('#anom-learn').onclick = async (e) => {
    const b = e.target.closest('[data-learn]');
    if (!b) return;
    const finish = b.dataset.learn === 'finish';
    if (!confirm(finish ? L('Leren nu afronden? Vanaf nu meldt RzSense alles wat deze server nog niet deed.') : L('Opnieuw leren? Alles wat RzSense over deze server geleerd heeft, gaat weg; de komende dagen wordt er niets als afwijking gemeld.'))) return;
    try { await api('edr_learn', { host_id: +b.dataset.h, action: b.dataset.learn }); toast(finish ? L('Leren afgerond') : L('De server leert opnieuw')); loadAnom(); } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#prot-cards').onchange = async (e) => {
    const c = e.target.closest('[data-prot-toggle]');
    if (!c) return;
    try { await api('settings_save', { [c.dataset.protToggle]: c.checked ? '1' : '0' }); toast(c.checked ? L('Aangezet') : L('Uitgezet')); loadProt(); } catch (ex) { toast(ex.message, 'err'); c.checked = !c.checked; }
  };
  $('#prot-cards').onclick = (e) => {
    const set = e.target.closest('[data-prot-set]');   // 1.181.0: instellen gebeurt in de beheerportal
    if (set) { protFocus = set.dataset.protSet; showTab('settings/ids_prot'); return; }
    const b = e.target.closest('[data-prot-show]');
    if (!b) return;
    idsCard = b.dataset.protShow; idsFilter = 'all'; idsCat = '';
    showTab('ids/inc');
  };
  $('#mitre-host').onchange = () => loadMitre();
  $('#mitre-cover').onchange = () => renderMitre();
  $('#mitre-days').onclick = (e) => {
    const b = e.target.closest('[data-md]');
    if (!b) return;
    mitreDays = +b.dataset.md;
    $$('#mitre-days .seg-btn').forEach((x) => x.classList.toggle('active', x === b));
    loadMitre();
  };
  $('#mitre-matrix').onclick = (e) => {
    const b = e.target.closest('[data-mt]');
    if (b) mitreSignals(b.dataset.mt, b.dataset.ta);
  };
  $('#edr-host').onchange = () => loadEdrFeed();
  initLive();   // 1.219.0
  ['#rule-q', '#rule-q-set'].forEach((id) => { if ($(id)) $(id).oninput = () => renderEdrRules(); });
  ['#rule-src', '#rule-src-set'].forEach((id) => {
    if (!$(id)) return;
    $(id).onclick = (e) => {
      const b = e.target.closest('[data-rs]');
      if (!b) return;
      ruleSrc = b.dataset.rs;
      $$(id + ' .seg-btn').forEach((x) => x.classList.toggle('active', x === b));
      renderEdrRules();
    };
  });
  initExceptions();   // 1.132.0
  if ($('#edr-rules-set')) $('#edr-rules-set').onchange = async (e) => {
    const c = e.target.closest('[data-rule]');
    if (!c) return;
    try { await api('edr_rule_set', { id: c.dataset.rule, on: c.checked }); toast(c.checked ? L('Regel aan') : L('Regel uit')); loadEdrRules(); } catch (ex) { toast(ex.message, 'err'); c.checked = !c.checked; }
  };
  $('#ids-tabs').onclick = (e) => {
    const b = e.target.closest('[data-it]');
    if (!b) return;
    idsTab = b.dataset.it;
    try { localStorage.setItem('rzmon-ids-tab', idsTab); } catch (ex) { /* alleen voor nu */ }
    showIdsTab();
    fxSyncHash();
  };
  $('#acc-filter').onclick = (e) => {
    const b = e.target.closest('[data-ac]');
    if (!b) return;
    accFilter = b.dataset.ac;
    if (accFilter !== 'ssh') accSsh = 'all';
    $$('#acc-filter .seg-btn').forEach((x) => { x.classList.toggle('active', x === b); x.classList.toggle('on', x === b); });
    renderAccess();
  };
  // 1.152.0: SSH: Alles · Gelukt · Mislukt
  if ($('#acc-ssh')) $('#acc-ssh').onclick = (e) => { const b = e.target.closest('[data-acs]'); if (b) { accSsh = b.dataset.acs; renderAccess(); } };
  $('#acc-host').onchange = (e) => { accHost = e.target.value; renderAccess(); };
  $('#acc-toggle').onclick = () => {
    const days = $$('#ids-access .alert-day');
    const all = days.every((x) => x.open);
    days.forEach((x) => { x.open = !all; });
  };
  $('#ids-access').addEventListener('click', (e) => {
    if (toggleBundle(e)) return;
    const th = e.target.closest('th[data-sort]');
    if (th) {
      const k = th.dataset.sort;
      accSort = accSort.key !== k ? { key: k, dir: 1 } : accSort.dir > 0 ? { key: k, dir: -1 } : { key: '', dir: 1 };
      uiSortSet('acc_sort', 'rzmon-acc-sort', accSort);
      return renderAccess();
    }
    const b = e.target.closest('[data-acc]');
    if (!b) return;
    const r = accData.rows.find((x) => +x.id === +b.dataset.id);
    if (!r) return;
    if (b.dataset.acc === 'block') return idsBlockDialog(accDet(r).ip, r.host_id, `${r.type === 'ssh' ? 'SSH' : r.type}: ${r.kind === 'authfail' ? L('mislukte pogingen') : 'login'}`);
    if (b.dataset.acc === 'ignore') accIgnoreDialog(r);
  });
  $('#tab-ids').addEventListener('click', async (e) => {
    const go = e.target.closest('[data-goto]');
    if (go) { e.preventDefault(); showTab(go.dataset.goto); return; }
    if (e.target.closest('#ids-incidents') && toggleBundle(e)) return;
    const hsb = e.target.closest('[data-hostsec]');
    if (hsb) return hostSecDialog(+hsb.dataset.hostsec);
    if (e.target.closest('[data-ids-attn-more]')) { idsAttnOpen = !idsAttnOpen; return renderIdsAttention(idsData.incidents.filter((i) => i.status === 'open')); }
    const ih = e.target.closest('[data-ihost]');
    if (ih) { const h = idsData.hosts.find((x) => +x.id === +ih.dataset.ihost); idsFilter = 'open'; idsCat = ''; $('#ids-search').value = h ? h.name : ''; showTab('ids/inc'); renderIds(); return $('#ids-incidents').scrollIntoView({ block: 'start', behavior: 'smooth' }); }
    const io = e.target.closest('[data-inc]');
    if (io) return incidentDialog(+io.dataset.inc, io.dataset.incList ? io.dataset.incList.split(',').map(Number) : null);
    const b = e.target.closest('[data-ids], [data-unblock], [data-rebase]');
    if (!b) return;
    try {
      if (b.dataset.ids === 'block') return idsBlockDialog(b.dataset.ip, b.dataset.host);
      if (b.dataset.ids === 'release') return wlDialog({ type: 'ip', value: b.dataset.ip, src: 'ids', wl: false });
      // 1.217.0: afhandelen gebeurt meteen op het scherm; de server krijgt één aanvraag (ook voor een bundel of een keuze)
      if (['handle', 'normal', 'reopen'].includes(b.dataset.ids)) {
        const ids = (b.dataset.many || b.dataset.id || '').split(',').map(Number).filter(Boolean);
        return idsAct(ids, b.dataset.ids);
      }
      if (b.dataset.unblock) {
        if (!confirm(L('Deze blokkade opheffen?'))) return;
        await api('ids_unblock', { id: +b.dataset.unblock });
        toast(L('Gedeblokkeerd; de agent past het binnen een minuut aan'));
      } else if (b.dataset.rebase) {
        if (!confirm(L('Basismeting opnieuw doen? Wat er nu op de server staat wordt als normaal vastgelegd; tot het einde van de basismeting worden afwijkingen niet gemeld.'))) return;
        await api('ids_rebaseline', { host_id: +b.dataset.rebase });
      }
      loadIds();
    } catch (ex) { toast(ex.message, 'err'); }
  });
  $('#alerts-toggle').onclick = () => {
    const days = $$('#alerts .alert-day');
    const all = days.every((x) => x.open);
    days.forEach((x) => { x.open = !all; });   // het toggle-event bouwt de inhoud op en onthoudt de stand
  };
  $('#alerts').addEventListener('click', (e) => {
    // 1.207.0: herhalingen (4× in 2 u) uitklappen
    const rb = e.target.closest('[data-al2-rep]');
    if (rb) {
      const sub = $(`#alerts .al2-sub[data-bfor="${rb.dataset.al2Rep}"]`);
      if (sub) (sub.classList.toggle('closed') ? alertBundles.delete(sub.dataset.b) : alertBundles.add(sub.dataset.b));
      return;
    }
    // ⛔ bij een geweigerde verbinding of mislukte login: dat IP blokkeren
    const bl = e.target.closest('[data-block-ip]');
    if (bl) { e.stopPropagation(); return blockDialog(bl.dataset.blockIp); }
    // Kolomkop: sorteren (oplopend → aflopend → standaard)
    const th = e.target.closest('.al2-headrow [data-sort]');
    if (th) {
      const k = th.dataset.sort;
      alertSort = alertSort.key !== k ? { key: k, dir: 1 } : alertSort.dir > 0 ? { key: k, dir: -1 } : { key: '', dir: 1 };
      return saveAlertSort();
    }
    // Anders: zijpaneel met de details
    const row = e.target.closest('.al2-row[data-aid]');
    if (row && !e.target.closest('button, a')) al2Open(row.dataset.aid);
  });
  $('#alerts').addEventListener('keydown', (e) => {
    const row = e.target.closest && e.target.closest('.al2-row[data-aid]');
    if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); al2Open(row.dataset.aid); }
  });
  const setAlertFilter = (f) => {
    alertFilter = alertFilter === f && f !== 'all' ? 'all' : f;
    try { localStorage.setItem('rzmon-alert-filter', alertFilter); } catch (ex) { /* alleen voor nu */ }
    savePref('alert_filter', alertFilter);
    renderAlerts();
  };
  $('#alerts-filter').addEventListener('click', (e) => { const b = e.target.closest('[data-af]'); if (b) setAlertFilter(b.dataset.af); });
  $('#alerts-counts').addEventListener('click', (e) => { const b = e.target.closest('[data-af]'); if (b) setAlertFilter(b.dataset.af); });
  let alertSearchT;
  $('#alerts-search').addEventListener('input', () => { clearTimeout(alertSearchT); alertSearchT = setTimeout(renderAlerts, 200); });
  $('#alerts-host').onchange = renderAlerts;
  $('#alerts-kpis').addEventListener('click', (e) => {
    const go = e.target.closest('[data-go]');
    if (go) showTab(go.dataset.go);
  });
  $('#alerts-sort').onchange = (e) => {
    const [key, dir] = e.target.value.split(':');
    alertSort = { key, dir: +dir || 1 };
    saveAlertSort();
  };
  $('#tab-settings').addEventListener('click', (e) => {
    const h = e.target.closest('.set-l1nes [data-sk] > h2');
    if (h && !e.target.closest('a, button, input, label')) toggleSetting(h.parentElement);
  });
  $('#tab-settings').addEventListener('keydown', (e) => {
    const h = e.target.closest('.set-l1nes [data-sk] > h2');
    if (h && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); toggleSetting(h.parentElement); }
  });
  $('#set-toggle-all').onclick = () => {
    const shown = setCards().filter(setVisible);
    const all = shown.every((c) => c.classList.contains('set-open'));
    shown.forEach((c) => (setOpen[c.dataset.sk] = !all));
    saveSetOpen();
    applySettingsLayout();
  };
  $('#settings-form').addEventListener('input', () => settingsSummaries());
  $('#settings-form').addEventListener('change', (e) => { if (!e.target.closest('[data-nodirty]')) markSettingsDirty(true, e.target); settingsSummaries(); });
  // Knoppen die iets toevoegen of weghalen (stille uren): ook opslaan
  $('#settings-form').update_auto.addEventListener('change', backupFormToggle);
  $('#update-check').onclick = async (e) => { const btn = e.currentTarget; btn.disabled = true; await loadUpdate(true); btn.disabled = false; };
  $('#update-badge').onclick = openUpdateDialog;
  $('#agents-update-all').onclick = updateAllAgents;
  $('#backup-upload').onclick = () => $('#backup-file').click();
  $('#backup-file').onchange = (e) => backupUpload(e.target.files[0]);
  $('#backup-list').addEventListener('click', onBackupListClick);
  $('#settings-form').backup_freq.addEventListener('change', backupFormToggle);
  $('#pushover-test').onclick = testPushover;
  $('#ntfy-test').onclick = (e) => testChannel(e, 'ntfy_test', L('Testbericht verstuurd! Check je telefoon') + ' 📱', 'ntfy');
  $('#webhook-test').onclick = testWebhook;
  $('#email-test').onclick = (e) => testChannel(e, 'email_test', L('Testmail verstuurd 📧'), L('E-mail'));
  $('#mqtt-test').onclick = (e) => testChannel(e, 'mqtt_test', null, 'MQTT');
  $('#maint-all').onclick = () => maintenanceDialog('all', 0, L('alle servers'), state.maintenance);
  initIsoBanner();   // 1.172.0
  $('#maint-banner').addEventListener('click', (e) => e.target.closest('[data-maint-end]') && endMaintenance('all', 0));
  if ($('#password-form')) $('#password-form').addEventListener('submit', changePassword);   // alleen op Mijn account (niet zonder account via het LAN)
  $('#user-form').addEventListener('submit', createUser);
  $('#db-rotate').onclick = dbRotateDialog;
  // 1.158.0: ook knoppen die later getekend worden (kop van het dashboard)
  document.addEventListener('click', (e) => { const b = e.target.closest('[data-view-layout]'); if (b) setLayout(b.dataset.viewLayout); });
  // 1.157.0: rode gloed en live-animaties (per account)
  $$('[data-mon-pref]').forEach((c) => (c.onchange = () => { savePref(MON_PREF_KEY[c.dataset.monPref], c.checked ? 'on' : 'off'); applyMonPrefs(); }));
  $$('[data-acct-ids-modern]').forEach((c) => (c.onchange = () => { savePref('ids_modern', c.checked ? 'on' : 'off'); savedFlag(c, true); if (typeof idsData !== 'undefined' && idsData) renderIds(); }));
  $$('[data-acct-motion]').forEach((c) => (c.onchange = () => { savePref('af_motion', String(c.value)); savedFlag(c, true); }));
  applyMonPrefs();
  initPageGrid();   // 1.158.0: lay-out CC
  // Bij openen: alles dicht of slim openen (voor je account, 1.71.0)
  $$('[data-view-open]').forEach((b) => (b.onclick = () => {
    savePref('open_mode', b.dataset.viewOpen);
    showViewPrefs();
    toast(b.dataset.viewOpen === 'smart' ? L('Slim openen: vandaag en ernstige dingen staan open') : L('Bij openen staat alles dicht, behalve vandaag'));
  }));
  $$('[data-view-density]').forEach((b) => (b.onclick = () => setViewPref('density', b.dataset.viewDensity)));
  showViewPrefs();
  $('#users').addEventListener('click', onUsersClick);
  $('#users').addEventListener('change', async (e) => {
    const sel = e.target.closest('select[data-uact=role]');
    if (!sel) return;
    try { await api('user_role', { id: sel.dataset.id, role: sel.value }); toast(L('Rol gewijzigd')); }
    catch (ex) { toast(ex.message, 'err'); }
    loadUsers();
  });
  // Taal: EN links, NL rechts; bewaard bij je account, daarna de pagina opnieuw laden
  const langToggle = $('#lang-toggle');
  if (langToggle) {
    langToggle.onchange = async () => {
      try { await api('lang_set', { lang: langToggle.checked ? 'nl' : 'en' }); location.reload(); }
      catch (ex) { toast(ex.message, 'err'); langToggle.checked = !langToggle.checked; }
    };
  }
  $('#api-key-form').addEventListener('submit', createApiKey);
  initSecurity();
  initSysinfo();
  initTemplates();
  initAllChecks();
  initSystemTools();
  $('#api-keys').addEventListener('click', apiKeyClick);
  $('#access-form').addEventListener('submit', saveAccess);
  $('#access-form').addEventListener('change', (e) => saveAccess(null, e.target));
  initHttps();
  initHibp();

  refresh();
  // 1.107.0: live bijwerken. Elke 3 s een klein kenmerk ophalen (statuswissels, meldingen, nieuwe meetwaarden,
  // bevestigd, onderhoud, wijzigingen); verandert het, dan de stand ophalen. Licht voor de server: geen open
  // verbindingen die PHP bezet houden. 1.109.0: alleen wat veranderde wordt bijgewerkt (morph), dus ook met een
  // venster open; dat venster werkt zelf mee (liveModal).
  let liveRev = null, liveParts = {}, liveFail = 0, liveFull = Date.now(), liveBusy = false;
  setInterval(async () => {
    if (document.hidden || liveBusy) return;
    liveBusy = true;
    try {
      const r = await api('rev');
      liveFail = 0;
      $$('.live-dot').forEach((d) => d.classList.remove('off'));
      // 1.119.0: alleen herladen wat veranderde: meldingen, IDS en de live-feed elk apart
      const p = r.parts || {}, was = liveParts, first = liveRev === null;
      const what = { alerts: !first && p.a !== was.a, ids: !first && p.i !== was.i };
      const full = Date.now() - liveFull > 60000;
      if ((!first && (r.rev !== liveRev || what.alerts || what.ids)) || full) {
        if (full) liveFull = Date.now();
        await refresh(true, what);
        liveModal();
      } else if (!first && p.e !== was.e && !$('#tab-ids').hidden && idsTab === 'live') {
        loadEdrFeed();   // alleen nieuwe gebeurtenissen: alleen de feed
      }
      liveRev = r.rev;
      liveParts = p;
    } catch (e) {
      if (++liveFail >= 3) $$('.live-dot').forEach((d) => d.classList.add('off'));
    } finally {
      liveBusy = false;
    }
  }, 3000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}

/** 1.214.0: in het adres heet Monitoring #monitoring (intern blijft het tabblad 'dashboard'; #dashboard werkt nog) */
function hashName(n) { return String(n).replace(/^dashboard(?=$|\/|\?)/, 'monitoring').replace(/^monitoring\/checks(?=$|\?)/, 'monitoring'); }

function showTab(name) {
  name = String(name).replace(/^monitoring(?=$|\/|\?)/, 'dashboard');
  xpClose();
  // 1.171.4: terug op Monitoring vanaf een andere pagina: weer alles (filterknop en statusfilter terug naar Alle)
  if (/^dashboard\b/.test(String(name)) && ($('.tab-panel:not([hidden])') || {}).id !== 'tab-dashboard') { dashFilter = 'all'; statusFilter = null; }
  if (!/^dashboard\b/.test(String(name))) ccOpen.clear();   // 1.164.0: CC-servers weer dicht   // 1.161.0: van pagina wisselen = alle uitleg weer dicht
  let sub = null;
  // Filters in het adres (1.71.0): #alerts?server=NAS,Pi&status=crit, #ids/acc?result=fail, #vulns/leak?status=open
  const qi = name.indexOf('?');
  const qs = qi >= 0 ? name.slice(qi + 1) : '';
  if (qi >= 0) name = name.slice(0, qi);
  if (name === 'settings/blocks') name = 'ids/blk';   // 1.92.0: blokkades en whitelist staan in het IDS-tabblad
  // 1.197.0: diepe links (o.a. vanuit de Home Assistant-kaart): #net/map, #net/list, #net/disc en #dashboard/paths, /avail, /cont
  let netReq = null, monReq = null;
  if (/^net\/(map|list|disc)$/.test(name)) { netReq = name.slice(4); name = 'net'; }
  if (/^dashboard\/(paths|avail|cont|checks)$/.test(name)) { monReq = name.slice(10); name = 'dashboard'; }
  if (/^(ids|vulns)\//.test(name)) {
    const [tab, s] = name.split('/');
    name = tab;
    if (tab === 'ids' && ['inc', 'map', 'acc', 'blk', 'prot', 'live', 'rules', 'anom', 'mitre', 'apps', 'hunt'].includes(s)) idsTab = s;
    if (tab === 'vulns' && ['cve', 'leak', 'eol', 'patch'].includes(s)) vulnTab = s;
  }
  if (qs) {
    const page = { dashboard: 'dash', alerts: 'alerts', ids: { inc: 'ids', acc: 'acc', blk: 'blk' }[idsTab], vulns: vulnTab === 'leak' ? 'leaks' : 'vulns' }[name];
    if (page) fxFromQuery(page, qs);
  }
  if (name.startsWith('settings/')) { sub = name.slice(9); name = 'settings'; }
  if (name === 'settings' && !isAdmin() && !isUser()) name = $('#tab-account') ? 'account' : 'dashboard';   // 1.181.0: beheerders, en gebruikers alleen bekijken
  if (!$('#tab-' + name)) name = 'home';
  if (name === 'mds' && !MDS_MQ.matches) name = 'home';   // 1.194.0: mDS alleen op een telefoon
  $$('.tab').forEach((t) => { const on = t.dataset.tab === name; t.classList.toggle('active', on); if (t.getAttribute('role') === 'tab') t.setAttribute('aria-selected', on ? 'true' : 'false'); });   // 1.224.4: ook voor schermlezers
  { const m = $('#tab-more'); if (m) m.classList.toggle('active', MORE_TABS.includes(name)); }   // 1.196.0
  moveTabIndicator();
  $$('.tab-panel').forEach((p) => (p.hidden = p.id !== 'tab-' + name));
  history.replaceState(null, '', '#' + hashName(name));
  fxSyncHash();
  sideMode(name);   // 1.167.0
  renderIsoBanner(name);   // 1.172.0
  pageWaitOpen(name);   // 1.202.0: laadteller
  if (name === 'settings') { loadSettings(); showSettingsSection(sub || setSection); }
  if (name === 'account') { loadAccount(); setTimeout(() => { if (sideTab === 'account' && document.body.classList.contains('side-admin-on')) sideAcctList(); }, 600); }
  if (name === 'alerts') loadAlerts();
  if ((name === 'dashboard' || name === 'alerts') && state.badges) refresh();   // tab gezien: teller naar 0
  if (name === 'ids') loadIds();
  if (name === 'net') loadNet();
  if (netReq) setNetSub(netReq);   // 1.197.0
  if (monReq) setMonSub(monReq);
  if (name === 'home') loadHome();
  if (name === 'mds') loadMds();   // 1.194.0
  document.body.classList.toggle('mds-on', name === 'mds');   // 1.195.0: geen trekken-om-te-herladen van de browser zelf
  if (name === 'analytics') loadAnalytics();
  if (name === 'logs') loadLogsPage();   // 1.211.0
  if (name === 'incidents') loadIncidents();
  if (name === 'vulns') { showVulnTab(); if ($('#vuln-search')) loadVulns(); if ($('#leak-list')) loadLeaks(); if ($('#eol-list') && !$('#vuln-tabs')) loadEol(); }
  pageWaitClose();
  crumbs();   // 1.199.0
}

/** 1.199.0: kruimelpad boven elke pagina: 🏠 › pagina › tabblad (› onderdeel) en de filters die aan staan; elk stuk is een link */
const CRUMB_DEF = { dashboard: 'dashboard/checks', net: 'net/map', ids: 'ids/inc', vulns: 'vulns/cve' };
function crumbText(b) {
  if (!b) return '';
  const x = b.cloneNode(true);
  $$('.it-n, .tab-badge, .tab-ic, .tab-icw svg', x).forEach((n) => n.remove());
  return x.textContent.replace(EMOJI_LEAD, '').replace(/\s+\d+\s*$/, '').replace(/\s+/g, ' ').trim();
}
function crumbs() {
  const el = $('#crumbs');
  if (!el) return;
  const tab = (($('.tab-panel:not([hidden])') || {}).id || '').replace('tab-', '');
  document.body.classList.toggle('crumbs-on', !!tab && tab !== 'mds');
  if (!tab || tab === 'mds') { el.hidden = true; return; }
  const tabName = (t) => (t === 'settings' ? L('Instellingen') : t === 'account' ? L('Mijn account') : crumbText($(`#main-tabs .tab[data-tab="${t}"] .tab-lbl`) || $(`#main-tabs .tab[data-tab="${t}"]`)) || t);
  const parts = [{ l: L('Start'), go: 'home' }];
  if (tab !== 'home') parts.push({ l: tabName(tab), go: CRUMB_DEF[tab] || tab });
  if (tab === 'dashboard' && monSub !== 'checks') parts.push({ l: crumbText($(`#mon-tabs [data-msub="${monSub}"]`)), go: 'dashboard/' + monSub });   // 1.214.0: eerste subtabblad heet zelf Monitoring
  if (tab === 'net') parts.push({ l: crumbText($(`#net-tabs [data-nsub="${netSub}"]`)), go: 'net/' + netSub });
  if (tab === 'ids') parts.push({ l: crumbText($(`#ids-tabs [data-it="${idsTab}"]`)), go: 'ids/' + idsTab });
  if (tab === 'vulns' && $('#vuln-tabs')) parts.push({ l: crumbText($(`#vuln-tabs [data-vt="${vulnTab}"]`)), go: 'vulns/' + vulnTab });
  if (tab === 'settings') {
    const f = setFind(setSection);
    const g = f && SET_NAV.find((x) => x[2] === f.group);
    if (f) {
      if (g && g[3][0][0] !== f.sub[0]) parts.push({ l: f.group, go: 'settings/' + g[3][0][0] });
      else parts.push({ l: f.group, go: null });
      parts.push({ l: f.sub[1], go: 'settings/' + f.sub[0] });
      const tabs = f.sub[4];
      if (tabs) { const tb = tabs.find((x) => x[0] === setTab[setSection]) || tabs[0]; parts.push({ l: tb[1], go: null }); }
    }
  }
  // Filters die aan staan, met ✕ om ze weg te halen
  const chips = [];
  if (tab === 'dashboard' && monSub === 'checks') {
    if (dashFilter !== 'all') { const d = DASH_FILTERS.find((x) => x[0] === dashFilter); if (d) chips.push({ l: d[1](), k: 'dash' }); }
    if (statusFilter !== null) chips.push({ l: STATUS_WORD[statusFilter] || L('Onderhoud'), k: 'status' });
  }
  const page = fxCurrent();
  if (page && fxActive(page)) chips.push({ l: L('{n} filters', { n: fxCount(page) }), k: 'fx:' + page });
  // 1.216.0: paginakop: een altijd klikbaar pad, de titel en een korte samenvatting; rechts de filters en het live-lampje
  parts.forEach((p, i) => { if (!p.go) p.go = (parts.slice(i + 1).find((x) => x.go) || parts[i - 1] || {}).go || tab; });
  const title = tab === 'home' ? tabName('home') : parts[parts.length - 1].l;
  let sub = '';
  if (tab === 'dashboard' && state.hosts) {
    const c = openCounts(), open = c.crit + c.warn;
    sub = [L('{n} checks', { n: c.checks }), L('{n} servers', { n: c.hosts })].map(esc).join(' · ')
      + ' · ' + (open ? `<b class="${c.crit ? 'crit-txt' : 'warn-txt'}">${esc(L('{n} open', { n: open }))}</b>` : esc(L('alles in orde')));
  }
  const live = tab === 'dashboard' && $('#last-run') ? `<span class="ph-live${$('#live-dot.off') ? ' off' : ''}"><i aria-hidden="true"></i><span>${$('#last-run').innerHTML}</span></span>` : '';
  const wait = $('.crumb-wait', el);
  el.innerHTML = `<div class="ph-main"><nav class="ph-path" aria-label="${esc(L('Kruimelpad'))}">` + parts.map((p, i) => {
    const last = i === parts.length - 1;
    return (i ? '<span class="crumb-sep" aria-hidden="true">›</span>' : '')
      + `<button type="button" class="crumb${last ? ' cur' : ''}" data-crumb="${esc(p.go)}"${last ? ' aria-current="page"' : ''}>${esc(p.l || '…')}</button>`;
  }).join('') + `</nav><h1 class="ph-title">${esc(title || '')}</h1>${sub ? `<div class="ph-sub">${sub}</div>` : ''}</div>`
    + `<div class="ph-right">${chips.map((c) => `<button type="button" class="crumb-chip" data-crumb-clear="${esc(c.k)}" title="${esc(L('Filter weghalen'))}">${esc(c.l)} ✕</button>`).join('')}${live}</div>`;
  if (wait) el.appendChild(wait);
  document.body.classList.toggle('ph-live-dash', !!live);
  el.dataset.tab = tab;
  el.hidden = false;
}
document.addEventListener('click', (e) => {
  const b = e.target.closest && e.target.closest('#crumbs [data-crumb], #crumbs [data-crumb-clear]');
  if (!b) return;
  if (b.dataset.crumb) return showTab(b.dataset.crumb);
  const k = b.dataset.crumbClear;
  if (k === 'dash') dashFilter = 'all';
  else if (k === 'status') statusFilter = null;
  else if (k.startsWith('fx:')) { const p = k.slice(3); Object.keys(fxGet(p)).forEach((x) => delete fxSel[p][x]); fxChanged(p); }
  render();
  crumbs();
});

/** Het open venster live bijwerken (1.109.0): het venster van een check; de Agent Manager doet dat zelf al */
function liveModal() {
  const dlg = $('#modal');
  if (!dlg.open || !dlg.dataset.live) return;
  const [kind, id] = dlg.dataset.live.split(':');
  if (kind === 'check') checkInfo(id, true);
}

/** Nieuwe problemen (er ging echt een melding uit: melden na N gehaald) als klikbaar meldinkje rechtsonder */
function liveToasts(prev) {
  if (!prev) return;
  const fresh = [];
  state.hosts.forEach((h) => h.checks.forEach((c) => {
    const p = prev.get(+c.id);
    const s = +c.notified_status;
    if (p === undefined || !+c.enabled || +c.info_only || c.maintenance || c.acked_at || !(s === 1 || s === 2) || p === s || +c.status !== s) return;
    fresh.push([c, h]);
  }));
  if (fresh.length > 3) return toast(L('{n} nieuwe problemen', { n: fresh.length }), 'err', () => showTab('dashboard'));
  fresh.forEach(([c, h]) => toast(`${ST_ICON[+c.status]} ${h.name} – ${c.name}: ${STATUS[+c.status]}`, +c.status === 2 ? 'err' : 'warn', () => checkInfo(c.id)));
}

let statsAt = 0;
let refreshSeq = 0;
async function refresh(live = false, what = null) {
  const seq = ++refreshSeq;   // 1.224.4: een trager, ouder antwoord mag een nieuwer niet overschrijven
  try {
    // Openstaande tab (Dashboard of Meldingen) telt als gezien, maar alleen als je echt kijkt
    const seen = document.hidden ? '' : ['dashboard', 'alerts'].find((t) => !$('#tab-' + t).hidden) || '';
    const prev = live && state && state.hosts ? new Map(state.hosts.flatMap((h) => h.checks.map((c) => [+c.id, +c.notified_status]))) : null;
    const st = await api('state', null, seen ? { seen } : {});
    if (seq !== refreshSeq) return;
    state = st;
    syncClock(state.now_ts);
    if (state.confirm) CONFIRM = { ...state.confirm, until: Date.now() / 1000 + (+state.confirm.left || 0) };   // 1.206.0
    // Info-check: telt nooit als storing. Voor tellers, filters en "Problemen" is hij OK; de echte status staat in info_status
    state.hosts.forEach((h) => h.checks.forEach((c) => { if (+c.info_only && +c.status !== 0) { c.info_status = +c.status; c.status = 0; } }));
    if (state.role && document.body.dataset.role !== state.role) location.reload();   // rol gewijzigd
    render();
    // 1.201.0: het openingsscherm: je eigen servers lichten op in hun statuskleur, daarna verdwijnt het
    if (window.RzPulse) RzPulse.ready(state.hosts.filter((h) => +h.enabled).map((h) => ({ name: h.name, st: hostStatus(h) })));
    liveToasts(prev);
    // Live: de meldingen en de IDS-pagina alleen als daar iets veranderde (anders na hooguit 5 minuten)
    const stale = (k) => !what || what[k] || Date.now() - (liveLoaded[k] || 0) > 300000;
    if (!$('#tab-alerts').hidden && stale('alerts')) { liveLoaded.alerts = Date.now(); loadAlerts(); }
    if (!$('#tab-ids').hidden && stale('ids')) { liveLoaded.ids = Date.now(); loadIds(); }
    if ($('#tab-vulns') && !$('#tab-vulns').hidden) loadVulns();
    if (live && $('#tab-net') && !$('#tab-net').hidden) loadNet();
    // 1.134.0: Dashboard: servers en checks meteen, de rest (trends, IDS, CVE's) hooguit eens per minuut
    if (!$('#tab-home').hidden) { if (Date.now() - hmAt > 60000) loadHome(); else renderHome(); }
  if (!$('#tab-mds').hidden) { if (Date.now() - mdsAt > 60000) loadMds(); else renderMds(); }   // 1.194.0
    // 1.131.1: het Agents-overzicht (versie, verbinding, contact) loopt live mee, hooguit eens per 30 s
    if (live && $('#enc-card')?.offsetParent && Date.now() - (liveLoaded.sec || 0) > 30000) { liveLoaded.sec = Date.now(); loadSecurity(); }
    renderTabBadges();
    if ($('#net-badge')) { $('#net-badge').hidden = !state.net_new; $('#net-badge').textContent = state.net_new || ''; }
    $('#ids-badge').hidden = !state.ids_open;
    $('#ids-badge').textContent = state.ids_open || '';
    if (isAdmin()) renderAgentUpdates();
    const ub = $('#update-badge');
    ub.hidden = !state.update && !state.update_running;
    ub.textContent = state.update_running ? '⏳ ' + L('Bijwerken…') : state.update ? '⬆ ' + state.update : '';
    // Loopt er een update (ook na herladen of in een ander tabblad): het voortgangsvenster weer openen
    if (state.update_running && !$('#modal').open) { showUpdateDialog({ state: 'queued', step: 'queued', message: '' }, ''); pollUpdate(); }
  } catch (ex) {
    if (window.RzPulse) RzPulse.hide();
    toast(ex.message, 'err');
  }
  // Beschikbaarheid en minigrafieken apart: mag iets langer duren; live hooguit eens per 30 s
  if (live && Date.now() - statsAt < 30000) return;
  try { stats = await api('stats'); statsAt = Date.now(); render(); } catch (ex) { /* niet kritiek */ }
}

/** Tellers op de tabs Dashboard (nieuwe problemen, kleur van het ergste) en Meldingen (nieuwe meldingen). */
function renderTabBadges() {
  const b = state.badges || { dashboard: 0, alerts: 0 };
  const set = (el, n, title) => { el.hidden = !n; el.textContent = n > 99 ? '99+' : n || ''; el.title = title; };
  set($('#dash-badge'), b.dashboard, L('{n} nieuwe problemen sinds je laatste bezoek', { n: b.dashboard }));
  $('#dash-badge').className = 'tab-badge s' + (b.dashboard_status ?? 2);
  set($('#alerts-badge'), b.alerts, L('{n} nieuwe meldingen sinds je laatste bezoek', { n: b.alerts }));
}

const pct = (v) => (v === null || v === undefined ? '–' : dec(v >= 99.995 ? '100' : v.toFixed(v >= 99 ? 2 : 1)) + '%');

/** Beschikbaarheid van een server (24 u / 7 d / 30 d), of '' zonder netwerkchecks. */
function uptimeLine(h, short) {
  const u = stats.uptime[h.id];
  if (!u) return '';
  if (short) return ` · <span title="${L('Bereikbaar afgelopen 7 dagen')}">⏱ ${pct(u.d7)}</span>`;
  return `<span class="uptime" title="${L('Percentage van de metingen (ping, of anders poort/HTTP) dat de server bereikbaar was')}">
    ${L('Bereikbaar')}: ${L('24 u')} <b>${pct(u.h24)}</b> · ${L('7 d')} <b>${pct(u.d7)}</b> · ${L('30 d')} <b>${pct(u.d30)}</b></span>`;
}

/** Minigrafiek van de laatste 24 uur: lijn voor de waarde, gekleurde streepjes voor de status. */
function sparkSvg(c) {
  const data = stats.sparks[c.id];
  if (!data) return '';
  const W = 240, H = 26;
  const t = typeOf(c);
  const vals = data.filter((d) => d && d[0] !== null).map((d) => d[0]);
  const max = t.unit === '%' ? 100 : Math.max(...vals, +c.warn_threshold || 0, 1) * 1.1;
  const x = (i) => (i / 47) * W;
  const y = (v) => H - 6 - (v / max) * (H - 8);
  // Lege blokjes overslaan: de lijn loopt door naar het volgende meetpunt
  let line = '';
  data.forEach((d, i) => {
    if (d && d[0] !== null) line += `${line ? 'L' : 'M'}${x(i).toFixed(1)},${y(d[0]).toFixed(1)}`;
  });
  const strip = data.map((d, i) => (d ? `<rect class="s${d[1]}" x="${x(i) - W / 96}" y="${H - 3}" width="${W / 48 + .3}" height="3"/>` : '')).join('');
  return `<svg class="spark" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" data-act="history" data-id="${c.id}" role="img">
    <title>${L('Laatste 24 uur – klik voor de grafiek')}</title>${t.unit && vals.length ? `<path d="${line}"/>` : ''}<g>${strip}</g></svg>`;
}

// 4 = alles in onderhoud
function hostStatus(h) {
  const enabled = h.checks.filter((c) => +c.enabled);
  const active = enabled.filter((c) => !c.maintenance);
  if (!active.length) return enabled.length || h.maintenance ? 4 : 3;
  const sts = active.map((c) => +c.status);
  if (sts.includes(2)) return 2;
  if (sts.includes(1)) return 1;
  if (sts.includes(3)) return 3;
  return 0;
}

const STATUS_RANK = { 2: 4, 1: 3, 3: 2, 0: 1, 4: 0 };

/** "14:30" voor vandaag, anders "di 21 okt 14:30" */
function fmtUntil(dt) {
  const d = new Date(dt.replace(' ', 'T'));
  const time = d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' });
  return d.toDateString() === new Date().toDateString()
    ? time : d.toLocaleDateString(LOCALE, { weekday: 'short', day: 'numeric', month: 'short' }) + ' ' + time;
}

function maintLabel(m) {
  // Automatisch onderhoud loopt "tot opgelost" (de datum is een placeholder ver in de toekomst)
  if (m.auto) return `🔧 ${L('automatisch, tot opgelost')}${m.reason ? ' – ' + m.reason : ''}`;
  return `🔧 ${L('tot {t}', { t: fmtUntil(m.until) })}${m.reason ? ' – ' + m.reason : ''}`;
}

// ---------------------------------------------------------------- Algehele gezondheid
// De toestand en score komen van de server (Health.php); hier alleen weergave en filters.
let statusFilter = null;   // null, of 0-3 (status) / 4 (onderhoud): alleen die checks tonen
const HEALTH_COUNTS = [[0, 'ok', 'OK'], [1, 'warning', 'W'], [2, 'critical', 'C'], [3, 'unknown', '?'], [4, 'maintenance', '🔧']];
const HEALTH_TITLES = { 0: 'OK', 1: 'warning', 2: 'critical', 3: L('onbekend'), 4: L('in onderhoud') };

/** "12 min", "2 u 14 min", "3 d 4 u" */
function dur(dt) {
  const m = Math.max(0, Math.round((Date.now() - new Date(dt.replace(' ', 'T')).getTime()) / 60000));
  if (m < 60) return `${m} min`;
  if (m < 1440) return `${Math.floor(m / 60)} ${L('u')}${m % 60 ? ` ${m % 60} min` : ''}`;
  return `${Math.floor(m / 1440)} ${L('d')}${Math.floor(m % 1440 / 60) ? ` ${Math.floor(m % 1440 / 60)} ${L('u')}` : ''}`;
}

// Filterchips en sortering van het dashboard (per apparaat onthouden)
// Keuze bovenaan (Alle, Problemen, of een soort apparaat): per gebruiker (1.75.0), daarvoor per browser
let dashFilter = 'all';   // 1.171.4: niet meer onthouden; Monitoring opent altijd op Alle, zodat je geen problemen mist
let dashSort = uiGet('dash_sort', 'rzmon-dash-sort', 'status');
let attnOpen = false;
const attnBundles = new Set();   // 1.157.0: opengeklapte bundels in "Nu aandacht nodig"   // "Nu aandacht nodig": alles tonen (anders 6, op de telefoon 3)
const isHa = (h) => h.checks.some((c) => c.type === 'ha') || h.metrics.includes('service:homeassistant');
// Soort apparaat (1.75.0; zie HostKind.php): zelf gekozen, anders herkend (agent, SNMP), anders op het type
const KIND_INFO = {
  server: ['🖥️', () => L('Server')], router: ['🌐', () => L('Router')], switch: ['🔀', () => L('Switch')], ap: ['📶', () => L('Access point')],
  firewall: ['🧱', () => L('Firewall')], nas: ['🗄️', () => 'NAS'], ups: ['🔋', () => 'UPS'], camera: ['📷', () => L('Camera')], smarthome: ['🏠', () => L('Smart home')],
  printer: ['🖨️', () => L('Printer')], other: ['📦', () => L('Overig')], internet: ['🌍', () => L('Internet')],
};
const KIND_GROUP = { server: 'server', router: 'network', switch: 'network', ap: 'network', firewall: 'network', nas: 'storage', ups: 'power',
  camera: 'camera', smarthome: 'smarthome', printer: 'other', other: 'other', internet: 'network' };
const GROUP_INFO = {
  server: ['🖥️', () => L('Servers')], network: ['🌐', () => L('Netwerk')], storage: ['🗄️', () => L('Opslag')], power: ['🔋', () => L('Stroom')],
  camera: ['📷', () => L('Camera\'s')], smarthome: ['🏠', () => L('Smart home')], other: ['📦', () => L('Overig')],
};
// Zelfde regel als HostKind::CAMERA_NAME: een naam als "Cam voordeur" of "IPCam2" is vanzelf een camera
const CAMERA_NAME = /\b(ip)?cam(era)?s?[-_ ]?\d*\b|cctv|deurbel|doorbell/i;
const hostKind = (h) => [h.kind, h.kind_auto].find((k) => k && KIND_INFO[k])
  || (isHa(h) ? 'smarthome' : ['agent', 'local'].includes(h.mode) ? 'server' : CAMERA_NAME.test(h.name || '') ? 'camera' : 'other');
const hostGroup = (h) => KIND_GROUP[hostKind(h)];
const kindLabel = (k) => `${KIND_INFO[k][0]} ${KIND_INFO[k][1]()}`;
/** Soort apparaat van een server op naam (meldingen en IDS kennen alleen de naam) */
const kindByName = (name) => { const h = state && state.hosts.find((x) => x.name === name); return h ? hostKind(h) : null; };
/** Icoon voor de soort apparaat */
const hostIcon = (h) => KIND_INFO[hostKind(h)][0];
const DASH_FILTERS = [
  ['all', () => L('Alle'), () => true],
  ['problems', () => L('Problemen'), (h) => +h.enabled && h.checks.some(isProblem)],
  ['maint', () => L('Onderhoud'), (h) => !!h.maintenance || h.checks.some((c) => c.maintenance)],
  ...Object.keys(GROUP_INFO).map((g) => ['g:' + g, () => `${GROUP_INFO[g][0]} ${GROUP_INFO[g][1]()}`, (h) => hostGroup(h) === g]),
];
if (!DASH_FILTERS.some((f) => f[0] === dashFilter)) dashFilter = 'all';   // bv. 'agent' van vóór 1.75.0
/** Gekozen soort op het dashboard (of null bij Alle, Problemen, Onderhoud) */
const dashGroup = () => (dashFilter.startsWith('g:') ? dashFilter.slice(2) : null);

/** 1.199.0: mini-overzicht in de tellerbalk: per soort de hoogste waarde met de server erbij (niet het gemiddelde: dat verbergt
 * de ene server op 95%). Hover = de top 3, klik = de servers op die waarde sorteren (nog eens = terug naar Ergste eerst). */
const MINI_RES = [['cpu', () => 'CPU'], ['ram', () => 'RAM'], ['disk', () => L('Disk')]];
function hostMax(h, type) {
  const v = h.checks.filter((c) => c.type === type && +c.enabled && !c.maintenance && c.last_value !== null && c.last_value !== undefined).map((c) => +c.last_value);
  return v.length ? Math.max(...v) : null;
}
