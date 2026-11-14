// ---------------------------------------------------------------- 1.254.0 (agent 1.97.0): reverse proxy beschermen
// Pagina Reverse proxy: per server met de rol de stand van de agent en per site het beleid (aan/uit, adminpagina's
// alleen vanaf je netwerk, rate limit, bots). Beheerportal › Beveiliging › Reverse proxy: welke server de proxy is.

const PX_LAN = () => [['off', L('Iedereen')], ['paths', L('Adminpaden alleen mijn netwerk')], ['site', L('Hele site alleen mijn netwerk')]];
const PX_RATE = () => [['off', L('Uit')], ['normal', L('Normaal')], ['strict', L('Streng')]];
const PX_STATE = () => ({ ok: ['ok', '✔ ' + L('doorgevoerd')], pending: ['wait', '⏳ ' + L('wacht op de agent')], error: ['bad', '✘ ' + L('mislukt')],
  off: ['', L('uit')], unsupported: ['wait', L('niet ondersteund')] });
const PX_KIND = { swag: 'SWAG', npm: 'Nginx Proxy Manager', nginx: 'nginx', traefik: 'Traefik', caddy: 'Caddy', none: '–' };
// 1.255.0 (agent 1.98.0): WAF (CrowdSec AppSec)
const WAF_MODES = ['detect', 'sure', 'strict'];
const WAF_MODE_TXT = () => ({ detect: L('Alleen melden: niets wordt tegengehouden. Kijk op de pagina Reverse proxy wat er tegengehouden zou worden.'),
  sure: L('Houdt alleen misbruik van bekende lekken tegen (virtuele patches): vrijwel nooit vals alarm.'),
  strict: L('Houdt ook verdachte verzoeken volgens de brede OWASP-regels tegen: meer bescherming, meer kans op vals alarm.') });
const WAF_SITE = () => [['inherit', L('Standaard (slider)')], ['detect', L('Alleen melden')], ['off', L('Uit')]];
const WAF_STATE = () => ({ ok: ['ok', '✔ ' + L('actief')], waiting: ['wait', '⏳ ' + L('wacht op SWAG')], starting: ['wait', '⏳ ' + L('wordt gestart')],
  error: ['bad', '✘ ' + L('fout')], off: ['', L('uit')], unsupported: ['wait', L('niet ondersteund')] });
