# Woo Kontor Sync Pro

A WooCommerce extension that synchronises products, orders and customers with the **Kontor** ERP
over its REST API.

- Text domain / slug: `woo-kontor-sync-pro`
- PHP namespace: `WooKontorSync\` (PSR-4 → `includes/`)
- Global prefix: `wksync` / `woo_kontor_sync` — constants, hooks, options and meta keys.
  WPCS rejects prefixes shorter than four characters, so `wks` is not usable.

## Minimum requirements

The plugin deliberately targets the current release of everything. There is no
backwards-compatibility budget: do not add shims, polyfills or version branches for older
WordPress, WooCommerce or PHP.

| Requirement | Floor | Enforced by |
|---|---|---|
| WordPress | 7.0 (current latest) | `Requires at least` header; WordPress blocks activation below it |
| PHP | 8.2 | `Requires PHP` header, `composer.json`, PHPCompatibilityWP `testVersion` |
| WooCommerce | 11.0 (current latest) | `Requires Plugins` header plus a runtime `version_compare` check |
| HPOS | **Required, not merely supported** | Runtime check; the plugin refuses to boot without it |

**High-Performance Order Storage is a hard requirement.** Declaring compatibility is not enough:
`bootstrap()` calls `OrderUtil::custom_orders_table_usage_is_enabled()` and, when HPOS is off,
registers an admin notice and returns without initialising. Running against the legacy post-based
order store would silently read and write the wrong data rather than fail loudly, which is far worse
than not running at all.

Because HPOS is guaranteed, order code should use the CRUD APIs directly and never carry a legacy
fallback path. When you bump these floors, update the plugin header, `WKSYNC_MIN_WC_VERSION`,
`composer.json`, and the `testVersion` / `minimum_wp_version` values in `phpcs.xml.dist` together.


## Environment

Development runs against a **Local by WP Engine** site called *TestShop*, which this directory is
already symlinked into:

| | |
|---|---|
| Site root | `~/Local Sites/testshop/app/public` |
| URL | http://testshop.local |
| Stack | WordPress 7.0.2, WooCommerce 11.0.0, PHP 8.2.29, MySQL 8.4.0 |
| Plugin path in site | `wp-content/plugins/woo-kontor-sync-pro` → symlink to this repo |

**A bare `wp` command does not work.** wp-config.php sets `DB_HOST` to `localhost` and Local serves
MySQL over a socket the Homebrew PHP cannot see, so wp-cli fails with *Error establishing a database
connection*. Always use the wrapper, which resolves the site, the socket and Local's own PHP build
from Local's `sites.json`:

```bash
./bin/wp plugin list
```

Note that the host CLI is PHP 8.5.8 while the site runs 8.2.29. Composer pins `config.platform.php`
to 8.2.29 so dependency resolution targets the runtime the code actually executes on.

## Commands

```bash
composer lint           # phpcs against the WooCommerce standard
composer lint:fix       # phpcbf — fix what can be fixed automatically
composer test           # PHPUnit against the WordPress test library
composer test:coverage  # …with a coverage report in coverage-html/
composer i18n           # re-extract the POT and recompile the translations
composer i18n:check     # is the POT still in step with the source?
composer check          # everything CI checks: validate, version, i18n, lint, test
composer build          # build dist/woo-kontor-sync-pro-<version>.zip
./bin/wp <args>         # wp-cli against the Local site
```

`bin/install-wp-tests.sh` provisions the `woo_kontor_tests` database once, before the first
`composer test`.

## Coding standards

`phpcs.xml.dist` (ruleset `WooCommerce-Core`) is the authority — when this document and the sniffs
disagree, the sniffs win. A PostToolUse hook runs `phpcbf` then `phpcs` on every PHP file written or
edited in this repo and blocks on remaining violations, so standards are enforced rather than
remembered. If the hook reports that phpcbf reformatted a file, re-read it before editing again.

- Tabs for indentation, not spaces.
- Yoda conditions: `if ( 'value' === $variable )`.
- Spaces inside parentheses: `function foo( $bar )`, `if ( $baz )`, `array( 'key' => 'value' )`.
- `snake_case` for functions and variables, `PascalCase` for classes, `UPPER_SNAKE_CASE` for constants.
- Every file, class, method and property carries a docblock. Inline comments are full sentences
  ending in a period.
- Every user-facing string is translated with the `woo-kontor-sync-pro` text domain, and uses
  `printf`-style placeholders rather than concatenation.
- Class files follow PSR-4 (`includes/Api/Client.php`), not WordPress's `class-*.php` convention —
  the ruleset already excludes `includes/` from `WordPress.Files.FileName`.

## Security — not negotiable

These are the rules most often broken in WooCommerce integrations, and the ones that turn a sync bug
into an incident. Never ship a change that skips one.

- **Sanitise on input**: `sanitize_text_field()`, `absint()`, `sanitize_email()`, `wp_unslash()` on
  every `$_POST` / `$_GET` value. Never read `$_REQUEST`.
- **Escape on output**: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` — at the point of
  output, not at assignment.
- **Authorise every state change**: `check_admin_referer()` / `wp_verify_nonce()` *and*
  `current_user_can()`. A nonce alone is not authorisation.
- **Prepare every query**: `$wpdb->prepare()` for anything with a variable in it. Prefer the CRUD
  APIs and `wc_get_orders()` over hand-written SQL.
- **REST routes** need a real `permission_callback`. `__return_true` is only acceptable for genuinely
  public, read-only data.
- **Never log credentials.** Kontor tokens, API keys and full request headers must be redacted before
  they reach the log.
- **Never commit a real credential**, including as a test fixture. Fixtures must be synthetic values
  that reproduce the *shape* of the real thing — mixed case, punctuation, non-ASCII, a percent
  octet — and nothing more. A key pasted into a test file is a leak the moment it is committed.

## WooCommerce specifics

