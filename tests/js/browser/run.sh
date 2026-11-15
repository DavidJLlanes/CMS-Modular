#!/usr/bin/env bash
# Prueba del editor en Chrome sin cabeza (si está instalado). Uso: bash tests/js/browser/run.sh
set -u
cd "$(dirname "$0")/../../.."
CHROME=$(command -v google-chrome || command -v chromium || command -v chromium-browser || true)
[ -z "$CHROME" ] && { echo "SALTADA: no hay Chrome/Chromium"; exit 0; }
PORT=$((20000 + RANDOM % 10000))
python3 -m http.server "$PORT" --bind 127.0.0.1 >/dev/null 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null' EXIT
sleep 1
D=$(mktemp -d)
OUT=$("$CHROME" --headless=new --no-sandbox --disable-gpu --user-data-dir="$D" --virtual-time-budget=4000 --dump-dom "http://127.0.0.1:$PORT/tests/js/browser/harness.html" 2>/dev/null | sed -n '/<pre id="result">/,/<\/pre>/p' | sed 's/<[^>]*>//g' | sed 's/&lt;/</g;s/&gt;/>/g;s/&amp;/\&/g')
rm -rf "$D"
echo "$OUT"
echo "$OUT" | grep -q "RESULT PASS"
