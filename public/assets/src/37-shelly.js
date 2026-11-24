// ---------------------------------------------------------------- 1.321.0: Monitoring → Shelly's
// Verbruik nu en vandaag, afwijkingen van het normale verbruik, en de temperatuur van elke Shelly (oververhitting).
// Klik op een Shelly voor de grafieken van vandaag. "Zoeken op het netwerk" vindt Shelly's die nog niet bewaakt worden.

let shData = null;
const shOpen = new Set();
const shDay = new Map();

async function shLoad() {
  const el = $('#shellys');
  if (!el) return;
  if (!shData) el.innerHTML = `<p class="muted">${L('laden…')}</p>`;
  try { shData = await api('shelly_overview'); } catch (ex) { el.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  for (const id of shOpen) await shLoadDay(id);
  shRender();
}

async function shLoadDay(id) {
  try { shDay.set(id, await api('shelly_day', null, { id })); } catch { shDay.delete(id); }
}

const shW = (w) => (w === null || w === undefined ? '–' : dec(Math.round(w).toLocaleString(LOCALE)) + ' W');
const shT = (t) => (t === null || t === undefined ? '–' : dec(t.toFixed(1)) + ' °C');

function shRender() {
  const el = $('#shellys');
  const d = shData;
  if (!el || !d) return;
  const devs = d.devices;
  const attn = devs.filter((x) => x.status === 2 || x.status === 1 || x.anom);
  const sev = (x) => (x.status === 2 ? 0 : x.anom ? 1 : x.status === 1 ? 2 : 3);
  const sorted = [...devs].sort((a, b) => sev(a) - sev(b) || a.name.localeCompare(b.name));
  const stPill = (x) => (x.status === 2 ? `<span class="sh-pill crit">${L('Critical')}</span>` : x.anom ? `<span class="sh-pill warn">${L('Afwijking')}</span>`
    : x.status === 1 ? `<span class="sh-pill warn">${L('Warning')}</span>` : x.status === 0 ? `<span class="sh-pill ok">OK</span>` : `<span class="sh-pill">${L('onbekend')}</span>`);
  const band = (x) => (x.learning ? `<span class="muted">${L('leert nog (een week)')}</span>` : x.band ? `${shW(x.band.lo)} – ${shW(x.band.hi)}` : '–');
  const tBar = (x) => {
    if (x.tc === null || x.tc === undefined) return '';
    const pct = Math.max(4, Math.min(100, Math.round(x.tc / Math.max(x.crit + 10, 90) * 100)));
    const cls = x.overtemp || x.tc >= x.crit ? 'crit' : x.tc >= x.warn ? 'warn' : 'ok';
    return `<span class="sh-tbar" aria-hidden="true"><span class="${cls}" style="width:${pct}%"></span></span>`;
  };
  el.innerHTML = `
    <div class="sh-head"><div><p class="muted small">${L('{n} apparaten · rechtstreeks uitgelezen', { n: devs.length })}</p></div>
      <div class="btn-row need-admin"><button type="button" class="btn" data-sh-find>${L('Zoeken op het netwerk')}</button></div></div>
    ${devs.length ? `<div class="sh-kpis">
      <div class="sh-kpi"><span>${L('Nu')}</span><b>${shW(d.w)}</b></div>
      <div class="sh-kpi"><span>${L('Vandaag')}</span><b>${dec(d.kwh.toFixed(1))} kWh</b></div>
      <div class="sh-kpi ${d.anomalies ? 'warn' : ''}"><span>${L('Afwijkingen')}</span><b>${d.anomalies}</b></div>
      <div class="sh-kpi ${d.warmest && devs.some((x) => x.name === d.warmest.name && (x.overtemp || x.tc >= x.warn)) ? 'crit' : ''}"><span>${L('Warmste Shelly')}</span><b>${d.warmest ? shT(d.warmest.tc) : '–'}</b>${d.warmest ? `<small>${esc(d.warmest.name)}</small>` : ''}</div>
    </div>` : `<div class="av-hint">${L('Nog geen Shelly\'s. Zoek ze op het netwerk, of voeg bij een server een check van het type Shelly toe.')}</div>`}
    ${attn.length ? `<section class="sh-attn"><h3>${L('Wat aandacht vraagt')}</h3>${attn.map((x) => `<div class="sh-attn-row ${x.status === 2 ? 'crit' : 'warn'}">
      ${stPill(x)} <span><b>${esc(x.name)}</b> · ${esc(x.anom ? x.anom.text : x.message)}</span></div>`).join('')}</section>` : ''}
    ${devs.length ? `<div class="table-scroll"><table class="vuln-table sh-table"><thead><tr><th>${L('Shelly')}</th><th>${L('Status')}</th><th>${L('Nu')}</th><th>${L('Normaal (dit uur)')}</th><th>${L('Vandaag')}</th><th>${L('Temperatuur')}</th><th>${L('Verder')}</th></tr></thead><tbody>
      ${sorted.map((x) => `<tr class="sh-row" data-sh="${x.id}" tabindex="0" aria-expanded="${shOpen.has(x.id)}">
        <td><b>${esc(x.name)}</b><span class="muted small" style="display:block">${esc(x.model || '')}${x.gen ? ' · Gen ' + x.gen : ''}</span></td>
        <td>${stPill(x)}</td>
        <td class="mono ${x.anom ? 'warn-txt' : ''}">${shW(x.w)}</td>
        <td class="small">${band(x)}</td>
        <td class="mono">${x.kwh === null ? '–' : dec(x.kwh.toFixed(2)) + ' kWh'}</td>
        <td><span class="sh-tcell">${tBar(x)}<span class="mono ${x.overtemp || x.tc >= x.crit ? 'crit-txt' : x.tc >= x.warn ? 'warn-txt' : ''}">${shT(x.tc)}</span></span></td>
        <td class="muted small">${[x.relays && x.relays.length ? (x.relays.includes(true) ? L('relais aan') : L('relais uit')) : '', x.phases && x.phases.length ? x.phases.map((p) => shW(p)).join(' · ') : '',
          x.rssi !== null && x.rssi !== undefined ? `wifi ${x.rssi} dBm` : '', x.update ? L('firmware-update klaar') : ''].filter(Boolean).map(esc).join(' · ')}</td></tr>
        ${shOpen.has(x.id) ? `<tr class="sh-detail"><td colspan="7">${shDetail(x)}</td></tr>` : ''}`).join('')}
    </tbody></table></div>` : ''}`;
}

/** Grafieken van vandaag: vermogen met de normale band, en de temperatuur met de grenzen */
function shDetail(x) {
  const d = shDay.get(x.id);
  if (!d) return `<p class="muted small">${L('laden…')}</p>`;
  if (!d.points.length) return `<p class="muted small">${L('Nog geen metingen vandaag.')}</p>`;
  const W = 900, H = 170, X0 = 44, Y0 = 12, PW = W - X0 - 8, PH = H - Y0 - 24;
  const day0 = new Date(); day0.setHours(0, 0, 0, 0);
  const t0 = day0.getTime() / 1000;
  const xOf = (t) => X0 + ((t - t0) / 86400) * PW;
  const maxW = Math.max(10, ...d.points.map((p) => p.wmax || 0), ...d.bands.map((b) => b.hi || 0)) * 1.1;
  const yW = (w) => Y0 + PH - (w / maxW) * PH;
  const bandPath = d.bands.filter((b) => b.hi !== null).map((b) => `<rect x="${xOf(t0 + b.h * 3600).toFixed(1)}" y="${yW(b.hi).toFixed(1)}" width="${(PW / 24).toFixed(1)}" height="${Math.max(1, yW(b.lo) - yW(b.hi)).toFixed(1)}" class="sh-band"></rect>`).join('');
  const line = d.points.filter((p) => p.w !== null).map((p) => `${xOf(p.t).toFixed(1)},${yW(p.w).toFixed(1)}`).join(' ');
  const temps = d.points.filter((p) => p.tc !== null);
  const maxT = Math.max(x.crit + 10, ...temps.map((p) => p.tc));
  const minT = Math.min(15, ...temps.map((p) => p.tc));
  const yT = (t) => Y0 + PH - ((t - minT) / (maxT - minT)) * PH;
  const tline = temps.map((p) => `${xOf(p.t).toFixed(1)},${yT(p.tc).toFixed(1)}`).join(' ');
  const axis = [0, 6, 12, 18].map((h) => `<text x="${xOf(t0 + h * 3600).toFixed(1)}" y="${H - 4}">${String(h).padStart(2, '0')}:00</text>`).join('');
  return `<div class="sh-charts">
    <figure><figcaption>${L('Verbruik vandaag')} <span class="muted small">${L('lijn = gemeten · band = normaal voor dit uur van de week')}</span></figcaption>
      <svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(L('Verbruik vandaag'))}"><g class="sh-ax">${axis}<text x="2" y="${Y0 + 8}">${shW(maxW)}</text><text x="2" y="${Y0 + PH}">0 W</text></g>
        ${bandPath}<polyline class="sh-line" points="${line}"></polyline></svg></figure>
    ${temps.length ? `<figure><figcaption>${L('Temperatuur van de Shelly')} <span class="muted small">${L('warning {w} °C · critical {c} °C', { w: x.warn, c: x.crit })}</span></figcaption>
      <svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(L('Temperatuur van de Shelly'))}"><g class="sh-ax">${axis}<text x="2" y="${(yT(x.crit) + 4).toFixed(1)}">${x.crit}°</text><text x="2" y="${(yT(x.warn) + 4).toFixed(1)}">${x.warn}°</text></g>
        <line x1="${X0}" x2="${W - 8}" y1="${yT(x.crit).toFixed(1)}" y2="${yT(x.crit).toFixed(1)}" class="sh-crit"></line>
        <line x1="${X0}" x2="${W - 8}" y1="${yT(x.warn).toFixed(1)}" y2="${yT(x.warn).toFixed(1)}" class="sh-warn"></line>
        <polyline class="sh-tline" points="${tline}"></polyline></svg></figure>` : ''}
    <p class="small"><a href="#" data-sh-check="${x.id}">${L('Check openen')} →</a></p></div>`;
}