- **HPOS**: the plugin declares `custom_order_tables` compatibility in `before_woocommerce_init`.
  Any code touching orders must work with High-Performance Order Storage — no `get_post_meta()` on
  order IDs, no `WP_Query` against `shop_order`.
- **Use CRUD objects**: `wc_get_order()`, `wc_get_product()`, `$order->update_meta_data()`,
  `$order->save()`. Direct post meta reads on orders are a bug under HPOS.
- **Order queries** go through `wc_get_orders()`, product queries through `wc_get_products()`.
- The main file declares `Requires Plugins: woocommerce`, and the bootstrap still guards against
  WooCommerce being inactive — the header is advisory on older WordPress versions.
- Custom order/product meta uses the `_wksync_` prefix so it stays out of the visible custom fields UI.

## The Kontor API

Everything is one `POST` to `{api_base_url}/search`, distinguished by an `entity` in the body, with
the key in an **`x-api-key`** header — not a bearer token. Every reply, success or failure, uses the
same envelope: `success`, `message`, `meta.rowCount`, `meta.totalCount`, `data`, `errorCode`.

These were established by probing the live API. They are not in any documentation, and getting any
of them wrong produces silently wrong data rather than an error:

- **`shoptype` selects a price list, not a catalogue.** B2B, B2C, EDU and no filter all return the
  same 4386 articles. What changes is `UVP`: one article is 22.50 for B2B, 45.00 for B2C, 36.00 for
  EDU, while `Ek` stays constant across all three. **`UVP` is the product price.** `Ek` is the
  purchase price and is **not imported at all** — mapping it to the price would sell the whole
  catalogue at wholesale.
- **`Ek` and `Categories` are deliberately ignored**, and neither is part of the change hash. The
  hash covers only the fields in `ProductSync::$mapped_fields`; hashing the whole row would rewrite
  every product whenever purchase prices moved. `Herstellerid` *is* in the hash, because brands are
  matched on it — an article skipped as unchanged never reaches `Brands::resolve()`, so a
  manufacturer that moved would never be followed.
- **`Hersteller` becomes a WooCommerce brand** (`product_brand`, core since WooCommerce 9.6). 28
  distinct manufacturers appear in the first 500 articles and map 1:1 to names; the `manufacturer`
  entity lists **114** across the whole account. Only 2 rows in 500 carry no manufacturer at all —
  those products keep whatever brand they already had rather than being cleared.
- **Brands are matched on `Herstellerid`**, recorded in term meta as `Brands::TERM_META_ID`, with the
  name as the fallback when no ID is available. That is what makes a rename in the ERP a rename here
  rather than a second brand term with the old one stranded beside it. **Keep the IDs as strings**:
  they carry leading zeros (`084`), so casting to int would collide `084` with `84`. The IDs arrive
  alongside the name in 1998 of 2000 sampled rows, and none has one without the other.
  - A term found by *name* is adopted and stamped with the ID, which is what pulls brand terms
    created before this existed into the scheme.
  - A manufacturer arriving under a **new ID but the same name** keeps its existing term and
    re-stamps it. The ERP renumbering a manufacturer is far more likely than two companies sharing
    a name, and splitting the brand in two would be the worse failure.
  - The **slug is never changed** on a rename. Changing it would break any URL already pointing at
    the brand archive, and a stale slug alongside a new name works perfectly well.
- **The product sync can be restricted to chosen manufacturers** via `filter.herstellerids`, sent as
  one comma-separated string. The choices come from the **`manufacturer` entity**, which takes no
  paging and returns `Herstellerid` / `Hersteller` pairs, fetched on demand by the **Fetch
  manufacturers** button (`wksync_fetch_manufacturers`, nonce plus `manage_woocommerce`). An empty
  selection means the whole catalogue. Confirmed against the live API: the entity returns 114 rows,
  and filtering on three of them takes the catalogue from 4386 articles to 118.
  - **Narrowing the filter drafts products.** Excluded articles simply stop arriving, so
    `finalise()` drafts the ones it previously imported, exactly as if Kontor had dropped them.
    Widening it again republishes them, because `restore_if_sync_drafted()` only ever undoes this
    sync's own drafting. This is correct, and it is also surprising, so the settings screen says so.
  - The picker is a **list of checkboxes, not a multi-select**. A multi-select cannot be emptied
    without knowing to ctrl-click the last remaining item, and a plain click on any option silently
    collapses the whole selection to that one — it hides the way out and destroys work on the way
    in. The checkbox list comes with an **Import everything** button, which is the discoverable way
    back to "no filter".
  - Nothing ticked submits **no field at all**, so "absent" cannot mean "clear" — that would let a
    partial save silently widen the import to the whole catalogue. The form carries a
    `manufacturer_choice` marker that is always present, and only a submission carrying it may clear
    the list. Same reasoning as the intervals and the shop.
  - Pressing **Fetch manufacturers** keeps a ticked manufacturer that Kontor no longer lists, at the
    end of the list. Looking something up must not quietly edit the selection underneath it.
