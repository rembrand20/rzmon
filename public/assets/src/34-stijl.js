// ---------------------------------------------------------------- 1.312.0: de schone stijl (cs2) voor instellingen
// Deelt een instellingenkaart rustig in, zonder de inhoud te veranderen: een kop met een klein label (het onderwerp),
// de titel met aan/uit en de hoofdschakelaar rechts; de uitleg als notitie; velden naast elkaar; Geavanceerd en de
// actieknop in een vaste voetregel. Bovenaan een onderdeel met drie of meer schakelbare kaarten een overzicht.
// Er verhuizen alleen elementen binnen hun kaart (namen en waarden blijven), dus opslaan werkt zoals altijd.
// Per onderdeel aan te zetten (CS2_SECS); de thema's (Standaard, @S, Liquid Glass, Neo) kleuren het zelf in.

const CS2_SECS = ['channels', 'rules', 'idspush', 'sysinfo', 'reports'];
const CS2_EYEBROW = () => ({
  pushover: L('Push · telefoon'), ntfy: L('Push · telefoon'), webpush: L('Push · zonder app'), email: L('E-mail'), webhook: L('Koppeling · automatisering'),
  chat: L('Chat · team'), chain: L('Test'), confirm: L('Bevestigen'), notify: L('Regels'), alarm: L('Alarm'), quiet: L('Rust'),
  idspush: L('IDS'), sysinfo: L('RzSense zelf'), reports: L('Overzichten'),
});

/** De hoofdschakelaar van een kaart: de eerste aan/uit-schakelaar (naam op _enabled) direct na de titel en de uitleg */
function cs2Main(card) {
  let el = card.querySelector(':scope > h2');
  if (!el) return null;
  el = el.nextElementSibling;
  while (el && el.classList.contains('xp')) el = el.nextElementSibling;
  const inp = el && el.matches('label.switch') && el.querySelector(':scope > input[type="checkbox"]');
  return inp && /_enabled$/.test(inp.name) ? el : null;
}

/** Eén kaart indelen (één keer) */
function cs2Card(card) {
  if (card.dataset.cs2) return;
  const h2 = card.querySelector(':scope > h2');
  if (!h2) return;
  card.dataset.cs2 = '1';
  card.classList.add('cs2');
  // Kop: label, titel (zonder emoji vooraan), status en schakelaar
  const t = h2.firstChild;
  if (t && t.nodeType === 3) {
    const m = t.nodeValue.match(/^\s*(\p{Extended_Pictographic}[️‍\p{Extended_Pictographic}]*)\s+/u);
    if (m) { const s = document.createElement('span'); s.className = 'cs2-emo'; s.setAttribute('aria-hidden', 'true'); s.textContent = m[1] + ' '; t.nodeValue = t.nodeValue.slice(m[0].length); h2.prepend(s); }
  }
  const main = cs2Main(card);
  const head = document.createElement('div');
  head.className = 'cs2-h';
  const tt = document.createElement('div');
  tt.className = 'cs2-ht';
  const eb = CS2_EYEBROW()[card.dataset.sk];
  if (eb) { const e = document.createElement('span'); e.className = 'eyebrow'; e.textContent = eb; tt.append(e); }
  h2.before(head);
  tt.append(h2);
  head.append(tt);
  if (main) {
    const pill = document.createElement('span');
    pill.className = 'cs2-pill';
    h2.append(' ', pill);
    // De tekst van de schakelaar blijft voor schermlezers, maar is niet te zien (de titel zegt het al)
    [...main.childNodes].forEach((n) => {
      if (n.nodeType === 1 && (n.matches('input') || (n.matches('span') && !n.textContent.trim()))) return;
      if (n.nodeType === 3 && !n.nodeValue.trim()) return;
      const w = document.createElement('span'); w.className = 'cs2-sr'; n.before(w); w.append(n);
    });
    main.classList.add('cs2-hsw');
    head.append(main);
  }
  // Velden naast elkaar: twee of meer gewone velden achter elkaar
  const plain = (el) => el && el.tagName === 'LABEL' && !el.classList.contains('switch') && el.querySelector('input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]), select') && !el.querySelector('textarea');
  let run = [];
  const flush = () => { if (run.length >= 2) { const g = document.createElement('div'); g.className = 'cs2-grid'; run[0].before(g); g.append(...run); } run = []; };
  [...card.children].forEach((el) => { if (plain(el)) run.push(el); else flush(); });
  flush();
  // Voetregel: Geavanceerd en de actieknoppen achteraan
  const tail = [];
  for (let el = card.lastElementChild; el && (el.matches('button.btn, details.adv') || el.classList.contains('btn-row')); el = el.previousElementSibling) tail.unshift(el);
  if (tail.length && tail.some((el) => !el.matches('details'))) {
    const f = document.createElement('div');
    f.className = 'cs2-f';
    card.append(f);
    f.append(...tail);
  }
}

