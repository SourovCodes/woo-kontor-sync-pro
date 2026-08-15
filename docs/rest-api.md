# REST API

Two things this plugin can be asked to do over HTTP: **start a sync**, and **say how one is going**.
Nothing else. It exists because until it did, a sync could only be started by its schedule or by the
**Run now** button on the settings screen, and a run could only be watched through that screen's
progress bar — both behind a logged-in browser session. A deploy script, a hook on the ERP side and a
monitoring dashboard all want exactly those two things and none of them has a browser.

| Surface | Route |
|---|---|
| Every job this API serves | `GET /wp-json/wc-wksync/v1/jobs` |
| One job | `GET /wp-json/wc-wksync/v1/jobs/{job}` |
| Start a run | `POST /wp-json/wc-wksync/v1/jobs/{job}/run` |

`{job}` is `products` or `stock`. **Nothing here returns catalogue data** — what Kontor says about a
product is served on WooCommerce's own `/wc/v3/products`, where this plugin adds `msrp`,
`min_order_quantity` and `order_quantity_step`.

Implemented in `includes/Rest/Jobs.php` and asserted by `tests/RestJobsTest.php`. **When this
document and the code disagree, the code and its tests win; fix the document.**

## Authentication

An ordinary **WooCommerce REST API key** (WooCommerce → Settings → Advanced → REST API). Whatever
already works for `/wc/v3/orders` works here unchanged: HTTP Basic auth with the consumer key and
secret over HTTPS, or OAuth 1.0a over plain HTTP.

**The key's permissions are enforced by WooCommerce before this plugin is reached**, and by HTTP
method rather than by route:

| | Needs |
|---|---|
| `GET /jobs`, `GET /jobs/{job}` | a **Read** key (or Read/Write) |
| `POST /jobs/{job}/run` | a **Write** key (or Read/Write) |

A read-only key POSTing gets `401 woocommerce_rest_authentication_error` — a WooCommerce refusal, not
one of ours. If you will both trigger and poll with one key, issue it Read/Write.

The key must belong to a user holding **`manage_woocommerce`** — the same capability the settings
screen is gated on, so anyone who can press Run now can use this, and nobody who cannot, can.
Unauthenticated is `401`; authenticated without the capability is `403 wksync_rest_forbidden`. A
client riding on a login cookie instead of a key must send `X-WP-Nonce`, which WordPress verifies
itself.

**The namespace begins `wc-` on purpose.** `WC_REST_Authentication` reads a consumer key only for a
request URI containing `wc/` or `wc-` — it is the prefix WooCommerce documents for third-party
plugins wanting its authentication, beside the comment *"Allow third party plugins use our
authentication methods"*. A namespace of `wksync/v1` would route perfectly well and then answer 401
to every client holding a key, because the credentials would never be read at all.

## The progress object

Both `GET` routes return this shape; so does the `progress` in a trigger's answer.

```json
{
  "job": "stock",
  "label": "Stock sync",
  "state": "success",
  "running": false,
  "queued": false,
  "run_id": 1786734356,
  "started_gmt": "2026-08-14T18:25:56",
  "finished_gmt": "2026-08-14T18:26:11",
  "percent": 100,
  "total": 2945,
  "processed": 2945,
  "counts": { "updated": 2805, "missing": 140, "unmanaged": 0 },
  "message": "2805 products updated, 140 article numbers had no matching SKU, 0 skipped as not stock-managed.",
  "next_run_gmt": "2026-08-14T18:41:11"
}
```

| Field | Type | Meaning |
|---|---|---|
| `job` | string | `products` or `stock`. **Key logic on this**, never on `label`. |
| `label` | string | For display. Translated into the site's language, so it is not stable text. |
| `state` | string | `never`, `running`, `success` or `failed` — what the job last recorded about itself. |
| `running` | boolean | Whether that record is still to be believed. See below. |
| `queued` | boolean | Whether a run of this job is due now or already under way. |
| `run_id` | integer | **Identifies the run being reported.** `0` when the job has never run. Compare it for equality; do not read it as a time. |
| `started_gmt` | string / null | When the run began, UTC, ISO 8601. `null` when the job has never run. |
| `finished_gmt` | string / null | When it ended. `null` while it is still going. |
| `percent` | integer / **null** | How far through. **`null` is not `0`** — see below. |
| `total` | integer | Records the run expects to handle. **`0` means not yet known**, not "none". |
| `processed` | integer | Records handled so far. |
| `counts` | object | What happened to each record, by outcome. Always an object, `{}` when there is nothing. |
| `message` | string | The run's summary, or why it failed. Translated. |
| `next_run_gmt` | string / null | When the schedule next runs this job. `null` when no schedule is set. |

Four of these need more than a row:

**`percent` is `null` when the run cannot be measured yet**, and that is a different claim from `0`.
Zero percent says the run has started and achieved nothing; until the first page has come back, that
is not true yet. Render `null` as an indeterminate bar, which is what the settings screen does.

**`state` and `running` are different questions.** A run only ever leaves the `running` state from
inside one of its own background actions, so a run that crashed outright would look in flight
for ever. `running` applies a six-hour limit on top of the record. A stale run therefore reads
`"state": "running"` with `"running": false` — which is the truth, and is exactly the condition
under which a new trigger will be accepted rather than refused as a conflict.

**`counts` keys are not a contract.** They are each job's own vocabulary and change when the jobs do.
Today `products` reports `created`, `updated`, `skipped`, `no_sku`, `no_image`, `inactive`,
`duplicate_sku`, `failed` and `drafted`, and `stock` reports `updated`, `missing`, `unmanaged`,
`drafted` and `restored`. Read what is there; do not require any of them.