- **Images are deduplicated on their source URL**, recorded on the attachment as
  `ProductSync::META_IMAGE_SOURCE`. The same photograph is shared across articles often enough that
  downloading per product would multiply the media library. That meta doubles as the marker for
  "this plugin downloaded this file".
  - An image the product no longer uses is deleted only when it carries that marker **and** no
    product references it at all. Deduplication means one file can be the featured image of one
    article and a gallery entry of another, so "this product dropped it" is not "nobody wants it".
  - The in-use check matches the gallery with **`FIND_IN_SET`, never `LIKE`**. The gallery is a
    comma-separated list, and `LIKE '%12%'` matches the gallery `123` — the dangerous half being
    attachment 123 looking in use because 12 sits somewhere in a list.
  - **Downloads run in their own chained action, one per product**
    (`Scheduler::ACTION_SYNC_PRODUCT_IMAGES`), never inside the page action. Measured against the
    live catalogue, sideloading costs about **2.2 seconds per image** and articles average **2.38**
    of them, so a page of 200 would spend some seventeen minutes downloading — past the execution
    limit of an ordinary host, where the action is killed, Action Scheduler abandons the chain and
    `finalise()` never runs. Split out, the catalogue walk is bound by write speed alone (~35s a
    page) and a slow image can only delay itself.
  - **Image actions outlive the run that queued them.** `Status::finish()` leaves the run stamp
    alone, so the tail of downloads still passes `is_current_run()` after the walk has reported
    success; only a *new* run supersedes them. The job therefore reports complete while images are
    still arriving, which is correct — the catalogue is right, the pictures are cosmetic.
  - **A product's images are downloaded concurrently**, `ProductSync::IMAGE_CONCURRENCY` (4) at a
    time, filterable through `woo_kontor_sync_image_concurrency` and clamped to 1–8. Of the 2.2
    seconds a sideload takes, only about **0.1 is this machine's work** — `media_handle_sideload()`
    reading the file and building the seven thumbnail sizes, measured on the development site. The
    other two seconds are spent waiting on Kontor's host, and several images can wait at once.
    Measured end to end against a host answering in 1.5s, eight images took **12.6s serially and
    3.6–5.1s at four at a time**. The ceiling is politeness, not throughput: the host belongs to
    somebody else.
    - **Only the downloads are parallel.** Attaching stays serial — it is the CPU-bound tenth of the
      cost, so there is nothing to win, and WordPress's media handling is not written to be
      re-entered.
    - **The gallery is rebuilt in feed order, not arrival order.** Responses come back in any order
      at all, and the first image is the featured one, so ordering by reply would put an arbitrary
      photograph on the shop front. One entry per input URL, so a list naming the same file twice
      still counts as complete; a failed image is simply absent, which is what leaves the set
      partial.
  - **This means leaving `wp_remote_get()`, because WordPress cannot fetch two things at once.**
    `Requests::request_multiple()` — the library core is built on — runs a batch over `curl_multi`
    and streams each response to disk. Without curl the fsockopen transport answers the same call
    serially, so it degrades rather than fails. The `http_request_args` filter is lost with it.
    **Two things are kept deliberately**, because they are controls a site relies on rather than
    conveniences: `WP_HTTP_BLOCK_EXTERNAL` (via `WP_Http::block_request()`) and the
    `pre_http_request` short-circuit. A filter answering with a *response* is treated as a refusal:
    its contract is a response in memory and what is needed here is bytes on disk — core has the
    same gap, and `download_url()` under such a filter returns an empty file that fails as "not an
    image" two steps later.
  - **`ProductSync::IMAGE_TIMEOUT` (20s) bounds each download**, and now the batch with it, so an
    unresponsive host costs one timeout per batch rather than one per image. `media_sideload_image()`
    could never have been used: it calls `download_url()` with WordPress's 300-second default and
    offers no way to shorten it, and the `http_request_timeout` filter cannot help either, because
    an explicit `timeout` argument beats the filtered default. Confirmed live: the image host can
    accept a connection and then never answer, which without a bound holds the action open for five
    minutes per file.
  - **A non-200 deletes the file it streamed.** curl writes whatever comes back, so an error page
    would otherwise be saved under a `.jpg` name and handed to the media library as a photograph.
  - **Only a complete set stamps `META_IMAGE_HASH`.** Recording the whole list as done after a
    partial download would retire the missing images for good, because the next run finds the
    article unchanged and never asks again. For the same reason the unchanged-article skip path
    still offers the row to the image queue before returning `skipped`.
- **`paging.take` is capped at 2000** server-side, silently. Requesting 5000 returns 2000, so a
  pager that trusts its own page size skips records. `Client::MAX_PAGE_SIZE` enforces the cap, and
  `ProductSync::import_page()` advances `skip` by the rows actually returned rather than by the page
  size it asked for, which is what keeps the walk correct when a page comes back short.
- **The catalogue is walked at 200 per page** (`Client::PRODUCT_PAGE_SIZE`), one page per Action
  Scheduler action — about 22 actions for 4386 articles. The limit is our write speed, not the API:
  saving 500 products took around 78 seconds, long enough to risk being cut short on a slow host.
  Raise this and the failure mode is a truncated pass, not an API error. That budget only holds
  because images are downloaded elsewhere; do not put them back in the page action.
- **The `stock` entity takes no paging and no filter.** One request returns a level for every
  article (~2945 rows in ~65ms). Sending paging to it is not an error, just pointless.
- **The `shops` entity takes no paging or filter either**, and returns one row per shop — 13 on the
  account this was built against — as a `Shopid` GUID and a display `Name`. The chosen `Shopid` is
  stored as the `shop_id` setting, picked in the admin from a list fetched on demand by the **Fetch
  shops** button (`wksync_fetch_shops`, nonce plus `manage_woocommerce`). The list is never rendered
  from a cached copy that could go stale silently. Product and stock sync do not use the shop at
  all; it identifies the store when **orders are pushed and delivery information is pulled back**,
  so both of those need it set before they can run.
- **`/upsert` is the only write endpoint**, selected by `name: orders` rather than `entity`, and it
  needs `meta.userId` plus `params.shopid`. **Its top-level `success` stays `true` even when every
  order in the batch was rejected** — the per-row `status` (`ok` / `fehler`) is the only real signal.
  Reading the envelope alone would silently lose orders.
- **`meta.userId` is fixed to `WKSP`** (`OrderSync::UPLOAD_USER_ID`), agreed with Kontor. The API
  requires the field but does not validate it. It is a constant rather than a setting or a filter:
  the settings screen shows it read-only, and anything able to change it would make that display
  disagree with what is actually sent. The field there carries no `name` attribute, so it is never
  submitted and `sanitize()` has nothing to validate.
