# Deploying FlatMeta on HestiaCP

| Address | What runs there | Folder on the server |
| --- | --- | --- |
| `https://api.flatmeta.io` | This Laravel API, plus `wss://api.flatmeta.io/ws` (live avatars and chat) | `/home/USER/web/api.flatmeta.io/public_html` |
| `https://flatmeta.io` | The app (static files from `npx ng build` in the `flatmeta.io` repo) | `/home/USER/web/flatmeta.io/public_html` |

Replace `USER` everywhere with the HestiaCP user that owns both sites (for example `flatmeta`), including inside the
config files you paste. Commands marked **(root)** need `ssh root@your-server`; all others run as `USER`.

Deploy from `main`: first merge `develop` into `main` on GitHub (a pull request from `develop` to `main`).

---

## 0. Before you start

- A HestiaCP server (Ubuntu or Debian) with root SSH access.
- The domain `flatmeta.io` at your registrar.
- Access to the two GitHub repos (`flatmeta/flatmetaAPI`, `flatmeta/flatmeta.io`), with `develop` merged into `main` for release.
- On your computer: Node 22.22+ (or 24.15+) to build the app.
- PayPal and email accounts (steps 8 and 11). Keys go into the server's `.env` only, never into git or chat.

**Which web server do you have?** Open any web domain in HestiaCP and click *Edit*:
- If you see **Proxy Template** and **Web Template (Apache2)**, you have the default **nginx + Apache** stack. Follow the *Apache* notes.
- If you see **Web Template (NGINX)** and **Backend Template (PHP-FPM)**, you have **nginx only**. Follow the *nginx-only* notes.

---

## 1. DNS

Point these at your server's IP address. Use HestiaCP's *DNS* tab if it hosts your DNS; otherwise set them at your registrar:

| Name | Type | Value |
| --- | --- | --- |
| `flatmeta.io` | A | server IP |
| `www.flatmeta.io` | A | server IP |
| `api.flatmeta.io` | A | server IP |

Wait until `ping api.flatmeta.io` shows your server's IP before requesting SSL certificates in steps 3 and 9.

---

## 2. HestiaCP user, PHP 8.3 and SSH

1. **Users** → add (or edit) `USER`. Under *Advanced options*, set **SSH Access** to `bash`.
2. **Server** (gear icon) → **Configure** → PHP: make sure **PHP 8.3** (or 8.4) is installed.
   Laravel 13 needs PHP 8.3 or newer.
3. Check the PHP extensions **(root)**:
   ```bash
   php8.3 -m | grep -Ei 'pdo_mysql|mbstring|curl|openssl|gd|xml|zip|bcmath|intl|fileinfo'
   ```
   Install any that are missing, e.g. `apt install php8.3-gd php8.3-intl php8.3-bcmath`.
4. Composer for the user **(root)**:
   ```bash
   v-add-user-composer USER
   ```
   It is then available as `~/.composer/composer`. The commands below use `composer`; add this to `~/.bashrc` first:
   `alias composer="php8.3 ~/.composer/composer"`.
5. Raise PHP upload limits. Avatar images are up to 5 MB, and land media is up to 20 MB.
   In **Server → Configure → PHP 8.3**, set `upload_max_filesize = 25M` and `post_max_size = 30M`.

---

## 3. API site: `api.flatmeta.io`

1. **Web** → **Add Web Domain** → `api.flatmeta.io`. Leave aliases empty. Untick *DNS* and *Mail* for this domain.
2. Edit the domain:
   - **SSL**: tick *Enable SSL*, *Use Let's Encrypt*, *Enable automatic HTTPS redirection*, and *Enable HSTS*.
   - **PHP version**: PHP-8_3 (the *Backend Template*).
   - **Web template**: *Apache* stack → `default`. *nginx-only* stack → `laravel`.
   - Save.
3. **Custom document root**: tick it under *Advanced options*, choose `api.flatmeta.io`, and enter the directory `public`.
   The site then serves `/home/USER/web/api.flatmeta.io/public_html/public`. The `.env` file and the code stay outside the web root.

---

## 4. Database

**DB** → **Add Database**:
- Database: `flatmeta`, User: `flatmeta`, a long random password, Type: MySQL.
- HestiaCP adds your user as a prefix, so the names become **`USER_flatmeta`** for both. Note them for `.env`.

**Moving existing data instead of starting empty?** On your computer, run `mysqldump -u root flatmeta > flatmeta.sql`. Then import it with **DB → phpMyAdmin → Import** into `USER_flatmeta` *before* step 5.6. The migrations add anything missing.

---

## 5. Install the API

SSH in as `USER`: `ssh USER@your-server`.

1. **GitHub access**: this is only needed if the repo is private. Create a read-only deploy key:
   ```bash
   ssh-keygen -t ed25519 -C "flatmeta-server" -f ~/.ssh/id_ed25519 -N ""
   cat ~/.ssh/id_ed25519.pub
   ```
   Add it in GitHub → `flatmeta/flatmetaAPI` → *Settings → Deploy keys* (leave write access off).
