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
- **`paging.take` is capped at 2000** server-side, silently. Requesting 5000 returns 2000, so a
  pager that trusts its own page size skips records. `Client::MAX_PAGE_SIZE` enforces the cap, and
  `ProductSync::import_page()` advances `skip` by the rows actually returned rather than by the page
  size it asked for, which is what keeps the walk correct when a page comes back short.
- **The catalogue is walked at 200 per page** (`Client::PRODUCT_PAGE_SIZE`), one page per Action
  Scheduler action — about 22 actions for 4386 articles. The limit is our write speed, not the API:
  saving 500 products took around 78 seconds, long enough to risk being cut short on a slow host.
  Raise this and the failure mode is a truncated pass, not an API error.
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
- **Delivery rows are matched on the order number this plugin sent**, recorded as
  `_wksync_order_number` at push time rather than recomputed, so orders pushed by an earlier version
  still match on whatever they were actually sent as.
- **`deliveryAddress` is always sent**, falling back to the billing address when the order has no
  shipping street or postcode — which is what WooCommerce leaves on a virtual order, or on one where
  the customer did not tick "ship to a different address". An order reaching the ERP with nowhere to
  send it is one nobody can pick and pack. A shipping address carrying only a name is treated as
  absent for the same reason.
- **`provider` and `trackinginfo` arrive as `null`, not absent** — confirmed against live data, where
  all 7 rows for one shop had both null. Anything reading them has to treat null as empty.
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

Both jobs default to **Never**, so a fresh install contacts Kontor only when someone chooses a
schedule or presses Run now. Never is interval `0` (`Settings::INTERVAL_NEVER`); treat a missing
interval in a submission as "keep the stored value", never as `0`, or a partial save silently
disables a schedule.

## Kontor sync layer

The ERP is a remote REST service. Treat it as slow, occasionally unavailable, and never trusted.

Four jobs are implemented: **product sync** (7–30 days), **stock sync** (15 minutes–1 day), **order
sync** pushing to Kontor, and **delivery sync** pulling status and tracking back.

**No job runs until its preconditions hold** — `Preflight::check()`, called at the top of every
`start()`. Three gates, cheapest first: the API base URL and key are set; order jobs additionally
have a shop selected; and the credentials actually authenticate. This is not defensive padding. An
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
  refunded is left alone rather than resurrected. `DeliverySync::should_complete()` is the one place
  that decides this.
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
  title. An article with no `Artnr` is a failed row, never a row to match some other way. Do not
  store a second Kontor identifier on the product either — the SKU already is the identifier, and a
  spare one kept "for reconciliation" is a competing key waiting to be used.
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
- `bin/build-zip.sh` produces `dist/woo-kontor-sync-pro-<version>.zip` and a `.sha256` beside it, and
  `gh release create` publishes both with generated notes. A tag containing a hyphen (`v0.5.0-rc.1`)
  is published as a pre-release. Running the workflow by hand builds and verifies without publishing.

The zip carries only what WordPress runs: the main file, `includes/`, `assets/`, `languages/`,
`uninstall.php` and a `--no-dev` `vendor/`. Composer runs inside the staging copy rather than the
checkout, so building never disturbs the dev dependencies the suite needs, and `composer.json` is
removed from the build so nobody is invited to run Composer inside a live plugins directory.

`.github/dependabot.yml` watches Composer and the actions themselves weekly. It is told to ignore
PHPUnit 10+ and PHP_CodeSniffer 4+, because both pins below are deliberate.

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
