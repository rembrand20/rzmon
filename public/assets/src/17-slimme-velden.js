// ---------------------------------------------------------------- 🧩 Slimme velden (1.239.0)
// Eén bouwsteen voor lijsten (IP-adressen, netwerken, accounts, e-mailadressen, domeinen): kiezen uit wat RzSense al
// weet (je netwerken, de whitelist, gebruikers, eigen domeinen, apparaten) of zelf typen. Na elke waarde komt er weer een
// leeg veld. "Gekoppelde" waarden (zoals @lan = de lokale netwerken uit Toegang) blijven gekoppeld: pas je je netwerken
// aan, dan klopt de lijst vanzelf mee. Het oorspronkelijke veld blijft bestaan (verborgen) en krijgt de waarde, dus opslaan
// werkt zoals altijd; zet de code het veld zelf (el.value = …), dan tekent het slimme veld mee.

let pickCache = null;
let pickAt = 0;
let pickBusy = null;
/** De bronnen (api pick_sources), hooguit één keer per minuut opgehaald */
async function pickSources(fresh = false) {
  if (!isAdmin()) return {};
  if (pickCache && !fresh && Date.now() - pickAt < 60000) return pickCache;
  if (!pickBusy) pickBusy = api('pick_sources').then((d) => { pickCache = d; pickAt = Date.now(); return d; }).finally(() => { pickBusy = null; });
  try { return await pickBusy; } catch (e) { return pickCache || {}; }
}

const SL_IP = (v) => {
  const [ip, bits] = String(v).split('/');
  const v4 = /^(25[0-5]|2[0-4]\d|1?\d?\d)(\.(25[0-5]|2[0-4]\d|1?\d?\d)){3}$/.test(ip);
  const v6 = !v4 && ip.includes(':') && /^[0-9a-f:.]+$/i.test(ip);
  if (!v4 && !v6) return false;
  return bits === undefined || (/^\d{1,3}$/.test(bits) && +bits <= (v4 ? 32 : 128));
};
/** Controle per soort: wat mag er in het veld */
const SL_CHECK = {
  ip: SL_IP,
  email: (v) => /^[^\s@,;]+@[^\s@,;]+\.[a-z]{2,}$/i.test(v),
  domain: (v) => /^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i.test(v),
  ipuser: (v) => SL_IP(v) || (!/^[\d.:/]+$/.test(v) && /^[A-Za-z0-9_][A-Za-z0-9_.@-]{0,63}$/.test(v)),
  url: (v) => /^https?:\/\/[^\s/]+(:\d+)?(\/\S*)?$/i.test(v),
};
/** Gekoppelde waarden: wat er op het chipje staat */
const SL_TOKENS = () => ({ '@lan': '🏠 ' + L('Lokale netwerken (gekoppeld)') });

/**
 * Een bestaand invoerveld of tekstvak slim maken.
 * opts: kind (ip | email | domain | ipuser | url), sources(bronnen) → [{v, l, g}] (waarde, uitleg, groep),
 *   sep (tussen waarden bij opslaan: ' ', ', ' of '\n'), tokens (true = @lan mag), max (aantal waarden), single (één waarde)
 */