/** Shelly's op het netwerk zoeken en toevoegen */
async function shFind() {
  modal({ title: L("Shelly's zoeken op het netwerk"), wide: true,
    body: `<p class="small">${L('RzSense vraagt elk apparaat dat de netwerkscan kent of het een Shelly is. Staat een Shelly er niet bij, vul dan zijn IP-adres in.')}</p>
      <div class="row2"><label>${L('Extra IP-adressen (optioneel)')}<input id="sh-extra" placeholder="192.168.1.50 192.168.1.51"></label></div>
      <div class="btn-row"><button type="button" class="btn primary" id="sh-go">${L('Zoeken')}</button></div><div id="sh-found"></div>`,
    buttons: [{ label: L('Sluiten'), onClick: () => $('#modal').close() }] });
  const gen = modalGen;
  $('#modal-body').addEventListener('click', async (e) => {
    if (e.target.closest('#sh-go')) {
      const out = $('#sh-found');
      out.innerHTML = `<p class="muted">${L('zoeken… (dit kan een halve minuut duren)')}</p>`;
      let r;
      try { r = await api('shelly_discover', { extra: $('#sh-extra').value }); } catch (ex) { out.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
      if (modalGen !== gen) return;
      const fresh = r.found.filter((f) => !f.have);
      out.innerHTML = !r.found.length ? `<p class="muted">${L('Geen Shelly\'s gevonden. Draait de netwerkscan, en kan RzSense ze bereiken?')}</p>`
        : `<table class="vuln-table small"><thead><tr><th></th><th>${L('Naam')}</th><th>${L('Adres')}</th><th>${L('Type')}</th><th>${L('Wachtwoord')}</th></tr></thead><tbody>
          ${r.found.map((f) => `<tr class="${f.have ? 'muted' : ''}"><td>${f.have ? '✓' : `<input type="checkbox" data-sh-pick="${esc(f.ip)}" checked aria-label="${esc(f.ip)}">`}</td>
            <td>${f.have ? esc(f.name || 'Shelly') + ' · ' + esc(L('al bewaakt')) : `<input data-sh-name="${esc(f.ip)}" value="${esc(f.name || '')}" placeholder="Shelly ${esc(f.ip)}">`}</td>
            <td class="mono">${esc(f.ip)}</td><td>${esc(f.model)} · Gen ${f.gen}</td>
            <td>${!f.have && f.auth ? `<input type="password" data-sh-pass="${esc(f.ip)}" autocomplete="new-password" placeholder="${esc(L('wachtwoord'))}">` : f.auth ? '🔒' : '–'}</td></tr>`).join('')}</tbody></table>
          ${fresh.length ? `<div class="btn-row"><button type="button" class="btn primary" id="sh-add">${L('{n} toevoegen', { n: fresh.length })}</button></div>` : ''}`;
    }
    if (e.target.closest('#sh-add')) {
      const list = $$('[data-sh-pick]').filter((x) => x.checked).map((x) => ({ ip: x.dataset.shPick, name: ($(`[data-sh-name="${x.dataset.shPick}"]`) || {}).value || '',
        pass: ($(`[data-sh-pass="${x.dataset.shPick}"]`) || {}).value || '' }));
      try {
        const r = await api('shelly_add', { list });
        toast(L('{n} Shelly\'s toegevoegd', { n: r.added }));
        $('#modal').close();
        refresh();
        shLoad();
      } catch (ex) { toast(ex.message, 'err'); }
    }
  }, { signal: modalSignal() });
}

document.addEventListener('click', async (e) => {
  if (!e.target.closest('#shellys')) return;
  if (e.target.closest('[data-sh-find]')) { shFind(); return; }
  const ck = e.target.closest('[data-sh-check]');
  if (ck) { e.preventDefault(); checkInfo(+ck.dataset.shCheck); return; }
  const row = e.target.closest('[data-sh]');
  if (row) {
    const id = +row.dataset.sh;
    if (shOpen.has(id)) shOpen.delete(id); else { shOpen.add(id); shRender(); await shLoadDay(id); }
    shRender();
  }
});
