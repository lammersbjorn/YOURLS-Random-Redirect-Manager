#!/usr/bin/env bash
# Generate user/config.php from environment variables on first start, then
# hand off to whatever command was requested (defaults to apache2-foreground).
set -euo pipefail

CONFIG="/var/www/html/user/config.php"

if [ ! -f "$CONFIG" ]; then
    : "${YOURLS_DB_HOST:=db}"
    : "${YOURLS_DB_USER:=yourls}"
    : "${YOURLS_DB_PASS:=yourlspass}"
    : "${YOURLS_DB_NAME:=yourls}"
    : "${YOURLS_SITE:=http://localhost:8080}"
    : "${YOURLS_USER:=admin}"
    : "${YOURLS_PASS:=admin}"
    : "${YOURLS_PRIVATE:=false}"

    cookiekey=$(head -c 32 /dev/urandom | base64)

    cat > "$CONFIG" <<PHP
<?php
define( 'YOURLS_DB_USER',  '${YOURLS_DB_USER}' );
define( 'YOURLS_DB_PASS',  '${YOURLS_DB_PASS}' );
define( 'YOURLS_DB_NAME',  '${YOURLS_DB_NAME}' );
define( 'YOURLS_DB_HOST',  '${YOURLS_DB_HOST}' );
define( 'YOURLS_DB_PREFIX', 'yourls_' );
define( 'YOURLS_SITE',      '${YOURLS_SITE}' );
define( 'YOURLS_HOURS_OFFSET', 0 );
define( 'YOURLS_LANG', '' );
define( 'YOURLS_UNIQUE_URLS', false );
define( 'YOURLS_PRIVATE', ${YOURLS_PRIVATE} );
define( 'YOURLS_COOKIEKEY', '${cookiekey}' );
\$yourls_user_passwords = array(
    '${YOURLS_USER}' => '${YOURLS_PASS}',
);
define( 'YOURLS_URL_CONVERT', 36 );
\$yourls_reserved_URL = array();
define( 'YOURLS_DEBUG', false );
define( 'YOURLS_NOSTATS', false );
// Test environment — drop the flood-protection delay so the suite can
// rapid-fire create_shortlink calls without getting HTTP 429.
define( 'YOURLS_FLOOD_DELAY_SECONDS', 0 );
PHP

    chown www-data:www-data "$CONFIG"
fi

# YOURLS' source archive on GitHub strips index.php (it expects users to copy
# from sample-public-front-page.txt or rely on the release ZIP). Ship a
# minimal one ourselves that simply hands off to the loader, so requests for
# / reach the plugin's shutdown / pre_redirect hooks the same way they would
# in a real install.
if [ ! -f /var/www/html/index.php ]; then
    cat > /var/www/html/index.php <<'PHP'
<?php
require_once __DIR__ . '/yourls-loader.php';
PHP
    chown www-data:www-data /var/www/html/index.php
fi

# YOURLS expects a .htaccess at the docroot to rewrite short URLs into
# yourls-loader.php. The installer creates it after a successful install,
# but seeding it here means the first /keyword visit already works.
if [ ! -f /var/www/html/.htaccess ]; then
    cat > /var/www/html/.htaccess <<'HTACCESS'
# BEGIN YOURLS
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^.*$ /yourls-loader.php [L]
</IfModule>
# END YOURLS
HTACCESS
    chown www-data:www-data /var/www/html/.htaccess
fi

# Make sure plugin folders are writable when bind-mounted in.
if [ -d /var/www/html/user/plugins ]; then
    chown -R www-data:www-data /var/www/html/user/plugins 2>/dev/null || true
fi

exec "$@"
