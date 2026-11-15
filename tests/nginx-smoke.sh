#!/usr/bin/env bash
# Prueba de humo con Nginx y PHP-FPM reales (Fase 1). Usa deploy/nginx/luxaeterna.conf adaptado a rutas y puerto temporales.
# Requiere: nginx, php-fpm, curl. Ejecutar como root o con permisos para lanzar ambos en puertos altos.
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$(mktemp -d)"; chmod 755 "$T"
PORT=8088
PUBLISH="$(php -r 'require "'"$ROOT"'/src/Core/Autoloader.php"; LuxAeterna\Core\Autoloader::register("'"$ROOT"'/src"); echo LuxAeterna\Core\Config::load("'"$ROOT"'")->path("publish");')"
FPM=$(command -v php-fpm8.3 || command -v php-fpm8.2 || command -v php-fpm8.1 || command -v php-fpm)
FAIL=0
cleanup() { [ -f "$T/nginx.pid" ] && kill "$(cat "$T/nginx.pid")" 2>/dev/null; [ -f "$T/fpm.pid" ] && kill "$(cat "$T/fpm.pid")" 2>/dev/null; sleep 0.3; rm -rf "$T"; }
trap cleanup EXIT

# Servidor: solo el bloque HTTPS, sin TLS, con rutas y socket temporales.
awk '/^server \{/{n++} n>=2' "$ROOT/deploy/nginx/luxaeterna.conf" \
 | sed -e "s#listen 443 ssl http2;#listen 127.0.0.1:$PORT;#" -e '/listen \[::\]:443/d' -e '/ssl_certificate/d' \
       -e "s#/var/www/luxaeterna.es/publish/current#$PUBLISH/current#" -e "s#/var/www/luxaeterna.es/app/public#$ROOT/public#" \
       -e "s#unix:/run/php/luxaeterna-panel.sock#unix:$T/fpm.sock#" > "$T/server.conf"
cp /etc/nginx/fastcgi_params "$T/fastcgi_params"
cat > "$T/nginx.conf" <<EOF
pid $T/nginx.pid; error_log $T/error.log; daemon on;
events { worker_connections 64; }
http { include /etc/nginx/mime.types; access_log $T/access.log; client_body_temp_path $T/b; proxy_temp_path $T/p; fastcgi_temp_path $T/f; uwsgi_temp_path $T/u; scgi_temp_path $T/s;
 include $T/server.conf; }
EOF
cat > "$T/fpm.conf" <<EOF
[global]
pid = $T/fpm.pid
error_log = $T/fpm.log
daemonize = yes
[www]
user = $(id -un)
group = $(id -gn)
listen = $T/fpm.sock
listen.mode = 0666
pm = ondemand
pm.max_children = 2
php_admin_value[display_errors] = 0
EOF
$FPM -R -y "$T/fpm.conf" 2>/dev/null || $FPM -y "$T/fpm.conf" || { echo "No arranca php-fpm"; exit 2; }
nginx -t -c "$T/nginx.conf" >/dev/null 2>"$T/t.log" || { cat "$T/t.log"; exit 2; }
nginx -c "$T/nginx.conf" || exit 2
sleep 0.5

check() { # nombre, esperado, obtenido
  if [ "$2" = "$3" ]; then echo "OK    $1 ($3)"; else echo "FALLO $1: esperado $2, obtenido $3"; FAIL=1; fi
}
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
B="http://127.0.0.1:$PORT"

# 1) Con el panel y PHP-FPM detenidos, el sitio público sigue sirviéndose.
kill "$(cat "$T/fpm.pid")"; sleep 0.5; rm -f "$T/fpm.pid"
check "portada con PHP-FPM detenido" 200 "$(code $B/)"
check "página interior con PHP-FPM detenido" 200 "$(code $B/sobre-nosotros/)"
check "404 real en ruta inexistente" 404 "$(code $B/no-existe/)"
check "cuerpo 404 personalizado" 1 "$(curl -s $B/no-existe/ | grep -c '<h1>Página no encontrada')"
check "panel sin PHP-FPM no es 200" 502 "$(code $B/admin/)"
CSS=$(curl -s $B/ | grep -o '/assets/style\.[0-9a-f]*\.css')
check "css con huella servido" 200 "$(code $B$CSS)"
check "css caché inmutable" 1 "$(curl -sI $B$CSS | grep -ci 'cache-control: public, max-age=31536000, immutable')"
check "html con no-cache" 1 "$(curl -sI $B/ | grep -ci 'cache-control: no-cache')"
check "cabecera nosniff" 1 "$(curl -sI $B/ | grep -ci 'x-content-type-options: nosniff')"
check "sin versión de nginx" 0 "$(curl -sI $B/ | grep -ci 'nginx/')"

# 2) Bloqueos de rutas privadas y ocultas.
for p in /.lux-release.json /var/ /var/db/ /src/Core/Config.php /config/lux.json /bin/lux /publish/ /releases/ /docs/ /deploy/nginx/luxaeterna.conf /.git/config; do
  check "bloqueada $p" 404 "$(code $B$p)"
done

# 3) Con PHP-FPM activo, el panel responde y es el único PHP ejecutable.
rm -f "$T/fpm.sock"; $FPM -R -y "$T/fpm.conf" 2>"$T/fpm2.err" || $FPM -y "$T/fpm.conf"; sleep 0.8
check "panel con PHP-FPM" 200 "$(code $B/admin/)"
check "redirección /admin → /admin/" 301 "$(code $B/admin)"
check "panel noindex" 1 "$(curl -sI $B/admin/ | grep -ci 'x-robots-tag: noindex')"
check "panel no-store" 1 "$(curl -sI $B/admin/ | grep -ci 'cache-control: no-store')"
check "otro php no ejecutable" 404 "$(code $B/admin/otro.php)"
check "sin fuga de rutas en el panel" 0 "$(curl -s $B/admin/ | grep -c "$ROOT")"

# 4) Un php dentro de la versión pública se sirve como texto o se bloquea, nunca se ejecuta.
echo '<?php echo "EJECUTADO";' > "$PUBLISH/current/prueba.php"
check "php en raíz pública ni se ejecuta ni se sirve" 404 "$(code $B/prueba.php)"
rm -f "$PUBLISH/current/prueba.php"

[ $FAIL -ne 0 ] && { echo "--- fpm.log"; tail -5 "$T/fpm.log"; echo "--- nginx error"; tail -5 "$T/error.log"; }
[ $FAIL -eq 0 ] && echo "TODO CORRECTO" || echo "HAY FALLOS"
exit $FAIL
