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
composer lint       # phpcs against the WooCommerce standard
composer lint:fix   # phpcbf — fix what can be fixed automatically
composer test       # PHPUnit against the WordPress test library
./bin/wp <args>     # wp-cli against the Local site
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
  every product whenever purchase prices moved.
- **`Hersteller` and `Herstellerid` become WooCommerce brands** (`product_brand`, core since
  WooCommerce 9.6). They always arrive together — 1998 of 2000 sampled rows carry both, none has one
  without the other — and 28 distinct manufacturers map 1:1 to names. `Herstellerid` is stored as
  term meta and is what terms are matched on, so a manufacturer renamed in the ERP renames the
  existing brand instead of leaving a duplicate. **Keep the IDs as strings**: they carry leading
  zeros (`084`), so casting to int would collide `084` with `84`.
- **`paging.take` is capped at 2000** server-side, silently. Requesting 5000 returns 2000, so a
  pager that trusts its own page size skips records. `Client::MAX_PAGE_SIZE` enforces the cap;
  the catalogue is walked at 500 per page.
- **The `stock` entity takes no paging and no filter.** One request returns a level for every
  article (~2945 rows in ~65ms). Sending paging to it is not an error, just pointless.
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

Two jobs are implemented, both pulling from Kontor: **product sync** (7–30 days) and **stock sync**
(15 minutes–1 day). Order push and delivery-information pull are planned and not built.

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
  Store the key alongside the remote ID.
- **Store the remote ID** as `_wksync_kontor_id` meta on the local object, plus `_wksync_synced_at`
  and `_wksync_sync_hash`, so reconciliation can tell "never synced" from "synced and unchanged".
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
  together.

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
