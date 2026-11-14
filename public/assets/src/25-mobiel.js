// ---------------------------------------------------------------- 1.261.0: mobielvriendelijk
// Op een telefoon (smaller dan 700 px) worden tabellen die niet passen kaartjes: elke rij een kaartje, elke cel een regel
// met de kolomnaam ervoor (uit de kop van de tabel). Een tabel die wel past blijft een tabel. Werkt vanzelf voor elke tabel,
// ook als een pagina zichzelf opnieuw tekent. Niet stapelen: class "no-stack" op de tabel (of een ouder).

const MT_MAX = 700;

/** Kolomnamen per kolomnummer (rekening houdend met colspan in de kop) */
function mtLabels(tbl) {
  const head = tbl.tHead && tbl.tHead.rows[tbl.tHead.rows.length - 1];
  if (!head) {   // geen kop: kaartjes zonder labels
    const n = Math.max(0, ...[...tbl.rows].slice(0, 5).map((r) => r.cells.length));
    return n >= 2 ? Array(n).fill('') : null;
  }
  const out = [];
  for (const th of head.cells) {
    // Een kop met een knop of vinkje (bv. "Alles invullen") is geen label: die blijft boven de kaartjes staan
    const txt = th.querySelector('button, input, select, a') ? '' : th.textContent.replace(/\s+/g, ' ').trim();
    for (let i = 0; i < (th.colSpan || 1); i++) out.push(txt);
  }
  return out;
}

function mtApply(tbl, labels) {
  for (const tb of tbl.tBodies) {
    for (const tr of tb.rows) {
      let col = 0;
      for (const td of tr.cells) {
        const span = td.colSpan || 1;
        // Een cel over (bijna) de hele breedte is een detailregel: zonder label, volle breedte
        const lab = span >= labels.length - 1 && labels.length > 2 ? '' : (labels[col] || '');
        if (td.dataset.label !== lab) td.dataset.label = lab;
        col += span;
      }
    }
  }
}

/** Alle tabellen nalopen: past hij niet, dan kaartjes */
function mtScan() {
  const narrow = document.documentElement.clientWidth <= MT_MAX;
  const tables = [...document.querySelectorAll('table')].filter((t) => !t.closest('.no-stack') && t.getClientRects().length);
  if (!narrow) { tables.forEach((t) => t.classList.remove('mt-stack')); return; }
  const limit = document.documentElement.clientWidth - 8;
  // Eerst alles terug naar een gewone tabel en meten, daarna pas omzetten (geen heen-en-weer per tabel)
  const cand = tables.map((t) => ({ t, labels: mtLabels(t), was: t.classList.contains('mt-stack') })).filter((x) => x.labels && x.labels.length >= 2);
  cand.forEach((x) => x.t.classList.remove('mt-stack'));
  const wide = cand.filter((x) => {
    const box = x.t.parentElement;
    return x.t.getBoundingClientRect().width > Math.min(limit, box ? box.getBoundingClientRect().width + 2 : limit) || x.t.getBoundingClientRect().right > limit + 8;
  });
  for (const x of wide) { mtApply(x.t, x.labels); x.t.classList.add('mt-stack'); }
}

let mtTimer = 0;
const mtLater = () => { clearTimeout(mtTimer); mtTimer = setTimeout(mtScan, 120); };
new MutationObserver((list) => {
  // Alleen reageren op nieuwe inhoud (niet op onze eigen data-label/class-wijzigingen)
  if (list.some((m) => m.type === 'childList')) mtLater();
}).observe(document.documentElement, { childList: true, subtree: true });
window.addEventListener('resize', mtLater);
window.addEventListener('orientationchange', mtLater);
document.addEventListener('DOMContentLoaded', mtLater);
