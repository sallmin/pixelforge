# Pixelforge

Users sign in, ask for an image, and pay Pixelforge's cost plus 25%. The live app is [https://pixelforge.sallmin.xyz](https://pixelforge.sallmin.xyz).

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
```

`migrate:fresh --seed` wipes the database, then creates two accounts. Both use the password `password`.

| Email | Role |
| --- | --- |
| `admin@example.com` | Operator. Can open Report. |
| `test@example.com` | Normal user. |

Running the seeder again does not create a second admin.

Set `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`, and `PIXELFORGE_BASE_URL` (default `https://pixelforge.promptedit.dev/v1`). Use one Pixelforge key per environment. Each key has its own webhook and its own `GET /v1/usage` report.

Point that key's webhook at `https://<host>/webhooks/pixelforge`. Pixelforge does not follow redirects. Run a queue worker (`php artisan queue:work database`) and the scheduler (`php artisan schedule:work`, or cron `schedule:run` every minute). The scheduler polls generations whose webhook never arrived.

A new account starts at zero credits. Buy credits before generating. The maximum estimate is held on submit, replaced by the actual charge on success, and returned on failure.

## Decisions

- Money is integer nanodollars (1 USD = 1,000,000,000). Rate strings are parsed as decimals, never floats. A fractional nanodollar rounds up, for both the provider cost and the 25% markup.
- The price shown before submit is a range. Text tokens can land within 15% of the character estimate, and reasoning tokens depend on quality. The charge uses the rates and usage on the finished generation.
- Status only moves forward: pending, queued, processing, then succeeded or failed. A final status never changes. A failed generation stays at zero.
- The local id is the idempotency key and is also sent in metadata, so a webhook can find the row before the create request returns. A 429 or 503 goes back on the queue with the `Retry-After` delay, capped at five tries. The same key makes the retry safe.
- Each webhook event id is stored once. The charge is written inside a row lock, and the database allows only one charge per generation.

## Credits and the operator report

Credits are a ledger of purchases, holds, charges, and releases. The balance is the sum. Buying credits adds a fake purchase. Submitting a generation holds the maximum estimate and is refused when the balance is too low. Success replaces the hold with the actual charge. Failure gives the hold back. The user row is locked while the balance changes.

Operators (`is_admin`) can open Report. It totals credits, provider cost, and profit by the UTC completion day and by user, and shows that day's `GET /v1/usage` total as a match or a mismatch.
