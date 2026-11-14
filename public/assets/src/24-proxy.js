// ---------------------------------------------------------------- 1.254.0 (agent 1.97.0): reverse proxy beschermen
// Pagina Reverse proxy: per server met de rol de stand van de agent en per site het beleid (aan/uit, adminpagina's
// alleen vanaf je netwerk, rate limit, bots). Beheerportal › Beveiliging › Reverse proxy: welke server de proxy is.

const PX_LAN = () => [['off', L('Iedereen')], ['paths', L('Adminpaden alleen mijn netwerk')], ['site', L('Hele site alleen mijn netwerk')]];
const PX_RATE = () => [['off', L('Uit')], ['normal', L('Normaal')], ['strict', L('Streng')]];
const PX_STATE = () => ({ ok: ['ok', '✔ ' + L('doorgevoerd')], pending: ['wait', '⏳ ' + L('wacht op de agent')], error: ['bad', '✘ ' + L('mislukt')],
  off: ['', L('uit')], unsupported: ['wait', L('niet ondersteund')] });
const PX_KIND = { swag: 'SWAG', npm: 'Nginx Proxy Manager', nginx: 'nginx', traefik: 'Traefik', caddy: 'Caddy', none: '–' };
/** De agent stuurt vaste Nederlandse teksten mee; die vertalen we hier */
const pxMsg = (m) => {
  if (!m) return '';
  const r = [[/^doorgevoerd \((\d+) sites\)$/, (x) => L('doorgevoerd ({n} sites)', { n: x[1] })], [/^doorgevoerd$/, () => L('doorgevoerd')],
    [/^bescherming weggehaald$/, () => L('bescherming weggehaald')],
    [/^geen reverse proxy gevonden/, () => L('geen reverse proxy gevonden (SWAG of Nginx Proxy Manager in Podman of Docker)')],
    [/^(\w+) wordt herkend maar nog niet ondersteund$/, (x) => L('{p} wordt herkend maar nog niet ondersteund', { p: x[1] })],
    [/^teruggezet: deze sites antwoordden daarna niet meer: (.*)$/, (x) => L('teruggezet: deze sites antwoordden daarna niet meer: {s}', { s: x[1] })],
    [/^de configmap van (.+) is niet gevonden op deze server$/, (x) => L('de configmap van {c} is niet gevonden op deze server', { c: x[1] })]];
  for (const [re, f] of r) { const x = m.match(re); if (x) return f(x); }
  return m;
};

