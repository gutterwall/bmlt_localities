#!/usr/bin/env bash
# End-to-end test of the release zip on real servers:
#
#   fake BMLT root server (fake-bmlt/router.php) behind HTTPS (nginx, with a certificate made for the test that only
#   this WordPress trusts: the plugin accepts https root servers only)
#   WordPress (latest, default block theme) with the plugin zip, served by
#     ├─ Apache + PHP-FPM
#     └─ nginx + PHP-FPM
#   headless Chrome as the administrator and as visitors (bin/browser-e2e.py)
#
# Then BMLT is taken down: visitors still get the last list, and saving the settings keeps the selection. Last, the
# plugin is uninstalled and must leave nothing behind.
#
# Usage: bin/e2e.sh <work-dir>      (the directory is created; KEEP=1 leaves the servers' files behind)
#
# Environment (defaults suit Ubuntu with apache2, nginx, php-fpm and google-chrome installed):
#   DB_HOST=127.0.0.1 DB_PORT=3306 DB_ADMIN_USER=root DB_ADMIN_PASS=
#   APACHE=apache2  APACHE_MODULES=/usr/lib/apache2/modules  NGINX=nginx  PHP_FPM=php-fpm8.3  CHROME=google-chrome
#   WP_CORE=<directory with WordPress files> (default: wp core download)   PORT_BASE=8560
#   PLUGIN_ZIP (default: this version's zip in dist/, built if missing)
set -euo pipefail

