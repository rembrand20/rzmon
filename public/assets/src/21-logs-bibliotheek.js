// ---------------------------------------------------------------- 1.250.0: bibliotheek met kant-en-klare vaste zoekacties
// Logs → Vaste zoekacties → 📚 Kies uit de bibliotheek: sjablonen in groepen, met of ze bij je servers passen,
// een proef op de laatste 24 uur, en vinkjes om er meerdere in één keer toe te voegen (drempel en ernst aan te passen).

const LL_SEV = () => [[0, 'info'], [1, 'warning'], [2, 'critical']];

async function lwLibrary() {
  let r;
  try { r = await api('log_library'); } catch (ex) { toast(ex.message, 'err'); return; }
  const tps = r.templates;
  const winTxt = (w) => lwWinText(w);
  const cond = (t) => (t.mode === 'none' ? L('geen treffer in {w}', { w: winTxt(t.win) }) : L('≥ {n} in {w}', { n: t.threshold, w: winTxt(t.win) }));
  const room = Math.max(0, r.max - r.count);
  const row = (t) => {
    const fit = t.added ? `<span class="ll-tag ll-added">✔ ${L('al toegevoegd')}</span>`
      : !t.fits ? `<span class="ll-tag ll-nofit">${L('bron niet gevonden op je servers')}</span>`
      : t.via_journal ? `<span class="ll-tag ll-journal" title="${esc(t.hosts.join(', '))}">${L('past via de journal')}</span>`
      : `<span class="ll-tag ll-fit" title="${esc(t.hosts.join(', '))}">✔ ${L('past bij {s}', { s: t.hosts.slice(0, 3).join(', ') + (t.hosts.length > 3 ? ' …' : '') })}</span>`;
    return `<div class="ll-row${t.added ? ' is-added' : ''}${t.fits ? '' : ' is-nofit'}" data-ll-row="${t.key}">
      <label class="ll-main"><input type="checkbox" data-ll="${t.key}" ${t.added ? 'disabled' : ''}>
        <span class="ll-txt"><b>${esc(t.name)}</b> <span class="lw-sev lw-sev${t.severity}">${LL_SEV()[t.severity][1]}</span>
          <span class="muted small">${esc(cond(t))}</span>
          <span class="ll-why small">${esc(t.why)}</span>
          <code class="lw-q">${esc(t.q)}</code>
          <span class="ll-meta">${fit} <span class="ll-proef small muted" data-ll-proef="${t.key}"></span></span></span></label>
      <div class="ll-set" hidden>
        ${t.edit ? `<label class="ll-q">${L('Zoektekst')} <input type="text" class="mono" data-ll-q value="${esc(t.q)}"></label>` : ''}
        ${t.mode === 'many' ? `<label>${L('Drempel')} <input type="number" min="1" max="100000" data-ll-n value="${t.threshold}"> <span class="muted small">${L('in {w}', { w: winTxt(t.win) })}</span></label>` : ''}
        <label>${L('Ernst')} <select data-ll-sev>${LL_SEV().map(([v, l]) => `<option value="${v}" ${v === t.severity ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
      </div></div>`;
  };
  const groups = Object.entries(r.groups).map(([g, label]) => {
    const list = tps.filter((t) => t.group === g).sort((a, b) => (+b.fits - +a.fits) || (+a.via_journal - +b.via_journal));
    return list.length ? `<section class="ll-grp"><h3>${esc(label)}</h3>${list.map(row).join('')}</section>` : '';
  }).join('');
  const { form } = modal({
    title: '📚 ' + L('Bibliotheek met zoekacties'), wide: true,
    body: `<div style="padding:1rem 1.2rem" class="ll-dlg">
      ${xpHtml(L('Kant-en-klare vaste zoekacties voor wat je meestal wilt weten. Vink aan wat je wilt en voeg ze in één keer toe.'), L('<b>Wat het doet:</b> elk sjabloon is een gewone vaste zoekactie, met de zoekopdracht, de bronnen, de drempel en de ernst al ingevuld. Na het toevoegen kun je ze aanpassen zoals elke andere. <b>Past bij je servers:</b> RzSense kijkt of je de bijbehorende logs verzamelt. <i>Past via de journal</i> betekent dat alleen de journal (met alle meldingen van het systeem) erbij past; dat werkt als het programma naar de journal schrijft. <b>Proef:</b> hoe vaak het sjabloon de laatste 24 uur gemeld had, zodat je vooraf ziet of het rustig blijft. <b>Goed om te weten:</b> elke zoekactie meldt per server apart, met herstel als het weer rustig is.'))}
      <div class="ll-top"><span class="muted small">${L('{n} van {m} vaste zoekacties in gebruik', { n: r.count, m: r.max })}${r.sources_total ? '' : ' · <span class="warn-txt">' + L('nog geen logbronnen: zet eerst logs aan bij een server (Agent Manager › Logs)') + '</span>'}</span>
        <span class="lp-sp"></span><button type="button" class="btn small-btn" data-ll-fit>${L('Alles wat past aanvinken')}</button></div>
      ${groups}
    </div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '＋ ' + L('Toevoegen'), cls: 'primary', submit: true }],
    onSubmit: async () => {
      const items = $$('[data-ll]:checked', form).map((cb) => {
        const rw = cb.closest('.ll-row');
        const n = $('[data-ll-n]', rw), sev = $('[data-ll-sev]', rw), q = $('[data-ll-q]', rw);
        return { key: cb.dataset.ll, ...(n ? { threshold: +n.value } : {}), severity: +sev.value, ...(q ? { q: q.value } : {}) };
      });
      if (!items.length) throw new Error(L('Vink minstens één zoekactie aan'));
      if (items.length > room) throw new Error(L('Er passen nog {n} vaste zoekacties bij (hooguit {m}). Vink er minder aan.', { n: room, m: r.max }));
      const res = await api('log_library_add', { items });
      toast(res.added.length === 1 ? L('1 vaste zoekactie toegevoegd') : L('{n} vaste zoekacties toegevoegd', { n: res.added.length }));
      if (res.skipped.length) toast(L('Overgeslagen: {s}', { s: res.skipped.map((x) => `${x.name} (${x.why})`).join(', ') }), 'err');
      if (typeof lwLoad === 'function') lwLoad();
    },
  });
  const btn = $('#modal-footer button[type=submit]');
  const count = () => {
    const n = $$('[data-ll]:checked', form).length;
    btn.textContent = '＋ ' + (n ? (n === 1 ? L('1 zoekactie toevoegen') : L('{n} zoekacties toevoegen', { n })) : L('Toevoegen'));
    btn.disabled = !n;
  };
  count();
  form.onchange = (e) => {
    const cb = e.target.closest('[data-ll]');
    if (cb) { $('.ll-set', cb.closest('.ll-row')).hidden = !cb.checked; count(); }
  };
  form.onclick = (e) => {
    if (!e.target.closest('[data-ll-fit]')) return;
    $$('[data-ll]:not(:disabled)', form).forEach((cb) => {
      const t = tps.find((x) => x.key === cb.dataset.ll);
      if (t && t.fits && !t.via_journal && !t.edit) { cb.checked = true; $('.ll-set', cb.closest('.ll-row')).hidden = false; }
    });
    count();
  };
  // Proef op de laatste 24 uur, één voor één (alleen wat past en nog niet toegevoegd is)
  const gen = modalGen;
  for (const t of tps.filter((x) => x.fits && !x.added)) {
    if (gen !== modalGen) return;   // venster dicht of een ander venster: stoppen
    const el = $(`[data-ll-proef="${t.key}"]`, form);
    if (!el) continue;
    el.textContent = L('proef…');
    try {
      const p = await api('log_watch_test', { name: t.name, q: t.q, mode: t.mode, threshold: t.threshold, win: t.win, severity: t.severity, rest: t.rest, per_host: 1, recover: 1 });
      if (gen !== modalGen) return;
      el.className = 'll-proef small ' + (p.count ? 'warn-txt' : 'good-txt');
      el.textContent = t.mode === 'none'
        ? (p.count ? L('zou nu melden') : L('✔ zou nu niet melden'))
        : (p.count ? L('zou {n}× gemeld hebben in 24 uur', { n: p.count }) : L('✔ 0 meldingen in 24 uur')) + (p.partial ? ' ' + L('(onvolledig)') : '');
    } catch (ex) { el.textContent = ''; }
  }
}
