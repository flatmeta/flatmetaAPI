# FlatMeta launch checklist

Everything in the code is done and tested against a mocked PayPal. These steps need you
(accounts, keys, legal details) and are what stands between the code and taking real money.

## Before you start (can take days — start now)

- [ ] **PayPal Business account** for the business that will receive the money.
- [ ] **Ask PayPal to enable Payouts** on that account (Payouts sends sellers their 90%). Until it is enabled, sales still work, but payouts show as *failed* in the CMS and you pay sellers by hand.
- [ ] **Email sending**: an account with Mailgun, Postmark, Amazon SES or your domain's SMTP, with flatmeta.io verified as a sender. People cannot sign in without it.
- [ ] **Server** with PHP 8.3+, MySQL, nginx (see `deploy/README.md`), and DNS for `flatmeta.io`, `www.flatmeta.io` and `api.flatmeta.io` pointing at it.

## Legal (in the app repo, `src/app/pages/legal/legal.page.ts` and `.html`)

- [ ] Replace `[COMPANY NAME]`, `[COUNTRY]` and `[CONTACT EMAIL]`.
- [ ] Have the Terms, Privacy policy and Payments & refunds pages reviewed by a lawyer for your country (consumer law, data protection, selling digital goods).
- [ ] If you sell to the EU/UK: register for VAT on digital services if you pass the threshold. PayPal does not handle VAT for you.

## Sandbox test (about 30 minutes)

Follow *Test in sandbox before going live* in `deploy/README.md`:

- [ ] Put the sandbox keys in the server's `.env` (never in chat or in git), run `php artisan paypal:setup`, add the webhook.
- [ ] Claim more than 100 squares → approve the subscription → the land is active.
- [ ] Sell it to a second account → the buyer owns it, and the seller's payout shows *completed* in CMS → Payments.
- [ ] Cancel a subscription in sandbox PayPal → the land stays until its month ends.

## Go live

- [ ] Switch `.env` to the **live** PayPal keys, `PAYPAL_MODE=live`, run `php artisan paypal:setup` again, add the live webhook, update `PAYPAL_PLAN_ID` and `PAYPAL_WEBHOOK_ID`, then `php artisan config:cache`.
- [ ] Do one real claim past 100 squares and one real marketplace sale with small amounts, then refund yourself from PayPal.
- [ ] Make your account a CMS admin: `php artisan flatmeta:make-admin you@example.com`.
- [ ] Check CMS → Payments daily for the first weeks (failed payouts, refunds due).

## Phone apps (optional for launch; the website works on phones)

- [ ] iOS: open `ios/App/App.xcodeproj` in Xcode, set your Apple team and bundle id, archive, upload to App Store Connect. The App Store requires the Privacy policy URL (`https://flatmeta.io/privacy`).
- [ ] Android: open `android/` in Android Studio, create a signing key, build a release bundle, upload to Google Play.
- [ ] Both stores ask how payments work: land rent and marketplace purchases are sold through the website/PayPal. Check each store's current rules on digital purchases before submitting.

## Soon after launch

- [x] **Frameworks upgraded:** the API is on Laravel 13 (PHP 8.3+), and `composer audit` reports no known vulnerabilities. The app is being moved to Angular 22 and Ionic 9.
- [ ] Record the how-to video and set `howToVideoUrl` in the app's `src/environments/environment.prod.ts`.
- [ ] Back up the database daily (most hosts offer this; or `mysqldump` from cron).