const wafMsg = (m) => {
  if (!m) return '';
  const r = [[/^nep-aanval: (\d+) zoals verwacht$/, (x) => L('nep-aanval: {c} zoals verwacht', { c: x[1] })],
    [/^nep-aanval gaf (\S+), verwacht (\S+) \(gewoon verzoek: (\S+)\)$/, (x) => L('nep-aanval gaf {a}, verwacht {b} (gewoon verzoek: {c})', { a: x[1], b: x[2], c: x[3] })],
    [/^de WAF werkt nu alleen met SWAG \(gevonden: (.*)\)$/, (x) => L('de WAF werkt nu alleen met SWAG (gevonden: {p})', { p: x[1] })],
    [/^kon (\S+) niet aan het netwerk (\S+) hangen$/, (x) => L('kon {c} niet aan het netwerk {n} hangen', { c: x[1], n: x[2] })],
    [/^herstarten mislukt$/, () => L('herstarten mislukt')], [/^starten mislukt: (.*)$/s, (x) => L('starten mislukt: {m}', { m: x[1] })]];
  for (const [re, f] of r) { const x = m.match(re); if (x) return f(x); }
  return pxMsg(m);
};
/** Wat je één keer in SWAG zet (geen geheimen: de sleutel staat in een bestand dat alleen root kan lezen) */
const WAF_SWAG_ENV = 'DOCKER_MODS=linuxserver/mods:swag-crowdsec\nFILE__CROWDSEC_API_KEY=/config/nginx/rzsense-waf/crowdsec.key\nCROWDSEC_LAPI_URL=http://rzsense-crowdsec:8080\nCROWDSEC_APPSEC_URL=http://rzsense-crowdsec:7422';

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
        ${d.waf && d.waf.on ? `<td><select data-px="waf" aria-label="${esc(L('WAF'))}">${WAF_SITE().map(([k, l]) => `<option value="${k}" ${k === (x.waf || 'inherit') ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>${wafSiteStats((s.waf_stats || {})[x.site])}</td>` : ''}
        <td class="nowrap">${x.custom && x.source === 'ai' ? `<span class="lf-tag ok" title="${esc(x.ai_reason || '')}">🤖 ${L('AI')}</span> <button type="button" class="btn small-btn" data-px-reset title="${esc(L('Terug naar de standaard'))}">↺</button>` : x.custom ? `<span class="lf-tag">${L('eigen')}</span> <button type="button" class="btn small-btn" data-px-reset title="${esc(L('Terug naar de standaard'))}">↺</button>` : `<span class="lf-tag ok">${L('standaard')}</span>`}</td></tr>`
        + (d.ai && d.ai.on ? pxaRow(x, ((s.ai || {}).items || {})[x.site], d.waf && d.waf.on ? 7 : 6) : '')).join('');
    return `<section class="px-srv" data-px-host="${s.id}">
      <div class="px-h"><b>${esc(s.name)}</b> <span class="muted small">${esc(PX_KIND[r.kind] || r.kind || '–')}${r.ctr ? ' · ' + esc(r.ctr) : ''}${r.engine ? ' (' + esc(r.engine) + ')' : ''}</span>
        <span class="lf-tag ${cls}">${txt}</span>${sync}<span class="lp-sp"></span></div>
      ${!s.agent_ok ? `<div class="callout small">⬆ ${L('Agent 1.97.0 of nieuwer nodig. Werk de agent bij in de Agent Manager.')}</div>` : ''}
      ${r.msg ? `<p class="small ${r.state === 'error' ? 'danger-txt' : 'muted'}">${esc(pxMsg(r.msg))}</p>` : ''}
      ${d.ai && d.ai.on && (s.sites || []).length ? pxaHead(s, d.ai) : ''}
      ${(s.sites || []).length ? `<div class="table-scroll"><table class="vuln-table px-table"><thead><tr><th>${L('Aan')}</th><th>${L('Site')}</th><th>${L('Wie mag erbij')}</th><th>${L('Rate limit')}</th><th>${L('Bots weren')}</th>${d.waf && d.waf.on ? `<th>${L('WAF')}</th>` : ''}<th></th></tr></thead><tbody>${rows}</tbody></table></div>`
        : `<p class="muted small">${s.report ? L('Geen sites gevonden in de proxy.') : L('Nog geen rapport van de agent. Dat komt bij zijn volgende ronde (meestal binnen een minuut).')}</p>`}
      ${d.waf && d.waf.on ? wafPanel(s, d.waf) : ''}
    </section>`;
  }).join('');
  // Opslaan bij elke wijziging (de agent voert het bij zijn volgende ronde door)
  const save = async (tr, extra = {}) => {
    const host = +tr.closest('[data-px-host]').dataset.pxHost;
    const v = (k) => $(`[data-px="${k}"]`, tr);
    const body = { host_id: host, site: tr.dataset.pxSite, enabled: v('enabled').checked, lan_mode: v('lan_mode').value, lan_paths: v('lan_paths').value, rate: v('rate').value, bots: v('bots').checked, ...(v('waf') ? { waf: v('waf').value } : {}), ...extra };
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
  box.onclick = async (e) => {
    const r = e.target.closest('[data-px-reset]');
    if (r) save(r.closest('[data-px-site]'), { reset: 1 });
    const host = e.target.closest('[data-px-host]') ? +e.target.closest('[data-px-host]').dataset.pxHost : 0;
    const ask = e.target.closest('[data-pxa-ask]');
    if (ask) {
      try { const res = await api('proxy_ai_ask', { host_id: host }); const bad = res.ai.last && res.ai.last.status === 'error';
        const n = Object.values(res.ai.items || {}).filter((i) => i.status === 'new').length;
        toast(bad ? res.ai.last.error : (d.ai.auto ? L('Advies ontvangen en toegepast waar het mocht') : L('{n} voorstellen klaar', { n })), bad ? 'err' : undefined); }
      catch (ex) { toast(ex.message, 'err'); }
      loadProxy();
      return;
    }
    const pa = e.target.closest('[data-pxa-apply], [data-pxa-dismiss], [data-pxa-all]');
    if (pa) {
      pa.disabled = true;
      const site = pa.closest('[data-pxa-site]') ? pa.closest('[data-pxa-site]').dataset.pxaSite : '';
      try {
        if (pa.matches('[data-pxa-dismiss]')) { await api('proxy_ai_dismiss', { host_id: host, site }); toast(L('Voorstel genegeerd')); }
        else { const res = await api('proxy_ai_apply', pa.matches('[data-pxa-all]') ? { host_id: host, all: 1 } : { host_id: host, site });
          toast(L('{n} toegepast; de agent voert het binnen een minuut door', { n: res.n })); }
      } catch (ex) { toast(ex.message, 'err'); }
      loadProxy();
      return;
    }
    const x = e.target.closest('[data-waf-ex]');
    if (x) {
      const host = +x.closest('[data-px-host]').dataset.pxHost;
      if (!confirm(L('Regel {r} op {s} voortaan niet meer melden of tegenhouden?', { r: x.dataset.rule, s: x.dataset.site }))) return;
      x.disabled = true;
      try { await api('waf_except', { host_id: host, site: x.dataset.site, rule: x.dataset.rule }); toast(L('Uitzondering opgeslagen; de agent voert het binnen een minuut door')); loadProxy(); }
      catch (ex) { x.disabled = false; toast(ex.message, 'err'); }
    }
  };
}

