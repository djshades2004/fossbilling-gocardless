# FOSSBilling × GoCardless payment gateway adapter

Drop-in payment gateway adapter that lets [FOSSBilling](https://fossbilling.org/) accept
payments by **Direct Debit** through [GoCardless](https://gocardless.com/) — one-time
invoices and recurring subscriptions.

No SDK dependency: the adapter talks to the GoCardless REST API with plain `cURL`
and verifies webhooks with an HMAC-SHA256 signature check.

---

## Installation

1. Copy the adapter file:

   ```bash
   cp src/library/Payment/Adapter/GoCardless.php <your-fossbilling>/src/library/Payment/Adapter/GoCardless.php
   ```

2. Clear the FOSSBilling cache (or just use the "Support" → "Update" → clear cache
   button in the admin panel).

That is all — the gateway appears in **Configuration → Payments → Gateways** as
*GoCardless*.

> Note: Symfony's Intl component is used for minor-unit precision. It ships with
> FOSSBilling already; if your full install lacks it, FOSSBilling falls back to a
> 2-decimal assumption for every currency.

---

## Configuration

### 1. In your GoCardless dashboard

Create a pair of webhook endpoints (one for each environment you use):

- **Live webhook** — Dashboard → Developers → Webhooks
- **Sandbox webhook** — sandbox dashboard (https://dashboard-sandbox.gocardless.com)

For each endpoint:

| Setting | Value |
| --- | --- |
| URL | `https://your-fossbilling.example.com/ipn.php?gateway_id=YOUR_GATEWAY_ID` |
| Event selection | `All events` (or at minimum all `payments` and `subscriptions` events) |
| Secret | copy and save it — FOSSBilling needs the same value |

`YOUR_GATEWAY_ID` is the ID shown in FOSSBilling under **Configuration → Payments →
Gateways** after you save the GoCardless gateway (it is the numeric `id` in the
gateways list).

### 2. In FOSSBilling

**Configuration → Payments → Gateways → GoCardless → Set up:**

| Field | Value |
| --- | --- |
| **Live Access Token** | `live_...` from Dashboard → Developers |
| **Live Webhook Endpoint Secret** | the secret configured on the live webhook endpoint |
| **Sandbox Access Token** | `sandbox_...` (only used in test/development mode) |
| **Sandbox Webhook Endpoint Secret** | the secret configured on the sandbox webhook endpoint |
| **Mark invoices paid when a payment is** | `Paid out` (money arrives) or `Confirmed` (collected, payout pending) |
| **Redirect to GoCardless automatically** | your preference |

The live/sandbox pair is switched automatically by FOSSBilling's own **test mode**
toggle on the gateway.

### 3. Whitelist the GoCardless egress

If your server uses an outbound allow-list, permit API calls to:

- `https://api.gocardless.com` (live)
- `https://api-sandbox.gocardless.com` (sandbox)

And permit **HTTPS inbound** to `/ipn.php` from GoCardless webhook IPs
(see the [GoCardless docs](https://developer.gocardless.com/api-reference/#webhooks-ip-addresses)).
At minimum, do **not** IP-ban the `/ipn.php` route in any WAF — a valid webhook must
always get through.

---

## How it works

```
customer checks out
        │
        ▼
getHtml() creates a /redirect_flows object (session_token = gc_sess, and the
success_redirect_url points back at the invoice callback with gc_sub=0/1)
        │  renders a form that sends the customer to GoCardless' hosted
        ▼  mandate-setup page
customer authorises the Direct Debit mandate
        │
        ▼ GoCardless redirects the browser to:
/ipn.php?...&redirect_flow_id=RE...&gc_sess=...&gc_sub=0|1   [GET]
        │
        ▼ processTransaction(): completes the redirect flow, gets the mandate
one-time  ──►  POST /payments  (payment scheduled, charge_date assigned)
subscription ─► POST /subscriptions + FOSSBilling invoice_subscription_create
        │
        ▼ days later the scheme collects ──► GoCardless sends webhooks
/ipn.php?gateway_id=X   [POST application/json, Webhook-Signature: ...]
        │
        ▼ processWebhook(): HMAC verified, payment state fetched, then
payments.paid_out  ──►  invoice credited (client balance → approve → pay)
                         or, for a subscription whose original invoice is already
                         paid, a renewal invoice is generated and paid
```

### What is credited when

- **Default** — an invoice is marked paid on `payments.paid_out` (the money has
  reached your bank).
- **Optional** — configure `confirmed`: the invoice is paid as soon as the collection
  is confirmed (funds secured, payout arrives later).

Ingoing events like `payments.pending_submission` / `payments.submitted` only update
the transaction record; earlier lifecycle events are dropped so the admin
Transactions list stays meaningful.

### Subscriptions

- The first collection from a new subscription pays the **original invoice**.
- Every following collection pays a **freshly generated renewal invoice**
  (`invoice_service.generateRenewalInvoiceForSubscriptionPayment`), which keeps
  FOSSBilling's order/renewal bookkeeping accurate.
- If no renewal invoice can be generated, the money is credited to the **client
  balance** instead and FOSSBilling applies it to whatever is due.
- Cancelling a subscription in FOSSBilling (or GoCardless) calls
  `POST /subscriptions/{id}/actions/cancel`. GoCardless has no
  cancel-at-period-end API, so "cancel at end of period" also cancels immediately.

### Failure handling

`payments.failed`, `payments.cancelled` and `payments.charged_back` mark the
transaction as **error** with the payment's status — the invoice is left unpaid so
FOSSBilling can retry or the client can pay another way.

---

## Duplicate-charge safety

FOSSBilling's per-event `ipn_hash` deduplication is used for exact duplicate webhook
deliveries. Beyond that the adapter:

- refuses to charge an invoice that GoCardless has already settled (checked both on
  the redirect return and on webhooks),
- uses GoCardless **Idempotency-Keys** when completing flows, creating payments
  and creating subscriptions, so a retried request returns the original resource
  instead of minting a second one (each redirect-flow is deliberately created
  fresh — an idempotency key there could hand the customer back an expired flow),
- skips credit when the same GoCardless payment already produced a *processed*
  transaction,
- only credits after `claimForProcessing` on the transaction row, so concurrent
  webhook deliveries cannot double-credit.

---

## Testing / development

Use the **sandbox** access token and the FOSSBilling test-mode toggle. Sandbox
payments don't move real money; GoCardless' sandbox lets you simulate
`paid_out`, `failed`, `cancelled` etc. against a sandbox webhook endpoint.

A minimal local harness:

```bash
# point a queue consumer or php's built-in server at FOSSBilling, then:
curl 'https://api-sandbox.gocardless.com/redirect_flows' \
  -H 'Authorization: Bearer sandbox_...' -H 'Content-Type: application/json' \
  -d '{"description":"fx","session_token":"t1","success_redirect_url":"https://example.com","prefilled_customer":{"email":"a@b.c"}}'
```

The adapter is unit-testable without a network by injecting a fake `curl` for
`Payment_Adapter_GoCardless::request` (or by stubbing the GoCardless API base URI
to a `MemoryAdapter` in tests).

---

## Supported currencies

GoCardless Direct Debit is only available in these currencies:
`AUD`, `CAD`, `DKK`, `EUR`, `GBP`, `NZD`, `SEK`, `USD`.
FOSSBilling invoices in any other currency raise a clear configuration error in the
checkout flow.

Supported billing periods for subscriptions: **weekly**, **monthly**, **yearly**
(FOSSBilling `1W/2W/…`, `1M/3M/…`, `1Y`). Daily periods are rejected rather than
mis-billed.

---

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| "The … configuration options are required: access token and webhook endpoint secret." | Live mode needs the `live_` token + matching webhook secret (test mode needs the sandbox pair). |
| "GoCardless did not return a hosted payment URL." | Token is wrong / lacks `payment_read_write` access, or network egress to api.gocardless.com is blocked. |
| "GoCardless webhook failed signature verification." | Webhook URL secret doesn't match the one in FOSSBilling config; or the webhook wasn't delivered to `/ipn.php?gateway_id=X`. |
| Invoices stay unpaid despite default webhook acks | A *JSON* webhook is acked before it is processed (FOSSBilling's fastcgi path). Check the error log and pm2/php worker logs, and that the webhook reaches `/ipn.php` with `gateway_id`. |
| 4xx from GoCardless when creating a subscription | The mandate is not yet in a creatable state after the redirect flow (rare). Retry the checkout, or create a one-time payment against the mandate. |