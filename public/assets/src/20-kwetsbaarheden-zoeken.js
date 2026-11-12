// ---------------------------------------------------------------- 1.248.0: Kwetsbaarheden doorzoeken
// - één zoekvak voor servers, CVE's en software (#vs-q, met een lijstje in drie groepen);
// - "Waar staat hij?" in het venster van een CVE: kwetsbaar, niet kwetsbaar, niet van toepassing, onbekend, geaccepteerd;
// - het tabblad 📦 Software: per programma de versies per besturingssysteem, de open CVE's en vanaf welke versie het veilig is;
// - CSV-bestanden voor Excel (vuln_csv).

let vsTimer = null;
let vsSeq = 0;
let vswData = null;
let vswAll = false;
const vswFilter = new Set();
const VSW_FILTERS = () => [['exposed', '🌐 ' + L('Bereikbaar')], ['kev', '⚠️ ' + L('Actief misbruikt')], ['spread', '↕ ' + L('Versies lopen uiteen')]];
const vsServers = (n) => (+n === 1 ? L('1 server') : L('{n} servers', { n }));
const CVE_RE = /^(CVE|GHSA|DSA|DLA|USN|RHSA|ALSA|RLSA)-[\w-]+$/i;

/** Een CVE als knopje (zelfde stijl als in de serverlijst) */
function vsChip(c) {
  const [icon] = VULN_SEV[c.severity] || VULN_SEV[0];
  return `<button type="button" class="cve-chip sev${c.severity}${c.kev ? ' kev' : ''}" data-vcve="${esc(c.cve)}">${icon} ${esc(c.cve)}${c.kev ? ' ⚠️' : ''}</button>`;
}
/** Tellers per ernst als kleine pillen */
function vsSevPills(sev) {
  return [4, 3, 2, 1, 0].filter((i) => sev[i]).map((i) => `<span class="vsw-sev sev${i}" title="${esc(VULN_SEV[i][1])}">${VULN_SEV[i][0]} ${sev[i]}</span>`).join('');
}

function initVulnSearch() {
  const q = $('#vs-q');
  if (!q || q.dataset.init) return;
  q.dataset.init = '1';
  const sug = $('#vs-sug');
  const close = () => { sug.hidden = true; q.setAttribute('aria-expanded', 'false'); };
  q.addEventListener('input', () => {
    clearTimeout(vsTimer);
    const v = q.value.trim();
    if (v.length < 2) { close(); return; }
    vsTimer = setTimeout(() => vsSuggest(v), 200);
  });
  q.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { close(); return; }
    if (e.key === 'ArrowDown' && !sug.hidden) { e.preventDefault(); const f = $('[data-vs]', sug); if (f) f.focus(); return; }
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const v = q.value.trim();
    if (CVE_RE.test(v)) { close(); vulnDetail(v.toUpperCase()); return; }
    const first = $('[data-vs]', sug);
    if (first) first.click();
  });
  sug.addEventListener('keydown', (e) => {
    const items = $$('[data-vs]', sug);
    const i = items.indexOf(document.activeElement);
    if (e.key === 'ArrowDown' && i < items.length - 1) { e.preventDefault(); items[i + 1].focus(); }
    if (e.key === 'ArrowUp') { e.preventDefault(); (i > 0 ? items[i - 1] : q).focus(); }
    if (e.key === 'Escape') { close(); q.focus(); }
  });
  sug.addEventListener('click', (e) => {
    const b = e.target.closest('[data-vs]');
    if (!b) return;
    close();
    const [kind, val] = [b.dataset.vs, b.dataset.val];
    if (kind === 'cve') vulnDetail(val);
    else if (kind === 'server') vsGoServer(+val, b.dataset.name);
    else if (kind === 'software') vsGoSoftware(val);
  });
  document.addEventListener('click', (e) => { if (!e.target.closest('#vs-bar')) close(); });
}