// 1.256.0: AI-advies per site
const PXA_FIELD = () => ({ lan_mode: L('Wie mag erbij'), lan_paths: L('Adminpaden'), rate: L('Rate limit'), bots: L('Bots weren'), waf: L('WAF') });
const pxaVal = (f, v) => {
  if (f === 'lan_mode') return (PX_LAN().find(([k]) => k === v) || [, v])[1];
  if (f === 'rate') return (PX_RATE().find(([k]) => k === v) || [, v])[1];
  if (f === 'waf') return (WAF_SITE().find(([k]) => k === v) || [, v])[1];
  if (f === 'bots') return v ? L('aan') : L('uit');
  return String(v || '–').replace(/,/g, ', ');
};
/** Wat het voorstel verandert (zelfde vergelijking als de server) */
function pxaChanges(x, d) {
  const out = [];
  for (const f of ['lan_mode', 'lan_paths', 'rate', 'bots', 'waf']) {
    if (f === 'lan_paths' && d.lan_mode !== 'paths') continue;
    const cur = f === 'waf' ? (x.waf || 'inherit') : x[f];
    if (f === 'bots' ? !!cur !== !!d[f] : String(cur ?? '') !== String(d[f] ?? '')) out.push([f, cur, d[f]]);
  }
  return out;
}
/** Kop van het AI-advies bij een server: laatste vraag, samenvatting, Vraag advies en Alles toepassen */
function pxaHead(s, cfg) {
  const a = s.ai || {}, last = a.last;
  const open = Object.values(a.items || {}).filter((i) => i.status === 'new').length;
  const st = !last ? `<span class="muted small">${L('Nog geen advies gevraagd.')}</span>`
    : last.status === 'error' ? `<span class="lf-tag bad">✘ ${esc(last.error || L('mislukt'))}</span>`
    : ['pending', 'running'].includes(last.status) ? `<span class="lf-tag wait">⏳ ${L('bezig')}</span>`
    : `<span class="muted small">${L('Laatste advies {t}', { t: esc(ago(last.at)) })} · ${esc(last.model || last.provider)}${last.auto ? ' · ' + L('vanzelf') : ''}</span>${last.error ? ` <span class="lf-tag bad">${esc(last.error)}</span>` : ''}`;
  return `<div class="pxa-box"><div class="px-h"><b>🤖 ${L('AI-advies')}</b> <span class="lf-tag ${cfg.auto ? 'ok' : ''}">${cfg.auto ? L('Automatisch') : L('Zelf beheren')}</span> ${st}<span class="lp-sp"></span>
      ${open ? `<button type="button" class="btn small-btn" data-pxa-all>✔ ${L('Alles toepassen ({n})', { n: open })}</button>` : ''}
      <button type="button" class="btn small-btn primary" data-pxa-ask>🤖 ${L('Vraag advies')}</button></div>
    ${last && last.summary ? `<p class="small">${esc(last.summary)}</p>` : ''}
    ${last && last.note ? `<p class="small muted">${esc(last.note)}</p>` : ''}</div>`;
}
/** Onder een site: het open voorstel met wat er verandert, de reden en Toepassen / Negeren */
function pxaRow(x, item, cols) {
  if (!item || item.status !== 'new' || !item.data) return '';
  const ch = pxaChanges(x, item.data);
  if (!ch.length) return '';
  const F = PXA_FIELD();
  return `<tr class="pxa-row" data-pxa-site="${esc(x.site)}"><td></td><td colspan="${cols - 1}"><div class="pxa-prop">🤖 <b>${L('Voorstel')}:</b>
      ${ch.map(([f, a, b]) => `<span class="pxa-ch">${esc(F[f])}: <s>${esc(pxaVal(f, a))}</s> → <b>${esc(pxaVal(f, b))}</b></span>`).join(' ')}
      ${item.reason ? `<div class="muted small">${esc(item.reason)}</div>` : ''}
      <span class="pxa-btns"><button type="button" class="btn small-btn primary" data-pxa-apply>${L('Toepassen')}</button> <button type="button" class="btn small-btn" data-pxa-dismiss>${L('Negeren')}</button></span></div></td></tr>`;
}