[ $# -eq 1 ] || { sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
ROOT=$(cd "$(dirname "$0")/.." && pwd)
W=$(mkdir -p "$1" && cd "$1" && pwd)
DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${DB_PORT:-3306}
DB_ADMIN_USER=${DB_ADMIN_USER:-root}
DB_ADMIN_PASS=${DB_ADMIN_PASS:-}
APACHE=${APACHE:-apache2}
APACHE_MODULES=${APACHE_MODULES:-/usr/lib/apache2/modules}
NGINX=${NGINX:-nginx}
PHP_FPM=${PHP_FPM:-php-fpm8.3}
P=${PORT_BASE:-8560}
BMLT_PORT=$((P + 1)) BMLT_HTTP_PORT=$((P + 2)) APACHE_PORT=$((P + 3)) NGINX_PORT=$((P + 4))
export BMLT_PORT
SITE_A="http://127.0.0.1:$APACHE_PORT"
SITE_N="http://127.0.0.1:$NGINX_PORT"
BMLT_ROOT="https://127.0.0.1:$BMLT_PORT/main_server"

pass() { echo "  ok   $*"; }
fail() { echo "  FAIL $*" >&2; exit 1; }
section() { echo; echo "== $*"; }

PL=bmlt-localities-by-service-body
V=$(sed -n 's/^ \* Version: *//p' "$ROOT/$PL/$PL.php")
# PLUGIN_ZIP tests another zip (for example the previous release, to see the test fail).
PLUGIN_ZIP=${PLUGIN_ZIP:-$ROOT/dist/$PL-$V.zip}
[ -f "$PLUGIN_ZIP" ] || "$ROOT/bin/build.sh" --dev --allow-dirty

PIDS=()
cleanup() {
	for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null || true; done
	[ -f "$W/run/apache.pid" ] && kill "$(cat "$W/run/apache.pid")" 2>/dev/null || true
	mysql_admin -e "DROP DATABASE IF EXISTS e2e_loc" 2>/dev/null || true
	if [ "${KEEP:-0}" != 1 ]; then rm -rf "$W"; fi
}
trap cleanup EXIT
mysql_admin() { mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_ADMIN_USER" ${DB_ADMIN_PASS:+-p"$DB_ADMIN_PASS"} "$@"; }
settings() { "${WP[@]}" option get bmlt_localities_settings --format=json; }

rm -rf "${W:?}"/*
mkdir -p "$W/run" "$W/logs" "$W/nginx"

section "Fake BMLT root server"
FAKE_BMLT_LOG="$W/logs/bmlt-requests.log" FAKE_BMLT_FAULT_FILE="$W/run/bmlt-fault" \
	php -S "127.0.0.1:$BMLT_HTTP_PORT" "$ROOT/fake-bmlt/router.php" >"$W/logs/bmlt.log" 2>&1 &
PIDS+=($!)
# A certificate for 127.0.0.1, trusted by the test WordPress only (mu-plugin below).
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=127.0.0.1" -addext "subjectAltName=IP:127.0.0.1" \
	-keyout "$W/run/bmlt.key" -out "$W/run/bmlt.crt" 2>/dev/null
pass "fake BMLT on port $BMLT_HTTP_PORT, served over HTTPS as $BMLT_ROOT"

section "PHP-FPM, Apache, nginx"
cat > "$W/run/fpm.conf" <<EOF
[global]
error_log = $W/logs/fpm.log
pid = $W/run/fpm.pid
daemonize = no
[wp]
listen = $W/run/fpm.sock
pm = static
pm.max_children = 8
clear_env = no
catch_workers_output = yes
php_admin_value[error_log] = $W/logs/php.log
php_admin_flag[log_errors] = on
EOF
"$PHP_FPM" -y "$W/run/fpm.conf" -F >"$W/logs/fpm.out" 2>&1 &
PIDS+=($!)

mod() { echo "LoadModule ${1}_module $APACHE_MODULES/mod_$1.so"; }
{
	echo "ServerRoot $W/run"
	echo "ServerName 127.0.0.1"
	echo "PidFile $W/run/apache.pid"
	echo "ErrorLog $W/logs/apache-error.log"
	echo "Mutex file:$W/run"
	echo "<IfModule !mpm_event_module>"; mod mpm_event; echo "</IfModule>"
	echo "<IfModule !unixd_module>"; mod unixd; echo "</IfModule>"
	for m in authz_core authz_host access_compat dir mime rewrite proxy proxy_fcgi setenvif headers env log_config; do
		[ -f "$APACHE_MODULES/mod_$m.so" ] && echo "<IfModule !${m}_module>" && mod "$m" && echo "</IfModule>"
	done
	cat <<EOF
LogFormat "%h \"%r\" %>s" e2e
TypesConfig /dev/null
AddType text/css .css
AddType application/javascript .js
AddType text/plain .txt .md
DirectoryIndex index.php index.html
<Directory />
	AllowOverride None
	Require all denied
</Directory>
Listen 127.0.0.1:$APACHE_PORT
<Directory $W/wp>
	AllowOverride All
	Require all granted
	<FilesMatch "\.php$">
		SetHandler "proxy:unix:$W/run/fpm.sock|fcgi://wp"
	</FilesMatch>
</Directory>
<VirtualHost 127.0.0.1:$APACHE_PORT>
	DocumentRoot $W/wp
	CustomLog $W/logs/apache-access.log e2e
</VirtualHost>
EOF
} > "$W/run/apache.conf"
"$APACHE" -f "$W/run/apache.conf" -t >/dev/null 2>"$W/logs/apache-configtest.log" || { cat "$W/logs/apache-configtest.log"; fail "apache configuration"; }

cat > "$W/nginx/nginx.conf" <<EOF
pid $W/run/nginx.pid;
error_log $W/logs/nginx-error.log;
daemon off;
worker_processes 1;
events { worker_connections 64; }
http {
	access_log $W/logs/nginx-access.log;
	client_body_temp_path $W/run/nb; proxy_temp_path $W/run/np; fastcgi_temp_path $W/run/nf; uwsgi_temp_path $W/run/nu; scgi_temp_path $W/run/ns;
	types { text/html html; text/css css; application/javascript js; image/svg+xml svg; }
	default_type application/octet-stream;
	# HTTPS in front of the fake BMLT root server.
	server {
		listen 127.0.0.1:$BMLT_PORT ssl;
		ssl_certificate $W/run/bmlt.crt;
		ssl_certificate_key $W/run/bmlt.key;
		access_log $W/logs/bmlt-https-access.log;
		location / { proxy_pass http://127.0.0.1:$BMLT_HTTP_PORT; proxy_set_header Host \$host; }
	}
	# The usual WordPress server block (wordpress.org's nginx guide).
	server {
		listen 127.0.0.1:$NGINX_PORT;
		server_name 127.0.0.1;
		root $W/wp;
		index index.php;
		location / { try_files \$uri \$uri/ /index.php?\$args; }
		location ~ \.php\$ {
			try_files \$uri =404;
			fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
			fastcgi_param QUERY_STRING \$query_string; fastcgi_param REQUEST_METHOD \$request_method;
			fastcgi_param CONTENT_TYPE \$content_type; fastcgi_param CONTENT_LENGTH \$content_length;
			fastcgi_param SCRIPT_NAME \$fastcgi_script_name; fastcgi_param REQUEST_URI \$request_uri;
			fastcgi_param DOCUMENT_URI \$document_uri; fastcgi_param DOCUMENT_ROOT \$document_root;
			fastcgi_param SERVER_PROTOCOL \$server_protocol; fastcgi_param REMOTE_ADDR \$remote_addr;
			fastcgi_param REMOTE_PORT \$remote_port; fastcgi_param SERVER_ADDR \$server_addr;
			fastcgi_param SERVER_PORT \$server_port; fastcgi_param SERVER_NAME \$server_name;
			fastcgi_param HTTP_HOST \$http_host;
			fastcgi_pass unix:$W/run/fpm.sock;
		}
	}
}
EOF

section "WordPress with the plugin zip"
WP=(wp --path="$W/wp" --quiet)
if [ -n "${WP_CORE:-}" ]; then
	mkdir -p "$W/wp" && (cd "$WP_CORE" && tar --exclude=./wp-config.php --exclude=./wp-content/uploads -cf - .) | tar -xf - -C "$W/wp"
else
	"${WP[@]}" core download --path="$W/wp" >/dev/null
fi
mysql_admin -e "DROP DATABASE IF EXISTS e2e_loc; CREATE DATABASE e2e_loc CHARACTER SET utf8mb4"
"${WP[@]}" config create --dbname=e2e_loc --dbuser="$DB_ADMIN_USER" --dbpass="$DB_ADMIN_PASS" --dbhost="$DB_HOST:$DB_PORT" --skip-check --force \
	--extra-php <<'EOF'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
// The same site behind Apache and nginx (two ports): links follow the address used.
if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
	define( 'WP_HOME', 'http://' . $_SERVER['HTTP_HOST'] );
	define( 'WP_SITEURL', 'http://' . $_SERVER['HTTP_HOST'] );
}
EOF
"${WP[@]}" core install --url="$SITE_A" --title="E2E Region" --admin_user=admin --admin_password=Admin-pass-1 --admin_email=admin@example.org --skip-email
"${WP[@]}" rewrite structure '/%postname%/'
# WordPress's standard rules (what Settings → Permalinks writes on Apache).
cat > "$W/wp/.htaccess" <<'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF
mkdir -p "$W/wp/wp-content/mu-plugins"
# The test BMLT server is on 127.0.0.1 and an unusual port: the site owner allows it with WordPress's own filters, as
# the readme describes. Its certificate is trusted for that address only.
cat > "$W/wp/wp-content/mu-plugins/e2e-local-bmlt.php" <<EOF
<?php
add_filter( 'http_request_host_is_external', static function ( \$external, \$host ) {
	return \$external || '127.0.0.1' === \$host;
}, 10, 2 );
add_filter( 'http_allowed_safe_ports', static function ( \$ports ) {
	\$ports[] = $BMLT_PORT;
	return \$ports;
} );
add_filter( 'http_request_args', static function ( \$args, \$url ) {
	if ( 0 === strpos( \$url, 'https://127.0.0.1:$BMLT_PORT/' ) ) {
		\$args['sslcertificates'] = '$W/run/bmlt.crt';
	}
	return \$args;
}, 10, 2 );
EOF
"${WP[@]}" plugin install "$PLUGIN_ZIP" --activate
"$APACHE" -f "$W/run/apache.conf" -k start
"$NGINX" -e "$W/logs/nginx-early.log" -p "$W/nginx" -c "$W/nginx/nginx.conf" >"$W/logs/nginx.out" 2>&1 &
PIDS+=($!)
for i in $(seq 1 50); do
	curl -s -o /dev/null "$SITE_A/" && curl -s -o /dev/null "$SITE_N/" \
		&& curl -sf -o /dev/null --cacert "$W/run/bmlt.crt" "$BMLT_ROOT/client_interface/json/?switcher=GetServerInfo" && break
	sleep 0.2
done
curl -sf -o /dev/null --cacert "$W/run/bmlt.crt" "$BMLT_ROOT/client_interface/json/?switcher=GetServerInfo" || fail "fake BMLT over HTTPS"
pass "WordPress $("${WP[@]}" core version), theme $("${WP[@]}" theme list --status=active --field=name), plugin $("${WP[@]}" plugin get $PL --field=version); Apache $("$APACHE" -v | sed -n 's/Server version: Apache\/\([0-9.]*\).*/\1/p'), nginx $("$NGINX" -v 2>&1 | sed 's/.*nginx\///'), PHP $(php -r 'echo PHP_VERSION;')"
PAGE=$("${WP[@]}" post create --post_type=page --post_status=publish --post_title='Where We Meet' --post_name=where-we-meet --post_content='<!-- wp:shortcode -->[bmlt_localities]<!-- /wp:shortcode -->' --porcelain)
pass "page \"Where We Meet\" ($PAGE) with [bmlt_localities]"
EXPECTED_SETTINGS='{"server":"https:\/\/127.0.0.1:'"$BMLT_PORT"'\/main_server","bodies":[101,102,103,104,106]}'

section "Browser: the administrator sets up the plugin"
python3 "$ROOT/bin/browser-e2e.py" setup "$SITE_A" "$BMLT_ROOT"
[ "$(settings)" = "$EXPECTED_SETTINGS" ] || fail "saved settings: $(settings)"
pass "settings stored as entered in the browser"

section "Browser: visitors (Apache, then nginx)"
: > "$W/logs/bmlt-requests.log"
python3 "$ROOT/bin/browser-e2e.py" visit "$SITE_A" "$SITE_A/where-we-meet/" apache
python3 "$ROOT/bin/browser-e2e.py" visit "$SITE_N" "$SITE_N/where-we-meet/" nginx
switchers=$(grep -o '"switcher":"[A-Za-z]*"' "$W/logs/bmlt-requests.log" | cut -d'"' -f4 | tr '\n' ' ')
# The service bodies are already cached from the settings page.
[ "$switchers" = "GetSearchResults " ] || fail "BMLT requests for two page views: $switchers"
grep -q '"data_field_key":"service_body_bigint,location_municipality,location_province"' "$W/logs/bmlt-requests.log" \
	&& grep -q '"services":\["101","102","103","104","106"\]' "$W/logs/bmlt-requests.log" \
	|| fail "GetSearchResults request: $(cat "$W/logs/bmlt-requests.log")"
pass "two page views, one BMLT request: a meeting search for the five bodies at once, three fields (service bodies cached by the settings page)"

section "BMLT down"
echo down > "$W/run/bmlt-fault"
# An hour later: the fresh cache entries have expired; the last good copies are kept for a week.
"${WP[@]}" eval 'global $wpdb; foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"\\_transient\\_bmlt\\_loc\\_%\" AND option_name NOT LIKE \"%\\_stale\\_%\"" ) as $n ) { delete_transient( substr( $n, 11 ) ); }'
: > "$W/logs/bmlt-requests.log"
python3 "$ROOT/bin/browser-e2e.py" visit "$SITE_A" "$SITE_A/where-we-meet/" "apache, BMLT down"
python3 "$ROOT/bin/browser-e2e.py" visit "$SITE_N" "$SITE_N/where-we-meet/" "nginx, BMLT down"
n=$(wc -l < "$W/logs/bmlt-requests.log")
[ "$n" -eq 2 ] || fail "BMLT requests during the outage for two views: $n"
pass "the last good list is shown; BMLT was asked once (two requests), not again on the next view"
# Nothing cached at all: visitors get a short notice.
"${WP[@]}" transient delete --all >/dev/null
code=$(curl -s -o "$W/run/page.html" -w '%{http_code}' "$SITE_N/where-we-meet/")
[ "$code" = 200 ] && grep -q 'Meeting locality data is temporarily unavailable.' "$W/run/page.html" || fail "page with BMLT down and no cache: $code"
pass "with nothing cached, visitors see \"Meeting locality data is temporarily unavailable.\" (HTTP 200)"
"${WP[@]}" transient delete --all >/dev/null
python3 "$ROOT/bin/browser-e2e.py" outage "$SITE_A"
[ "$(settings)" = "$EXPECTED_SETTINGS" ] || fail "selection after saving during the outage: $(settings)"
pass "the selection is unchanged after saving during the outage"
: > "$W/run/bmlt-fault"

section "Uninstall"
"${WP[@]}" plugin deactivate $PL
"${WP[@]}" plugin uninstall $PL
left=$("${WP[@]}" db query "SELECT option_name FROM wp_options WHERE option_name LIKE '%bmlt\_loc%'" --skip-column-names)
[ -z "$left" ] || fail "left behind: $left"
[ ! -d "$W/wp/wp-content/plugins/$PL" ] || fail "plugin folder still there"
pass "settings, cached data and the plugin folder removed"

section "Logs"
if grep -v 'PHP Deprecated' "$W/logs/php.log" 2>/dev/null | grep -q 'PHP '; then
	grep 'PHP ' "$W/logs/php.log" | tail -20
	fail "PHP errors in the server log"
fi
if [ -s "$W/wp/wp-content/debug.log" ] && grep -q "$PL" "$W/wp/wp-content/debug.log"; then
	grep "$PL" "$W/wp/wp-content/debug.log" | tail -20
	fail "the plugin logged PHP notices or errors"
fi
pass "no PHP warnings or errors from the plugin"
grep -q "\" 5[0-9][0-9]$" "$W/logs/apache-access.log" && fail "a 5xx answer from Apache: $(grep '" 5' "$W/logs/apache-access.log" | head -3)"
awk '$9 ~ /^5/' "$W/logs/nginx-access.log" | grep -q . && fail "a 5xx answer from nginx: $(awk '$9 ~ /^5/' "$W/logs/nginx-access.log" | head -3)"
pass "no server errors"
echo
echo "End-to-end test passed."