**`queued` counts a run due now, not a schedule.** Every job with an interval keeps a recurring
action in the queue permanently — often days out — and that does not make a run queued. An *overdue*
one does count, correctly: it is about to run. And a `queued: true` you did not cause is perfectly
ordinary, which is why you compare run identifiers rather than treating `queued` as "mine".

### The collection

```json
{ "jobs": [ { "job": "products", … }, { "job": "stock", … } ], "image_queue": 812 }
```

`image_queue` is how many product images are still waiting to be downloaded. It sits beside the jobs
rather than inside one because **image downloads outlive the run that queued them**: they are
deliberately the last thing in the queue, one action per product, and a catalogue of four thousand
articles takes a while to fetch pictures for. **A `products` run reporting `"state": "success"` with a
non-zero `image_queue` is finished and correct** — the catalogue is right, and the photographs are
still arriving. Do not treat the queue as work outstanding on the run.

## Starting a run

```bash
curl -u ck_xxx:cs_xxx -X POST "https://shop.example/wp-json/wc-wksync/v1/jobs/products/run"
```

```json
{
  "job": "products",
  "previous_run_id": 1786477962,
  "progress": { "job": "products", "…": "…" }
}
```

**202 Accepted**, because that is all it can honestly claim: the run is queued, not done. `progress`
is how the job stood the instant after queueing — which, on the first poll, is usually still the
*previous* run's outcome.

> **A 202 does not mean the credentials work.** Queueing runs the local checks only — is the API base
> URL and key set, is this job not already running — and never contacts Kontor. Whether Kontor accepts
> the key is settled inside the run, and surfaces as `"state": "failed"` with a `message` on a later
> poll.

**A second POST while a run is already queued is not refused**, deliberately: the Run now button does
not refuse it either, and refusing it here would also mean turning down a run because a recurring one
happens to be due next week. Whichever action runs second finds the job already running and stops.

### Refusals

| `code` | Status | What it means |
|---|---|---|
| `wksync_already_running` | **409** | A run is in flight. Wait and poll; a run that died releases the job after six hours. |
| `wksync_not_configured` | **503** | This shop has no API base URL or key stored. Nothing to fix in the request. |
| `wksync_unavailable` | **503** | WooCommerce or its scheduler is not available. |
| `wksync_no_shop` | **503** | No Kontor shop selected. Cannot arise for these two jobs; documented because the code maps it. |

Nothing is queued when a trigger is refused.

## The polling contract

`POST` cannot hand back an identifier for the run it just asked for, because nothing has minted one
yet: the identifier is stamped by the job itself, from inside the background action. So you detect
your run by watching for the identifier to **change**.

1. `POST /jobs/{job}/run`. Keep `previous_run_id` — call it **P**.
2. Poll `GET /jobs/{job}`, **no faster than every 5 seconds**. The settings screen polls at that rate
   and nothing here moves quicker.
3. On each poll:
   - **`queued` is `true`** → your trigger has not been dealt with yet. Keep polling.
   - **`queued` is `false` and `run_id` differs from P** → **your run started.** `running`, `percent`,
     `processed`, `total` and `counts` describe it. It has ended when `state` is `success` or `failed`
     and `finished_gmt` is set.
   - **`queued` is `false`, `run_id` still P, `state` is `failed`** → the run was refused before it
     could start — most often the credentials do not authenticate. `message` says why. **This is the
     case the 202 could not rule out.**
   - **`queued` is `false`, `run_id` still P, `state` unchanged** → nothing recorded the attempt at
     all, which means the background action died before it could write anything. Rare. Give up after
     a bounded number of polls and look at WooCommerce → Status → Scheduled Actions, and at the
     `woo-kontor-sync` log under Status → Logs.
4. **Stop polling** once nothing is running and nothing is queued. On an idle shop this API should not
   be called at all.

A poll is one option read and a couple of counting queries — cheap, but not free.

```bash
# Start it, and remember the run you are replacing.
curl -sS -u ck_xxx:cs_xxx -X POST \
  "https://shop.example/wp-json/wc-wksync/v1/jobs/stock/run"
# {"job":"stock","previous_run_id":1786734223,"progress":{…,"queued":true,"state":"success"}}

# Poll. Still waiting for the queue to pick it up.
curl -sS -u ck_xxx:cs_xxx "https://shop.example/wp-json/wc-wksync/v1/jobs/stock"
# {…,"queued":true,"run_id":1786734223,…}

# Under way: the identifier has moved.
# {…,"queued":true,"run_id":1786734356,"state":"running","percent":38,"processed":1120,"total":2945}

# Done.
# {…,"queued":false,"run_id":1786734356,"state":"success","percent":100,"processed":2945,"total":2945}
```

## Errors

Every refusal is WordPress's standard shape:

```json
{ "code": "wksync_already_running", "message": "That job is already running.", "data": { "status": 409 } }
```

**Key on `code`, never on `message`.** Messages are translated — the plugin ships English and two
German catalogues — so a client matching on text works in English and silently stops working the day
somebody switches the site to German.

Codes you will meet from WordPress and WooCommerce rather than from this plugin:
`rest_invalid_param` (400 — a `{job}` this API does not serve, including the real jobs it does not
expose), `rest_no_route` (404 — **also what a wrong method gets**; WordPress does not answer 405),
`rest_cookie_invalid_nonce`, and `woocommerce_rest_authentication_error` (401 — bad key, or a key
without the permission for that method).

## Deliberately not here

- **The order, delivery and invoice syncs.** They need a shop selected, they push financial records,
  and the delivery sync completes orders — which emails customers. None of that is refused for ever;
  it is simply not what anybody has asked for, and each is a different question to answer.
- **Changing a schedule or storing credentials.** Settings are a separate security question from
  running a job, and nothing has asked for them either.
- **Cancelling a run.** There is no route for it. A run that dies releases its job after six hours.