/** Per site: wat de WAF de laatste 14 dagen zag, en wat hij bij elke stand van de slider tegengehouden had */
function wafSiteStats(st) {
  if (!st || !st.seen) return `<div class="muted small">${L('geen aanvallen gezien')}</div>`;
  return `<div class="small waf-st" title="${esc(L('Laatste 14 dagen'))}">${L('{n} gezien', { n: st.seen })} · <b>${L('{n} tegengehouden', { n: st.blocked })}</b>
    <div class="muted">${L('zou tegenhouden: {a} (alleen zeker) · {b} (streng)', { a: st.sure, b: st.strict })}</div></div>`;
}

/** Op de pagina Reverse proxy: stand van de WAF en de laatste meldingen, met "dit is normaal" */
function wafPanel(s, cfg) {
  const w = s.waf || {};
  const [cls, txt] = WAF_STATE()[w.state] || ['wait', '⏳ ' + L('wacht op de agent')];
  const ev = (s.waf_recent || []).slice(0, 15);
  const rows = ev.map((e) => {
    const rule = (e.rules || '').split(' ').filter(Boolean)[0] || '';
    return `<tr><td class="nowrap small">${esc(ago(e.at))}</td><td><code>${esc(e.ip)}</code></td><td>${esc(e.site)}<div class="muted small mono">${esc(e.uri)}</div></td>
      <td class="small mono">${esc((e.rules || '').split(' ').slice(0, 3).join(', '))}${e.vpatch ? ` <span class="lf-tag">${L('bekend lek')}</span>` : ''}</td>
      <td>${e.blocked ? `<span class="lf-tag bad">${L('tegengehouden')}</span>` : `<span class="lf-tag">${L('gemeld')}</span>`}</td>
      <td>${rule && e.site ? `<button type="button" class="btn small-btn" data-waf-ex data-site="${esc(e.site)}" data-rule="${esc(rule)}" title="${esc(L('Deze regel op deze site voortaan overslaan'))}">${L('Dit is normaal')}</button>` : ''}</td></tr>`;
  }).join('');
  return `<div class="waf-box"><div class="px-h"><b>🧱 ${L('WAF')}</b> <span class="lf-tag ${cls}">${txt}</span>
      <span class="muted small">${esc({ detect: L('Detecteren'), sure: L('Blokkeren, alleen zeker'), strict: L('Blokkeren, streng') }[cfg.mode] || cfg.mode)}</span>
      ${w.test === 'ok' ? `<span class="lf-tag ok">✔ ${L('zelftest')}</span>` : w.test === 'fail' ? `<span class="lf-tag bad">✘ ${L('zelftest')}</span>` : ''}</div>
    ${s.waf_agent_ok === false ? `<div class="callout small">⬆ ${L('Agent 1.98.0 of nieuwer nodig voor de WAF. Werk de agent bij in de Agent Manager.')}</div>` : ''}
    ${w.msg || w.test_msg ? `<p class="small ${w.state === 'error' || w.test === 'fail' ? 'danger-txt' : 'muted'}">${esc(wafMsg(w.state === 'error' ? w.msg : (w.test_msg || w.msg)))}</p>` : ''}
    ${ev.length ? `<div class="table-scroll"><table class="vuln-table"><thead><tr><th>${L('Tijd')}</th><th>${L('Van')}</th><th>${L('Site')}</th><th>${L('Regel')}</th><th></th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`
      : `<p class="muted small">${L('Nog geen aanvallen gezien door de WAF.')}</p>`}</div>`;
}

