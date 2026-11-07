/*
 * RzSense 1.201.0: laadanimaties ("de pols van je netwerk").
 *
 * - Bij het openen: RzSense in het midden, de servers eromheen. Een radar veegt rond; zodra de status binnen is lichten je
 *   eigen servers een voor een op in hun statuskleur en sturen ze een pakketje naar het midden. Daarna verdwijnt het scherm.
 * - Overal waar "Laden…" staat: een korte hartslag ervoor. Bij "Bezig…": een kleine radarboog.
 * Eén animatielus voor alles; stopt als er niets te tekenen is, pauzeert als het tabblad niet zichtbaar is.
 * Uit met Mijn account → Weergave → Laadanimatie, en vanzelf rustig (stilstaand) bij "minder beweging".
 */
(() => {
  'use strict';
  const body = document.body;
  if (!body) return;
  const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const off = () => body.classList.contains('no-loader');
  const root = document.documentElement;
  let colors = null, colorsAt = 0;
  const palette = () => {
    if (colors && Date.now() - colorsAt < 2000) return colors;
    const cs = getComputedStyle(root);
    const g = (v, d) => (cs.getPropertyValue(v).trim() || d);
    colors = { ok: g('--ok', '#2fbf71'), warn: g('--warn', '#f0a531'), crit: g('--crit', '#f25555'), unk: g('--unknown', '#8b96a5'), maint: g('--maint', '#8b7cf6'),
      primary: g('--primary', '#4f8cff'), teal: g('--lg-teal', '#2dd4bf'), ink: g('--text', '#e6edf6'), muted: g('--muted', '#93a1b8') };
    colorsAt = Date.now();
    return colors;
  };
  const rgba = (c, a) => {
    const m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(c);
    if (!m) return c;
    const h = m[1].length === 3 ? m[1].split('').map((x) => x + x).join('') : m[1];
    const n = parseInt(h, 16);
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
  };
  const fit = (cv) => {
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    const w = cv.clientWidth, h = cv.clientHeight;
    if (!w || !h) return null;
    if (cv.width !== Math.round(w * dpr) || cv.height !== Math.round(h * dpr)) { cv.width = Math.round(w * dpr); cv.height = Math.round(h * dpr); }
    const ctx = cv.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return [ctx, w, h];
  };
  // Hartslag: P-golf, QRS-piek, T-golf (ongeveer 1,2 slag per seconde)
  const beat = (t) => {
    const p = ((t * 1.2) % 1 + 1) % 1;
    const g = (c, w, a) => a * Math.exp(-((p - c) ** 2) / w);
    return g(0.18, 0.0012, 0.15) - g(0.34, 0.00008, 0.25) + g(0.37, 0.00012, 1) - g(0.40, 0.0001, 0.35) + g(0.62, 0.003, 0.3);
  };
  const STATUS_KEY = ['ok', 'warn', 'crit', 'unk', 'maint'];

  // ---------------------------------------------------------------- het openingsscherm
  const boot = document.getElementById('rz-boot');
  const bootCv = boot && boot.querySelector('canvas');
  const bootHead = boot && boot.querySelector('[data-boot-head]');
  const bootSub = boot && boot.querySelector('[data-boot-sub]');
  const t0 = performance.now();
  let nodes = Array.from({ length: 8 }, (_, i) => ({ name: '', st: 'unk', orbit: i % 2, a0: (i / 8) * Math.PI * 2 + (i % 2 ? 0.35 : 0), lit: 0 }));
  let readyAt = 0, total = 0, done = false, packets = [], pings = [];
  const label = (k, d) => ((window.I18N && window.I18N[k]) || k).replace(/\{(\w+)\}/g, (m, x) => (d && d[x] !== undefined ? d[x] : m));
  function bootFrame(now) {
    const f = fit(bootCv);
    if (!f) return;
    const [ctx, w, h] = f, C = palette();
    const t = (now - t0) / 1000;
    const cx = w / 2, cy = h * 0.44, R = Math.min(w * 0.34, h * 0.3, 260);
    ctx.clearRect(0, 0, w, h);
    // banen
    [R * 0.62, R].forEach((r, k) => {
      ctx.save(); ctx.translate(cx, cy); ctx.rotate(t * (k ? -0.05 : 0.08));
      ctx.setLineDash([2, 7]); ctx.strokeStyle = rgba(C.primary, 0.35); ctx.lineWidth = 1;
      ctx.beginPath(); ctx.arc(0, 0, r, 0, Math.PI * 2); ctx.stroke(); ctx.restore();
    });
    // radar
    const sweep = (t * 1.6) % (Math.PI * 2);
    if (ctx.createConicGradient) {
      const g = ctx.createConicGradient(sweep - Math.PI / 2.6, cx, cy);
      g.addColorStop(0, rgba(C.teal, 0)); g.addColorStop(0.11, rgba(C.teal, 0.25)); g.addColorStop(0.115, rgba(C.teal, 0)); g.addColorStop(1, rgba(C.teal, 0));
      ctx.fillStyle = g; ctx.beginPath(); ctx.arc(cx, cy, R * 1.12, 0, Math.PI * 2); ctx.fill();
    }
    ctx.strokeStyle = rgba(C.teal, 0.7); ctx.lineWidth = 1.5;
    ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(cx + Math.cos(sweep) * R * 1.12, cy + Math.sin(sweep) * R * 1.12); ctx.stroke();
    // servers: zodra de status er is, een voor een in hun eigen kleur
    let lit = 0;
    nodes.forEach((n, i) => {
      const r = n.orbit ? R : R * 0.62;
      const a = n.a0 + t * (n.orbit ? -0.05 : 0.08);
      const x = cx + Math.cos(a) * r, y = cy + Math.sin(a) * r;
      n.x = x; n.y = y;
      if (readyAt && !n.lit && now >= readyAt + (i / Math.max(1, nodes.length)) * 750) {
        n.lit = now; pings.push({ x, y, t: now, c: C[n.st] || C.unk });
        for (let k = 0; k < 2; k++) packets.push({ n, s: now + k * 140, c: C[n.st] || C.unk });
      }
      if (n.lit) lit++;
      const col = n.lit ? (C[n.st] || C.unk) : C.unk;
      ctx.strokeStyle = rgba(col, n.lit ? 0.35 : 0.12); ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(cx, cy); ctx.stroke();
      const pulse = n.lit && (n.st === 'crit' || n.st === 'warn') ? 1 + Math.sin(t * 6) * 0.25 : 1;
      ctx.shadowColor = col; ctx.shadowBlur = n.lit ? 18 : 0;
      ctx.fillStyle = col; ctx.beginPath(); ctx.arc(x, y, 6 * pulse, 0, Math.PI * 2); ctx.fill(); ctx.shadowBlur = 0;
      ctx.strokeStyle = rgba(col, 0.45); ctx.beginPath(); ctx.arc(x, y, 10.5, 0, Math.PI * 2); ctx.stroke();
      if (n.name && w > 520) {
        ctx.fillStyle = n.lit ? C.ink : C.muted; ctx.font = '600 12px system-ui, sans-serif'; ctx.textAlign = x < cx ? 'right' : 'left';
        ctx.fillText(n.name.length > 22 ? n.name.slice(0, 21) + '…' : n.name, x + (x < cx ? -16 : 16), y + 4);
      }
    });
    packets = packets.filter((p) => now - p.s < 850);
    packets.forEach((p) => {
      const k = (now - p.s) / 850;
      if (k < 0) return;
      const e = k * k * (3 - 2 * k);
      ctx.fillStyle = p.c; ctx.shadowColor = p.c; ctx.shadowBlur = 10;
      ctx.beginPath(); ctx.arc(p.n.x + (cx - p.n.x) * e, p.n.y + (cy - p.n.y) * e, 2.6, 0, Math.PI * 2); ctx.fill(); ctx.shadowBlur = 0;
    });
    pings = pings.filter((p) => now - p.t < 1300);
    pings.forEach((p) => {
      const k = (now - p.t) / 1300;
      ctx.strokeStyle = rgba(p.c, 1 - k); ctx.lineWidth = 2;
      ctx.beginPath(); ctx.arc(p.x, p.y, 10 + k * 32, 0, Math.PI * 2); ctx.stroke();
    });
    // kern met voortgangsring
    const bt = 1 + Math.max(0, Math.sin(t * 5.2)) ** 8 * 0.18;
    const cg = ctx.createRadialGradient(cx, cy, 0, cx, cy, 46 * bt);
    cg.addColorStop(0, rgba(C.primary, 0.95)); cg.addColorStop(0.55, rgba(C.teal, 0.45)); cg.addColorStop(1, rgba(C.teal, 0));
    ctx.fillStyle = cg; ctx.beginPath(); ctx.arc(cx, cy, 46 * bt, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#ffffff'; ctx.font = '800 15px system-ui, sans-serif'; ctx.textAlign = 'center'; ctx.fillText('Rz', cx, cy + 5);
    const prog = readyAt ? Math.min(1, 0.6 + 0.4 * (lit / Math.max(1, nodes.length))) : Math.min(0.6, t / 6);
    ctx.strokeStyle = rgba(C.muted, 0.25); ctx.lineWidth = 3; ctx.beginPath(); ctx.arc(cx, cy, 30, 0, Math.PI * 2); ctx.stroke();
    ctx.strokeStyle = C.teal; ctx.lineCap = 'round'; ctx.beginPath(); ctx.arc(cx, cy, 30, -Math.PI / 2, -Math.PI / 2 + prog * Math.PI * 2); ctx.stroke(); ctx.lineCap = 'butt';
    // hartslag onderaan
    const ey = h - 92;
    ctx.strokeStyle = C.teal; ctx.lineWidth = 2; ctx.shadowColor = C.teal; ctx.shadowBlur = 8; ctx.beginPath();
    for (let x = 0; x <= w; x += 2) { const v = beat(t - (w - x) / 160); const y = ey - v * 18; x ? ctx.lineTo(x, y) : ctx.moveTo(x, y); }
    ctx.stroke(); ctx.shadowBlur = 0;
    // tekst
    if (bootHead && bootSub) {
      if (!readyAt) {
        bootHead.textContent = t < 1.2 ? label('Verbinden met RzSense') : label('Servers antwoorden');
        bootSub.textContent = t > 8 ? label('Dit duurt langer dan normaal…') : '';
      } else {
        bootHead.textContent = lit >= nodes.length ? label('✓ Alles binnen') : label('Servers antwoorden');
        bootSub.textContent = label('{n} van {m} servers', { n: Math.round(total * lit / Math.max(1, nodes.length)), m: total });
      }
    }
    if (readyAt && !done && lit >= nodes.length && now - readyAt > 1000) hideBoot();
    if (!readyAt && now - t0 > 15000) hideBoot();   // nooit vast blijven hangen
  }
  function hideBoot() {
    if (done || !boot) return;
    done = true;
    boot.classList.add('out');
    setTimeout(() => { boot.remove(); }, 450);
  }
  /** De status is binnen: je eigen servers (hooguit 12, de ergste eerst) lichten op, daarna verdwijnt het scherm */
  function ready(hosts) {
    if (!boot || readyAt) return;
    const list = (hosts || []).slice();
    total = list.length;
    if (off() || reduce || !list.length) { readyAt = performance.now(); hideBoot(); return; }
    const rank = { crit: 0, warn: 1, unk: 2, maint: 3, ok: 4 };
    list.sort((a, b) => rank[STATUS_KEY[a.st] || 'unk'] - rank[STATUS_KEY[b.st] || 'unk']);
    const pick = list.slice(0, 12);
    nodes = pick.map((hh, i) => ({ name: String(hh.name || ''), st: STATUS_KEY[hh.st] || 'unk', orbit: i % 2, a0: (i / pick.length) * Math.PI * 2 + (i % 2 ? 0.35 : 0), lit: 0 }));
    readyAt = performance.now();
  }
  if (boot && (off() || reduce)) boot.remove();

  // ---------------------------------------------------------------- "Laden…" en "Bezig…" overal
  const LOAD_RE = /^(laden|loading)(…|\.\.\.)$/i, BUSY_RE = /^(bezig|busy|working)(…|\.\.\.)$/i;
  const upgrade = (el) => {
    if (el.dataset.rzl || el.closest('#rz-boot')) return;
    const txt = (el.textContent || '').trim();
    const kind = LOAD_RE.test(txt) ? 'ecg' : BUSY_RE.test(txt) ? 'radar' : null;
    if (!kind || el.children.length) return;
    el.dataset.rzl = kind;
    el.classList.add('rz-wait', 'rz-wait-' + kind);
    const cv = document.createElement('canvas');
    cv.className = 'rz-wait-cv';
    cv.setAttribute('aria-hidden', 'true');
    el.prepend(cv);
    kick();
  };
  const scan = (rootEl) => {
    if (off()) return;
    if (rootEl.nodeType === 1 && rootEl.matches('p, span, div, td, li, em, small, output, button') && !rootEl.children.length) upgrade(rootEl);
    if (rootEl.querySelectorAll) rootEl.querySelectorAll('p, span, div, td, li, em, small, output, button').forEach((el) => { if (!el.children.length && el.textContent.length < 14) upgrade(el); });
  };
  const mo = new MutationObserver((list) => {
    for (const m of list) {
      if (m.type === 'characterData') { const p = m.target.parentElement; if (p) { delete p.dataset.rzl; if (!LOAD_RE.test(p.textContent.trim()) && !BUSY_RE.test(p.textContent.trim())) p.classList.remove('rz-wait', 'rz-wait-ecg', 'rz-wait-radar'); else scan(p); } continue; }
      m.addedNodes.forEach((n) => { if (n.nodeType === 1) scan(n); else if (n.nodeType === 3 && n.parentElement) scan(n.parentElement); });
    }
  });
  const start = () => { scan(body); mo.observe(body, { childList: true, subtree: true, characterData: true }); };

  const mt0 = performance.now();
  function waitFrame(now) {
    const C = palette(), t = (now - mt0) / 1000;
    let any = false;
    document.querySelectorAll('canvas.rz-wait-cv').forEach((cv) => {
      const el = cv.parentElement;
      if (!el || !el.offsetParent) return;
      const kind = el.dataset.rzl;
      if (!kind || !(LOAD_RE.test(el.textContent.trim()) || BUSY_RE.test(el.textContent.trim()))) { cv.remove(); el.classList.remove('rz-wait', 'rz-wait-ecg', 'rz-wait-radar'); delete el.dataset.rzl; return; }
      const f = fit(cv);
      if (!f) return;
      any = true;
      const [c, w, h] = f;
      c.clearRect(0, 0, w, h);
      if (kind === 'ecg') {
        c.strokeStyle = C.teal; c.lineWidth = 1.8; c.shadowColor = C.teal; c.shadowBlur = 5; c.beginPath();
        for (let x = 0; x <= w; x += 1.5) { const v = beat(t - (w - x) / 120); const y = h / 2 - v * h * 0.42; x ? c.lineTo(x, y) : c.moveTo(x, y); }
        c.stroke(); c.shadowBlur = 0;
      } else {
        const cx = w / 2, cy = h / 2, r = Math.min(w, h) * 0.38;
        c.strokeStyle = rgba(C.muted, 0.3); c.lineWidth = 2; c.beginPath(); c.arc(cx, cy, r, 0, Math.PI * 2); c.stroke();
        const a = t * 4; c.strokeStyle = C.primary; c.lineCap = 'round'; c.beginPath(); c.arc(cx, cy, r, a, a + 1.4); c.stroke(); c.lineCap = 'butt';
        c.fillStyle = C.primary; c.beginPath(); c.arc(cx, cy, 1.5 + Math.abs(Math.sin(t * 4)) * 1.2, 0, Math.PI * 2); c.fill();
      }
    });
    return any;
  }

  // ---------------------------------------------------------------- 1.202.0: de pols in een pagina die lang laadt
  // attach(canvas, nodes, label): je servers (met hun bekende status) om het midden, met het label van de pagina erin.
  const panels = new Map();
  function attach(cv, list, lbl) {
    if (!cv || reduce) return;
    const pick = (list || []).slice(0, 10);
    panels.set(cv, { lbl: String(lbl || 'Rz').slice(0, 10), t0: performance.now(),
      nodes: pick.map((hh, i) => ({ name: String(hh.name || ''), st: STATUS_KEY[hh.st] || 'unk', orbit: i % 2, a0: (i / Math.max(1, pick.length)) * Math.PI * 2 + (i % 2 ? 0.35 : 0) })) });
    kick();
  }
  function detach(cv) { panels.delete(cv); }
  function panelFrame(now) {
    let any = false;
    panels.forEach((pn, cv) => {
      if (!cv.isConnected) { panels.delete(cv); return; }
      const f = fit(cv);
      if (!f) return;
      any = true;
      const [ctx, w, h] = f, C = palette(), t = (now - pn.t0) / 1000;
      const cx = w / 2, cy = h * 0.42, R = Math.min(w * 0.3, h * 0.3, 190);
      ctx.clearRect(0, 0, w, h);
      [R * 0.6, R].forEach((r, k) => {
        ctx.save(); ctx.translate(cx, cy); ctx.rotate(t * (k ? -0.05 : 0.08));
        ctx.setLineDash([2, 7]); ctx.strokeStyle = rgba(C.primary, 0.35); ctx.lineWidth = 1; ctx.beginPath(); ctx.arc(0, 0, r, 0, Math.PI * 2); ctx.stroke(); ctx.restore();
      });
      const sweep = (t * 1.6) % (Math.PI * 2);
      if (ctx.createConicGradient) {
        const g = ctx.createConicGradient(sweep - Math.PI / 2.6, cx, cy);
        g.addColorStop(0, rgba(C.teal, 0)); g.addColorStop(0.11, rgba(C.teal, 0.25)); g.addColorStop(0.115, rgba(C.teal, 0)); g.addColorStop(1, rgba(C.teal, 0));
        ctx.fillStyle = g; ctx.beginPath(); ctx.arc(cx, cy, R * 1.1, 0, Math.PI * 2); ctx.fill();
      }
      pn.nodes.forEach((n) => {
        const r = n.orbit ? R : R * 0.6, a = n.a0 + t * (n.orbit ? -0.05 : 0.08);
        const x = cx + Math.cos(a) * r, y = cy + Math.sin(a) * r, col = C[n.st] || C.unk;
        // een server licht op als de radar langskomt
        const d = Math.abs(((a - sweep) % (Math.PI * 2) + Math.PI * 3) % (Math.PI * 2) - Math.PI);
        const glow = Math.max(0, 1 - d / 0.9);
        ctx.strokeStyle = rgba(col, 0.12 + glow * 0.3); ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(cx, cy); ctx.stroke();
        ctx.shadowColor = col; ctx.shadowBlur = 6 + glow * 14; ctx.fillStyle = col; ctx.beginPath(); ctx.arc(x, y, 5 + glow * 1.5, 0, Math.PI * 2); ctx.fill(); ctx.shadowBlur = 0;
        if (n.name && w > 460) {
          ctx.fillStyle = C.ink; ctx.globalAlpha = 0.6 + glow * 0.4; ctx.font = '600 11px system-ui, sans-serif'; ctx.textAlign = x < cx ? 'right' : 'left';
          ctx.fillText(n.name.length > 20 ? n.name.slice(0, 19) + '…' : n.name, x + (x < cx ? -12 : 12), y + 4); ctx.globalAlpha = 1;
        }
      });
      const bt = 1 + Math.max(0, Math.sin(t * 5.2)) ** 8 * 0.16;
      const cg = ctx.createRadialGradient(cx, cy, 0, cx, cy, 36 * bt);
      cg.addColorStop(0, rgba(C.primary, 0.95)); cg.addColorStop(0.6, rgba(C.teal, 0.4)); cg.addColorStop(1, rgba(C.teal, 0));
      ctx.fillStyle = cg; ctx.beginPath(); ctx.arc(cx, cy, 36 * bt, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = '#ffffff'; ctx.font = '800 12px system-ui, sans-serif'; ctx.textAlign = 'center'; ctx.fillText(pn.lbl, cx, cy + 4);
    });
    return any;
  }

  // ---------------------------------------------------------------- één lus
  let running = false;
  function loop(now) {
    if (document.hidden) { running = false; return; }
    const bootOn = !!(boot && !done && boot.isConnected);
    if (bootOn) bootFrame(now);
    const waits = off() ? false : waitFrame(now);
    const pans = panels.size ? panelFrame(now) : false;
    if ((bootOn || waits || pans) && !reduce) requestAnimationFrame(loop); else running = false;
  }
  function kick() {
    if (running) return;
    running = true;
    requestAnimationFrame(loop);
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) kick(); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  kick();
  window.RzPulse = { ready, hide: hideBoot, kick, attach, detach, refreshColors: () => { colorsAt = 0; } };
})();