async function vsSuggest(v) {
  const seq = ++vsSeq;
  let r;
  try { r = await api('vuln_search', null, { q: v }); } catch (ex) { return; }
  if (seq !== vsSeq) return;   // er is intussen verder getypt
  const sug = $('#vs-sug');
  const grp = (title, rows) => (rows.length ? `<div class="vs-grp"><div class="vs-gh">${title}</div>${rows.join('')}</div>` : '');
  const cveTyped = CVE_RE.test(v) && !r.cves.some((c) => c.cve.toUpperCase() === v.toUpperCase());
  const html = [
    grp('🖥️ ' + L('Servers'), r.servers.map((s) => `<button type="button" class="vs-it" data-vs="server" data-val="${s.id}" data-name="${esc(s.name)}" role="option">
      <b>${esc(s.name)}</b><span class="muted small">${esc(s.os)}</span><span class="vs-n">${s.open ? L('{n} open', { n: s.open }) : '✔'}</span></button>`)),
    grp('🐞 ' + L('CVE\'s'), [...(cveTyped ? [`<button type="button" class="vs-it" data-vs="cve" data-val="${esc(v.toUpperCase())}" role="option"><b>${esc(v.toUpperCase())}</b><span class="muted small">${L('opzoeken')}</span></button>`] : []),
      ...r.cves.map((c) => `<button type="button" class="vs-it" data-vs="cve" data-val="${esc(c.cve)}" role="option">
      <b>${VULN_SEV[c.severity] ? VULN_SEV[c.severity][0] : ''} ${esc(c.cve)}${c.kev ? ' ⚠️' : ''}</b><span class="vs-n">${c.hosts ? (c.hosts === 1 ? L('op 1 server') : L('op {n} servers', { n: c.hosts })) : L('nergens open')}</span></button>`)]),
    grp('📦 ' + L('Software'), r.software.map((s) => `<button type="button" class="vs-it" data-vs="software" data-val="${esc(s.name)}" role="option">
      <b class="mono">${esc(s.name)}</b><span class="muted small">${vsServers(s.hosts)}</span><span class="vs-n">${s.open ? `<span class="warn-txt">${L('{n} open CVE\'s', { n: s.open })}</span>` : '✔'}</span></button>`)),
  ].join('');
  sug.innerHTML = html || `<p class="muted small vs-none">${L('Niets gevonden voor "{q}"', { q: esc(v) })}</p>`;
  sug.hidden = false;
  $('#vs-q').setAttribute('aria-expanded', 'true');
}

