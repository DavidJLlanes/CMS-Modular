#!/bin/bash
# LuxAeterna · aplica las redirecciones 301 propuestas por la aplicación a Nginx. Se ejecuta COMO ROOT (unit systemd).
# La aplicación solo puede escribir una propuesta; este script la valida línea a línea y solo entonces la instala.
# Uso: lux-apply-rules.sh <base>   (base = /var/www/luxaeterna.es)
# Variables para pruebas: NGINX_BIN, RELOAD_CMD, OUT_FILE, STATE_FILE.
set -u
BASE="${1:?uso: $0 <base>}"
DOMAIN="${LUX_DOMAIN:-$(basename "$BASE")}"
SRC="$BASE/publish/rules/redirects.nginx.conf"
REQ="$BASE/publish/rules/apply.request"
OUT="${OUT_FILE:-/etc/nginx/lux-rules/$DOMAIN.conf}"
STATE="${STATE_FILE:-/var/lib/lux-rules/$DOMAIN.status.json}"
NGINX="${NGINX_BIN:-nginx}"
RELOAD="${RELOAD_CMD:-systemctl reload nginx}"
SEG='[a-z0-9]+(-[a-z0-9]+)*'
LINE_RE="^(#.*|)$|^location = /(${SEG}/)* \{ return 301 /(${SEG}/)*; \}$"
MAXLINES=5000

status() { # ok message count
  mkdir -p "$(dirname "$STATE")"
  local tok; tok="$(head -c 64 "$REQ" 2>/dev/null | cut -d' ' -f1 | tr -cd 'a-f0-9')"
  printf '{"ok":%s,"token":"%s","message":"%s","count":%s,"time":%s}\n' "$1" "$tok" "$2" "${3:-0}" "$(date +%s)" > "$STATE.tmp" && mv -f "$STATE.tmp" "$STATE"
  chmod 0644 "$STATE"
}

[ -f "$SRC" ] && [ ! -L "$SRC" ] || { status false "No hay propuesta de reglas válida." 0; exit 1; }
[ ! -L "$(dirname "$SRC")" ] || { status false "Directorio de reglas no válido." 0; exit 1; }
n=0; bad=0; count=0
while IFS= read -r line || [ -n "$line" ]; do
  n=$((n+1))
  [ $n -le $MAXLINES ] || { status false "Demasiadas reglas (máximo $MAXLINES)." 0; exit 1; }
  if ! [[ "$line" =~ $LINE_RE ]]; then bad=$n; break; fi
  [[ "$line" == location* ]] && count=$((count+1))
done < "$SRC"
if [ $bad -ne 0 ]; then status false "Línea $bad con formato no permitido." 0; exit 1; fi

mkdir -p "$(dirname "$OUT")"
[ -f "$OUT" ] && cp -p "$OUT" "$OUT.prev"
cp "$SRC" "$OUT.new" && mv -f "$OUT.new" "$OUT" && chmod 0644 "$OUT"
if ! "$NGINX" -t >/dev/null 2>&1; then
  if [ -f "$OUT.prev" ]; then mv -f "$OUT.prev" "$OUT"; else : > "$OUT"; fi
  status false "Nginx rechazó las reglas; se restauraron las anteriores." 0; exit 1
fi
if ! $RELOAD >/dev/null 2>&1; then
  status false "No se pudo recargar Nginx." 0; exit 1
fi
status true "Reglas aplicadas." "$count"