- **`orderPlatformid` is optional and deliberately not sent.** It identifies the platform to Kontor
  and no value has been agreed for this integration; inventing one would stamp a meaningless string
  on every order in the ERP.
- **`overwrite_all` stays `false`, and that is the idempotency mechanism.** Kontor deduplicates on
  `orderNumber`: re-sending an order already there comes back as `fehler` / *Dublette* rather than
  creating a second one. `OrderSync` therefore treats a Dublette as **success** — the order is in the
  ERP, which is the goal — instead of retrying it forever. Kontor does not return the existing
  `Auftrnr` in that reply; the delivery sync backfills it.
- **The `orders` entity honours only `filter.shopid`.** Order number, status and date filters are
  accepted and silently ignored, so there is no incremental fetch — every order for the shop comes
  back, capped around 1000 rows. A missing or unknown-but-well-formed shop ID returns an empty list,
  but a **malformed one is an HTTP 500**, which is why `shop_id` is validated as a GUID before it is
  ever sent.
- **`orderNumber` is the WooCommerce order ID; `orderId` carries the display number.** They are
  different fields on purpose. `orderNumber` is Kontor's deduplication key and the value delivery
  rows are matched back on, so it has to be stable for the life of the order — the ID is, and
  `get_order_number()` is not, being filterable and rewritten wholesale the day a
  sequential-order-number plugin is installed. `orderId` carries what the shop displays, so an order
  is still findable in the ERP by the number the customer and the shop manager both see.
- **Rows coming back are matched on the order number this plugin sent**, recorded as
  `_wksync_order_number` at push time rather than recomputed, so orders pushed by an earlier version
  still match on whatever they were actually sent as. Delivery rows and invoice rows both go through
  `OrderSync::find_by_number()`, which is where that lookup lives.
- **`deliveryAddress` is always sent**, falling back to the billing address when the order has no
  shipping street or postcode — which is what WooCommerce leaves on a virtual order, or on one where
  the customer did not tick "ship to a different address". An order reaching the ERP with nowhere to
  send it is one nobody can pick and pack. A shipping address carrying only a name is treated as
  absent for the same reason.
- **`taxStatus` is the only thing telling Kontor whether the amounts include VAT.** Nothing in the
  numbers says so, and reading a gross total as net understates the whole order by the rate.
  `paymentMethodName` and `paymentTransactionId` ride alongside `paymentMethod`: the slug is stable
  and the title is the wording the customer saw, and the transaction reference is what lets a
  payment be reconciled from the ERP.
- **A line's prices are all derived from the line, never from the product.** `regularPrice` is the
  line subtotal per unit, `unitPrice` the line total per unit, `discount` the difference, so
  `regularPrice - discount === unitPrice` and `unitPrice × quantity === totalPrice`. Reading
  `regularPrice` off the product instead would report today's price for an order placed months ago
  and break that arithmetic the moment a coupon was involved. Four decimal places, because two on a
  per-unit figure cannot always be multiplied back up to the line total. `priceFaktor` is sent as a
  constant `1`: Kontor multiplies by it, so what it defaults to on an absent field is the difference
  between the right price and none, while an explicit 1 cannot change an amount.
- **`provider` and `trackinginfo` arrive as `null`, not absent** — confirmed against live data, where
  all 7 rows for one shop had both null. Anything reading them has to treat null as empty.
- **An order the upsert reply says nothing about is counted as failed.** Nothing is written on it, so
  the next sweep sends it again; leaving it out of the counts instead would report a batch of
  twenty-five as "five sent" and give nobody a reason to look.
- **Invoices are a two-step download, and the second step is not under the base URL.** The
  `invoices` entity lists what exists — `id`, `Belegnr`, `Datum`, `Auftrnr` and the `ordernumber`
  this plugin sent — honouring only `filter.shopid`, exactly like `orders`. Fetching a document is
  then a `POST` to **`/api/v1/files/dms/getdocument`** with `{ "id": … }`, which is a *sibling* of
  the configured `api_base_url` (`…/api/v1/kontor`), not a child of it. `Client::build_url()`
  resolves `DOCUMENT_ENDPOINT` against the base's parent so there is still one URL in the settings
  rather than two that could drift onto different hosts; `woo_kontor_sync_document_url` overrides it.
  - **Its `data` is a base64 string, not a list of rows.** `interpret_response()` drops a `data`
    that is not an array, so reading a document through the normal path returns an empty result
    with `success` still true — a download that silently produced no file. `Client::SHAPE_DOCUMENT`
    is what keeps the payload.
  - Decoding is **strict** (`base64_decode( $x, true )`), and `Storage::put()` then checks the bytes
    actually start with `%PDF-`. Loose decoding silently discards what it does not recognise and
    hands back a shorter file that still looks like a success.
  - **The listing has no incremental filter**, so every run sees the shop's whole invoice history.
    What makes the job incremental is the **document id recorded on the order**; without it each run
    would re-download everything. An order can be invoiced more than once, so `_wksync_invoices`
    holds a *list*, and nothing already downloaded is ever replaced or deleted — an invoice is a
    financial record, and a second one is a new document rather than a correction of the last.
  - A recorded invoice whose file has been deleted counts as **still held**. Re-downloading it is
    the obvious alternative, but it would mean a shop that deliberately purged old invoices got
    them all back on the next run.