2. **Get the code**. `public_html` must be empty first:
   ```bash
   cd ~/web/api.flatmeta.io/public_html
   rm -rf ./* ./.[!.]*            # removes HestiaCP's placeholder index.html
   git clone -b main git@github.com:flatmeta/flatmetaAPI.git .
   ```
3. **Dependencies**:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
4. **Settings**:
   ```bash
   cp .env.production.example .env
   php8.3 artisan key:generate
   nano .env
   ```
   Fill in:
   - `DB_DATABASE=USER_flatmeta`, `DB_USERNAME=USER_flatmeta`, `DB_PASSWORD=` (from step 4)
   - `PAYPAL_MODE=sandbox` with your **sandbox** keys for now (step 11)
   - the `MAIL_*` values (step 8)

   Keep `APP_DEBUG=false`, `APP_URL=https://api.flatmeta.io/`, and `FRONTEND_URL=https://flatmeta.io`.
5. **Lock down `.env`**:
   ```bash
   chmod 600 .env
   chmod -R ug+rwX storage bootstrap/cache public/assets/uploads
   ```
   (HestiaCP runs PHP as `USER`, so no `chown` to `www-data` is needed.)
6. **Database tables and starter data**:
   ```bash
   php8.3 artisan migrate --force
   php8.3 artisan db:seed --class=ImagesSeeder --force       # country flags for land artwork
   php8.3 artisan db:seed --class=ReportTextSeeder --force   # reasons for reporting users
   ```
   Run the seeders only on an empty database. Skip them if you imported data.
7. **Speed up Laravel**:
   ```bash
   php8.3 artisan config:cache && php8.3 artisan route:cache && php8.3 artisan view:cache
   ```
8. **Check**: open `https://api.flatmeta.io/api/BoxImages`. It should return JSON.
   `https://api.flatmeta.io/login` should show the CMS login page.

---

## 6. Live avatars and chat (WebSocket server)

The real-time server is `php artisan websocket:serve`, listening on `127.0.0.1:3500`. nginx forwards `wss://api.flatmeta.io/ws` to it. Port 3500 must **not** be opened in the firewall.

1. **nginx proxy (root)**. HestiaCP loads extra nginx config from files named `nginx.ssl.conf_*` in the domain's config folder:
   ```bash
   cat > /home/USER/conf/web/api.flatmeta.io/nginx.ssl.conf_websocket <<'EOF'
   location /ws {
       proxy_pass http://127.0.0.1:3500;
       proxy_http_version 1.1;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "upgrade";
       proxy_set_header Host $host;
       proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
       proxy_read_timeout 3600s;
       proxy_send_timeout 3600s;
   }
   EOF
   nginx -t && systemctl reload nginx
   ```
2. **Keep it running with supervisor (root)**:
   ```bash
   apt install -y supervisor
   cat > /etc/supervisor/conf.d/flatmeta-websocket.conf <<'EOF'
   [program:flatmeta-websocket]
   command=/usr/bin/php8.3 /home/USER/web/api.flatmeta.io/public_html/artisan websocket:serve --host=127.0.0.1 --port=3500
   directory=/home/USER/web/api.flatmeta.io/public_html
   user=USER
   autostart=true
   autorestart=true
   stopsignal=TERM
   stdout_logfile=/home/USER/web/api.flatmeta.io/public_html/storage/logs/websocket.log
   redirect_stderr=true
   EOF
   supervisorctl reread && supervisorctl update && supervisorctl status
   ```
   The status should read `flatmeta-websocket RUNNING`.

---

## 7. Scheduled jobs (cron)

**Cron** tab (logged in as `USER`) → **Add Cron Job**:
- Command: `cd /home/USER/web/api.flatmeta.io/public_html && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1`
- Every minute: `*` in all five fields.

This runs the following jobs:
- **Every 15 minutes:** releases unpaid reservations and land whose rent stopped.
- **Hourly:** retries failed seller payouts.
- **Daily:** deletes expired sign-in tokens.

---

## 8. Email (sign-in codes)

People can't sign in without email, so test this properly. There are two options:

- **A mail provider (recommended for reliable delivery)**: Mailgun, Postmark, Amazon SES, Zoho, or Google Workspace.
  Verify `flatmeta.io` with them, add the DNS records they give you, and put their SMTP details into `MAIL_*`.
