#!/usr/bin/env bash
# LuxAeterna · instalación/actualización en un VPS Ubuntu/Debian con Nginx ya instalado (perfil A).
# Idempotente: repetirlo actualiza el código y reaplica la configuración. No toca otros sitios de Nginx.
#
# Uso (como root):
#   bash install-vps.sh --domain=luxaeterna.es [--base=/var/www/luxaeterna.es] [--email=tu@correo]
#                       [--repo=https://github.com/DavidJLlanes/LuxAeterna.git] [--branch=main] [--skip-ssl] [--no-ipv6]
#
# Variables solo para pruebas: LUX_NO_SERVICES=1 (no recarga servicios), LUX_SKIP_DNS=1.
set -euo pipefail

DOMAIN="luxaeterna.es"
BASE="/var/www/luxaeterna.es"
REPO="https://github.com/DavidJLlanes/LuxAeterna.git"
BRANCH="main"
EMAIL=""
SKIP_SSL=0
NO_IPV6=0
for a in "$@"; do
  case "$a" in
    --domain=*) DOMAIN="${a#*=}" ;;
    --base=*) BASE="${a#*=}" ;;
    --repo=*) REPO="${a#*=}" ;;
    --branch=*) BRANCH="${a#*=}" ;;
    --email=*) EMAIL="${a#*=}" ;;
    --skip-ssl) SKIP_SSL=1 ;;
    --no-ipv6) NO_IPV6=1 ;;
    *) echo "Opción desconocida: $a"; exit 1 ;;
  esac
done
BASE="${BASE%/}"
APP_USER="luxaeterna"
NOSVC="${LUX_NO_SERVICES:-0}"