function smartList(el, opts) {
  if (!el || el.dataset.sl) return;
  el.dataset.sl = '1';
  const { kind = 'ip', sources = () => [], sep = ' ', tokens = false, max = 100, single = false } = opts;
  const proto = Object.getPrototypeOf(el);
  const desc = Object.getOwnPropertyDescriptor(proto, 'value');
  const tok = tokens ? SL_TOKENS() : {};
  const box = document.createElement('div');
  box.className = 'sl' + (single ? ' sl-single' : '');
  const listId = 'sl-dl-' + Math.random().toString(36).slice(2, 9);
  box.innerHTML = `<span class="sl-chips"></span><input class="sl-in mono" list="${listId}" autocomplete="off" spellcheck="false" placeholder="${esc(el.getAttribute('placeholder') || (single ? L('kies of typ…') : L('+ kies of typ…')))}"><datalist id="${listId}"></datalist><span class="sl-err small bad" hidden></span>`;
  el.after(box);
  el.hidden = true;
  const chips = $('.sl-chips', box), inp = $('.sl-in', box), dl = $('datalist', box), err = $('.sl-err', box);
  const read = () => String(desc.get.call(el) || '').split(/[\s,;]+/).map((x) => x.trim()).filter(Boolean);
  const write = (vals, notify = true) => {
    desc.set.call(el, vals.join(sep));
    draw();
    if (notify) { el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }
  };
  const ok = (v) => (tok[v] !== undefined) || (SL_CHECK[kind] ? SL_CHECK[kind](v) : true);
  let srcList = [];
  const draw = () => {
    const vals = read();
    chips.innerHTML = vals.map((v, i) => {
      const src = srcList.find((s) => s.v === v);
      return `<span class="sl-chip${ok(v) ? '' : ' bad'}${tok[v] ? ' linked' : ''}" title="${esc(tok[v] ? L('Gekoppeld: verandert vanzelf mee') : src && src.l ? src.l : ok(v) ? '' : L('Geen geldige waarde'))}">${esc(tok[v] || v)}${src && src.l && !tok[v] ? ` <small class="muted">${esc(src.l)}</small>` : ''}<button type="button" class="sl-x" data-i="${i}" aria-label="${esc(L('Weghalen'))}">✕</button></span>`;
    }).join('');
    inp.hidden = single && vals.length > 0;
    const used = new Set(vals);
    dl.innerHTML = srcList.filter((s) => !used.has(s.v)).map((s) => `<option value="${esc(s.v)}" label="${esc([s.g, s.l].filter(Boolean).join(' · '))}"></option>`).join('');
  };
  const add = (raw) => {
    const parts = String(raw).split(/[\s,;]+/).map((x) => x.trim()).filter(Boolean);
    if (!parts.length) return;
    const bad = parts.filter((p) => !ok(p));
    if (bad.length) { err.hidden = false; err.textContent = L('Geen geldige waarde: {v}', { v: bad.join(', ') }); inp.classList.add('bad'); return; }
    err.hidden = true; inp.classList.remove('bad');
    const vals = read();
    for (const p of parts) if (!vals.includes(p) && vals.length < (single ? 1 : max)) vals.push(p);
    inp.value = '';
    write(vals);
    if (!single) inp.focus();   // meteen het volgende lege veld
  };
  inp.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ',' || (e.key === ' ' && kind !== 'url')) { e.preventDefault(); add(inp.value); }
    else if (e.key === 'Backspace' && !inp.value) { const v = read(); if (v.length) { v.pop(); write(v); } }
  });
  inp.addEventListener('change', () => add(inp.value));   // gekozen uit de lijst
  inp.addEventListener('blur', () => { if (inp.value.trim()) add(inp.value); });
  box.addEventListener('click', (e) => {
    const x = e.target.closest('.sl-x');
    if (x) { const v = read(); v.splice(+x.dataset.i, 1); write(v); inp.hidden = false; inp.focus(); return; }
    if (e.target === box || e.target === chips) inp.focus();
  });
  // Zet de code het oorspronkelijke veld (laden van de instellingen), dan tekenen we mee
  Object.defineProperty(el, 'value', { configurable: true, get: () => desc.get.call(el), set: (v) => { desc.set.call(el, v); draw(); } });
  draw();
  pickSources().then((d) => { srcList = (sources(d || {}) || []).filter((s) => s && s.v); draw(); });
}