async function loadProxy() {
  const box = $('#proxy-page');
  if (!box) return;
  let d;
  try { d = await api('proxy_overview'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const st = PX_STATE();
  const proxies = d.servers.filter((s) => s.role);
  const badge = $('#proxy-badge');
  if (badge) { const n = proxies.filter((s) => s.report && s.report.state === 'error').length; badge.textContent = n || ''; badge.hidden = !n; }
  const head = !d.on ? `<div class="callout">⏸ ${L('De bescherming staat uit voor alle servers.')} <a href="#settings/proxy" data-goto="settings/proxy">${L('Aanzetten in de beheerportal')} →</a></div>`
    : `<p class="small muted">${L('Mijn netwerk: {n}', { n: d.lan.map((x) => `<code>${esc(x)}</code>`).join(' ') })}</p>`;
  if (!proxies.length) {
    box.innerHTML = head + `<div class="callout">${L('Nog geen server met de rol reverse proxy.')} <a href="#settings/proxy" data-goto="settings/proxy">${L('Kies de server in de beheerportal')} →</a></div>`;
    return;
  }
  box.innerHTML = head + proxies.map((s) => {
    const r = s.report || {};
    const [cls, txt] = st[r.state || 'pending'] || st.pending;
    const sync = r.state === 'ok' && !s.in_sync ? ` <span class="lf-tag wait">⏳ ${L('nieuwe wijziging wacht op de agent')}</span>` : '';
    const rows = (s.sites || []).map((x) => `<tr data-px-site="${esc(x.site)}" class="${x.enabled ? '' : 'dim'}">
        <td>${amSw('px-on-' + s.id + '-' + x.site.replace(/[^a-z0-9]/gi, '_'), x.enabled, 'data-px="enabled"')}</td>
        <td><b>${esc(x.site)}</b>${x.names.length > 1 ? `<div class="muted small">${esc(x.names.slice(1).join(', '))}</div>` : ''}${x.up ? `<div class="muted small mono">→ ${esc(x.up)}</div>` : ''}</td>
        <td><select data-px="lan_mode" aria-label="${esc(L('Wie mag erbij'))}">${PX_LAN().map(([k, l]) => `<option value="${k}" ${k === x.lan_mode ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>
          <input type="text" class="mono px-paths" data-px="lan_paths" value="${esc(x.lan_paths.replace(/,/g, ', '))}" placeholder="/admin" ${x.lan_mode === 'paths' ? '' : 'hidden'} aria-label="${esc(L('Adminpaden'))}"></td>
        <td><select data-px="rate" aria-label="${esc(L('Rate limit'))}">${PX_RATE().map(([k, l]) => `<option value="${k}" ${k === x.rate ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></td>
        <td>${amSw('px-bots-' + s.id + '-' + x.site.replace(/[^a-z0-9]/gi, '_'), x.bots, 'data-px="bots"')}</td>
        <td class="nowrap">${x.custom ? `<span class="lf-tag">${L('eigen')}</span> <button type="button" class="btn small-btn" data-px-reset title="${esc(L('Terug naar de standaard'))}">↺</button>` : `<span class="lf-tag ok">${L('standaard')}</span>`}</td></tr>`).join('');
    return `<section class="px-srv" data-px-host="${s.id}">
      <div class="px-h"><b>${esc(s.name)}</b> <span class="muted small">${esc(PX_KIND[r.kind] || r.kind || '–')}${r.ctr ? ' · ' + esc(r.ctr) : ''}${r.engine ? ' (' + esc(r.engine) + ')' : ''}</span>
        <span class="lf-tag ${cls}">${txt}</span>${sync}<span class="lp-sp"></span></div>
      ${!s.agent_ok ? `<div class="callout small">⬆ ${L('Agent 1.97.0 of nieuwer nodig. Werk de agent bij in de Agent Manager.')}</div>` : ''}
      ${r.msg ? `<p class="small ${r.state === 'error' ? 'danger-txt' : 'muted'}">${esc(pxMsg(r.msg))}</p>` : ''}
      ${(s.sites || []).length ? `<div class="table-scroll"><table class="vuln-table px-table"><thead><tr><th>${L('Aan')}</th><th>${L('Site')}</th><th>${L('Wie mag erbij')}</th><th>${L('Rate limit')}</th><th>${L('Bots weren')}</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`
        : `<p class="muted small">${s.report ? L('Geen sites gevonden in de proxy.') : L('Nog geen rapport van de agent. Dat komt bij zijn volgende ronde (meestal binnen een minuut).')}</p>`}
    </section>`;
  }).join('');
  // Opslaan bij elke wijziging (de agent voert het bij zijn volgende ronde door)
  const save = async (tr, extra = {}) => {
    const host = +tr.closest('[data-px-host]').dataset.pxHost;
    const v = (k) => $(`[data-px="${k}"]`, tr);
    const body = { host_id: host, site: tr.dataset.pxSite, enabled: v('enabled').checked, lan_mode: v('lan_mode').value, lan_paths: v('lan_paths').value, rate: v('rate').value, bots: v('bots').checked, ...extra };
    try { await api('proxy_site_save', body); toast(extra.reset ? L('Terug naar de standaard') : L('Opgeslagen; de agent voert het binnen een minuut door')); loadProxy(); }
    catch (ex) { toast(ex.message, 'err'); }
  };
  box.onchange = (e) => {
    const tr = e.target.closest('[data-px-site]');
    if (!tr) return;
    if (e.target.dataset.px === 'lan_mode') {
      const p = $('[data-px="lan_paths"]', tr);
      p.hidden = e.target.value !== 'paths';
      if (!p.hidden && !p.value.trim()) { p.focus(); return; }   // eerst een pad invullen
    }
    save(tr);
  };
  box.onclick = (e) => {
    const r = e.target.closest('[data-px-reset]');
    if (r) save(r.closest('[data-px-site]'), { reset: 1 });
  };
}

/** Beheerportal: bij welke server de rol staat (en welke op een proxy lijken) */
async function loadProxyRoles() {
  const box = $('#proxy-roles');
  if (!box) return;
  let d;
  try { d = await api('proxy_overview'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  box.innerHTML = d.servers.length ? `<div class="px-roles">${d.servers.map((s) => `<label class="switch"><input type="checkbox" data-px-role="${s.id}" ${s.role ? 'checked' : ''}><span></span>
      <b>${esc(s.name)}</b> ${s.report && s.report.kind && s.report.kind !== 'none' ? `<span class="lf-tag ok">${esc(PX_KIND[s.report.kind] || s.report.kind)}</span>` : ''}
      ${!s.agent_ok ? `<span class="muted small">${L('agent 1.97.0 nodig')}</span>` : ''}</label>`).join('')}</div>`
    : `<p class="muted small">${L('Nog geen servers met de agent.')}</p>`;
  box.onchange = async (e) => {
    const c = e.target.closest('[data-px-role]');
    if (!c) return;
    try { await api('proxy_role', { host_id: +c.dataset.pxRole, on: c.checked }); toast(c.checked ? L('Deze server is nu de reverse proxy') : L('Rol uitgezet; de agent haalt de bescherming weg')); }
    catch (ex) { c.checked = !c.checked; toast(ex.message, 'err'); }
  };
}
