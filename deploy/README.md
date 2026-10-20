# Putting FlatMeta live

> Using HestiaCP? Follow [`HESTIACP.md`](HESTIACP.md) instead of steps 1–4 below; sections 5–6 (email, PayPal) apply to both.

Two sites on one Linux server (Ubuntu 22.04+ works well):

| Address | What runs there |
| --- | --- |
| `https://flatmeta.io` | The app (static files built from the `flatmeta.io` repo) |
| `https://api.flatmeta.io` | This Laravel API, plus `wss://api.flatmeta.io/ws` for live avatars and chat |

Needs: PHP 8.3+ (with `pdo_mysql`, `mbstring`, `curl`, `openssl`, `gd`), Composer, MySQL 8 / MariaDB 10.6+, nginx, supervisor, certbot, Node 22+ (to build the app).

## 1. API

```bash
cd /var/www && git clone https://github.com/flatmeta/flatmetaAPI.git && cd flatmetaAPI
composer install --no-dev --optimize-autoloader
cp .env.production.example .env        # fill in every value (see below)
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=ImagesSeeder --force       # country flags for land artwork
php artisan db:seed --class=ReportTextSeeder --force   # reasons for reporting users
php artisan config:cache && php artisan route:cache
chown -R www-data:www-data storage bootstrap/cache public/assets/uploads
```

Make yourself a CMS admin after you have signed up in the app:

```bash
php artisan flatmeta:make-admin you@example.com
```

## 2. Real-time server and scheduled jobs

```bash
sudo cp deploy/supervisor-flatmeta.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
```

Add the Laravel scheduler to the `www-data` crontab (`sudo crontab -u www-data -e`):

```
* * * * * cd /var/www/flatmetaAPI && php artisan schedule:run >> /dev/null 2>&1
```

It releases unpaid reservations and land whose rent stopped (every 15 minutes), retries failed seller payouts (hourly) and deletes expired sign-in tokens (daily).

Sign-in tokens are Laravel Sanctum personal access tokens, valid for a year (`config/sanctum.php`). If you are upgrading a server that ran the older Passport version, `migrate` drops the old `oauth_*` tables, everyone signs in again once, and you can delete `storage/oauth-private.key` and `storage/oauth-public.key`.

## 3. The app

```bash
cd /var/www && git clone https://github.com/flatmeta/flatmeta.io.git && cd flatmeta.io
npm ci
npx ng build            # production build into www/ (uses src/environments/environment.prod.ts)
```

## 4. nginx and HTTPS

```bash
sudo cp /var/www/flatmetaAPI/deploy/nginx-api.flatmeta.io.conf /etc/nginx/sites-available/api.flatmeta.io
sudo cp /var/www/flatmetaAPI/deploy/nginx-flatmeta.io.conf     /etc/nginx/sites-available/flatmeta.io
sudo ln -s /etc/nginx/sites-available/api.flatmeta.io /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/flatmeta.io     /etc/nginx/sites-enabled/
sudo certbot certonly --nginx -d flatmeta.io -d www.flatmeta.io -d api.flatmeta.io
sudo nginx -t && sudo systemctl reload nginx
```

(Adjust `php8.3-fpm.sock` in the API config to your PHP version.)

## 5. Email

Sign-in codes are sent by email, so set the `MAIL_*` values to a real provider (Mailgun, Postmark, Amazon SES, or your domain's SMTP) and send yourself a code from the app to check it arrives.

## 6. PayPal

Do this twice: first with **sandbox** to test, then with **live**.

1. Go to [developer.paypal.com](https://developer.paypal.com) → *Apps & Credentials* → choose Sandbox or Live → *Create App* (type: Merchant). Copy the **Client ID** and **Secret** into `.env` as `PAYPAL_CLIENT_ID` and `PAYPAL_SECRET`, and set `PAYPAL_MODE` to `sandbox` or `live`.
2. In the app's settings make sure these features are ticked: **Accept payments**, **Subscriptions** (Billing), **Payouts**.
   Live Payouts must also be approved for your business account — ask PayPal support to enable *Payouts* if it isn't.
3. Create the monthly plan ($0.10 per square per month):
   ```bash
   php artisan config:clear
   php artisan paypal:setup
   ```
   Put the printed `PAYPAL_PLAN_ID=...` into `.env`.
4. Back in the app on developer.paypal.com → *Webhooks* → *Add Webhook*:
   - URL: `https://api.flatmeta.io/api/paypal/webhook`
   - Events:
     `BILLING.SUBSCRIPTION.ACTIVATED`, `BILLING.SUBSCRIPTION.CANCELLED`, `BILLING.SUBSCRIPTION.EXPIRED`,
     `BILLING.SUBSCRIPTION.SUSPENDED`, `BILLING.SUBSCRIPTION.PAYMENT.FAILED`, `PAYMENT.SALE.COMPLETED`,
     `CHECKOUT.ORDER.APPROVED`, `PAYMENT.PAYOUTS-ITEM.SUCCEEDED`, `PAYMENT.PAYOUTS-ITEM.FAILED`,
     `PAYMENT.PAYOUTS-ITEM.RETURNED`, `PAYMENT.PAYOUTS-ITEM.BLOCKED`, `PAYMENT.PAYOUTS-ITEM.DENIED`,
     `PAYMENT.PAYOUTS-ITEM.UNCLAIMED`

   Copy the **Webhook ID** into `.env` as `PAYPAL_WEBHOOK_ID`.
5. `php artisan config:cache`

### Test in sandbox before going live

Use two sandbox *personal* accounts from developer.paypal.com → *Sandbox accounts*.

1. **Rent:** sign in to the app, claim more than 100 squares, approve the subscription with a sandbox buyer. You return to the app's thank-you page and the land shows as active. In the CMS (*Payments*) the subscription is listed.
2. **Marketplace:** list that land for sale; sign in as a second user, buy it and pay with the other sandbox account. The land moves to the buyer, and a payout of 90% appears under *Payments* (status *sent*, then *completed* once PayPal's webhook arrives).
3. **Cancel:** cancel the subscription at sandbox.paypal.com → the land stays until its paid month ends, then `billing:enforce` releases it.

When all three work, switch `.env` to the live app's keys, run `paypal:setup` again in live mode, add the live webhook, and update `PAYPAL_PLAN_ID` / `PAYPAL_WEBHOOK_ID`.

## How money works

- First 100 squares per person are free. Each square after that is $0.10/month by PayPal subscription; the first payment is taken a month after approval, and people can cancel any time in PayPal.
- Reservations not paid within 24 hours are released. If rent stops (cancelled, or payments fail), the land is kept until the paid period ends plus 7 days, then released.
- Marketplace sales are one-time PayPal payments. Ownership moves when PayPal confirms the money; if the listing changed in the meantime the buyer is refunded automatically. The seller receives 90% by PayPal Payout to their payout email (Profile → payout email, or their sign-in email). FlatMeta keeps 10%.
- Failed payouts are retried hourly (5 attempts) and listed in the CMS under *Payments* to resolve by hand, e.g. `php artisan payouts:retry --id=12` after the seller fixes their email.
