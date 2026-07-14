#!/usr/bin/env bash
# PioDeploy VPS bootstrap — run as root FROM the cloned repo:
#   git clone <your-repo> /var/www/piodeploy
#   cd /var/www/piodeploy && sudo bash deploy/setup.sh
#
# Edit the CONFIG block below first. DNS for the domain must already point
# at this server (certbot needs it). Mail + Stripe keys are set by hand in
# .env afterwards — this script does not handle them.
set -euo pipefail

# ─────────────── CONFIG — edit these ───────────────
DOMAIN="piodeploy.com"
APP_DIR="/var/www/piodeploy"
DB_NAME="piodeploy_platform"
DB_USER="piodeploy"
DB_PASSWORD="CHANGE-ME-strong-db-password"
CERTBOT_EMAIL="you@piodeploy.com"
ADMIN_NAME="Admin"
ADMIN_EMAIL="you@piodeploy.com"
ADMIN_PASSWORD="CHANGE-ME-strong-admin-password"   # 10+ chars, letters+numbers
# ───────────────────────────────────────────────────

echo "==> Installing packages"
export DEBIAN_FRONTEND=noninteractive
apt update && apt upgrade -y
apt install -y nginx mariadb-server php8.3-fpm php8.3-cli php8.3-mysql \
  php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath \
  php8.3-intl unzip git curl
if ! command -v composer >/dev/null; then
  curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer
fi
if ! command -v node >/dev/null; then
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && apt install -y nodejs
fi

echo "==> Creating database"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

cd "$APP_DIR"

echo "==> Installing dependencies + building assets"
composer install --no-dev --optimize-autoloader
npm ci && npm run build

echo "==> Writing .env"
if [ ! -f .env ]; then cp deploy/.env.production.example .env; fi
sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" .env
sed -i "s|^ASSET_URL=.*|ASSET_URL=https://${DOMAIN}|" .env
sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" .env
sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" .env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env
php artisan key:generate --force

echo "==> Migrating + seeding"
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan storage:link || true

echo "==> Creating the first Super Admin"
php artisan tinker --execute "
\$u = App\\Models\\User::firstOrCreate(['email'=>'${ADMIN_EMAIL}'], ['name'=>'${ADMIN_NAME}','password'=>bcrypt('${ADMIN_PASSWORD}')]);
\$u->forceFill(['email_verified_at'=>now()])->save();
\$u->syncRoles(['Super Admin']);
echo 'admin ready';
"

echo "==> Caching config"
php artisan config:cache && php artisan route:cache && php artisan view:cache

echo "==> Permissions"
chown -R www-data:www-data "$APP_DIR"
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

echo "==> nginx"
sed "s|piodeploy.com|${DOMAIN}|g; s|/var/www/piodeploy|${APP_DIR}|g" deploy/nginx-piodeploy.conf > /etc/nginx/sites-available/piodeploy
ln -sf /etc/nginx/sites-available/piodeploy /etc/nginx/sites-enabled/piodeploy
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo "==> HTTPS (certbot)"
apt install -y certbot python3-certbot-nginx
certbot --nginx -d "${DOMAIN}" -d "www.${DOMAIN}" --redirect -m "${CERTBOT_EMAIL}" --agree-tos --non-interactive || \
  echo "!! certbot failed — check that DNS for ${DOMAIN} points here, then re-run: certbot --nginx -d ${DOMAIN} -d www.${DOMAIN} --redirect"

echo "==> Scheduler cron"
( crontab -u www-data -l 2>/dev/null | grep -v 'artisan schedule:run' ; \
  echo "* * * * * cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1" ) | crontab -u www-data -

echo "==> Queue worker service"
cat >/etc/systemd/system/piodeploy-queue.service <<EOF
[Unit]
Description=PioDeploy queue worker
After=network.target
[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php ${APP_DIR}/artisan queue:work --sleep=3 --tries=3 --max-time=3600
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload && systemctl enable --now piodeploy-queue

echo ""
echo "============================================================"
echo " Done. Now:"
echo "  1. Edit ${APP_DIR}/.env  → set MAIL_* (and STRIPE_* later), then:"
echo "       php artisan config:cache"
echo "  2. Upload the agent bundle to:"
echo "       ${APP_DIR}/storage/app/private/agent/PioDeployAgent.zip"
echo "  3. php artisan security:check   (should pass)"
echo "  Visit: https://${DOMAIN}   ·   sign in at https://${DOMAIN}/login"
echo "============================================================"