/** Naar een server: tabblad Kwetsbaarheden, gefilterd op de naam, de kaart open */
function vsGoServer(id, name) {
  vulnTab = 'cve';
  showVulnTab();
  const s = $('#vuln-search');
  if (s) { s.value = name; renderVulns(); }
  setTimeout(() => {
    const card = $(`#vuln-hosts details.vuln-host[data-host="${id}"]`);
    if (card) { card.open = true; card.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  }, 80);
}

/** Naar een programma: tabblad Software, gezocht op de naam en opengeklapt */
async function vsGoSoftware(name) {
  vulnTab = 'sw';
  $('#vsw-q').value = name;
  showVulnTab();
  await loadVulnSoftware();
  // staat het programma niet bij "met open CVE's", dan alle software tonen
  if (vswData && !vswData.programs.some((p) => p.name === name) && !vswAll) { vswAll = true; await loadVulnSoftware(); }
  const d = $(`#vsw-list details.vsw-p[data-prog="${CSS.escape(name)}"]`);
  if (d) { d.open = true; d.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
}

// ---------------------------------------------------------------- Waar staat een CVE? (in het venster van de CVE)

async function vulnWhere(cve, root) {
  const box = $('#vd-where', root);
  if (!box) return;
  let w;
  try { w = await api('vuln_where', null, { cve }); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  if (!box.isConnected) return;
  const g = w.groups;
  const host = (x) => `<b>${esc(x.host)}</b> <span class="muted small">${esc(x.os)}</span>`;
  const sec = (key, icon, title, hint, rows, open) => `<details class="vw-grp vw-${key}" ${open ? 'open' : ''} ${rows.length ? '' : 'data-empty'}>
    <summary><span class="vw-ic">${icon}</span> <b>${title}</b> <span class="vw-n">${rows.length}</span> <span class="muted small">${hint}</span></summary>
    ${rows.length ? `<div class="vw-rows">${rows.join('')}</div>` : ''}</details>`;
  const vuln = g.vuln.map((x) => `<div class="vw-row">${host(x)} · <span class="mono small">${esc(x.package)} ${esc(x.version)}</span>
      → ${x.fixed ? `<span class="mono small good-txt">${esc(x.fixed)}</span>` : `<span class="muted">${L('nog geen oplossing')}</span>`}
      ${x.exposed.length ? ` <span class="vw-exp" title="${esc(L('Luistert op het netwerk'))}">🌐 ${esc(x.exposed.join(', '))}</span>` : ''}
      ${x.fixed ? `<button type="button" class="btn small-btn need-admin" data-vw-up="${x.host_id}" data-pkg="${esc(x.package)}">⬆ ${L('Bijwerken')}</button>` : ''}</div>`);
  const acc = g.accepted.map((x) => `<div class="vw-row">${host(x)} · <span class="mono small">${esc(x.package)} ${esc(x.version)}</span></div>`);
  const safe = g.safe.map((x) => `<div class="vw-row">${host(x)} · <span class="mono small">${esc(x.package)} ${esc(x.version)}</span>
      ${x.resolved ? ` · <span class="good-txt small">${L('opgelost op {d}', { d: esc(fmtDateTime(x.resolved).slice(0, 10)) })}</span>` : ''}</div>`);
  const na = g.na.map((x) => `<div class="vw-row">${host(x)}${x.resolved ? ` · <span class="good-txt small">${L('opgelost op {d}', { d: esc(fmtDateTime(x.resolved).slice(0, 10)) })}</span>` : ''}</div>`);
  const unk = g.unknown.map((x) => `<div class="vw-row">${host(x)} · <span class="warn-txt small">${esc(x.why)}</span></div>`);
  box.innerHTML = `<h3>${L('Waar staat hij?')}
      <a class="btn small-btn" href="api.php?${new URLSearchParams({ action: 'vuln_csv', kind: 'cve', cve })}" title="${esc(L('Deze lijst als CSV-bestand (opent in Excel)'))}">⤓ CSV</a></h3>
    ${xpHtml(L('Al je servers, verdeeld in: kwetsbaar, niet kwetsbaar, niet van toepassing, onbekend en geaccepteerd.'), L('<b>Kwetsbaar:</b> het pakket staat erop in een versie met deze CVE; met ⬆ werk je hem bij. <b>Niet kwetsbaar:</b> het pakket staat erop, maar in een versie zonder deze CVE (al bijgewerkt, of de distributie zegt dat haar versie niet geraakt wordt). <b>Niet van toepassing:</b> het pakket staat er niet op. <b>Onbekend:</b> RzSense kan niets zeggen: geen softwarelijst, nog niet gescand of de scan mislukte. Tel die dus niet als veilig. <b>Geaccepteerd:</b> iemand heeft het risico bewust geaccepteerd.'))}
    <div class="vw-sum">${[['vuln', '🔴', g.vuln.length], ['safe', '✅', g.safe.length], ['na', '⚪', g.na.length], ['unknown', '❔', g.unknown.length], ['accepted', '🟦', g.accepted.length]]
      .map(([k, i, n]) => `<span class="vw-pill vw-${k}">${i} ${n}</span>`).join('')}</div>
    ${sec('vuln', '🔴', L('Kwetsbaar'), L('bijwerken'), vuln, true)}
    ${sec('safe', '✅', L('Niet kwetsbaar'), L('pakket staat erop, in een veilige versie'), safe, false)}
    ${sec('na', '⚪', L('Niet van toepassing'), L('pakket staat er niet op'), na, false)}
    ${sec('unknown', '❔', L('Onbekend'), L('RzSense weet het niet'), unk, g.unknown.length > 0 && !g.vuln.length)}
    ${sec('accepted', '🟦', L('Geaccepteerd'), L('risico bewust geaccepteerd'), acc, false)}`;
  box.onclick = async (e) => {
    const b = e.target.closest('[data-vw-up]');
    if (!b) return;
    const data = vulnData || await api('vulns').catch(() => null);
    const h = (data && data.hosts || []).find((x) => x.id === +b.dataset.vwUp) || { id: +b.dataset.vwUp, name: b.closest('.vw-row').querySelector('b').textContent };
    vulnUpgrade(h, [b.dataset.pkg]);
  };
}

// ---------------------------------------------------------------- 📦 Software

function initVulnSoftware() {
  if (!$('#vsw-list') || $('#vsw-list').dataset.init) return;
  $('#vsw-list').dataset.init = '1';
  $('#vsw-q').addEventListener('input', () => renderVulnSoftware());
  $('#vsw-scope').addEventListener('click', (e) => {
    const b = e.target.closest('[data-vsw-all]');
    if (!b) return;
    vswAll = b.dataset.vswAll === '1';
    loadVulnSoftware();
  });
  $('#vsw-filter').addEventListener('click', (e) => {
    const b = e.target.closest('[data-vswf]');
    if (!b) return;
    vswFilter.has(b.dataset.vswf) ? vswFilter.delete(b.dataset.vswf) : vswFilter.add(b.dataset.vswf);
    renderVulnSoftware();
  });
  $('#vsw-list').addEventListener('click', async (e) => {
    const c = e.target.closest('[data-vcve]');
    if (c) { vulnDetail(c.dataset.vcve); return; }
    const up = e.target.closest('[data-vsw-up]');
    if (up) {
      const data = vulnData || await api('vulns').catch(() => null);
      const h = (data && data.hosts || []).find((x) => x.id === +up.dataset.vswUp) || { id: +up.dataset.vswUp, name: up.dataset.name };
      vulnUpgrade(h, [up.dataset.pkg]);
    }
  });
}

async function loadVulnSoftware() {
  initVulnSoftware();
  try {
    vswData = await api('vuln_software', null, vswAll ? { all: 1 } : {});
  } catch (ex) { $('#vsw-list').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  renderVulnSoftware();
}

function renderVulnSoftware() {
  if (!vswData) return;
  const q = $('#vsw-q').value.trim().toLowerCase();
  $('#vsw-scope').innerHTML = [['0', L('Met open CVE\'s')], ['1', L('Alle software')]]
    .map(([v, l]) => `<button type="button" class="dchip${(v === '1') === vswAll ? ' on' : ''}" data-vsw-all="${v}">${l}</button>`).join('');
  $('#vsw-filter').innerHTML = VSW_FILTERS().map(([k, l]) => `<button type="button" class="dchip${vswFilter.has(k) ? ' on' : ''}" data-vswf="${k}" aria-pressed="${vswFilter.has(k)}">${l}</button>`).join('');
  $('#vsw-csv').href = 'api.php?' + new URLSearchParams({ action: 'vuln_csv', kind: 'software', ...(vswAll ? { all: 1 } : {}) });
  const list = vswData.programs.filter((p) => (!q || p.name.toLowerCase().includes(q) || p.os.some((o) => o.versions.some((v) => v.hosts.some((h) => h.host.toLowerCase().includes(q)))))
    && (!vswFilter.has('exposed') || p.exposed) && (!vswFilter.has('kev') || p.kev) && (!vswFilter.has('spread') || p.spread));
  const withOpen = vswData.programs.filter((p) => p.open).length;
  if ($('#vt-n-sw')) $('#vt-n-sw').textContent = withOpen ? String(withOpen) : '';
  $('#vsw-info').innerHTML = [
    vswAll ? L('{n} programma\'s op {h} servers, waarvan {o} met open CVE\'s', { n: vswData.programs.length, h: vswData.hosts, o: withOpen })
      : L('{n} programma\'s met open CVE\'s', { n: withOpen }),
    vswData.unknown.length ? `<span class="warn-txt">❔ ${L('onbekend: {s}', { s: esc(vswData.unknown.map((u) => `${u.host} (${u.why})`).join(', ')) })}</span>` : '',
  ].filter(Boolean).join(' · ');
  if (!list.length) {
    $('#vsw-list').innerHTML = `<p class="muted">${q || vswFilter.size ? L('Niets gevonden met deze zoekopdracht of filters.') : vswAll ? L('Nog geen softwarelijsten.') : '✔ ' + L('Geen programma\'s met open CVE\'s.')}</p>`;
    return;
  }
  const open = new Set($$('#vsw-list details.vsw-p[open]').map((d) => d.dataset.prog));
  const shown = list.slice(0, 400);
  $('#vsw-list').innerHTML = shown.map((p) => {
    const vers = p.os.flatMap((o) => o.versions.map((v) => `${v.version} (${v.hosts.length}×)`));
    const safeTxt = p.open ? p.os.filter((o) => o.safe || o.nofix).map((o) => (o.nofix && !o.safe ? L('nog geen oplossing') : L('veilig vanaf {v}', { v: o.safe }))).join(' · ') : '✔ ' + L('geen open CVE\'s');
    return `<details class="vsw-p${p.open ? ' has-open' : ''}" data-prog="${esc(p.name)}" ${open.has(p.name) || (q && q === p.name.toLowerCase()) ? 'open' : ''}>
      <summary>
        <span class="vsw-name mono">${esc(p.name)}</span>
        <span class="vsw-meta muted small">${vsServers(p.hosts)} · ${esc(vers.slice(0, 3).join(' · '))}${vers.length > 3 ? ' …' : ''}</span>
        <span class="vsw-tags">${vsSevPills(p.sev)}${p.kev ? '<span class="vsw-tag bad">⚠️</span>' : ''}${p.exposed ? '<span class="vsw-tag" title="' + esc(L('Luistert op het netwerk')) + '">🌐</span>' : ''}${p.spread ? '<span class="vsw-tag" title="' + esc(L('Versies lopen uiteen')) + '">↕</span>' : ''}${p.accepted ? `<span class="vsw-tag" title="${esc(L('Geaccepteerd'))}">🟦 ${p.accepted}</span>` : ''}</span>
        <span class="vsw-safe small ${p.open ? 'warn-txt' : 'good-txt'}">${esc(safeTxt)}</span>
      </summary>
      <div class="vsw-body">${p.os.map((o) => `<div class="vsw-os">
        <div class="vsw-os-h"><b>${esc(o.os)}</b>${o.safe ? ` <span class="small">→ ${L('veilig vanaf')} <span class="mono good-txt">${esc(o.safe)}</span></span>` : ''}${o.nofix ? ` <span class="muted small">· ${L('voor een deel nog geen oplossing')}</span>` : ''}</div>
        <table class="vsw-t"><thead><tr><th>${L('Versie')}</th><th>${L('Servers')}</th><th>${L('Open CVE\'s')}</th></tr></thead><tbody>
        ${o.versions.map((v) => `<tr class="${v.cves.length ? 'vsw-bad' : 'vsw-ok'}">
          <td class="mono">${esc(v.version)}${v.cves.length ? '' : ' <span class="good-txt">✔</span>'}</td>
          <td>${v.hosts.map((h) => `<span class="vsw-host">${esc(h.host)}${h.exposed.length ? ` <span title="${esc(L('Luistert op het netwerk'))}: ${esc(h.exposed.join(', '))}">🌐</span>` : ''}${v.cves.some((c) => c.fixed) ? ` <button type="button" class="btn small-btn need-admin" data-vsw-up="${h.host_id}" data-name="${esc(h.host)}" data-pkg="${esc(p.name)}" title="${esc(L('Bijwerken via de agent'))}">⬆</button>` : ''}</span>`).join('')}</td>
          <td>${v.cves.length ? v.cves.sort((a, b) => b.severity - a.severity).map(vsChip).join(' ') : '<span class="muted">–</span>'}</td>
        </tr>`).join('')}</tbody></table></div>`).join('')}</div>
    </details>`;
  }).join('') + (list.length > shown.length ? `<p class="muted small">${L('Nog {n} programma\'s: zoek om ze te vinden.', { n: list.length - shown.length })}</p>` : '');
}
