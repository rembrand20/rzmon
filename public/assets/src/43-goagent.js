// ---------------------------------------------------------------- 1.339.0: Agent Manager → Go-agent (proef)
// De nieuwe agent (Go, het commando rzsense) draait op een Linux-server náást de bash-agent en stuurt elke minuut zijn
// metingen. RzSense legt ze naast die van de bash-agent. Dit blok laat zien hoe vaak ze gelijk zijn en waar ze
// verschillen, met het commando om de proef te starten of weg te halen. Er wordt niets overgenomen.
async function amGo(h, body) {
  const box = $('#am-go', body);
  if (!box) return;
  let d;
  try { d = await api('agent_shadow_get', null, { host_id: h.id }); } catch (ex) { box.innerHTML = `<p class="err-txt small">${esc(ex.message)}</p>`; return; }
  const base = agentBase(h), pin = d.pin && base.startsWith('https') ? ` -k --pinnedpubkey 'sha256//${d.pin}'` : '';
  const cmd = (extra) => `curl -fsSL${pin} '${base}/api.php?action=agent_go_install${dlArg()}' | sudo bash${extra}`;
  const copy = (txt) => `<div class="copy-line"><pre class="code">${esc(txt)}</pre><button type="button" class="btn" data-copy-text="${esc(txt)}">${esc(L('Kopieer'))}</button></div>`;
  const s = d.shadow;
  const when = (t) => new Date(String(t).replace(' ', 'T')).toLocaleString(LOCALE, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  let html = '';
  if (!d.archs.length) {
    html += `<p class="callout small">${esc(L('RzSense heeft de Go-agent voor Linux nog niet. GitHub bouwt en ondertekent hem na een update (workflow "Windows-agent bouwen"); werk RzSense daarna bij.'))}</p>`;
  }
  if (!s) {
    html += `<p class="muted small">${esc(L('Op deze server loopt nog geen proef.'))}</p>`;
  } else {
    const cls = !s.live ? 'unk' : s.n && s.same === s.n ? 'ok' : s.n ? 'warn' : 'unk';
    html += `<div class="go-sum"><span class="go-pct ${cls}">${s.pct === null ? '–' : dec(String(s.pct)) + '%'}</span>
      <div><b>${esc(s.rounds ? L('{ok} van de {n} proefrondes gelijk aan de bash-agent', { ok: s.rounds_ok, n: s.rounds }) : L('Nog geen proefronde om te vergelijken'))}</b>
        <small>${esc([L('sinds {t}', { t: when(s.since) }), s.live ? L('laatste ronde {t}', { t: when(s.at) }) : L('geen proefronde meer sinds {t}', { t: when(s.at) }),
          `Go ${s.version}`, s.bash_version ? `bash ${s.bash_version}` : '', L('meten kostte {n} ms', { n: s.took_ms })].filter(Boolean).join(' · '))}</small></div></div>`;
    // 1.340.0: een oudere Go-agent op de server meet minder; de proef werkt zichzelf niet bij
    if (s.live && s.version && d.version && s.version !== d.version && d.archs.length) {
      html += `<p class="callout small">${esc(L('Op de server draait Go-agent {a}; RzSense heeft {b}. Vanaf 0.12.0 werkt de Go-agent zichzelf bij; een oudere werk je bij door het installatiecommando hieronder opnieuw uit te voeren.', { a: s.version, b: d.version }))}
        ${s.update_last && s.update_last.includes('✘') ? '<br>' + esc(s.update_last) : ''}</p>`;
    }
    if (s.n) {
      html += `<div class="table-wrap"><table class="small go-tbl"><thead><tr><th>${esc(L('Meting'))}</th><th>${esc(L('Bash-agent'))}</th><th>${esc(L('Go-agent'))}</th><th></th></tr></thead><tbody>${
        s.items.map((i) => `<tr class="${i.ok ? '' : 'go-diff'}"><td data-th="${esc(L('Meting'))}">${esc(i.k)}</td><td data-th="${esc(L('Bash-agent'))}">${esc(i.bash)}</td><td data-th="${esc(L('Go-agent'))}">${esc(i.go)}</td>
          <td>${i.ok ? '✔' : '≠'}</td></tr>`).join('')}</tbody></table></div>`;
    } else {
      html += `<p class="muted small">${esc(L('De laatste ronde telde niet mee: er was geen meting van de bash-agent van de laatste 3 minuten om mee te vergelijken.'))}</p>`;
    }
    const top = Object.entries(s.diff_n || {}).slice(0, 5);
    if (top.length) html += `<p class="small"><b>${esc(L('Waar het vaakst een verschil zat:'))}</b> ${top.map(([k, n]) => `${esc(k)} (${n}×)`).join(' · ')}</p>`;
    if ((s.doctor || []).length) html += `<p class="small warn-txt">${s.doctor.map((f) => `${esc(f.t)}: ${esc(f.d)}${f.fix ? ' → ' + esc(f.fix) : ''}`).join('<br>')}</p>`;
  }
  html += `<details class="go-how" ${s ? '' : 'open'}><summary>${esc(s ? L('Opnieuw installeren, bijwerken of weghalen') : L('De proef starten'))}</summary>
    <p class="small">${L('Voer dit uit op de server. Het zet <code>/usr/local/bin/rzsense</code> neer (alleen als de handtekening van de ontwikkelaar klopt) en een timer die elke minuut een proefronde draait. De bash-agent, zijn instellingen en zijn sleutel blijven zoals ze zijn.')}</p>
    ${copy(cmd(''))}
    <p class="small">${esc(L('Weghalen (de server is daarna precies zoals hij was):'))}</p>
    ${copy(cmd(' -s -- --remove'))}
    <p class="small muted">${L('Op de server zelf: <code>rzsense status</code>, <code>rzsense test</code> en <code>rzsense doctor</code> (loopt alles na en zegt hoe je een probleem oplost).')}</p>
    ${s ? `<p><button type="button" class="btn small-btn" data-go-reset>${esc(L('Opnieuw beginnen te tellen'))}</button></p>` : ''}</details>`;
  box.innerHTML = html;
  if (typeof amSum === 'function') amSum('goagent', esc(!s ? L('loopt nog niet op deze server') : !s.live ? L('geen proefronde meer sinds {t}', { t: when(s.at) }) : L('{ok} van de {n} proefrondes gelijk aan de bash-agent', { ok: s.rounds_ok, n: s.rounds })), !!(s && s.live && s.n && s.same !== s.n));
  const rb = $('[data-go-reset]', box);
  if (rb) rb.onclick = async () => { try { await api('agent_shadow_reset', { host_id: h.id }); amGo(h, body); } catch (ex) { toast(ex.message, 'err'); } };
}