- **HestiaCP's own mail server**:
  1. **Mail** → **Add Mail Domain** `flatmeta.io`, with *DKIM* and *SSL (Let's Encrypt)* ticked.
  2. Add the account `hello@flatmeta.io`.
  3. Set `MAIL_HOST=mail.flatmeta.io`, `MAIL_PORT=587`, `MAIL_ENCRYPTION=tls`, `MAIL_USERNAME=hello@flatmeta.io`, `MAIL_PASSWORD=...`.
  4. Make sure the SPF, DKIM and DMARC DNS records exist. HestiaCP adds them if it hosts your DNS.
  5. Some hosts block outgoing mail; check with yours.

After editing `.env`, run `php8.3 artisan config:cache`. Then request a code from the app and check that it arrives, and is not in spam, at Gmail and Outlook.

---

## 9. App site: `flatmeta.io`

1. **Web** → **Add Web Domain** → `flatmeta.io`, with the alias `www.flatmeta.io`.
2. Edit the domain:
   - **SSL**: tick *Enable SSL*, *Use Let's Encrypt*, *Enable automatic HTTPS redirection*, and *Enable HSTS*.
   - *Advanced options* → **Enable domain redirection** → redirect to `https://flatmeta.io`, so `www` goes to the main address.
   - **Web template**: *Apache* stack → `default`. *nginx-only*: see step 9.5.
3. **Build on your computer** (from the `flatmeta.io` repo, on `main`):
   ```bash
   npm ci
   npx ng build
   ```
   This produces `www/`, including an `.htaccess` that sends app links like `/u/name` to the app. The production build already points at `https://api.flatmeta.io/api/` and `wss://api.flatmeta.io/ws` (`src/environments/environment.prod.ts`).
4. **Upload** the *contents* of `www/` (not the folder itself) into `/home/USER/web/flatmeta.io/public_html`.
   - With rsync:
     ```bash
     rsync -avz --delete www/ USER@your-server:web/flatmeta.io/public_html/
     ```
   - Or with SFTP (FileZilla, etc.): delete HestiaCP's placeholder `index.html` first, then upload everything, including the hidden `.htaccess`.
5. **nginx-only stack**: `.htaccess` is ignored there, so make a template that sends unknown paths to `index.html` **(root)**:
   ```bash
   cd /usr/local/hestia/data/templates/web/nginx/php-fpm
   cp default.tpl spa.tpl && cp default.stpl spa.stpl
   sed -i 's#try_files $uri $uri/ /index.php?$args;#try_files $uri $uri/ /index.html;#' spa.tpl spa.stpl
   grep -n "try_files" spa.stpl     # must show /index.html
   ```
   Then edit `flatmeta.io` in HestiaCP → Web template **`spa`** → Save.
6. **Check**:
   - `https://flatmeta.io` shows the map.
   - `https://www.flatmeta.io` redirects to it.
   - `https://flatmeta.io/terms` loads the Terms page on its own (not a 404).
   - In the browser's developer tools, the Network tab shows a `wss://api.flatmeta.io/ws` connection with status 101.

---

## 10. Final checks and your admin account

1. In the app, **save with your email** (this creates your account), then make it a CMS admin:
   ```bash
   cd ~/web/api.flatmeta.io/public_html && php8.3 artisan flatmeta:make-admin you@example.com
   ```
   Sign in to the CMS at `https://api.flatmeta.io/login`.
2. On two devices or browsers, check that you see each other's avatars and can chat.
3. Claim a few free squares, give them a name and image, and reload. They should still be there.

---

## 11. PayPal: sandbox first, then live

Follow **section 6 of `deploy/README.md`**:
- create the app and plan with `php8.3 artisan paypal:setup`
- add the webhook `https://api.flatmeta.io/api/paypal/webhook` with the event list given there
- run the three sandbox tests (rent, marketplace sale, cancellation)

Then switch `.env` to live keys with `PAYPAL_MODE=live`, run `paypal:setup` again, update `PAYPAL_PLAN_ID` and `PAYPAL_WEBHOOK_ID`, and finish with `php8.3 artisan config:cache`.

---

## Updating later

**API** (as `USER`):
```bash
cd ~/web/api.flatmeta.io/public_html
php8.3 artisan down
git pull origin main
composer install --no-dev --optimize-autoloader
php8.3 artisan migrate --force
php8.3 artisan config:cache && php8.3 artisan route:cache && php8.3 artisan view:cache
php8.3 artisan up
```
Then restart the WebSocket server **(root)** so it runs the new code: `supervisorctl restart flatmeta-websocket`.

**App**: run `npx ng build` on your computer and upload `www/` again (step 9.4). People get the update on their next visit.

---

## If something goes wrong

| Symptom | Where to look |
| --- | --- |
| API shows "500 Server Error" | `storage/logs/laravel-*.log` in the API folder. After any `.env` change, run `php8.3 artisan config:cache`. |
| API shows the HestiaCP default page or a file list | Custom document root (step 3.3) must point at `public`. |
| App loads, but nothing loads from the API (CORS error in the browser console) | `FRONTEND_URL=https://flatmeta.io` in `.env`, then `config:cache`. |
| No other avatars and no chat | `supervisorctl status`, `storage/logs/websocket.log`, and that `nginx.ssl.conf_websocket` exists and `nginx -t` passes. |
| `/terms` or `/u/name` gives a 404 | Apache: `.htaccess` missing from `public_html`. nginx-only: the `spa` template (step 9.5). |
| Sign-in code never arrives | `storage/logs/laravel-*.log`, the `MAIL_*` values, and the spam folder. Try a mail provider. |
| Everyone gets "Too many attempts" at once | The API sees one IP for everyone. Set `TRUSTED_PROXIES` in `.env` to your server's IP, then `config:cache`. |
| Changes in `.env` have no effect | `php8.3 artisan config:cache` (the cached config wins over `.env`). |
