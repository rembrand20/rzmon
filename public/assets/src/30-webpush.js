// ---------------------------------------------------------------- 📱 Web Push (1.285.0)
// Meldingen rechtstreeks op telefoon of computer. Mijn account: dit apparaat aanmelden en je eigen apparaten.
// Instellingen → Kanalen: de hoofdschakelaar en alle apparaten (beheerder). De service worker staat in /sw.js.

const WP_IOS = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const wpStandalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

/** Waarom Web Push in deze browser (nog) niet kan, of null */
function wpBlocker() {
  if (!window.isSecureContext) return L('Web Push werkt alleen via https met een echt certificaat, bijvoorbeeld via SWAG. Open RzSense via dat adres.');
  if (WP_IOS && !wpStandalone()) return L('Op een iPhone of iPad: tik op Deel (□↑) → <b>Zet op beginscherm</b>, open RzSense vanaf het beginscherm en meld je daar aan. Nodig: iOS 16.4 of nieuwer.');
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return L('Deze browser kan geen pushmeldingen ontvangen.');
  if (Notification.permission === 'denied') return L('Meldingen voor RzSense zijn geblokkeerd in deze browser. Zet ze aan bij de site-instellingen (het slotje naast het adres) en probeer het opnieuw.');
  return null;
}

/** Een naam voor dit apparaat, bv. "iPhone · Safari" */
function wpGuessName() {
  const ua = navigator.userAgent;
  const dev = /iPhone/.test(ua) ? 'iPhone' : /iPad/.test(ua) || WP_IOS ? 'iPad' : /Android/.test(ua) ? 'Android' : /Windows/.test(ua) ? 'Windows' : /Mac OS X/.test(ua) ? 'Mac' : /Linux/.test(ua) ? 'Linux' : L('Apparaat');
  const br = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : '';
  return br ? `${dev} · ${br}` : dev;
}

async function wpRegistration() {
  if (!('serviceWorker' in navigator)) return null;
  return (await navigator.serviceWorker.getRegistration('./')) || null;
}

/** Het abonnement van deze browser (of null) */
async function wpCurrent() {
  try {
    const reg = await wpRegistration();
    return reg ? await reg.pushManager.getSubscription() : null;
  } catch (e) { return null; }
}