- **Invoice PDFs cannot go in the media library.** They carry a customer's name, address and what
  they bought, and everything under `wp-content/uploads` is served straight off disk to anyone
  holding the URL. `Invoices\Storage` writes them to a directory whose name carries a per-site
  random suffix, with `.htaccess`, `web.config` and `index.php` guards and a random component in
  every filename; `Invoices\Download` is the only way one comes back out.
  - **Nginx honours none of those guard files, and WordPress offers a plugin no portable directory
    outside what the web server publishes.** On such a host only the random names protect the files
    and `Download`'s permission check can be walked around. Assuming otherwise would fail
    invisibly, so `Storage::is_exposed()` **asks the web server directly**, by fetching a probe file
    over HTTP once a day, and the settings screen prints the `location` block to paste when the
    answer is yes. The Local development site is one of these — the probe returns 200 there.
  - `Storage::resolve()` treats the stored path as untrusted and refuses anything that `realpath()`
    puts outside the invoice directory. It comes from order meta, and a `../` would otherwise read
    whatever the web server can.
  - **Downloads carry the order key, not a nonce.** Three ways to be entitled to an invoice: a shop
    manager, the logged-in customer, or anyone holding the order key — the same token WooCommerce
    itself trusts on the order-received page, and the only thing a guest checkout has. A nonce would
    add nothing (a download changes no state) and would expire the link in an order email within a
    day of it being sent.
  - Invoices are **attached to customer emails** and skipped on the admin copies, alongside links in
    the order view and the emails, because a mail client that hides attachments still has to leave
    the invoice reachable. `woo_kontor_sync_attach_invoices` narrows which emails carry them.
  - **Uninstalling deletes neither the files nor the option naming their directory.** They are
    records the shop may be required to keep, and dropping the option would generate a new directory
    on reinstall and strand everything already there.
- **The `categories` entity exists but returns zero rows**, filtered or not, so the `Categories`
  GUIDs on an article could not be resolved to names even if we wanted them. Category mapping is
  not possible.
- **Image fields are bare filenames** (`abel-AB12_001.jpg`), not URLs. They are only usable if an
  image base URL is configured; with it blank, the sync skips images.
- **Errors are well formed**: a bad key gives HTTP 401 with `success:false` and
  `errorCode: ERR-401-INVALID-API-KEY`, and the `message` is in German. Surface `message` and
  `errorCode` to the user rather than inventing our own wording.

Three traps that all fail silently rather than loudly:

- **`sanitize_text_field()` strips percent-encoded octets.** This bites twice. A key containing
  `%5a` loses three characters and every request then fails with a confusing 401 — use
  `Settings::sanitize_api_key()`, which preserves everything except control characters (those must
  go, because the key becomes a request header where a newline would allow header injection). The
  same applies to any text coming from Kontor: a product title like `Rabatt 20%ab Lager` becomes
  `Rabatt 20 Lager`. Use `wp_strip_all_tags()` for names; WooCommerce escapes on output. Reach for
  `sanitize_text_field()` only where the value genuinely is plain prose.
- **`WP_Error::get_error_data()` takes an error code, not a data key.** Use `Client::detail()` to
  read `disposition` or `error_code`; `get_error_data( 'disposition' )` returns null and silently
  reduces every retry to a single attempt.
- **EANs in the feed are not unique.** WooCommerce enforces uniqueness on `global_unique_id` and
  throws `WC_Data_Exception` on a duplicate. Check with `wc_get_product_id_by_global_unique_id()`
  before setting it, and keep the per-article import inside a `try`/`catch` so one bad row cannot
  abort the whole page.
- **`wc_update_product_stock()` is a silent no-op when `manage_stock` is off.** The quantity stays
  null and the status stays `instock`, so the product keeps selling however low Kontor says it is.
  `StockSync::apply()` therefore loads the product, turns stock management on for products this
  plugin imported, and counts anyone else's separately rather than reconfiguring them.

Every job defaults to **Never**, so a fresh install contacts Kontor only when someone chooses a
schedule or presses Run now. Never is interval `0` (`Settings::INTERVAL_NEVER`); treat a missing
interval in a submission as "keep the stored value", never as `0`, or a partial save silently
disables a schedule.

## Kontor sync layer

The ERP is a remote REST service. Treat it as slow, occasionally unavailable, and never trusted.

Five jobs are implemented: **product sync** (7–30 days), **stock sync** (15 minutes–1 day), **order
sync** pushing to Kontor, **delivery sync** pulling status and tracking back, and **invoice sync**
(1 hour–1 day) downloading invoice PDFs. Nothing shorter than an hour for invoices: the listing has
no incremental filter, so a tighter schedule only re-reads the same history more often.

**No job runs until its preconditions hold** — `Preflight::check()`, called at the top of every
`start()`. Three gates, cheapest first: the API base URL and key are set; every job that talks to
Kontor about orders — the push, the delivery import and the invoice import — additionally has a shop
selected; and the credentials actually authenticate. This is not defensive padding. An
unauthenticated product sync reads as "Kontor lists no articles", and `finalise()` would then draft
the entire catalogue. Only *success* is cached (`Preflight::CONNECTION_TTL`, 15 minutes), so a
frequent job does not re-test every run while a fixed key still takes effect immediately. Saving the
settings clears the cache.

`Scheduler::trigger()` repeats the local gates so **Run now** can refuse with a reason instead of
queueing something that can only fail. It returns `true|WP_Error`, and the error *code* — never a
message — travels in the redirect, so nothing user-supplied is echoed back into the page. Note that
calling it queues real work: it is not a way to test whether a job would be allowed to run.

- **Schedule with Action Scheduler**, which ships inside WooCommerce — `as_schedule_single_action()`,
  `as_schedule_recurring_action()`, `as_next_scheduled_action()`. Do not use raw `wp_cron`: it has no
  retry semantics, no concurrency control, and no admin visibility.
- **Chain long runs across actions.** A job that walks thousands of records queues a follow-up
  action per page or chunk rather than looping in one request. See `ProductSync::import_page()`.
