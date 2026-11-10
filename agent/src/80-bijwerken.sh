# ---------------------------------------------------------------- Bijwerken op afstand
lap update
# Kan deze agent zichzelf bijwerken, en hoe? Gestart door systemd (afgeschermd: /usr is alleen-lezen)
# → via systemd-run als aparte taak; via cron of met de hand → direct.
upd_dir="${CACHE_DIRECTORY:-/var/cache/rzmon-agent}"
upd_setup="$AGENT_SETUP"
if [ "$TEST" = 1 ]; then
    upd_via="test"   # verbindingstest: RzSense stuurt dan geen update-opdracht
elif [ "$HA_MODE" = 1 ]; then
    upd_via=ha
elif [ "$REMOTE_UPDATE" != 1 ]; then
    upd_via=off
elif [ "$(id -u)" != 0 ] || [ ! -f "$upd_setup" ] || ! command -v sha256sum >/dev/null 2>&1; then
    upd_via=none
elif [ -n "${INVOCATION_ID-}" ] && command -v systemd-run >/dev/null 2>&1; then
    upd_via=systemd
else
    upd_via=direct
fi
# Uitkomst van de vorige update terugmelden (één regel: "ok <oud> <nieuw>" of "error <reden>")
upd_result=""
if [ -r "$upd_dir/update.result" ]; then
    upd_result=$(head -c 300 "$upd_dir/update.result" | head -1)
fi

