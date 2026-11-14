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

/**
 * Alle tabellen nalopen: past hij niet, dan kaartjes.
 * 1.263.6: nooit meer heen en weer. Een tabel die al kaartjes is, blijft dat (alleen nieuwe rijen krijgen hun labels);
 * alleen een gewone tabel wordt gemeten. Pas bij draaien of breder maken van het scherm (full) alles opnieuw. Eerst zette
 * dit bij elke live-update alle kaartjes terug naar een brede tabel om te meten: de pagina versprong en klikken mislukte.
 */
function mtScan(full) {
  const narrow = document.documentElement.clientWidth <= MT_MAX;
  const tables = [...document.querySelectorAll('table')].filter((t) => !t.closest('.no-stack') && t.getClientRects().length);
  if (!narrow) { tables.forEach((t) => { if (t.classList.contains('mt-stack')) t.classList.remove('mt-stack'); }); return; }
  if (full) tables.forEach((t) => { if (t.classList.contains('mt-stack')) t.classList.remove('mt-stack'); });
  const limit = document.documentElement.clientWidth - 8;
  // Eerst alles meten (één keer opmaak), daarna pas omzetten
  const todo = [];
  for (const t of tables) {
    const labels = mtLabels(t);
    if (!labels || labels.length < 2) continue;
    if (t.classList.contains('mt-stack')) { todo.push([t, labels]); continue; }   // blijft kaartjes; labels van nieuwe rijen bijwerken
    const r = t.getBoundingClientRect(), box = t.parentElement;
    if (r.width > Math.min(limit, box ? box.getBoundingClientRect().width + 2 : limit) || r.right > limit + 8) todo.push([t, labels]);
  }
  for (const [t, labels] of todo) { mtApply(t, labels); if (!t.classList.contains('mt-stack')) t.classList.add('mt-stack'); }
}

// Vóór het tekenen van het volgende beeld (requestAnimationFrame): een nieuwe brede tabel is meteen kaartjes, zonder flits
let mtFrame = 0, mtFull = false;
const mtLater = (full) => { mtFull = mtFull || full === true; if (!mtFrame) mtFrame = requestAnimationFrame(() => { mtFrame = 0; const f = mtFull; mtFull = false; mtScan(f); }); };
new MutationObserver((list) => {
  // Alleen reageren op nieuwe inhoud (niet op onze eigen data-label/class-wijzigingen)
  if (list.some((m) => m.type === 'childList')) mtLater();
}).observe(document.documentElement, { childList: true, subtree: true });
let mtW = document.documentElement.clientWidth;
window.addEventListener('resize', () => { const w = document.documentElement.clientWidth; if (w !== mtW) { mtW = w; mtLater(true); } });   // alleen bij een andere breedte (niet de adresbalk op iOS)
window.addEventListener('orientationchange', () => mtLater(true));
document.addEventListener('DOMContentLoaded', () => mtLater(true));
