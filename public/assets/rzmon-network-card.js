/*
 * RzSense netwerkkaart voor Home Assistant (1.197.0)
 *
 * Een samenvatting van het netwerk zoals mDS: één oordeel, internetlijn, verbruik deze maand, apparaten,
 * netwerkpaden, IDS, de top 3 verkeer vandaag en (1.198.0, schakelaar show_servers) de servers met CPU, RAM en disk. De kaart haalt alles rechtstreeks bij RzSense op
 * (GET /v1.php/network) met een API-sleutel met het recht "Status lezen" (en "IDS lezen" voor de IDS-tegel).
 *
 * In Home Assistant: Instellingen → Dashboards → ⋮ → Bronnen → Bron toevoegen:
 *   URL  https://<rzmon>/ha-card.php   soort  JavaScript-module
 * Daarna een kaart toevoegen: "RzSense netwerk" (of in YAML: type: custom:rzmon-network-card).
 *
 * Licentie: GPL-3.0
 */
(() => {
  const VERSION = '1.198.0';

  const EN = {
    'Netwerk': 'Network', 'Alles in orde': 'All good', 'Let op': 'Attention', 'Storing': 'Outage', 'Internet': 'Internet',
    'Deze maand': 'This month', 'Apparaten': 'Devices', 'online': 'online', 'nieuw': 'new', 'Netwerkpaden': 'Network paths',
    'Top verkeer vandaag': 'Top traffic today', 'geblokkeerd vandaag': 'blocked today', 'open incidenten': 'open incidents',
    'kritiek': 'critical', 'vandaag': 'today', 'Servers': 'Servers', 'agent stil {d}': 'agent silent {d}', '{n} problemen': '{n} problems', '1 probleem': '1 problem', 'onderhoud': 'maintenance', 'Disk': 'Disk', 'Max. servers': 'Max. servers', 'meer': 'more', 'van abonnement': 'of plan', 'open': 'open', 'nu actief': 'active now', 'verwacht': 'forecast', 'van': 'of', 'verlies': 'loss', 'hops': 'hops',
    'Geen meting': 'No measurement', 'Laden…': 'Loading…', 'live': 'live', 'Vul de URL van RzSense en een API-sleutel in.': 'Fill in the RzSense URL and an API key.',
    'De API-sleutel is ongeldig of verlopen.': 'The API key is invalid or expired.',
    'Deze API-sleutel heeft het recht "Status lezen" niet.': 'This API key lacks the "Read status" right.',
    'RzSense is niet bereikbaar.': 'RzSense cannot be reached.',
    'Gebruikt Home Assistant https, dan moet RzSense ook via https bereikbaar zijn.': 'If Home Assistant uses https, RzSense must be reachable over https too.',
    'Mag niet vanaf dit adres (zie "alleen vanaf" bij de API-sleutel).': 'Not allowed from this address (see "only from" on the API key).',
    'nog geen paden': 'no paths yet', 'Netwerkscan staat uit': 'Network scan is off',
    'URL van RzSense': 'RzSense URL', 'API-sleutel': 'API key', 'Titel': 'Title', 'Verversen (seconden)': 'Refresh (seconds)',
    'Internetlijn': 'Internet line', 'IDS': 'IDS', 'Top 3 verkeer': 'Top 3 traffic', 'Redenen bij een probleem': 'Reasons when there is a problem',
  };

  const css = `
    :host { --rz-ok: var(--success-color, #43a047); --rz-warn: var(--warning-color, #ffa000); --rz-crit: var(--error-color, #e53935); }
    ha-card { padding: 16px; overflow: hidden; }
    .hd { display: flex; align-items: center; gap: 12px; cursor: pointer; }
    .orb { width: 44px; height: 44px; border-radius: 50%; flex: none; display: grid; place-items: center; color: #fff;
      background: var(--rz-st); box-shadow: 0 0 0 6px color-mix(in srgb, var(--rz-st) 18%, transparent); }
    .orb svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .hd-t { flex: 1; min-width: 0; }
    .hd-k { font-size: .78rem; color: var(--secondary-text-color); letter-spacing: .04em; text-transform: uppercase; font-weight: 600; }
    .hd-v { font-size: 1.45rem; font-weight: 700; line-height: 1.2; color: var(--primary-text-color); }
    .live { font-size: .75rem; color: var(--secondary-text-color); display: flex; align-items: center; gap: 6px; white-space: nowrap; }
    .live i { width: 7px; height: 7px; border-radius: 50%; background: var(--rz-ok); }
    .live.stale i { background: var(--secondary-text-color); }
    .why { margin: 12px 0 0; padding: 0; list-style: none; display: grid; gap: 6px; }
    .why li { display: flex; gap: 8px; align-items: flex-start; font-size: .88rem; color: var(--primary-text-color); padding: 8px 10px; border-radius: 10px;
      background: color-mix(in srgb, var(--rz-st) 10%, transparent); }
    .why li.more { background: none; color: var(--secondary-text-color); padding: 0 10px; }
    .why li::before { content: ''; flex: none; width: 8px; height: 8px; margin-top: 6px; border-radius: 50%; background: var(--rz-st); }
    .g { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-top: 14px; }
    .t { border: 1px solid var(--divider-color); border-radius: 14px; padding: 12px; cursor: pointer; min-width: 0; position: relative; }
    .t:hover { background: color-mix(in srgb, var(--primary-color) 5%, transparent); }
    .t::after { content: ''; position: absolute; top: 12px; right: 12px; width: 8px; height: 8px; border-radius: 50%; background: var(--rz-st, transparent); }
    .k { font-size: .75rem; color: var(--secondary-text-color); text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
    .v { font-size: 1.25rem; font-weight: 700; margin-top: 4px; color: var(--primary-text-color); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .v small { font-size: .8rem; font-weight: 600; color: var(--secondary-text-color); }
    .s { font-size: .8rem; color: var(--secondary-text-color); margin-top: 2px; line-height: 1.35; }
    .bar { height: 6px; border-radius: 3px; background: var(--divider-color); margin-top: 8px; overflow: hidden; }
    .bar b { display: block; height: 100%; border-radius: 3px; background: var(--rz-bar, var(--primary-color)); }
    .sec { margin-top: 16px; }
    .sec-h { font-size: .75rem; color: var(--secondary-text-color); text-transform: uppercase; letter-spacing: .04em; font-weight: 600; margin-bottom: 6px; }
    .row { display: flex; align-items: center; gap: 10px; padding: 7px 0; border-top: 1px solid var(--divider-color); cursor: pointer; font-size: .9rem; }
    .row:first-of-type { border-top: 0; }
    .dot { width: 9px; height: 9px; border-radius: 50%; flex: none; background: var(--rz-st); }
    .nm { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--primary-text-color); }
    .nm span { color: var(--secondary-text-color); }
    .val { color: var(--secondary-text-color); white-space: nowrap; font-variant-numeric: tabular-nums; }
    .tb { flex: none; width: 64px; height: 6px; border-radius: 3px; background: var(--divider-color); overflow: hidden; }
    .tb b { display: block; height: 100%; background: var(--primary-color); border-radius: 3px; }
    .srv { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; column-gap: 10px; }
    .srv .nm small { display: block; font-size: .78rem; color: var(--secondary-text-color); }
    .srv .nm small.w { color: var(--rz-st); }
    .mm { display: grid; grid-template-columns: repeat(3, 40px); gap: 6px; }
    .mm span { display: block; font-size: .65rem; color: var(--secondary-text-color); text-align: center; line-height: 1.2; }
    .mm i { display: block; height: 5px; border-radius: 3px; background: var(--divider-color); overflow: hidden; margin-top: 2px; }
    .mm i b { display: block; height: 100%; border-radius: 3px; background: var(--rz-bar, var(--primary-color)); }
    .row.more { color: var(--secondary-text-color); font-size: .85rem; }
    .err { padding: 12px; border-radius: 10px; background: color-mix(in srgb, var(--rz-crit) 12%, transparent); color: var(--primary-text-color); font-size: .9rem; }
    .err small { display: block; color: var(--secondary-text-color); margin-top: 4px; }
    .muted { color: var(--secondary-text-color); font-size: .88rem; }
  `;

  const ST_COLOR = ['var(--rz-ok)', 'var(--rz-warn)', 'var(--rz-crit)', 'var(--secondary-text-color)'];
  const ICON = {
    0: '<path d="M20 6 9 17l-5-5"/>',
    1: '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
    2: '<path d="M18 6 6 18M6 6l12 12"/>',
  };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  class RzMonNetworkCard extends HTMLElement {
    static getConfigForm() {
      return {
        schema: [
          { name: 'url', required: true, selector: { text: { type: 'url' } } },
          { name: 'api_key', required: true, selector: { text: { type: 'password' } } },
          { name: 'title', selector: { text: {} } },
          { name: 'refresh', selector: { number: { min: 15, max: 3600, step: 5, mode: 'box', unit_of_measurement: 's' } } },
          { type: 'grid', name: '', schema: [
            { name: 'show_internet', selector: { boolean: {} } }, { name: 'show_month', selector: { boolean: {} } },
            { name: 'show_devices', selector: { boolean: {} } }, { name: 'show_ids', selector: { boolean: {} } },
            { name: 'show_paths', selector: { boolean: {} } }, { name: 'show_top', selector: { boolean: {} } },
            { name: 'show_problems', selector: { boolean: {} } }, { name: 'show_servers', selector: { boolean: {} } },
          ] },
          { name: 'max_servers', selector: { number: { min: 1, max: 50, step: 1, mode: 'box' } } },
        ],
        computeLabel: (s) => {
          const nl = { url: 'URL van RzSense', api_key: 'API-sleutel', title: 'Titel', refresh: 'Verversen (seconden)', show_internet: 'Internetlijn',
            show_month: 'Deze maand', show_devices: 'Apparaten', show_ids: 'IDS', show_paths: 'Netwerkpaden', show_top: 'Top 3 verkeer',
            show_problems: 'Redenen bij een probleem', show_servers: 'Servers', max_servers: 'Max. servers' }[s.name];
          return nl ? RzMonNetworkCard._t(nl, document.documentElement.lang) : undefined;
        },
      };
    }

    static getStubConfig() {
      return { url: 'https://rzmon.example.lan', api_key: '', refresh: 60 };
    }

    static _t(s, lang) {
      return String(lang || '').toLowerCase().startsWith('nl') ? s : (EN[s] || s);
    }

    setConfig(config) {
      if (!config) throw new Error('config');
      this._cfg = { refresh: 60, show_internet: true, show_month: true, show_devices: true, show_ids: true, show_paths: true, show_top: true, show_problems: true, show_servers: true, max_servers: 8, ...config };
      this._cfg.url = String(this._cfg.url || '').trim().replace(/\/+$/, '').replace(/\/(index\.php|v1\.php.*)$/, '');
      this._data = null;
      this._err = null;
      if (!this.shadowRoot) this.attachShadow({ mode: 'open' });
      this._render();
      if (this.isConnected) this._start();
    }

    set hass(hass) {
      const first = !this._hass;
      this._hass = hass;
      if (first) this._render();
    }

    connectedCallback() { this._start(); }

    disconnectedCallback() {
      clearInterval(this._timer);
      this._timer = null;
      document.removeEventListener('visibilitychange', this._vis);
    }

    getCardSize() { return 6; }

    getGridOptions() { return { columns: 12, min_columns: 6, rows: 'auto' }; }

    L(s) { return RzMonNetworkCard._t(s, this._hass?.language || document.documentElement.lang); }

    _start() {
      if (!this._cfg) return;
      clearInterval(this._timer);
      this._load();
      this._timer = setInterval(() => { if (!document.hidden) this._load(); }, Math.max(15, +this._cfg.refresh || 60) * 1000);
      this._vis = this._vis || (() => { if (!document.hidden && Date.now() - (this._at || 0) > 15000) this._load(); });
      document.addEventListener('visibilitychange', this._vis);
    }

    async _load() {
      const c = this._cfg;
      if (!c.url || !c.api_key) {
        this._err = [this.L('Vul de URL van RzSense en een API-sleutel in.')];
        return this._render();
      }
      try {
        const r = await fetch(`${c.url}/v1.php/network${c.show_servers ? '?servers=1' : ''}`, { headers: { Authorization: `Bearer ${c.api_key}` }, cache: 'no-store' });
        if (r.status === 401) throw [this.L('De API-sleutel is ongeldig of verlopen.')];
        if (r.status === 403) {
          const j = await r.json().catch(() => ({}));
          throw [/vanaf|from/i.test(j.error || '') ? this.L('Mag niet vanaf dit adres (zie "alleen vanaf" bij de API-sleutel).') : this.L('Deze API-sleutel heeft het recht "Status lezen" niet.'), j.error];
        }
        if (!r.ok) throw [this.L('RzSense is niet bereikbaar.'), `HTTP ${r.status}`];
        this._data = await r.json();
        this._err = null;
        this._at = Date.now();
      } catch (e) {
        this._err = Array.isArray(e) ? e : [this.L('RzSense is niet bereikbaar.'),
          location.protocol === 'https:' && c.url.startsWith('http:') ? this.L('Gebruikt Home Assistant https, dan moet RzSense ook via https bereikbaar zijn.') : String(e?.message || e)];
      }
      this._render();
    }

    _open(hash) {
      if (this._cfg?.url) window.open(`${this._cfg.url}/${hash ? `#${hash}` : ''}`, '_blank', 'noopener');
    }

    _loc() { return this._hass?.locale?.language || this._hass?.language || undefined; }

    _num(n, d = 0) {
      return Number(n).toLocaleString(this._loc(), { maximumFractionDigits: d, minimumFractionDigits: 0 });
    }

    _bytes(b) {
      if (b === null || b === undefined) return '–';
      const u = ['B', 'KB', 'MB', 'GB', 'TB'];
      let i = 0;
      let v = +b;
      while (v >= 1000 && i < u.length - 1) { v /= 1000; i++; }
      return `${this._num(v, v < 10 && i ? 1 : 0)} ${u[i]}`;
    }

    _dur(min) {
      if (min < 60) return `${min} min`;
      if (min < 2880) return `${Math.round(min / 60)} ${String(this._hass?.language || 'nl').startsWith('nl') ? 'u' : 'h'}`;
      return `${Math.round(min / 1440)} d`;
    }

    _mbit(v) {
      return v === null || v === undefined ? '–' : this._num(v, v < 10 ? 1 : 0);
    }

    _render() {
      if (!this.shadowRoot) return;
      const c = this._cfg || {};
      const d = this._data;
      const L = (s) => this.L(s);
      let body = '';
      if (this._err && !d) {
        body = `<div class="err">${esc(this._err[0])}${this._err[1] ? `<small>${esc(this._err[1])}</small>` : ''}</div>`;
      } else if (!d) {
        body = `<div class="muted">${esc(L('Laden…'))}</div>`;
      } else {
        const st = Math.min(2, Math.max(0, +d.status || 0));
        const time = d.at ? new Date(d.at).toLocaleTimeString(this._loc(), { hour: '2-digit', minute: '2-digit' }) : '';
        const stale = this._err || (this._at && Date.now() - this._at > 3 * Math.max(15, +c.refresh || 60) * 1000);
        body += `<div class="hd" data-go="" style="--rz-st:${ST_COLOR[st]}">
            <div class="orb"><svg viewBox="0 0 24 24">${ICON[st]}</svg></div>
            <div class="hd-t"><div class="hd-k">${esc(c.title || L('Netwerk'))}</div><div class="hd-v">${esc(L(['Alles in orde', 'Let op', 'Storing'][st]))}</div></div>
            <div class="live${stale ? ' stale' : ''}"><i></i>${esc(L('live'))} · ${esc(time)}</div>
          </div>`;
        if (c.show_problems && d.problems?.length) {
          body += `<ul class="why">${d.problems.slice(0, 4).map((p) => `<li style="--rz-st:${ST_COLOR[p.status]}">${esc(p.text)}</li>`).join('')}${d.problems.length > 4 ? `<li class="more" style="--rz-st:var(--secondary-text-color)">+${d.problems.length - 4} ${esc(L('meer'))}</li>` : ''}</ul>`;
        }
        const tiles = [];
        const i = d.internet;
        if (c.show_internet && i) {
          const pct = i.down && i.plan ? Math.min(100, Math.round(100 * i.down / i.plan)) : 0;
          tiles.push(`<div class="t" data-go="${esc(i.host ? `dashboard?server=${encodeURIComponent(i.host)}` : 'dashboard')}" style="--rz-st:${ST_COLOR[i.status || 0]};--rz-bar:${ST_COLOR[i.status || 0]}">
            <div class="k">${esc(L('Internet'))}</div>
            ${i.down === null && i.up === null ? `<div class="v"><small>${esc(L('Geen meting'))}</small></div>` : `<div class="v">↓ ${this._mbit(i.down)} <small>↑ ${this._mbit(i.up)} Mbit/s</small></div>`}
            <div class="s">${i.lat !== null && i.lat !== undefined ? `${this._num(i.lat, 1)} ms` : ''}${i.plan && i.down !== null ? ` · ${pct}% ${esc(L('van abonnement'))}` : ''}</div>
            ${i.plan ? `<div class="bar"><b style="width:${pct}%"></b></div>` : ''}</div>`);
        }
        const m = d.month;
        if (c.show_month && m) {
          const ms = m.cap && m.used >= m.cap ? 2 : (m.cap && m.forecast > m.cap ? 1 : 0);
          tiles.push(`<div class="t" data-go="net/list" style="--rz-st:${ms ? ST_COLOR[ms] : 'transparent'};--rz-bar:${ST_COLOR[ms]}">
            <div class="k">${esc(L('Deze maand'))}</div>
            <div class="v">${this._bytes(m.used)}${m.cap ? ` <small>/ ${this._bytes(m.cap)}</small>` : ''}</div>
            <div class="s">${m.forecast ? `${esc(L('verwacht'))} ${this._bytes(m.forecast)}` : ''}</div>
            ${m.cap ? `<div class="bar"><b style="width:${Math.min(100, m.pct || 0)}%"></b></div>` : ''}</div>`);
        }
        const v = d.devices;
        if (c.show_devices && v) {
          tiles.push(`<div class="t" data-go="${v.new ? 'net/disc' : 'net/list'}" style="--rz-st:${v.new ? ST_COLOR[1] : 'transparent'}">
            <div class="k">${esc(L('Apparaten'))}</div>
            <div class="v">${this._num(v.online)} <small>/ ${this._num(v.total)} ${esc(L('online'))}</small></div>
            <div class="s">${v.new ? `${this._num(v.new)} ${esc(L('nieuw'))}` : (v.today ? `${this._bytes(v.today)} ${esc(L('vandaag'))}` : '')}</div></div>`);
        }
        const x = d.ids;
        if (c.show_ids && x) {
          const xs = x.inc_crit ? 2 : (x.inc ? 1 : 0);
          tiles.push(`<div class="t" data-go="ids" style="--rz-st:${xs ? ST_COLOR[xs] : 'transparent'}">
            <div class="k">${esc(L('IDS'))}</div>
            <div class="v">${this._num(x.inc)} <small>${esc(L('open'))}</small></div>
            <div class="s">${x.inc_crit ? `${this._num(x.inc_crit)} ${esc(L('kritiek'))} · ` : ''}${this._num(x.blk_today)} ${esc(L('geblokkeerd vandaag'))}</div></div>`);
        }
        if (tiles.length) body += `<div class="g">${tiles.join('')}</div>`;
        if (c.show_paths && d.paths?.length) {
          body += `<div class="sec"><div class="sec-h">${esc(L('Netwerkpaden'))}</div>${d.paths.slice(0, 6).map((p) => `
            <div class="row" data-go="dashboard/paths" style="--rz-st:${ST_COLOR[p.status] || ST_COLOR[3]}"><i class="dot"></i>
              <div class="nm">${esc(p.host)} <span>· ${esc(p.name)}</span></div>
              <div class="val">${p.ms !== null && p.ms !== undefined ? `${this._num(p.ms, 1)} ms` : '–'}${p.loss ? ` · ${p.loss}% ${esc(L('verlies'))}` : ''}</div></div>`).join('')}</div>`;
        }
        const top = v?.top || [];
        if (c.show_top && top.length) {
          const max = Math.max(...top.map((t) => t.bytes), 1);
          body += `<div class="sec"><div class="sec-h">${esc(L('Top verkeer vandaag'))}</div>${top.map((t) => `
            <div class="row" data-go="net/list"><div class="nm">${esc(t.name)}</div>
              <div class="tb"><b style="width:${Math.round(100 * t.bytes / max)}%"></b></div><div class="val">${this._bytes(t.bytes)}</div></div>`).join('')}</div>`;
        }
        if (c.show_servers && d.servers?.length) {
          const max = Math.max(1, +c.max_servers || 8);
          const bar = (v, lbl) => {
            const col = v === null || v === undefined ? '' : (v >= 90 ? ST_COLOR[2] : (v >= 75 ? ST_COLOR[1] : 'var(--primary-color)'));
            return `<span title="${esc(lbl)}${v !== null && v !== undefined ? ` ${this._num(v)}%` : ''}">${esc(lbl)}<i style="--rz-bar:${col}"><b style="width:${v === null || v === undefined ? 0 : Math.min(100, v)}%"></b></i></span>`;
          };
          const rows = d.servers.slice(0, max).map((s) => {
            const st = s.quiet_min !== null && s.quiet_min !== undefined ? 2 : s.status;
            const sub = s.quiet_min !== null && s.quiet_min !== undefined ? `<small class="w">${esc(L('agent stil {d}').replace('{d}', this._dur(s.quiet_min)))}</small>`
              : s.maintenance ? `<small>${esc(L('onderhoud'))}</small>`
              : s.problems ? `<small class="w">${esc(s.problems === 1 ? L('1 probleem') : L('{n} problemen').replace('{n}', this._num(s.problems)))}</small>` : '';
            const has = s.cpu !== null || s.ram !== null || s.disk !== null;
            return `<div class="row srv" data-go="dashboard?server=${esc(encodeURIComponent(s.name))}" style="--rz-st:${ST_COLOR[st] || ST_COLOR[3]}"><i class="dot"></i>
              <div class="nm">${esc(s.name)}${sub}</div>
              ${has ? `<div class="mm">${bar(s.cpu, 'CPU')}${bar(s.ram, 'RAM')}${bar(s.disk, L('Disk'))}</div>` : '<div></div>'}</div>`;
          }).join('');
          const rest = d.servers.length - max;
          body += `<div class="sec"><div class="sec-h">${esc(L('Servers'))}</div>${rows}${rest > 0 ? `<div class="row more" data-go="dashboard">+${rest} ${esc(L('meer'))}</div>` : ''}</div>`;
        }
        if (this._err) body += `<div class="sec err">${esc(this._err[0])}${this._err[1] ? `<small>${esc(this._err[1])}</small>` : ''}</div>`;
      }
      this.shadowRoot.innerHTML = `<style>${css}</style><ha-card>${body}</ha-card>`;
      this.shadowRoot.querySelectorAll('[data-go]').forEach((el) => el.addEventListener('click', () => this._open(el.dataset.go)));
    }
  }

  if (!customElements.get('rzmon-network-card')) {
    customElements.define('rzmon-network-card', RzMonNetworkCard);
    window.customCards = window.customCards || [];
    window.customCards.push({ type: 'rzmon-network-card', name: 'RzSense netwerk', description: 'Netwerk in het kort uit RzSense: oordeel, internet, verbruik, apparaten, paden en IDS.', preview: false, documentationURL: 'https://github.com/rembrand20/rzmon' });
    console.info(`%c RzSense netwerkkaart %c ${VERSION} `, 'background:#2563eb;color:#fff;border-radius:3px 0 0 3px;padding:2px 4px', 'background:#1e293b;color:#fff;border-radius:0 3px 3px 0;padding:2px 4px');
  }
})();