/** Een onderdeel openen: de kaarten indelen en het overzicht tonen */
function cs2Apply(sec) {
  const on = CS2_SECS.includes(sec);
  document.getElementById('tab-settings')?.classList.toggle('cs2-on', on);
  let ov = $('#cs2-ov');
  if (!on) { if (ov) ov.hidden = true; return; }
  const cards = $$('#tab-settings [data-sk]').filter((c) => !c.classList.contains('set-off'));
  cards.forEach(cs2Card);
  const sw = cards.filter((c) => c.querySelector(':scope > .cs2-h > .cs2-hsw'));
  if (!ov) {
    ov = document.createElement('div');
    ov.id = 'cs2-ov'; ov.className = 'cs2-ov'; ov.setAttribute('aria-label', L('Overzicht'));
    $('#set-attn')?.after(ov);
    ov.addEventListener('click', (e) => {
      const a = e.target.closest('[data-cs2-go]');
      if (!a) return;
      const c = $(`#tab-settings [data-sk="${a.dataset.cs2Go}"]`);
      if (c) { c.scrollIntoView({ behavior: afMotion(null) ? 'smooth' : 'auto', block: 'start' }); c.classList.remove('live-pulse'); void c.offsetWidth; c.classList.add('live-pulse'); }
    });
  }
  ov.hidden = sw.length < 3;
  ov.dataset.sks = sw.map((c) => c.dataset.sk).join(',');
  cs2Sync();
}

/** Aan/uit bijwerken in de koppen en het overzicht */
function cs2Sync() {
  $$('#tab-settings .cs2 > .cs2-h').forEach((h) => {
    const inp = h.querySelector('.cs2-hsw > input'), pill = h.querySelector('.cs2-pill');
    if (!inp || !pill) return;
    pill.textContent = inp.checked ? L('aan') : L('uit');
    pill.classList.toggle('on', inp.checked);
  });
  const ov = $('#cs2-ov');
  if (!ov || ov.hidden) return;
  ov.innerHTML = (ov.dataset.sks || '').split(',').filter(Boolean).map((sk) => {
    const c = $(`#tab-settings [data-sk="${sk}"]`);
    if (!c) return '';
    const inp = c.querySelector(':scope > .cs2-h .cs2-hsw > input');
    const h2 = c.querySelector('h2');
    const name = (h2 ? [...h2.childNodes].filter((n) => n.nodeType === 3).map((n) => n.nodeValue).join('').trim() : '') || sk;
    const on = !!(inp && inp.checked);
    return `<button type="button" class="cs2-ovi" data-cs2-go="${esc(sk)}"><b>${esc(name)}</b><small><span class="cs2-dot ${on ? 'on' : ''}"></span>${on ? L('aan') : L('uit')}</small></button>`;
  }).join('');
}
document.addEventListener('change', (e) => { if (e.target.closest && e.target.closest('#tab-settings .cs2-hsw')) cs2Sync(); });