/** De slider (zonder naam) volgt het verborgen veld waf_mode, dat met de instellingen wordt opgeslagen */
function wafRangeSync(fallback) {
  const r = $('#waf-mode-range'), h = $('#settings-form [name="waf_mode"]');
  if (!r || !h) return;
  const sync = () => { $('#waf-mode-txt').textContent = WAF_MODE_TXT()[WAF_MODES[+r.value]] || ''; };
  r.value = String(Math.max(0, WAF_MODES.indexOf(h.value || fallback || 'detect')));
  sync();
  r.oninput = () => { h.value = WAF_MODES[+r.value]; sync(); };
}

/** De uitleg onder de slider Zelf beheren / Automatisch volgt de stand */
function pxaAutoSync() {
  const r = $('#proxy-ai-auto');
  if (!r) return;
  const sync = () => { $('#proxy-ai-txt').textContent = r.value === '1'
    ? L('RzSense past het advies meteen toe, behalve bij sites die je zelf hebt ingesteld. Alles staat in het logboek; ↺ zet een site terug.')
    : L('Het advies staat klaar op de pagina Reverse proxy; jij kiest per site Toepassen of Negeren.'); };
  sync();
  r.oninput = sync;
}

/** Beheerportal: uitleg bij de slider Zelf beheren / Automatisch, en een waarschuwing als AI-advies zelf uit staat */
function pxaSettings(d) {
  pxaAutoSync();
  const n = $('#proxy-ai-note');
  if (n) n.innerHTML = d.ai && !d.ai.ai_on ? `<div class="callout small">⚠️ ${L('AI-advies staat uit. Zet het eerst aan en kies een model.')} <a href="#settings/ai" data-goto="settings/ai">${L('Naar AI-advies')} →</a></div>` : '';
}

/** Beheerportal: de WAF-slider en per proxyserver de stand en wat je één keer in SWAG zet */
function wafSettings(d) {
  wafRangeSync(d.waf && d.waf.mode);
  pxaSettings(d);
  const box = $('#waf-status');
  if (!box) return;
  const px = d.servers.filter((s) => s.role);
  box.innerHTML = !d.waf || !d.waf.on || !px.length ? '' : px.map((s) => {
    const w = s.waf || {};
    const [cls, txt] = WAF_STATE()[w.state] || ['wait', '⏳ ' + L('wacht op de agent')];
    const need = w.state === 'waiting' || (w.running && w.bouncer !== 'active');
    return `<div class="px-srv"><div class="px-h"><b>${esc(s.name)}</b> <span class="lf-tag ${cls}">${txt}</span>
        ${w.test === 'ok' ? `<span class="lf-tag ok">✔ ${L('zelftest')}</span>` : w.test === 'fail' ? `<span class="lf-tag bad">✘ ${L('zelftest')}</span>` : ''}</div>
      ${w.msg || w.test_msg ? `<p class="small muted">${esc(wafMsg(w.state === 'error' ? w.msg : (w.test_msg || w.msg)))}</p>` : ''}
      ${need ? `<p class="small">${L('Zet deze regels één keer bij de omgevingsvariabelen van SWAG en maak de SWAG-container opnieuw aan. De sleutel heeft de agent al in een bestand gezet dat alleen root kan lezen.')}</p>
        <pre class="code small">${esc(WAF_SWAG_ENV)}</pre>` : ''}</div>`;
  }).join('');
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
  wafSettings(d);
  box.onchange = async (e) => {
    const c = e.target.closest('[data-px-role]');
    if (!c) return;
    try { await api('proxy_role', { host_id: +c.dataset.pxRole, on: c.checked }); toast(c.checked ? L('Deze server is nu de reverse proxy') : L('Rol uitgezet; de agent haalt de bescherming weg')); }
    catch (ex) { c.checked = !c.checked; toast(ex.message, 'err'); }
  };
}
