# ISP Automation

Billing and access control for a small ISP: clients, plans, monthly invoices,
PayMongo payments, Brevo emails, and automatic suspend / reconnect on a
MikroTik router through the RouterOS API.

## How it works

| When | What runs | Does |
|---|---|---|
| 00:00 daily | `billing:suspend-overdue` | Unpaid invoice past due date + `ISP_GRACE_DAYS` → subscription suspended → router job |
| 00:10 daily | `billing:issue-invoices` | Invoice for every subscription due within `ISP_INVOICE_LEAD_DAYS` |
| 01:00 daily | `mikrotik:sync` | Re-applies every subscription's status to the router (fixes drift) |
| 08:00 daily | `billing:send-reminders` | "Due in 3 days" email, once per invoice |
| PayMongo webhook | `POST /webhooks/paymongo` | Signature-checked → invoice paid → due date moves → reconnect |

Router changes go through the queued `SyncRouterAccess` job, which retries
for ~45 minutes if the router is unreachable and writes every result to
`router_logs` (shown on the admin dashboard).

**Due date rule:** paid on time → next due = old due + 1 month. Paid while
suspended → next due = payment day + 1 month (they had no service meanwhile).

## Local setup (XAMPP)

```bash
composer install
cp .env.example .env        # then fill in the values below
php artisan key:generate
```

1. Start MySQL in the XAMPP control panel and create the database:
   `CREATE DATABASE isp_automation CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
2. Set `ADMIN_EMAIL` / `ADMIN_PASSWORD` in `.env`, then:
   ```bash
   php artisan migrate --seed
   ```
3. Run these three in separate terminals:
   ```bash
   php artisan serve
   php artisan queue:work
   php artisan schedule:work
   ```
4. Sign in at http://localhost:8000 with the admin account.

## MikroTik preparation

On the router (Winbox terminal):

```
/ip service set api disabled=no address=<this server's IP>/32
/user group add name=billing policy=read,write,api,!ftp,!ssh,!telnet,!winbox,!web,!reboot,!policy,!password,!sniff,!sensitive,!romon
/user add name=billing group=billing password=<strong password>
/ppp profile add name=20M rate-limit=20M/20M
/ppp profile add name=50M rate-limit=50M/50M
/ppp profile add name=100M rate-limit=100M/100M
```

Then set `MIKROTIK_HOST`, `MIKROTIK_USERNAME`, `MIKROTIK_PASSWORD` in `.env` and check:

```bash
php artisan mikrotik:test               # connection + router identity
php artisan mikrotik:test juan01        # look up one PPP secret
php artisan isp:access juan01 suspend   # cut now (disables secret, drops session)
php artisan isp:access juan01 restore   # reconnect now
```

The plan's "MikroTik profile" must match a `/ppp profile` name exactly.

For API-SSL use port 8729 with `MIKROTIK_SSL=true` (needs a certificate on
`/ip service api-ssl`).

### Optional: walled garden instead of a hard cut

`ISP_SUSPEND_MODE=profile` moves suspended users to the `suspended` PPP
profile instead of disabling them. Give that profile an address list and
firewall rules that only allow DNS, this portal and `*.paymongo.com`, so the
client can pay over their own line.

## PayMongo

1. Put your **test** keys in `PAYMONGO_SECRET_KEY` / `PAYMONGO_PUBLIC_KEY`.
2. The webhook must reach this machine. Expose it (e.g. `ngrok http 8000`,
   then set `TRUSTED_PROXIES=*`) and create a webhook in PayMongo for
   `https://<public-url>/webhooks/paymongo` with event
   `checkout_session.payment.paid`. Put its secret in `PAYMONGO_WEBHOOK_SECRET`.
3. `PAYMONGO_METHODS` must only list methods your account has activated.

Even without the webhook, returning from checkout asks PayMongo directly and
records the payment.

## Brevo email

`MAIL_MAILER=brevo`, `BREVO_KEY=<API key>`, and a `MAIL_FROM_ADDRESS` that
is a verified sender in Brevo. With `MAIL_MAILER=log`, emails (including the
6-digit codes) are written to `storage/logs/laravel.log` instead.

## Security

- **Login:** 5 wrong passwords per email + IP, or 10 per email from anywhere,
  lock that account for 15 minutes. 20 login posts per minute per IP. The
  error message never reveals whether an email is registered.
- **Email verification:** every account must enter a 6-digit code emailed to
  it before using the app. Codes are hashed, single-use, expire in 10
  minutes, die after 5 wrong tries, 60 s resend cooldown, 5 per hour.
- **Password reset** by emailed code (also how new clients set their first
  password). Signs out every other session.
- Disabled accounts are signed out on their next request.
- PayMongo webhook: HMAC signature + 5-minute replay window, test/live mode
  check, each event id and payment id processed once.
- PPPoE passwords encrypted at rest; security headers + CSP on every page.

## Tests

```bash
php artisan test
```

They use an in-memory SQLite database and a fake router, so no MySQL or
MikroTik is needed.
