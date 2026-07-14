# Deploying PioDeploy to a Hostinger VPS (Ubuntu)

Target: `piodeploy.com` → Hostinger KVM 2 (Ubuntu), IP `2.25.84.119`.
The VPS hosts the **portal + API** (control plane). The Windows **agent**
still installs on each managed machine — see `agent/DEPLOYMENT.md`.

> Easiest path: point **Laravel Forge** or **Ploi** at this VPS — they do
> nginx, TLS, deploys, the queue worker and the scheduler for you. The
> steps below are the manual equivalent if you'd rather not use them.

---

## 1. DNS — point the domain at the VPS

At your domain registrar for `piodeploy.com`, add:

| Type | Name | Value          |
|------|------|----------------|
| A    | `@`  | `2.25.84.119`  |
| A    | `www`| `2.25.84.119`  |

Wait for it to resolve (`ping piodeploy.com` shows the VPS IP). Usually
minutes, up to a few hours.

## 2. SSH in and install the stack

```bash
ssh root@2.25.84.119

apt update && apt upgrade -y
apt install -y nginx mariadb-server php8.3-fpm php8.3-cli php8.3-mysql \
  php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath \
  php8.3-intl unzip git curl
# Composer
curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer
# Node (for building portal assets)
curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && apt install -y nodejs
```

## 3. Create the database

```bash
mysql -u root
```
```sql
CREATE DATABASE piodeploy_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'piodeploy'@'127.0.0.1' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON piodeploy_platform.* TO 'piodeploy'@'127.0.0.1';
FLUSH PRIVILEGES; EXIT;
```

## 4. Get the code onto the server

Push your local repo to a (private) GitHub repo first, then:

```bash
mkdir -p /var/www && cd /var/www
git clone https://github.com/<you>/piodeploy-platform.git piodeploy
cd piodeploy
```
(Or `rsync`/`scp` the folder up if you'd rather not use git.)

## 5. Configure and build

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build            # compiles the portal's Tailwind/Vite assets

cp deploy/.env.production.example .env
nano .env                          # set DB_PASSWORD, MAIL_*, etc.
php artisan key:generate

php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Create your first admin (no public registration by design):
```bash
php artisan tinker
>>> $u = \App\Models\User::create(['name'=>'Admin','email'=>'you@piodeploy.com','password'=>bcrypt('a-strong-password')]);
>>> $u->forceFill(['email_verified_at'=>now()])->save();
>>> $u->assignRole('Super Admin');
```

Permissions:
```bash
chown -R www-data:www-data /var/www/piodeploy
chmod -R 775 storage bootstrap/cache
```

## 6. nginx + HTTPS

```bash
cp deploy/nginx-piodeploy.conf /etc/nginx/sites-available/piodeploy
ln -s /etc/nginx/sites-available/piodeploy /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# Free TLS certificate
apt install -y certbot python3-certbot-nginx
certbot --nginx -d piodeploy.com -d www.piodeploy.com --redirect -m you@piodeploy.com --agree-tos
```

## 7. Scheduler + queue worker (systemd)

Scheduler (maintenance windows, offline alerts, drift digest, log prune):
```bash
crontab -e -u www-data
# add:
* * * * * cd /var/www/piodeploy && php artisan schedule:run >> /dev/null 2>&1
```

Queue worker as a service:
```bash
cat >/etc/systemd/system/piodeploy-queue.service <<'EOF'
[Unit]
Description=PioDeploy queue worker
After=network.target
[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/piodeploy/artisan queue:work --sleep=3 --tries=3 --max-time=3600
[Install]
WantedBy=multi-user.target
EOF
systemctl enable --now piodeploy-queue
```

## 8. Publish the agent bundle

The 33 MB agent zip is gitignored, so copy it up from your dev box:
```bash
# from Windows (PowerShell), one time:
scp C:\xampp\htdocs\piodeploy-platform\storage\app\private\agent\PioDeployAgent.zip root@2.25.84.119:/var/www/piodeploy/storage/app/private/agent/
```
Then re-point agents at `https://piodeploy.com` (the download URL in each
project already uses APP_URL).

## 9. Verify

```bash
php artisan security:check     # should pass every line in production
```
Open `https://piodeploy.com` (marketing site) and `https://piodeploy.com/login`.

## Redeploys later

```bash
cd /var/www/piodeploy && git pull
composer install --no-dev --optimize-autoloader && npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
systemctl restart piodeploy-queue
```