# Ondertekende updates (agent 1.71.0): de publieke sleutel van de ontwikkelaar (ed25519). Bij elke nieuwe agent zet GitHub er
# een handtekening bij (agent/*.sig, gemaakt met de geheime sleutel die alleen in GitHub staat). Een update wordt alleen
# geïnstalleerd als die klopt: zo kan zelfs een overgenomen RzSense geen eigen code op de servers zetten.
AGENT_SIGN_PUB="MCowBQYDK2VwAyEAKayiZIruYIYwZQ97qndO4lmnMjp/EEnVDNBIhZ07sSM="
sig_check() {  # sig_check <bestand> <handtekening (base64)>: 0 = klopt, 1 = klopt niet, 2 = niet te controleren (OpenSSL ouder dan 3)
    local d rc
    command -v openssl >/dev/null 2>&1 && openssl pkeyutl -help 2>&1 | grep -q -- '-rawin' || return 2
    d=$(mktemp -d "${TMPDIR:-/tmp}/rzmon-sig.XXXXXX") || return 2
    printf -- '-----BEGIN PUBLIC KEY-----\n%s\n-----END PUBLIC KEY-----\n' "$AGENT_SIGN_PUB" >"$d/pub.pem"
    if ! base64 -d <"$2" >"$d/sig" 2>/dev/null || [ "$(wc -c <"$d/sig")" != 64 ]; then rm -rf "$d"; return 1; fi
    openssl pkeyutl -verify -pubin -inkey "$d/pub.pem" -rawin -in "$1" -sigfile "$d/sig" >/dev/null 2>&1; rc=$?
    rm -rf "$d"
    [ "$rc" = 0 ] && return 0 || return 1
}
# UPDATE_UNSIGNED=1 in /etc/default/rzmon-agent: ook zonder (geldige) handtekening bijwerken (niet aangeraden)
UPDATE_UNSIGNED="${UPDATE_UNSIGNED:-0}"
remote_update() {  # remote_update <antwoord van RzSense>
    local want sha_a sha_s k
    want=$(sed -n 's/.*"update":[[:space:]]*{[^}]*"version":[[:space:]]*"\([0-9.]*\)".*/\1/p' <<<"$1")
    sha_a=$(sed -n 's/.*"update":[[:space:]]*{[^}]*"agent_sha256":[[:space:]]*"\([0-9a-f]\{64\}\)".*/\1/p' <<<"$1")
    sha_s=$(sed -n 's/.*"update":[[:space:]]*{[^}]*"setup_sha256":[[:space:]]*"\([0-9a-f]\{64\}\)".*/\1/p' <<<"$1")
    case "$upd_via" in systemd|direct) ;; *) return 0 ;; esac
    [ -n "$want" ] && [ -n "$sha_a" ] && [ -n "$sha_s" ] || return 0
    # Agent 1.87.0: nooit terug naar een oudere versie (ook niet als die netjes ondertekend is: die kan een bekende fout hebben)
    if [ "$want" != "$AGENT_VERSION" ] && [ "$(printf '%s\n%s\n' "$want" "$AGENT_VERSION" | sort -V | tail -1)" = "$AGENT_VERSION" ]; then
        printf 'error %s\n' "versie $want is ouder dan $AGENT_VERSION: niet teruggezet" >"$upd_dir/update.result" 2>/dev/null
        return 0
    fi
    mkdir -p "$upd_dir" 2>/dev/null && [ -w "$upd_dir" ] || return 0
    # Loopt er al een update (minder dan 10 minuten geleden gestart)? Dan niet nog een.
    if [ -f "$upd_dir/update.lock" ] && [ $(( $(date +%s) - $(stat -c %Y "$upd_dir/update.lock" 2>/dev/null || echo 0) )) -lt 600 ]; then
        return 0
    fi
    : >"$upd_dir/update.lock"
    upd_fail() {
        printf 'error %s\n' "$1" >"$upd_dir/update.result"
        rm -f "$upd_dir/update.lock" "$upd_dir/agent.new" "$upd_dir/setup.new"
    }
    k=(-fsS -m 60 "${tls[@]}")
    if ! curl "${k[@]}" -o "$upd_dir/agent.new" "${MONITOR_URL%/}/api.php?action=agent_script" 2>/dev/null \
        || ! curl "${k[@]}" -o "$upd_dir/setup.new" "${MONITOR_URL%/}/api.php?action=agent_install" 2>/dev/null; then
        upd_fail "downloaden van de nieuwe versie bij RzSense mislukt"
        return 0
    fi
    if [ "$(sha256sum "$upd_dir/agent.new" | cut -d' ' -f1)" != "$sha_a" ] || [ "$(sha256sum "$upd_dir/setup.new" | cut -d' ' -f1)" != "$sha_s" ]; then
        upd_fail "controlegetal (SHA-256) klopt niet: download onvolledig of onderweg gewijzigd"
        return 0
    fi
    # Handtekening van de ontwikkelaar (agent 1.71.0). Het installatiescript krijgt bij RzSense een downloadsleutel in de regel
    # DL_KEY=''; die ene regel gaat terug naar leeg voor de controle, verder moet alles precies kloppen.
    if [ "$UPDATE_UNSIGNED" != 1 ]; then
        if ! curl "${k[@]}" -o "$upd_dir/agent.sig" "${MONITOR_URL%/}/api.php?action=agent_script_sig" 2>/dev/null \
            || ! curl "${k[@]}" -o "$upd_dir/setup.sig" "${MONITOR_URL%/}/api.php?action=agent_install_sig" 2>/dev/null; then
            upd_fail "geen handtekening bij RzSense (nog niet ondertekend?): niet bijgewerkt"
            rm -f "$upd_dir/agent.sig" "$upd_dir/setup.sig"
            return 0
        fi
        sed "s/^DL_KEY='[A-Za-z0-9]*'\$/DL_KEY=''/" "$upd_dir/setup.new" >"$upd_dir/setup.chk"
        sig_check "$upd_dir/agent.new" "$upd_dir/agent.sig"; s_a=$?
        sig_check "$upd_dir/setup.chk" "$upd_dir/setup.sig"; s_s=$?
        rm -f "$upd_dir/agent.sig" "$upd_dir/setup.sig" "$upd_dir/setup.chk"
        if [ "$s_a" = 1 ] || [ "$s_s" = 1 ]; then
            upd_fail "handtekening klopt niet: deze versie komt niet van de ontwikkelaar; niet bijgewerkt"
            echo bad >"$upd_dir/update.sig"
            return 0
        fi
        # Agent 1.87.0: OpenSSL ouder dan 3.0 kan de handtekening niet controleren: dan niet bijwerken, tenzij je dat zelf
        # toestaat (UPDATE_OLD_OPENSSL=1); anders kon een overgenomen RzSense op zulke servers toch eigen code zetten
        if { [ "$s_a" = 2 ] || [ "$s_s" = 2 ]; } && [ "${UPDATE_OLD_OPENSSL:-0}" != 1 ]; then
            upd_fail "handtekening niet te controleren (OpenSSL ouder dan 3.0): niet bijgewerkt; zet UPDATE_OLD_OPENSSL=1 in /etc/default/rzmon-agent om toch bij te werken"
            echo old_openssl >"$upd_dir/update.sig"
            return 0
        fi
        if [ "$s_a" = 2 ]; then echo old_openssl >"$upd_dir/update.sig"; else echo ok >"$upd_dir/update.sig"; fi
    else
        echo off >"$upd_dir/update.sig"
    fi
    if ! bash -n "$upd_dir/agent.new" 2>/dev/null || ! bash -n "$upd_dir/setup.new" 2>/dev/null \
        || ! grep -q "^AGENT_VERSION=\"$want\"" "$upd_dir/agent.new" || ! grep -qE 'RzMon agent|RzSense agent' "$upd_dir/setup.new"; then
        upd_fail "de download is geen geldige RzSense-agent $want"
        return 0
    fi
    chmod 700 "$upd_dir/setup.new"
    # Het installatiescript doet de rest (zoals bij sudo rzmon-agent-setup --update), met de gecontroleerde bestanden
    local args=(/bin/bash "$upd_dir/setup.new" --update --yes --file "$upd_dir/agent.new" --result "$upd_dir/update.result")
    if [ "$upd_via" = systemd ]; then
        systemd-run --quiet --collect --unit="rzmon-agent-update-$(date +%s)" "${args[@]}" >/dev/null 2>&1 \
            || upd_fail "systemd-run mislukt; werk bij met: sudo rzmon-agent-setup --update"
    else
        setsid "${args[@]}" 9>&- </dev/null >/dev/null 2>&1 &
    fi
}