// ---------------------------------------------------------------- 1.240.0: keuzelijsten uit netwerkscan en servers
// Gewone velden (zelf typen blijft kunnen) krijgen een keuzelijst met wat RzSense al kent: apparaten uit de netwerkscan
// en je servers. Eén luisteraar voor de hele pagina: zodra je in zo'n veld klikt, komt de lijst eronder.
const PL_DEV = (d) => {
  const seen = new Set(), out = [];
  for (const x of [...(d.hosts || []), ...(d.devices || [])]) if (x.v && !seen.has(x.v)) { seen.add(x.v); out.push([x.v, x.l]); }
  return out;
};
const PL_HOST = (u) => { try { return new URL(u).hostname; } catch (e) { return ''; } };
/** Velden en wat er in hun lijst komt ([waarde, uitleg]) */
const PL_FIELDS = [
  ['#modal-form [name=address]', (d) => PL_DEV(d)],
  ['#modal-form [name=dns_server]', (d) => [...(d.adguard || []).map((u) => [PL_HOST(u), 'AdGuard Home']).filter((x) => x[0]), ...PL_DEV(d)]],
  ['[name=unifi_url]', (d) => PL_DEV(d).map(([v, l]) => ['https://' + v, l])],
  ['[name=ai_ollama_url]', (d) => [['http://127.0.0.1:11434', L('deze server')], ...PL_DEV(d).map(([v, l]) => ['http://' + v + ':11434', l])]],
];
async function pickList(el, fn) {
  if (!el || el.dataset.pl) return;
  el.dataset.pl = '1';
  let id = el.getAttribute('list');
  if (!id) { id = 'pl-' + Math.random().toString(36).slice(2, 9); el.setAttribute('list', id); el.insertAdjacentHTML('afterend', `<datalist id="${id}"></datalist>`); }
  const dl = document.getElementById(id);
  const d = await pickSources();
  const have = new Set([...dl.options].map((o) => o.value));
  dl.insertAdjacentHTML('beforeend', fn(d || {}).filter(([v]) => v && !have.has(v)).map(([v, l]) => `<option value="${esc(v)}" label="${esc(l || '')}"></option>`).join(''));
}
document.addEventListener('focusin', (e) => {
  if (!e.target.matches || !e.target.matches('input')) return;
  for (const [sel, fn] of PL_FIELDS) if (e.target.matches(sel)) { pickList(e.target, fn); break; }
});

/** Valt een IPv4-adres binnen een netwerk (CIDR) of is het hetzelfde adres? (IPv6: alleen gelijk) */
function slInNet(ip, net) {
  const [n, bits] = String(net).split('/');
  if (!ip.includes('.') || !n.includes('.')) return ip === n;
  const num = (a) => a.split('.').reduce((s, o) => (s * 256) + (+o || 0), 0);
  const b = bits === undefined ? 32 : +bits;
  const mask = b === 0 ? 0 : (2 ** 32) - (2 ** (32 - b));
  return Math.floor(num(ip) / 2 ** (32 - b)) === Math.floor(num(n) / 2 ** (32 - b)) && mask >= 0;
}

/** Veelgebruikte bronnen */
const SL_SRC = {
  /** Lokale netwerken (gekoppeld), whitelist, mijn IP */
  ips: (d) => [
    { v: '@lan', l: (d.lan || []).join(' '), g: '🏠 ' + L('Lokale netwerken (gekoppeld)') },
    ...(d.lan || []).map((n) => ({ v: n, l: '', g: '🏠 ' + L('Lokaal netwerk') })),
    ...(d.wl_ips || []).map((w) => ({ v: w.v, l: w.l, g: '✅ ' + L('Whitelist') })),
    d.my_ip ? { v: d.my_ip, l: '', g: '💻 ' + L('Mijn huidige IP') } : null,
  ],
  /** Accounts: whitelist, honeypot-namen (voor "logins negeren" ook IP's) */
  accounts: (d) => [...(d.wl_users || []).map((u) => ({ v: u, l: '', g: '✅ ' + L('Whitelist') })), ...(d.users || []).map((u) => ({ v: u.u, l: '', g: '👤 ' + L('Gebruiker van RzSense') }))],
  emails: (d) => (d.emails || []).map((e) => ({ v: e.v, l: e.l, g: '👤 ' + L('Bekend in RzSense') })),
  domains: (d) => (d.domains || []).map((v) => ({ v, l: '', g: '🌐 ' + L('Eigen domein') })),
};