function wpKeyBytes(b64) {
  const s = atob((b64 + '==='.slice((b64.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(s, (c) => c.charCodeAt(0));
}

async function wpSubscribe(name) {
  if (Notification.permission !== 'granted' && (await Notification.requestPermission()) !== 'granted') throw new Error(L('Je hebt meldingen niet toegestaan.'));
  const { key } = await api('webpush_key', {});
  const reg = await navigator.serviceWorker.register('sw.js', { scope: './' });
  await navigator.serviceWorker.ready;
  let sub = await reg.pushManager.getSubscription();
  // Andere sleutel van RzSense (bv. na herstel van een back-up)? Dan opnieuw aanmelden
  if (sub && sub.options && sub.options.applicationServerKey) {
    const cur = new Uint8Array(sub.options.applicationServerKey), want = wpKeyBytes(key);
    if (cur.length !== want.length || cur.some((v, i) => v !== want[i])) { await sub.unsubscribe(); sub = null; }
  }
  // De browser vraagt een adres aan bij zijn pushdienst; antwoordt die niet (bv. geblokkeerd netwerk), dan na 30 s een duidelijke fout
  sub = sub || await Promise.race([reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: wpKeyBytes(key) }),
    new Promise((_, no) => setTimeout(() => no(new Error(L('De pushdienst van deze browser antwoordt niet. Staat het netwerk verbindingen naar Google, Apple of Mozilla toe?'))), 30000))]);
  await api('webpush_subscribe', { sub: sub.toJSON(), name });
}

async function wpUnsubscribe() {
  const sub = await wpCurrent();
  if (!sub) return;
  try { await api('webpush_unsubscribe', { endpoint: sub.endpoint }); } finally { await sub.unsubscribe(); }
}

/** Lijst met apparaten; all = alle gebruikers (beheerder) */
function wpList(devs, all) {
  if (!devs.length) return `<p class="muted small">${all ? L('Nog geen apparaten aangemeld. Elke gebruiker meldt zijn eigen telefoon aan bij <b>Mijn account</b>.') : L('Nog geen apparaten aangemeld.')}</p>`;
  return `<table class="vuln-table small wp-list"><thead><tr>${all ? `<th>${L('Gebruiker')}</th>` : ''}<th>${L('Apparaat')}</th><th>${L('Dienst')}</th><th>${L('Laatst gelukt')}</th><th></th></tr></thead><tbody>${devs.map((d) => `<tr>
    ${all ? `<td>${esc(d.user)}</td>` : ''}<td>${esc(d.name || '#' + d.id)}${d.this ? ` <span class="u-tag ok">${L('dit apparaat')}</span>` : ''}${d.last_error ? `<br><span class="warn-txt small">⚠ ${esc(d.last_error)}</span>` : ''}</td>
    <td>${esc(d.service)}</td><td class="muted small">${d.last_ok ? esc(ago(d.last_ok)) : L('nog niet')}</td>
    <td class="nowrap"><button type="button" class="btn small-btn" data-wp-test="${d.id}">🧪 ${L('Test')}</button> <button type="button" class="btn small-btn danger" data-wp-del="${d.id}" title="${esc(L('Verwijderen'))}">✕</button></td></tr>`).join('')}</tbody></table>`;
}

function wpListEvents(box, after) {
  box.querySelectorAll('[data-wp-test]').forEach((b) => (b.onclick = async () => {
    b.disabled = true;
    try { await api('webpush_test', { id: +b.dataset.wpTest }); toast(L('Testbericht verstuurd') + ' 📱'); } catch (ex) { toast(ex.message, 'err'); }
    b.disabled = false;
    after();
  }));
  box.querySelectorAll('[data-wp-del]').forEach((b) => (b.onclick = async () => {
    try {
      const sub = await wpCurrent();
      await api('webpush_delete', { id: +b.dataset.wpDel });
      // Was het dit apparaat? Dan ook in de browser afmelden
      if (sub && b.closest('tr').querySelector('.u-tag.ok')) await sub.unsubscribe();
      toast(L('Apparaat verwijderd'));
    } catch (ex) { toast(ex.message, 'err'); }
    after();
  }));
}

/** Mijn account: dit apparaat en je eigen apparaten */
async function wpAccount() {
  const box = $('#acct-push-body');
  if (!box) return;
  const block = wpBlocker();
  const sub = block ? null : await wpCurrent();
  let r;
  try { r = await api('webpush_devices', { endpoint: sub ? sub.endpoint : '' }); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const here = sub && r.devices.some((d) => d.this);
  box.innerHTML = `${!r.enabled ? `<p class="warn-txt small">⚠ ${L('Een beheerder heeft Web Push uitgezet (Instellingen → Kanalen). Je kunt je apparaat wel aanmelden.')}</p>` : ''}
    ${block ? `<p class="muted small">${block}</p>` : here
      ? `<p><span class="good-txt">✔ ${L('Dit apparaat krijgt meldingen')}</span></p><div class="btn-row"><button type="button" class="btn" id="wp-off">🔕 ${L('Dit apparaat afmelden')}</button></div>`
      : `<label>${L('Naam van dit apparaat')}<input id="wp-name" maxlength="100" value="${esc(wpGuessName())}"></label>
         <div class="btn-row"><button type="button" class="btn primary" id="wp-on">🔔 ${L('Meldingen op dit apparaat aanzetten')}</button></div>`}
    <h3 class="small" style="margin-top:1rem">${L('Je apparaten')}</h3>${wpList(r.devices, false)}`;
  if ($('#wp-on')) $('#wp-on').onclick = async (e) => {
    e.target.disabled = true;
    try { await wpSubscribe($('#wp-name').value.trim() || wpGuessName()); toast(L('Dit apparaat krijgt nu meldingen') + ' 🔔'); } catch (ex) { toast(ex.message, 'err'); }
    wpAccount();
  };
  if ($('#wp-off')) $('#wp-off').onclick = async () => {
    try { await wpUnsubscribe(); toast(L('Dit apparaat is afgemeld')); } catch (ex) { toast(ex.message, 'err'); }
    wpAccount();
  };
  wpListEvents(box, wpAccount);
}

/** Instellingen → Kanalen: alle apparaten (beheerder) */
async function wpAdmin() {
  const box = $('#wp-admin-body');
  if (!box) return;
  const sub = wpBlocker() ? null : await wpCurrent();
  let r;
  try { r = await api('webpush_devices', { all: 1, endpoint: sub ? sub.endpoint : '' }); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  box.innerHTML = wpList(r.devices, true);
  wpListEvents(box, wpAdmin);
}
