'use strict';

const $ = (s, el = document) => el.querySelector(s);
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const CSRF = $('meta[name=csrf-token]').content;

async function call(action, body) {
  const res = await fetch('setup.php?action=' + action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(body || {}),
    credentials: 'same-origin',
  });
  const data = await res.json().catch(() => ({ error: `HTTP ${res.status}` }));
  if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
}

function toast(msg, type = 'ok') {
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = msg;
  $('#toasts').append(el);
  setTimeout(() => el.remove(), 5000);
}

function formData(form) {
  const d = {};
  for (const el of form.elements) {
    if (el.name) d[el.name] = el.type === 'checkbox' ? el.checked : el.value;
  }
  return d;
}

function showError(form, msg) {
  const err = $('.form-error', form);
  err.textContent = msg || '';
  err.hidden = !msg;
}

/** Config niet schrijfbaar: toon de inhoud om handmatig te plaatsen. */
function handleConfigResult(r) {
  if (r.written !== false) return true;
  $('#modal-title').textContent = 'config.php handmatig plaatsen';
  $('#modal-body').innerHTML = `
    <div style="padding:1.2rem">
      <p>De webserver mag <code>${esc(r.path)}</code> niet schrijven. Maak het bestand zelf aan met deze inhoud en herlaad daarna deze pagina:</p>
      <pre class="code" id="cfg-content">${esc(r.content)}</pre>
      <button type="button" class="btn" data-copy="#cfg-content">Kopieer</button>
      <p class="muted small">Of geef de webserver eenmalig schrijfrechten:<br><code>sudo chown www-data ${esc(r.path.replace(/\/config\.php$/, ''))}</code></p>
    </div>`;
  $('#modal').showModal();
  return false;
}

async function submit(form, action, extra, onOk) {
  const btn = $('button.primary', form);
  if (btn) btn.disabled = true;
  showError(form, '');
  try {
    const r = await call(action, { ...formData(form), ...extra });
    await onOk(r);
  } catch (ex) {
    showError(form, ex.message);
  }
  if (btn) btn.disabled = false;
}

// ---------------------------------------------------------------- Database
const dbForm = $('#db-form');
$('#db-create').addEventListener('change', (e) => ($('#db-admin').hidden = !e.target.checked));

dbForm.addEventListener('submit', (e) => {
  e.preventDefault();
  submit(dbForm, 'db_save', {}, (r) => {
    toast(r.message || 'Opgeslagen');
    if (handleConfigResult(r)) setTimeout(() => location.reload(), 800);
  });
});

dbForm.addEventListener('click', async (e) => {
  const b = e.target.closest('[data-action]');
  if (!b) return;
  b.disabled = true;
  showError(dbForm, '');
  try {
    const r = await call(b.dataset.action, formData(dbForm));
    if (b.dataset.action === 'db_test') {
      toast(`Verbonden met ${r.version}. Database "${dbForm.db_name.value}" ${r.db_exists ? 'bestaat' : 'bestaat nog niet'}.`);
    } else {
      toast(r.message || 'Klaar');
    }
  } catch (ex) {
    showError(dbForm, ex.message);
  }
  b.disabled = false;
});

// ---------------------------------------------------------------- App/PHP
const appForm = $('#app-form');
appForm.addEventListener('submit', (e) => {
  e.preventDefault();
  submit(appForm, 'app_save', {}, (r) => {
    toast('Instellingen opgeslagen');
    if (handleConfigResult(r)) setTimeout(() => location.reload(), 800);
  });
});

// ---------------------------------------------------------------- Admin
const adminForm = $('#admin-form');
if (adminForm) {
  adminForm.addEventListener('submit', (e) => {
    e.preventDefault();
    submit(adminForm, 'admin_create', {}, () => { location.href = 'index.php'; });
  });
}

// ---------------------------------------------------------------- Copy
document.addEventListener('click', async (e) => {
  const b = e.target.closest('[data-copy]');
  if (!b) return;
  const text = $(b.dataset.copy).textContent;
  try { await navigator.clipboard.writeText(text); toast('Gekopieerd'); }
  catch (ex) {
    const r = document.createRange();
    r.selectNodeContents($(b.dataset.copy));
    getSelection().removeAllRanges();
    getSelection().addRange(r);
    toast('Geselecteerd — druk Ctrl+C', 'err');
  }
});