say() { printf '\n== %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
svc() { [ "$NOSVC" = 1 ] && echo "(omitido en pruebas) systemctl $*" || systemctl "$@"; }

[ "$(id -u)" = 0 ] || die "Ejecútalo como root (sudo -i)."
command -v nginx >/dev/null || die "Nginx no está instalado."
command -v apt-get >/dev/null || die "Solo se soportan Ubuntu/Debian (apt)."
[[ "$DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || die "Dominio no válido: $DOMAIN"
[[ "$BASE" == /* && "$BASE" != "/" ]] || die "--base debe ser una ruta absoluta."

say "1/9 DNS"
IP="$(getent ahostsv4 "$DOMAIN" | awk 'NR==1{print $1}' || true)"
[ -n "$IP" ] || [ "${LUX_SKIP_DNS:-0}" = 1 ] || die "$DOMAIN no resuelve. Crea el registro A hacia la IP de este VPS y repite."
echo "$DOMAIN → $IP (comprueba que es la IP de este servidor)"

say "2/9 Paquetes (PHP-FPM, extensiones, git, certbot)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
PKGS="git curl php-fpm php-cli php-sqlite3 php-mbstring php-gd php-xml"
[ "$SKIP_SSL" = 1 ] || PKGS="$PKGS certbot"
# shellcheck disable=SC2086
apt-get install -y -qq $PKGS >/dev/null
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
php -r 'exit(version_compare(PHP_VERSION,"8.1.0",">=")?0:1);' || die "Se requiere PHP 8.1 o superior (hay $PHPV)."
POOLDIR="/etc/php/$PHPV/fpm/pool.d"
[ -d "$POOLDIR" ] || die "No existe $POOLDIR (¿php$PHPV-fpm instalado?)."
FPM_BIN="$(command -v "php-fpm$PHPV" || true)"; [ -n "$FPM_BIN" ] || die "No se encuentra php-fpm$PHPV."
echo "PHP $PHPV"

say "3/9 Usuario y directorios"
id "$APP_USER" >/dev/null 2>&1 || useradd --system --home-dir "$BASE" --no-create-home --shell /usr/sbin/nologin "$APP_USER"
install -d -m 0755 -o root -g root "$BASE" "$BASE/acme"
install -d -m 0750 -o "$APP_USER" -g "$APP_USER" "$BASE/var"
install -d -m 0755 -o "$APP_USER" -g "$APP_USER" "$BASE/publish"

say "4/9 Código ($REPO @ $BRANCH)"
if [ -d "$BASE/app/.git" ]; then
  git -C "$BASE/app" fetch --quiet origin "$BRANCH"
  git -C "$BASE/app" checkout --quiet "$BRANCH"
  git -C "$BASE/app" merge --ff-only --quiet "origin/$BRANCH"
else
  [ ! -e "$BASE/app" ] || [ -z "$(ls -A "$BASE/app" 2>/dev/null)" ] || die "$BASE/app existe y no es un repositorio; muévelo antes."
  git clone --quiet --branch "$BRANCH" "$REPO" "$BASE/app"
fi
# El código es de root y de solo lectura para el usuario de la aplicación.
chown -R root:root "$BASE/app"
chmod -R go-w "$BASE/app"
echo "Versión: $(git -C "$BASE/app" log -1 --format='%h %s')"

say "5/9 Configuración e instalación"
CFG="$BASE/app/config/lux.json"
if [ ! -f "$CFG" ]; then
  cat > "$CFG" <<JSON
{
  "schema": 1,
  "site": { "base_url": "https://$DOMAIN", "lang": "es", "name": "LuxAeterna" },
  "paths": { "data": "$BASE/var", "publish": "$BASE/publish" },
  "admin": { "prefix": "/admin" },
  "publishing": { "keep_releases": 5, "activation": "auto" }
}
JSON
  echo "Configuración creada."
else
  echo "Configuración existente conservada."
fi
# El código es de root; solo la configuración es legible por el usuario de la aplicación.
chown root:"$APP_USER" "$CFG"; chmod 0640 "$CFG"
runuser -u "$APP_USER" -- php "$BASE/app/bin/lux" install --url="https://$DOMAIN"

say "6/9 Pool de PHP-FPM (usuario $APP_USER, solo el panel)"
POOL="$POOLDIR/luxaeterna-panel.conf"
sed -e "s#/var/www/luxaeterna.es#$BASE#g" "$BASE/app/deploy/nginx/php-fpm-pool.conf" > "$POOL"
"$FPM_BIN" -t >/dev/null 2>&1 || { rm -f "$POOL"; die "La configuración de PHP-FPM no es válida; se ha retirado el pool."; }
svc reload "php$PHPV-fpm"

say "7/9 Nginx"
if [ -d /etc/nginx/sites-available ]; then
  VHOST="/etc/nginx/sites-available/$DOMAIN.conf"; LINK="/etc/nginx/sites-enabled/$DOMAIN.conf"
else
  VHOST="/etc/nginx/conf.d/$DOMAIN.conf"; LINK=""
fi
[ -f "$VHOST" ] && cp -a "$VHOST" "$VHOST.bak-$(date +%Y%m%d%H%M%S)"
enable() { [ -z "$LINK" ] || ln -sfn "$VHOST" "$LINK"; }
reload_nginx() {
  if nginx -t 2>/tmp/lux-nginx-test.log; then svc reload nginx; return 0; fi
  cat /tmp/lux-nginx-test.log >&2; return 1
}
render_final() {
  sed -e "s#/var/www/luxaeterna.es#$BASE#g" -e "s#luxaeterna\.es#$DOMAIN#g" "$BASE/app/deploy/nginx/luxaeterna.conf" > "$VHOST"
  [ "$NO_IPV6" = 0 ] || sed -i '/listen \[::\]/d' "$VHOST"
}
CERT="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
if [ "$SKIP_SSL" = 0 ] && [ ! -f "$CERT" ]; then
  cat > "$VHOST" <<NGX
server {
    listen 80;
    $( [ "$NO_IPV6" = 1 ] || echo "listen [::]:80;" )
    server_name $DOMAIN;
    location /.well-known/acme-challenge/ { root $BASE/acme; }
    location / { return 404; }
}
NGX
  enable
  reload_nginx || { rm -f "$VHOST" "$LINK"; die "Nginx rechaza la configuración temporal; se ha retirado."; }
  say "8/9 Certificado Let's Encrypt"
  MAIL_ARG="--register-unsafely-without-email"; [ -z "$EMAIL" ] || MAIL_ARG="--email $EMAIL"
  # shellcheck disable=SC2086
  certbot certonly --webroot -w "$BASE/acme" -d "$DOMAIN" --non-interactive --agree-tos $MAIL_ARG \
    || { rm -f "$VHOST" "$LINK"; reload_nginx || true; die "No se pudo obtener el certificado (¿DNS y puerto 80 abiertos?). Configuración retirada."; }
else
  say "8/9 Certificado"; echo "Ya existe o se omite (--skip-ssl)."
fi
if [ ! -f "$CERT" ]; then
  die "No hay certificado en $CERT: la configuración incluida exige HTTPS. Instálalo (o repite sin --skip-ssl)."
fi
render_final
enable
if ! reload_nginx; then
  die "Nginx rechaza la configuración final. Revisa $VHOST (copia anterior: $VHOST.bak-*)."
fi

say "9/9 Publicación y verificación"
runuser -u "$APP_USER" -- php "$BASE/app/bin/lux" publish
runuser -u "$APP_USER" -- php "$BASE/app/bin/lux" doctor --http || true
echo
echo "Comprobaciones externas:"
for u in "/" "/sobre-nosotros/" "/admin/" "/var/" "/.lux-release.json"; do
  printf '  %-22s %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "https://$DOMAIN$u" || true)"
done
echo "Esperado: 200, 200, 200, 404, 404."
echo
echo "Listo. Para actualizar: repite este mismo comando. Para volver a una versión: runuser -u $APP_USER -- php $BASE/app/bin/lux releases | rollback <id>"
