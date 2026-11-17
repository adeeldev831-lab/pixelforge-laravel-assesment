# Image Studio

A take-home assessment for PromptEdit: a Laravel, Vue and Inertia app where signed-in users generate images through the Pixelforge API and are charged exactly what each image cost, plus a 25% markup.

Both stretch goals are included: credit balances and the admin report.

## What it does

- **Generate.** Write a prompt, pick a size and quality, and add up to four reference image URLs. The price range is shown before you submit and updates as you type.
- **History.** Each generation shows its live status, the estimated range, the actual cost and the image. The list refreshes on its own while anything is running.
- **Credits.** Users buy credits with a pretend purchase. A generation can only start if the available credits cover its maximum price.
- **Admin report.** `/admin/report` shows credit spend, provider cost and profit per UTC day and per user, next to what Pixelforge's `GET /v1/usage` reports for the same day.

## How money is handled

- **No floats.** Every amount is a whole number of nanodollars (1 USD = 1,000,000,000) in a `bigint` column. Pixelforge reports costs to nine decimals, which is exactly one nanodollar. See `app/Billing/Money.php`.
- **Rates are read, not hardcoded.** The estimate uses `GET /v1/pricing`. The final charge uses the `rates` on the generation object, which Pixelforge fixes when the generation is created.
- **Provider cost** is `tokens × rate` summed over the four token types, and is exact.
- **The user's price** is provider cost × 1.25. A leftover fraction of a nanodollar is rounded up, so the markup is never less than 25%.
- **The estimate is a range.** Text tokens can be 15% either side of `ceil(characters / 4)` and reasoning tokens fall in a range set by quality. The lower bound is rounded down and the upper bound up. The maximum is what gets held from the user's credits.
- **Charged once.** A charge is a row in `credit_transactions` with a unique `generation_id`, written in the same transaction that marks the generation as succeeded.
- **Failed generations are free.** They record a cost of zero and release the credits they held.
- **Display.** Amounts are shown in full, with only trailing zeros removed, so the numbers on screen can be compared with Pixelforge's.

## How it copes with Pixelforge

- **One place changes a generation.** `GenerationUpdater` takes a generation object from Pixelforge and applies it under a row lock. A generation only moves forward (`submitting → queued → processing → succeeded | failed`) and a finished one never changes. The webhook, the response to our own create request and the polling fallback all go through it, so order and repetition don't matter.
- **Webhooks.** The signature is checked over the raw body in constant time, and timestamps older than five minutes are refused. The event id is the primary key of `webhook_events`, and the event is stored and applied in one transaction, so a repeated delivery is acknowledged and changes nothing.
- **A webhook can arrive before the create request returns.** Our own id is created first and sent as the `Idempotency-Key` and in `metadata`, so the webhook finds its row either way.
- **Lost webhooks.** `generations:sync` runs every minute and asks Pixelforge about generations that are still unfinished after two minutes.
- **Submitting.** Requests to Pixelforge are sent from a queued job. Rate limits, the concurrency limit, overloads and network errors are retried after `Retry-After`, with the delay doubling, for at most six attempts. Rejections that a retry can't fix (blocked prompt, invalid request, daily quota) fail at once with Pixelforge's message.
- **Rate limit budget.** Live status reads our own database, never Pixelforge.

## Decisions worth knowing

- The estimate shown and stored is the full range, and credits are held for the maximum. Once the image is ready the user pays the actual cost and the rest of the hold is released.
- A generation counts on the UTC day Pixelforge completed it (`completed_at`), because that is the day Pixelforge bills it.
- If we give up submitting after repeated timeouts but Pixelforge did accept the request, the generation is revived when its result arrives and is charged, so our provider costs still match Pixelforge's.
- Images are shown from Pixelforge's signed URL and are not copied, so they stop loading after 24 hours.
- Admins are the emails listed in `ADMIN_EMAILS`.

## Running it locally

Requirements: PHP 8.2+, Composer, Node 20+ and PostgreSQL.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# set DB_*, PIXELFORGE_API_KEY, PIXELFORGE_WEBHOOK_SECRET and ADMIN_EMAILS in .env
php artisan migrate
```

Then run these four, each in its own terminal:

```bash
php artisan serve
npm run dev
php artisan queue:work
php artisan schedule:work
```

Pixelforge needs a public URL for webhooks. Expose the app with a tunnel such as ngrok and set the API key's webhook URL to `https://<your-tunnel>/webhooks/pixelforge`.

## Tests

```bash
php artisan test
```

The tests cover the money maths against the worked examples in the Pixelforge docs, duplicate and out-of-order webhooks, signature checks, retries, balances and the admin report. Pixelforge is faked in tests.

## Deploying

The app needs a web process, a queue worker (`php artisan queue:work`), the scheduler (`php artisan schedule:run` every minute) and PostgreSQL. Use a separate Pixelforge API key for each environment: `GET /v1/usage` reports per key, so the admin report only matches when every generation in the database was made with the key in use.