- **Whoever breaks a chain owns closing the status behind it.** A run only ever leaves the
  `running` state from inside one of its own chained actions, so anything that destroys the chain
  strands the job: the admin screen reports it as running and `Scheduler::trigger()` refuses to
  start another until `Status::STALE_AFTER` (6 hours) expires. Two things close it instead.
  `Deactivator::deactivate()` calls `Status::abandon()` before emptying the queue — deactivating
  mid-sync is otherwise a six-hour phantom. And `Scheduler` listens to Action Scheduler's own
  verdicts (`action_scheduler_failed_execution`, `action_scheduler_failed_action`,
  `action_scheduler_unexpected_shutdown`), because a page action that throws is never retried and
  the chain simply ends there. `Scheduler::job_for_action()` decides which job an action belongs
  to, and deliberately **omits `ACTION_SYNC_PRODUCT_IMAGES`** — images outlive their run and the
  catalogue is already right without them, so a failed download must not turn a finished product
  sync into a failed one — and `ACTION_SYNC_ORDER`, which is one checkout's upload rather than the
  sweep. A failure carrying a superseded `run`, or arriving after the job reported its own reason,
  is ignored.
- **`Scheduler::SCHEDULE_GUARD` means "the queue matches the settings"**, which is why
  `unschedule_all()` deletes it: it stops being true the moment the queue is emptied, and leaving
  it set makes `ensure_recurring_actions()` return early for the rest of the hour. A plugin
  deactivated and immediately reactivated would otherwise sit with no recurring actions at all,
  with the settings screen still showing every interval as configured. `Activator::activate()` goes
  further and calls `Scheduler::restore_schedules()`, so the schedules are back before the next
  `init` rather than after it.
- **Never sync inside a request that a customer is waiting on.** Checkout and order-status hooks
  enqueue an action; they do not call Kontor.
- **HTTP via `wp_remote_request()`** with an explicit `timeout` (`Client::REQUEST_TIMEOUT`, 30s), a
  descriptive `user-agent`, and `WP_Error` handled on every call.
- **Retry with exponential backoff** and a bounded attempt count. Retry 429, 502, 503, 504 and
  network errors; do not retry 4xx client errors — those are bugs, and they belong in the log.
- **Idempotency keys on every write** so a retried request cannot double-post an order to the ERP.
  The real guarantee is Kontor's own `orderNumber` deduplication described above; the
  `Idempotency-Key` header is belt and braces for a retry that never reaches the application layer.
- **The delivery sync completes orders, which emails customers.** An order Kontor reports as
  `completed` is transitioned to completed in WooCommerce, firing WooCommerce's "Order complete"
  mail. That is deliberate — it is the moment the shop wants that mail sent — but it means this job
  sends real outbound email, so it only ever moves an order *forwards*: something cancelled or
  refunded is left alone rather than resurrected. `DeliverySync::target_status()` is the one place
  that decides this, and `DeliverySync::$movable` lists the statuses each transition may move out of.
- **Kontor's fourth status has no WooCommerce equivalent, so the plugin registers one.**
  `Orders\PartialStatus` adds **Partially completed** (`wc-partial-complete`), which is where
  `partially_completed` lands. Leaving such an order in processing hides that anything shipped;
  completing it tells the customer the whole order is on its way and mails them to say so. The status
  is a **paid** one — the part already shipped has been paid for — and carries **no email**, which is
  the point. Its key is 19 characters because the status column holds 20, and it is registered
  outside the `is_admin()` branch: an order can only be moved into a status WooCommerce considers
  valid, and the delivery sync moves orders from a background job. Kontor's other two statuses,
  `canceled` and `in_progress`, are deliberately not acted on — both would move an order backwards.
- **The tracking details are shown to the customer**, by `Frontend\Tracking`: the My Account order
  view, the order-received page, and the order emails in both HTML and plain text. Without it the
  carrier, tracking number and tracking URL sit in order meta where only someone editing the order
  in the admin would see them, which is not who a tracking link is for. It is registered outside the
  `is_admin()` branch on purpose — emails render wherever the status changed, including inside the
  background job the delivery sync runs in. Admin copies of the emails are skipped. Remember that
  `provider` and `trackinginfo` arrive as `null` rather than absent, so a synced but unshipped order
  has the meta present and empty; the tracking number is what decides there is anything to show.
- **SKU is the only key**, for both product and stock sync. It holds Kontor's article number
  (`Artnr`), Kontor is the source of truth for it, and nothing else is ever matched on: not the EAN
  (which repeats across articles, so it *cannot* be a key), not `Artzentralnr`, not the product
  title. Do not store a second Kontor identifier on the product either — the SKU already is the
  identifier, and a spare one kept "for reconciliation" is a competing key waiting to be used.
  - **An article with no `Artnr` is passed over** (`no_sku`), never matched some other way and never
    created either: with nothing to recognise it by, the next run would import it a second time.
  - **An `Artnr` held by more than one product is passed over too** (`duplicate_sku`), and nothing is
    written. `ProductSync::products_for_sku()` is core's own lookup without its `LIMIT 1`, because
    `wc_get_product_id_by_sku()` answers "which product" and the question here is "how many".
    WooCommerce rejects a duplicate SKU on save, so this should never fire — but a migration, a CSV
    import, anything short-circuiting `wc_product_pre_has_unique_sku` and any code writing `_sku`
    directly all produce them, and the alternative is the sync quietly picking a side and leaving
    the other product drifting.
  - The run stamp still moves on the products involved (`ProductSync::keep_alive()`), or
    `finalise()` would read them as articles Kontor dropped and unpublish both while the article is
    still in the feed. Only products *already* carrying the stamp are touched: it doubles as the
    marker for "this plugin imported this", so stamping a shop manager's product would adopt it.
  - Both counts are reported in the run summary as well as the log. They are data faults only a
    person can fix, and the summary line is the one place anybody looks.
- **Stamp what was synced, not what it is called**: `_wksync_synced_at` and `_wksync_sync_hash` let
  reconciliation tell "never synced" from "synced and unchanged". `_wksync_synced_at` doubles as the
  marker for products this plugin owns — every import writes it and nothing else does — which is
  what `ProductSync::finalise()` and `StockSync::apply()` test before touching a product.
