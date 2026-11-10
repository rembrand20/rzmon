# ---------------------------------------------------------------- Versleuteling (RzSense 1.57.0+)
# Met een sleutel gaan rapporten versleuteld naar RzSense (AES-256-CBC + HMAC-SHA256, met openssl) en komt het
# antwoord versleuteld terug; zie src/AgentCrypto.php in RzSense. De sleutel: ENC_KEY in /etc/default/rzmon-agent,
# of de sleutel die RzSense zelf meestuurt (enc.key, alleen leesbaar voor root; die gaat voor, zo werkt een nieuwe
# sleutel vanzelf). Staat versleutelen in RzSense uit voor deze server, dan zet RzSense enc.off neer.
#
# Beveiligde verbinding (agent 1.50.0+, RzSense 1.64.0+): een eigen sleutel voor deze server (agent.key). Die staat
# in een vaste map, niet in de cache: in de HA-add-on /data/rzmon-keys, op TrueNAS in de map van de agent, anders
# /var/lib/rzmon-agent (alleen root). Met een eigen sleutel stuurt de agent geen token meer mee, alleen het kenmerk
# van zijn sleutel, en is elk verzoek versleuteld of ondertekend. De sleutel komt bij het koppelen (koppelcode:
# PAIR_CODE) of, als de agent al met de algemene sleutel werkte, in een versleuteld antwoord van RzSense.
enc_file="$t_dir/enc.key"
if [ "$HA_MODE" = 1 ]; then k_dir=/data/rzmon-keys
elif [ -n "${AGENT_DIR-}" ]; then k_dir="$AGENT_DIR/keys"
else k_dir=/var/lib/rzmon-agent; fi
{ mkdir -p "$k_dir" && chmod 700 "$k_dir"; } 2>/dev/null
[ -w "$k_dir" ] || k_dir=$t_dir
own_file="$k_dir/agent.key"
own_key=""
enc_key=""
if command -v openssl >/dev/null 2>&1; then
    [ -r "$own_file" ] && own_key=$(tr -dc '0-9a-f' <"$own_file" | head -c 64)
    [ "${#own_key}" = 64 ] || own_key=""
    if [ ! -e "$t_dir/enc.off" ]; then
        enc_key=$own_key
        [ -z "$enc_key" ] && [ -r "$enc_file" ] && enc_key=$(tr -dc '0-9a-f' <"$enc_file" | head -c 64)
        [ "${#enc_key}" = 64 ] || enc_key=""
        [ -z "$enc_key" ] && [[ "${ENC_KEY-}" =~ ^[0-9a-f]{64}$ ]] && enc_key=$ENC_KEY
    fi
