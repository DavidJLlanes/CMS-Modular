#!/usr/bin/env bash
# Prueba HTTP real del panel con el servidor integrado de PHP (sin Nginx). Uso: tests/http-smoke.sh
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; PORT=$((20000 + RANDOM % 10000)); PASS=0; FAIL=0
trap 'kill $PID 2>/dev/null; rm -rf "$T"' EXIT
cp -r "$ROOT/bin" "$ROOT/src" "$ROOT/config" "$ROOT/migrations" "$ROOT/public" "$ROOT/tests" "$ROOT/components" "$T/"
cd "$T" && php bin/lux install --force --url="http://127.0.0.1:$PORT" >/dev/null
LINK=$(php bin/lux admin:create smoke@example.org | grep -o 'http://[^ ]*')
php -S "127.0.0.1:$PORT" -t public tests/http-router.php >/dev/null 2>&1 & PID=$!
sleep 1
B="http://127.0.0.1:$PORT"
ok(){ if [ "$2" = "$3" ]; then PASS=$((PASS+1)); else FAIL=$((FAIL+1)); echo "FALLO: $1 (esperado '$2', obtenido '$3')"; fi; }
code(){ curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$@"; }
ok "login 200" 200 "$(code $B/admin/login)"
ok "sin sesión redirige" 303 "$(code $B/admin/)"
ok "css servido" 200 "$(code $B/admin/assets/admin.css)"
ok "css content-type" "text/css" "$(curl -s --noproxy '*' -o /dev/null -w '%{content_type}' $B/admin/assets/admin.css | cut -d';' -f1)"
ok "fuera del prefijo 404" 404 "$(code $B/otra)"
ok "traversal no sirve código" 0 "$(curl -s --noproxy '*' --path-as-is "$B/admin/assets/../../src/Core/Config.php" | grep -c 'namespace')"
ok "enlace de alta válido" 200 "$(code "$LINK")"
ok "enlace falso" 400 "$(code "$B/admin/setup?token=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")"
HDR="$(curl -s --noproxy '*' -D - -o /dev/null $B/admin/login)"
for h in "content-security-policy: default-src 'none'" "x-content-type-options: nosniff" "cache-control: no-store" "x-frame-options: DENY"; do
  echo "$HDR" | tr -d '\r' | grep -qi "^$h" && ok "cabecera $h" 1 1 || ok "cabecera $h" presente ausente
done
JAR="$T/jar"; PAGE=$(curl -s --noproxy '*' -c "$JAR" $B/admin/login); CS=$(echo "$PAGE" | grep -o 'name="csrf" value="[^"]*"' | sed 's/.*value="//;s/"//')
ok "POST sin origen ajeno, credenciales malas" 401 "$(code -b "$JAR" -c "$JAR" -d "csrf=$CS&email=x@y.es&password=mala" $B/admin/login)"
ok "POST con origen ajeno" 403 "$(code -b "$JAR" -H 'Origin: https://evil.example' -d "csrf=$CS&email=x@y.es&password=mala" $B/admin/login)"
echo "$PASS correctas, $FAIL con fallo"; [ $FAIL -eq 0 ]