- **Log through `wc_get_logger()`** with `array( 'source' => 'woo-kontor-sync' )`, so output lands in
  WooCommerce → Status → Logs. Log the decision and the identifiers, never the payload's secrets.
- **Validate every response** before use. A field being present in the API docs is not a guarantee it
  is present in the response.
- Credentials live in a single autoloaded-`no` option and are never echoed back into an admin field
  in plaintext.

## Working agreement

- Run `composer lint` before calling any change done. The hook covers files you edit; the full run
  catches everything else.
- Add or update a test with each behaviour change — `composer test`.
- Verify against the real site (`./bin/wp`, http://testshop.local/wp-admin) rather than reasoning
  about what WooCommerce would do.
- Bump the version in both the `woo-kontor-sync-pro.php` header and the `WKSYNC_VERSION` constant
  together — `bin/check-version.sh` fails the build when they drift apart.

## Languages — English and German

The plugin ships English (the source strings) and German, and German is a requirement
rather than a courtesy: `tests/I18nTest.php` fails the build on a string that is in the catalogue
but untranslated. WordPress falls back to English silently, one label at a time, so nothing else
would notice.

- **Two German catalogues, not one.** WordPress treats `de_DE` (informal, *du* — the register
  WordPress core itself uses) and `de_DE_formal` (*Sie*) as unrelated locales, so both are
  maintained. Only the strings that address the reader differ between them.
- **Every other German locale is mapped**, by `Plugin::map_german_locale()` on
  `load_textdomain_mofile`. `de_AT`, `de_CH` and `de_CH_informal` have no catalogue of their own and
  WordPress does not fall back between German locales, so without this an Austrian shop reads an
  English admin screen. The filter only redirects a *missing* German catalogue, so a translation
  someone drops into `wp-content/languages/plugins` still wins. Filtering the `.mo` path is enough
  to bring the `.l10n.php` along, because WordPress derives that filename from the filtered value.
- **`.mo` and `.l10n.php` are both committed and both shipped.** WordPress 6.5 and newer load the
  PHP file in preference to the `.mo`; the `.mo` stays for anything reading the catalogue directly.
  The `.po` sources are the input and are left out of the build.
- **Regenerate with `composer i18n`** after adding or changing any translatable string: it
  re-extracts the POT, merges it into each `.po` (keeping existing translations, marking changed
  ones fuzzy) and recompiles. It uses wp-cli's `i18n` commands, which are pure PHP — no gettext
  toolchain, on a laptop or on a runner. A fuzzy entry counts as untranslated and fails the tests.
- **`composer i18n:check`** asserts the POT still lists every string in the source. It compares the
  *set of strings*, not the files: the POT also records the line each string came from, and failing
  a build because a function moved twenty lines down would teach everyone to ignore the check.
- Strings for the admin JavaScript are translated in PHP and handed over with
  `wp_localize_script()`, so there is nothing to extract from `assets/js` and no JSON catalogue to
  build.

## Continuous integration and releases

`.github/workflows/ci.yml` runs on every push to `main` and every pull request, and is the same set
of commands the working agreement asks for:

- **Coding standards** — `composer validate --strict`, `bin/check-version.sh`, `composer lint`, and
  `node --check` over `assets/js`.
- **Translations** — `bin/check-translations.sh`, which needs wp-cli and so runs in its own job.
  Whether the strings are *translated* is asserted by the test suite instead.
- **Dependency audit** — `composer audit --locked`.
- **Tests** — the full suite on PHP 8.2, 8.3 and 8.4. 8.2 is the floor and what the site runs; the
  others are forward compatibility. `fail-fast` is off so one version failing does not hide the rest.
- **Coverage** — one more run under pcov, published as a job summary and an artifact. No threshold is
  enforced; the number is there to be looked at, not to fail a pull request on.
- **Build** — `bin/build-zip.sh`, uploaded as an artifact, so every pull request proves the release
  artefact still builds and leaves something installable to test by hand.

Each job goes through `.github/actions/setup-plugin`, so PHP, the Composer cache and the test site
are configured in one place rather than four.

**CI cannot use `bin/install-wp-tests.sh`** — it resolves everything from Local's `sites.json`.
`bin/install-wp-tests-ci.sh` is the runner's equivalent: it downloads WordPress and WooCommerce,
creates the database through mysqli rather than a `mysql` client that a runner is not guaranteed to
have, and writes the same `tests/wp-tests-config.php`. It refuses to overwrite a config pointing
somewhere else, so running it on the development machine cannot silently repoint the suite away from
the Local site.

**It symlinks the checkout into `wp-content/plugins/woo-kontor-sync-pro`, and that link is
load-bearing.** `tests/bootstrap.php` loads the plugin from `WP_PLUGIN_DIR` and calls
`wp_register_plugin_realpath()` first, which is how WordPress itself loads a symlinked plugin, and
the only way `plugin_basename()` shortens to the plugin slug. Everything keyed on that slug behaves
differently when it does not: `FeaturesUtil::declare_compatibility()` records HPOS support under an
absolute path, and `load_plugin_textdomain()` registers a languages directory that does not exist,
so no translation loads and every i18n test fails while the plugin works perfectly on a real site.
Local provides the symlink; the CI script creates the same one.

Releases are cut by pushing a `v*` tag (`.github/workflows/release.yml`):

- The entire CI workflow runs against the tagged commit first, so nothing is ever published from a
  red build.
- `bin/check-version.sh <tag>` then asserts that the tag, the `Version:` header and `WKSYNC_VERSION`
  all agree.
- `bin/build-zip.sh` produces `dist/woo-kontor-sync-pro-<version>.zip`, a `.sha256` beside it and
  `dist/update.json`, and `gh release create` publishes all three with generated notes. A tag
  containing a hyphen (`v0.5.0-rc.1`) is published as a pre-release. Running the workflow by hand
  builds and verifies without publishing.

The zip carries only what WordPress runs: the main file, `includes/`, `assets/`, `languages/`,
`uninstall.php` and a `--no-dev` `vendor/`. Composer runs inside the staging copy rather than the
checkout, so building never disturbs the dev dependencies the suite needs, and `composer.json` is
removed from the build so nobody is invited to run Composer inside a live plugins directory.

`.github/dependabot.yml` watches Composer and the actions themselves weekly. It is told to ignore
PHPUnit 10+ and PHP_CodeSniffer 4+, because both pins below are deliberate.

## Updates from GitHub

The plugin is not in the WordPress.org directory, so nothing would otherwise tell an installed copy
that a newer version exists. `Updates\Updater` closes that gap through the **`Update URI` header**
and core's **`update_plugins_{$hostname}` filter** — the mechanism WordPress added in 5.8 for
exactly this — rather than a bundled update-checker library or a hand-written
`pre_set_site_transient_update_plugins`. Core then owns the bookkeeping: the update row, "update
now", the bulk updater and `WP_Automatic_Updater` all work unchanged, and nothing wp.org-specific
gates any of them.

- **An answer is returned even when the installed version is current**, and that is what makes the
  **auto-update toggle** appear. Core files an up-to-date answer under `no_update` in the
  `update_plugins` transient, and `WP_Plugins_List_Table` decides a plugin supports updates by
  finding it in `response` *or* `no_update`. Answer only when an update exists and every site that
  is already current — nearly all of them — reads *"Auto-updates are not available for this
  plugin"*. Nothing else is needed to enable auto-updates: the normal per-plugin toggle and the
  `auto_update_plugins` site option drive core's updater directly.
- **`package` is the built release asset**, which is what lets core install unattended. Without one
  WordPress shows the new version and says an automatic update is unavailable. The asset zip has
  `woo-kontor-sync-pro/` as its single top-level directory, so an update lands on the existing
  directory name; GitHub's generated source zipball does not, and would install the plugin twice
  under a versioned folder.
- **The metadata comes from `update.json` published beside the zip**, read from
  `…/releases/latest/download/update.json`, not from `api.github.com`. The API would work, but it is
  rate limited to **60 requests an hour per IP** for anonymous callers — shared hosting puts
  hundreds of sites behind one address — and it carries none of the plugin's requirements, so
  `requires_php`, the floor that stops an update installing onto a host too old for it, would have
  to be guessed. That URL always resolves to the newest **non-prerelease** asset of that name, which
  is also what keeps `v0.5.0-rc.1` from being offered to production sites. **A release published
  without `update.json` is invisible to every installed copy**, which is why the workflow uploads it
  by that exact constant name.
- **A package pointing anywhere but this repository's releases is discarded**, and the update is
  then reported without one. The manifest arrives from GitHub over TLS, but "unpack this zip over
  the plugin directory" is not an instruction to take on trust from a parsed response.
- **The updater is registered before the WooCommerce and HPOS gates**, in the main file rather than
  in `Plugin::init()`. An update is often exactly what fixes a plugin sitting inert behind one of
  those gates; a site whose WooCommerce is too old must still be offered the version that supports
  it.
- **A failed check reports nothing**, which reads as "updates not available" rather than as "up to
  date". Failure is cached for an hour and success for six, so an unreachable host costs one request
  an hour instead of one per admin page load. **Check again** clears both caches, because it deletes
  the `update_plugins` transient and the updater hooks `delete_site_transient_update_plugins`.
- **The settings screen carries its own "Check for updates"**, because both caches together mean a
  release published an hour ago is invisible with nothing on screen saying so: core reuses its
  answer for twelve hours outside the plugins and updates screens, and `wp_update_plugins()` returns
  without asking anybody while it is still warm. `Updater::refresh()` deletes both transients and
  then calls `wp_update_plugins()` — going **through core rather than reading the manifest
  directly**, so what the screen reports is what the plugins screen will act on rather than a second
  opinion that can disagree with it.
  - `Updater::status()` reads core's transient and never touches the network: `response` means an
    update, `no_update` means current, neither means **unknown**. Unknown is reported as unknown
    rather than as up to date — a failed check leaves the plugin in neither bucket, and a site
    running last year's version must not be told it has the newest.
  - Core asks WordPress.org first and **abandons the whole check if that request fails**, so the
    `Update URI` filter is never reached. An unreachable wp.org therefore reads exactly like an
    unreachable GitHub, which is why the failure message names both.
  - The button is gated on **`update_plugins`, not this screen's `manage_woocommerce`**. A shop
    manager can run every sync here but cannot install a plugin, and the whole section is hidden
    from them rather than offering to find an update they are not allowed to apply.
- The `Update URI` header also **stops WordPress.org answering for this slug**, which is the other
  half of what it is for.
- `tested` is deliberately left empty: there is no `readme.txt` stating a tested-up-to version, and
  inventing one from `Requires at least` would be a claim nobody checked.

## Dependency versions — do not "upgrade" these

Two pins look outdated and are not. Both were established by trying the newer version and watching
it break.

- **PHP_CodeSniffer stays on 3.x.** 4.0 is released, but `wp-coding-standards/wpcs` 3.4.1 requires
  `^3.13.5`. Requiring `woocommerce/woocommerce-sniffs ^2.0` resolves the whole standards stack
  correctly; do not pin PHPCS directly.
- **PHPUnit stays on 9.x.** WordPress's own test library calls
  `PHPUnit\Util\Test::parseTestMethodAnnotations()` and `$this->getName( false )`, both removed in
  PHPUnit 10. On 11.x every test errors out before it runs. This is a WordPress constraint, not a
  polyfills one.
- `config.platform.php` is pinned to `8.2.29` so Composer resolves for the site's runtime rather
  than the host's PHP 8.5.