fi
# Agent 1.87.0: HMAC-SHA256 zelf uitrekenen met openssl dgst over stdin, zodat geen sleutel op de opdrachtregel staat
# (die is voor iedereen op de server te zien in ps of /proc/*/cmdline). Uitkomst gelijk aan openssl -mac HMAC.
hmac_hex() {  # hmac_hex <hexsleutel>: HMAC-SHA256 van stdin (hex)
    local k=$1 ip="" op="" i x h
    while [ "${#k}" -lt 128 ]; do k+="00"; done
    for ((i = 0; i < 128; i += 2)); do
        printf -v x '\\x%02x' $(( 16#${k:i:2} ^ 0x36 )); ip+=$x
        printf -v x '\\x%02x' $(( 16#${k:i:2} ^ 0x5c )); op+=$x
    done
    h=$({ printf '%b' "$ip"; cat; } | openssl dgst -sha256 2>/dev/null | sed 's/^.*= *//')
    [ "${#h}" = 64 ] || return 1
    x=""
    for ((i = 0; i < 64; i += 2)); do x+="\\x${h:i:2}"; done
    { printf '%b' "$op"; printf '%b' "$x"; } | openssl dgst -sha256 2>/dev/null | sed 's/^.*= *//'
}
enc_sub() {  # enc_sub <sleutel> <label>: afgeleide sleutel (HMAC-SHA256, hex)
    printf '%s' "$2" | hmac_hex "$1"
}
seal() {  # seal <invoer> <uitvoer>: versleutelen met enc_key; de nonce staat daarna in seal_n
    local ke km iv t d m
    ke=$(enc_sub "$enc_key" enc) && km=$(enc_sub "$enc_key" mac) && iv=$(openssl rand -hex 16) && seal_n=$(openssl rand -hex 16) || return 1
    t=$(date +%s)
    d=$(openssl enc -aes-256-cbc -K "$ke" -iv "$iv" -in "$1" 2>/dev/null | base64 -w0) && [ -n "$d" ] || return 1
    m=$(printf 'rzenc1|%s|%s|%s|%s' "$t" "$seal_n" "$iv" "$d" | hmac_hex "$km")
    [ "${#m}" = 64 ] || return 1
    printf '{"rzenc":1,"t":%s,"n":"%s","iv":"%s","d":"%s","m":"%s"}' "$t" "$seal_n" "$iv" "$d" "$m" >"$2"
}
unseal() {  # unseal <antwoord> <nonce van het verzoek>: handtekening controleren, dan uitpakken; 1 = niet te vertrouwen
    local t rn iv d m ke km
    t=$(sed -n 's/^{"rzenc":1,"t":\([0-9]*\),.*/\1/p' <<<"$1")
    rn=$(sed -n 's/.*"rn":"\([0-9a-f]*\)".*/\1/p' <<<"$1")
    iv=$(sed -n 's/.*"iv":"\([0-9a-f]*\)".*/\1/p' <<<"$1")
    d=$(sed -n 's/.*"d":"\([A-Za-z0-9+/=]*\)".*/\1/p' <<<"$1")
    m=$(sed -n 's/.*"m":"\([0-9a-f]*\)".*/\1/p' <<<"$1")
    [ -n "$t" ] && [ -n "$d" ] && [ "${#m}" = 64 ] && [ -n "$2" ] && [ "$rn" = "$2" ] || return 1
    km=$(enc_sub "$enc_key" mac) && ke=$(enc_sub "$enc_key" enc) || return 1
    [ "$(printf 'rzenc1r|%s|%s|%s|%s' "$t" "$rn" "$iv" "$d" | hmac_hex "$km")" = "$m" ] || return 1
    printf '%s' "$d" | base64 -d 2>/dev/null | openssl enc -d -aes-256-cbc -K "$ke" -iv "$iv" 2>/dev/null
}
# Agent 1.87.0: het token niet als argument aan curl (dan staat het in ps), maar in een curl-configbestand dat
# alleen root kan lezen. Lukt dat niet, dan zoals voorheen.
tok_cfg="$k_dir/token.curl"
tok_auth() {  # tok_auth: zet tok_h op de curl-argumenten voor het token
    local t=${TOKEN//\\/\\\\}
    t=${t//\"/\\\"}
    if [ -n "$TOKEN" ] && (umask 077 && printf 'header = "X-Agent-Token: %s"\n' "$t" >"$tok_cfg.new" && mv -f "$tok_cfg.new" "$tok_cfg") 2>/dev/null; then
        tok_h=(-K "$tok_cfg")
    else
        tok_h=(-H "X-Agent-Token: $TOKEN")
    fi
}
# Wie we zijn: met de eigen sleutel alleen het kenmerk (X-Agent-Key), anders het token
set_auth() {
    kid=""
    if [ -n "$own_key" ] && [ "$enc_key" = "$own_key" ]; then
        kid=$(enc_sub "$own_key" id | cut -c1-16)
        auth=(-H "X-Agent-Key: $kid")
        rm -f "$tok_cfg" 2>/dev/null
    else
        tok_auth
        auth=("${tok_h[@]}")
    fi
}
set_auth
# GET-verzoek (actie $1): met de eigen sleutel ondertekend (X-Agent-Auth), anders het token en eventueel het kenmerk
# van de algemene sleutel. Het antwoord komt dan versleuteld terug, gebonden aan get_n.
get_auth() {
    get_n=""
    if [ -n "$kid" ]; then
        local t s
        t=$(date +%s); get_n=$(openssl rand -hex 16)
        s=$(printf 'rzget1|%s|%s|%s' "$1" "$t" "$get_n" | hmac_hex "$(enc_sub "$own_key" mac)")
        ga=(-H "X-Agent-Auth: $kid:$t:$get_n:$s")
    else
        tok_auth
        ga=("${tok_h[@]}")
        if [ -n "$enc_key" ]; then get_n=$(openssl rand -hex 16) && ga+=(-H "X-Agent-Enc: $(enc_sub "$enc_key" id | cut -c1-16):$get_n"); fi
    fi
}

# Adres van RzSense (agent 1.51.0, RzSense 1.72.0). RzSense kan de agent een ander adres geven (bv. https via de reverse
# proxy); de agent test het eerst en bewaart het dan in $k_dir/url (regel 1: het adres, 2: het adres uit de instellingen
# waar het voor in de plaats komt, 3: het reserve-adres). Pas je MONITOR_URL zelf aan, dan geldt weer dat. Het
# reserve-adres (MONITOR_URL_FALLBACK, of uit $k_dir/url) wordt gebruikt als het gewone adres niet bereikbaar is
# (geen verbinding, certificaat, of de proxy geeft 502-504); RzSense toont dat met een ⚠️.
conf_url=${MONITOR_URL%/}
MONITOR_URL_FALLBACK="${MONITOR_URL_FALLBACK-}"
url_file="$k_dir/url"
url_set_by_rzmon=0
if [ -r "$url_file" ]; then
    u_set=""; u_from=""; u_fb=""
    { IFS= read -r u_set; IFS= read -r u_from; IFS= read -r u_fb; } <"$url_file"
    if [ -n "$u_set" ] && [ "$u_from" = "$conf_url" ]; then
        MONITOR_URL=$u_set
        MONITOR_URL_FALLBACK=$u_fb
        url_set_by_rzmon=1
    else
        rm -f "$url_file"   # MONITOR_URL is met de hand gewijzigd: dat gaat voor
    fi
fi
MONITOR_URL=${MONITOR_URL%/}
MONITOR_URL_FALLBACK=${MONITOR_URL_FALLBACK%/}
[ "$MONITOR_URL_FALLBACK" = "$MONITOR_URL" ] && MONITOR_URL_FALLBACK=""
url_result=$(head -c 500 "$k_dir/url.result" 2>/dev/null | tr -d '\n')

# Controle per adres (agent 1.53.0, RzSense 1.78.0): in $k_dir/url.pins staat per adres hoe het certificaat gecontroleerd
# wordt: "-" = gewoon (geldig certificaat, of http), "sha256//…" = alleen het eigen certificaat van RzSense (vastzetten;
# met een klaargezet nieuw certificaat twee vingerafdrukken, "sha256//A;sha256//B"). RzSense geeft dit door in elk
# versleuteld antwoord. Geen regel voor een adres: zoals vroeger (de vingerafdruk uit $k_dir/pin, of INSECURE).
spec_re='^(-|sha256//[A-Za-z0-9+/=]{40,64}(;sha256//[A-Za-z0-9+/=]{40,64})?)$'
url_spec() { [ -n "$1" ] && [ -r "$k_dir/url.pins" ] && awk -v u="$1" '$1 == u { print $2; exit }' "$k_dir/url.pins"; }
url_pins_put() {  # url_pins_put <adres> <controle> […]: deze adressen bijwerken, de andere bewaren (hooguit 6)
    local f="$k_dir/url.pins" keep="" add="" a
    local -a urls=()
    while [ $# -ge 2 ]; do
        if [[ "$1" =~ ^https?://[^[:space:]\"]+$ ]] && [[ "$2" =~ $spec_re ]]; then urls+=("$1"); add+="$1 $2"$'\n'; fi
        shift 2
    done
    [ ${#urls[@]} -gt 0 ] || return 0
    if [ -r "$f" ]; then
        while read -r a _; do
            [[ " ${urls[*]} " == *" $a "* ]] || keep+="$(grep -m1 -F "$a " "$f")"$'\n'
        done <"$f"
    fi
    (umask 077; printf '%s%s' "$keep" "$add" | grep -v '^$' | tail -n 6 >"$f.tmp" && mv -f "$f.tmp" "$f")
}
# curl-opties voor een adres volgens zijn controle (leeg = geen aanwijzing)
spec_tls() {  # spec_tls <adres> <controle>: zet s_tls
    s_tls=()
    [[ "$1" == https://* ]] || return 0
    if [[ "$2" == sha256//* ]] && [ "$curl_pins" = 1 ]; then s_tls=(-k --pinnedpubkey "$2")
    elif [ "$INSECURE" = 1 ]; then s_tls=(-k); fi
}

# Overstappen naar een nieuw adres (opdracht uit een versleuteld antwoord van RzSense): eerst testen met een ondertekend
# verzoek; alleen als het antwoord met onze eigen sleutel klopt, gaat de agent dat adres gebruiken (vanaf de volgende
# keer). De uitkomst gaat met het volgende rapport naar RzSense.
url_switch() {  # url_switch <nieuw adres> <reserve-adres> [controle nieuw] [controle reserve] (agent 1.53.0)
    local new=${1%/} fb=${2%/} n_spec=${3-} f_spec=${4-} t_tls=() out code rip body why
    [ -e "$k_dir/url.result" ] && return 0   # vorige uitkomst nog niet doorgegeven
    [[ "$new" =~ ^https?://[^[:space:]\"]+$ ]] || return 0
    [[ -z "$fb" || "$fb" =~ ^https?://[^[:space:]\"]+$ ]] || fb=""
    [[ "$n_spec" =~ $spec_re ]] || n_spec=""
    [[ "$f_spec" =~ $spec_re ]] || f_spec=""
    if [ -z "$kid" ]; then
        printf 'error: deze agent heeft nog geen eigen sleutel (eerst koppelen met een koppelcode)\n' >"$k_dir/url.result"
        return 0
    fi
    if [[ "$new" == https://* && "$n_spec" == sha256//* && "$curl_pins" != 1 ]]; then
        printf 'error: curl %s op deze server kan het certificaat van RzSense niet vastzetten (7.44 of nieuwer nodig)\n' "$c_ver" >"$k_dir/url.result"
        return 0
    fi
    spec_tls "$new" "$n_spec"; t_tls=("${s_tls[@]}")
    get_auth agent_check
    # Ook het IP waarmee curl verbond: RzSense ziet daaraan of de naam hier naar de proxy wijst of via internet gaat
    out=$(curl -sS -m 15 "${t_tls[@]}" "${ga[@]}" -w '\n%{http_code} %{remote_ip}' "$new/api.php?action=agent_check" 2>&1)
    code=${out##*$'\n'}; out=${out%$'\n'*}
    rip=${code#* }; code=${code%% *}
    [[ "$rip" =~ ^[0-9a-fA-F.:]+$ ]] || rip=
    if [ "$code" = 200 ] && [ -n "$get_n" ] && body=$(unseal "$out" "$get_n") && [[ "$body" == *'"ok":true'* ]]; then
        if (umask 077; printf '%s\n%s\n%s\n' "$new" "$conf_url" "$fb" >"$url_file.tmp" && mv -f "$url_file.tmp" "$url_file"); then
            url_pins_put "$new" "$n_spec" "$fb" "$f_spec"
            printf 'ok %s%s\n' "$new" "${rip:+ [ip $rip]}" >"$k_dir/url.result"
            echo "Adres van RzSense gewijzigd naar $new${fb:+ (reserve: $fb)}" >&2
        else
            printf 'error: kan %s niet schrijven\n' "$url_file" >"$k_dir/url.result"
        fi
    else
        why=$(sed -n 's/.*"error":"\([^"]*\)".*/\1/p' <<<"$out" | head -c 150)
        [ -z "$why" ] && why=$(head -n 1 <<<"$out" | head -c 150)
        [ "$code" = 200 ] && why="het antwoord klopt niet met de sleutel van deze agent"
        printf 'error: %s werkt niet vanaf deze server (HTTP %s%s)%s\n' "$new" "${code:-000}" "${why:+: $why}" "${rip:+ [ip $rip]}" >"$k_dir/url.result"
        echo "Nieuw adres $new werkt niet; het huidige adres blijft" >&2
    fi
}

# Certificaat van RzSense vastzetten: bij een eigen (self-signed) certificaat accepteert de agent alleen dat ene
# certificaat (curl --pinnedpubkey). De vingerafdruk komt uit het installatiecommando (PIN) en daarna uit de
# versleutelde antwoorden van RzSense ("" = niet vastzetten). Zonder vingerafdruk: INSECURE=1 als vroeger.
curl_pins=0
c_ver=$(curl -V 2>/dev/null | awk 'NR == 1 { print $2 }'); IFS=. read -r c_v1 c_v2 _ <<<"$c_ver"
{ [ "${c_v1:-0}" -gt 7 ] || { [ "${c_v1:-0}" = 7 ] && [ "${c_v2:-0}" -ge 44 ]; }; } 2>/dev/null && curl_pins=1   # sha256-pins: curl 7.44+
set_tls() {
    pin=""
    u_spec=$(url_spec "$MONITOR_URL")
    if [ -n "$u_spec" ]; then   # agent 1.53.0: RzSense gaf voor dit adres door hoe het gecontroleerd wordt
        spec_tls "$MONITOR_URL" "$u_spec"; tls=("${s_tls[@]}")
        [[ "$u_spec" == sha256//* ]] && pin=${u_spec#sha256//} && pin=${pin%%;*}
        return 0
    fi
    [ -r "$k_dir/pin" ] && pin=$(tr -dc 'A-Za-z0-9+/=' <"$k_dir/pin" | head -c 64)
    [ -z "$pin" ] && [ ! -e "$k_dir/pin.off" ] && [[ "${PIN-}" =~ ^[A-Za-z0-9+/=]{40,64}$ ]] && pin=$PIN
    tls=()
    if [[ "$MONITOR_URL" == https://* ]] && [ -n "$pin" ] && [ "$curl_pins" = 1 ]; then tls=(-k --pinnedpubkey "sha256//$pin")
    elif [ "$INSECURE" = 1 ]; then tls=(-k); fi
}
set_tls
store_pin() {  # store_pin <versleuteld antwoord>: vingerafdruk van RzSense bewaren, of ("") niet meer vastzetten
    [[ "$1" =~ \"pin\":\"([A-Za-z0-9+/=\\]{0,90})\" ]] || return 0
    local p=${BASH_REMATCH[1]//\\/}
    if [ -n "$p" ]; then
        [ "$(cat "$k_dir/pin" 2>/dev/null)" = "$p" ] || (umask 077; printf '%s\n' "$p" >"$k_dir/pin")
        rm -f "$k_dir/pin.off"
    else
        rm -f "$k_dir/pin"; : >"$k_dir/pin.off"
    fi
}
store_key() {  # store_key <versleuteld antwoord>: eigen sleutel van RzSense bewaren (eerste keer, of vernieuwd)
    [[ "$1" =~ \"agent_key\":\"([0-9a-f]{64})\" ]] || return 0
    [ "${BASH_REMATCH[1]}" = "$own_key" ] && return 0
    (umask 077; printf '%s\n' "${BASH_REMATCH[1]}" >"$own_file.tmp" && mv -f "$own_file.tmp" "$own_file") || return 1
    own_key=${BASH_REMATCH[1]}
}

# Koppelen met een koppelcode (eenmalig; RzSense → Agent Manager → 🔗 Koppelcode). Het verzoek is versleuteld met een
# sleutel uit de code; RzSense antwoordt (ook versleuteld) met de eigen sleutel van deze server. Een code die al
# gebruikt is (pair.used) wordt overgeslagen.
pair_code=$(printf '%s' "${PAIR_CODE-}" | tr -dc 'A-Za-z0-9' | tr '[:lower:]' '[:upper:]')
pair_agent() {
    local pk pf raw code body p_key
    pk=$(printf 'rzpair1|%s' "$pair_code" | sha256sum | cut -d' ' -f1)
    pf=$(mktemp "${TMPDIR:-/tmp}/rzmon-pair.XXXXXX") || return 1
    printf '{"agent_version":"%s","hostname":"%s"}' "$AGENT_VERSION" "$(json_escape "$(hostname)")" >"$pf"
    enc_key=$pk
    if ! seal "$pf" "$pf.enc"; then rm -f "$pf" "$pf.enc"; echo "Koppelen mislukt: versleutelen met openssl lukt niet" >&2; return 1; fi
    raw=$(curl -sS "${tls[@]}" -m 20 -X POST -H "Content-Type: application/json" -H "X-Agent-Pair: $(enc_sub "$pk" id | cut -c1-16)" \
        --data-binary "@$pf.enc" -w '\n%{http_code}' "${MONITOR_URL%/}/api.php?action=agent_pair" 2>&1)
    rm -f "$pf" "$pf.enc"
    code=${raw##*$'\n'}; raw=${raw%$'\n'*}
    if [ "$code" != 200 ] || ! body=$(unseal "$raw" "$seal_n"); then
        echo "Koppelen mislukt (HTTP $code): $(sed -n 's/.*"error":"\([^"]*\)".*/\1/p' <<<"$raw" | head -c 200)" >&2
        [ "$code" = 200 ] && echo "  Het antwoord van RzSense klopt niet (handtekening); niet gekoppeld." >&2
        return 1
    fi
    p_key=$(sed -n 's/.*"agent_key":"\([0-9a-f]\{64\}\)".*/\1/p' <<<"$body")
    [ -n "$p_key" ] || { echo "Koppelen mislukt: geen sleutel in het antwoord" >&2; return 1; }
    own_key=""; store_key "$body" || { echo "Koppelen mislukt: kan $own_file niet schrijven" >&2; return 1; }
    printf '%s' "$pair_code" | sha256sum | cut -c1-16 >"$k_dir/pair.used"
    rm -f "$t_dir/enc.off" "$t_dir/enc.fail"
    store_pin "$body"
    echo "🔑 Gekoppeld met RzSense (server: $(sed -n 's/.*"host":"\([^"]*\)".*/\1/p' <<<"$body")): eigen sleutel ontvangen" >&2
}
pair_done() { [ "$(cat "$k_dir/pair.used" 2>/dev/null)" = "$(printf '%s' "$pair_code" | sha256sum | cut -c1-16)" ]; }
if [ -n "$pair_code" ] && command -v openssl >/dev/null 2>&1 && ! pair_done; then
    # Agent 1.68.0: één run tegelijk koppelt (de code werkt maar één keer). Bv. de timer die net start en het
    # testrapport van de installatie: de tweede wacht en gebruikt dan de sleutel die de eerste kreeg.
    if command -v flock >/dev/null 2>&1; then
        exec 8>"$k_dir/pair.lock"
        flock -w 30 8 2>/dev/null || flock 8 2>/dev/null || true
    fi
fi
if [ -n "$pair_code" ] && command -v openssl >/dev/null 2>&1 && pair_done; then
    # Al gekoppeld (eventueel net door een andere run): de eigen sleutel gebruiken
    if [ -z "$own_key" ] && [ -r "$own_file" ]; then
        own_key=$(tr -dc '0-9a-f' <"$own_file" | head -c 64)
        [ "${#own_key}" = 64 ] || own_key=""
        if [ -n "$own_key" ] && [ ! -e "$t_dir/enc.off" ]; then enc_key=$own_key; set_auth; set_tls; fi
    fi
elif [ -n "$pair_code" ] && command -v openssl >/dev/null 2>&1; then
    prev_key=$enc_key
    if pair_agent; then enc_key=$own_key
    else
        enc_key=$prev_key
        # Zonder sleutel en zonder token valt er niets te versturen
        [ -z "$enc_key" ] && ! [[ "$TOKEN" =~ ^[A-Za-z0-9]{16,128}$ ]] && [ "$TEST" != 1 ] && exit 1
    fi
    set_auth; set_tls
fi
{ exec 8>&-; } 2>/dev/null   # 1.73.0: alleen dit stil (was: alle foutmeldingen van de rest van de run naar /dev/null)

