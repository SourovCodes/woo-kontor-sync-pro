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
  EDU, while `Ek` stays constant across all three.
- **Kontor's B2B price list *is* `Ek`.** Verified against the live account on 986 articles sampled
  across five pages spread through the catalogue: `Ek` equalled the B2B `UVP` on **every single
  row**, with no exceptions, and was identical under all three shop types. So the purchase price and
  the wholesale selling price are one number, not two.
  - **A wholesale shop is therefore requested with the *retail* list** and prices from `Ek`
    (`ProductSync::request_shoptype()` and `price_field()`). That single request carries both figures
    it needs — `Ek` is what the shop charges, and the B2C `UVP` is the recommended retail price a
    business buying here can resell at. Asking for the B2B list instead would return the same `Ek`
    and a `UVP` that merely repeats it, so there would be no retail price to show at all, and
    fetching one would cost a second request per page.
  - It is **not** a shop selling at cost, and it does not contradict the rule below: the price is
    numerically what it always was. But the arrangement rests entirely on that equality holding. If
    Kontor ever puts a margin between the two, a wholesale shop silently sells at whichever is
    lower, with nothing to say so — re-check it before trusting this on another account.
  - **B2C and EDU are unchanged**: requested as themselves, priced from `UVP`, no retail price
    recorded, because there the `UVP` already *is* the price.
- **`UVP` is the product price on a retail or education shop**, and mapping `Ek` to the price on one
  of those would sell the whole catalogue at wholesale.
- **The retail price is stored as `ProductSync::META_MSRP`** (`_wksync_msrp`), on a wholesale shop
  only. `Frontend\ProductMeta` renders it on the product page and `Rest\Products` serves it; nothing
  else reads it.
  - **The product page shows it in the meta block**, beside the article number and the categories,
    through `woocommerce_product_meta_end` — the extension point a theme overriding
    `single-product/meta.php` keeps, and the one place a customer already looks for the article's
    identifiers. The row mirrors core's own SKU markup (`wksync_msrp_wrapper` / `wksync-msrp`, with
    the label in a `meta-label` span), and the amount goes through `wc_price()` so it carries the
    shop's currency.
  - **Off by default** (`Settings::SHOW_MSRP`), like every other setting that changes what the shop
    does. This one is public: it states a second price to every customer, on every product, and an
    update is not the thing that should decide to start.
  - **The label is a setting, not a translated string** (`Settings::MSRP_LABEL`). RRP, UVP, list
    price — what the figure is called differs between shops in the same language, which is the one
    thing a catalogue cannot answer. **Empty means the translated default**, resolved at render in
    `ProductMeta::label()` rather than stored, so a shop that never touched the field reads its own
    language and an emptied field is the way back rather than a row with nothing in front of it.
    Sanitised with `wp_strip_all_tags()`, never `sanitize_text_field()`, which would eat the percent
    out of "UVP inkl. 20% MwSt.".
  - **A zero or absent figure shows no row**, which the import already guarantees by deleting the
    meta rather than writing `0.00` — but a recommended price of nothing in front of a customer is
    worth being sure of twice.
  - **It is served on `/wc/v3/products` as `msrp`** (`Rest\Products`), because the meta key is
    underscore-prefixed and therefore protected: without this, anything reading products over HTTP
    sees the price and not the figure beside it. **Null when there is none**, never `0.00` or an
    empty string — an absent price is a different thing from a price of nothing, and the field is
    always present so a client can tell "this shop supplies none" from "this build predates the
    field". **Read-only**, because the sync rewrites the meta on every run and a write accepted here
    would vanish at the next one.
  - The field name is deliberately **unprefixed**, unlike the meta key. The prefix keeps this
    plugin's storage out of everyone else's way; the REST field is a published name, and a consumer
    asking for a recommended retail price should not have to know which plugin filled it in.
  - `register_rest_field()` is the mechanism, and it does work on WooCommerce's v3 controller —
    `WC_REST_Products_V2_Controller::prepare_object_for_response_core()` calls
    `add_additional_fields_to_object()`, and `get_item_schema()` ends in
    `add_additional_fields_schema()`. What it needs is to be registered **before `rest_api_init`
    fires**, which `Plugin::init()` satisfies. Testing this from `wp eval` does not: plugins on the
    site build the REST server during `init`, so the action has already run and the field silently
    never appears.
  - **Not on the Store API** (`/wc/store/v1`), which is a separate schema with its own extension
    mechanism, and not on variations, which never carry the meta.
  - **Stored raw, never as a saving.** Kontor lists no retail price at all for some articles and one
    no higher than `Ek` for others — 25 in 986 sampled, mostly nulls, plus articles where the two are
    equal. The product page states it raw for exactly that reason: a row promising a saving would be
    lying on those articles, and neither the import nor the template can tell which.
  - **Absent, zero or negative deletes the meta** rather than writing `0.00`. That also clears the
    figure from a shop that has since moved off wholesale.
- **The EAN is shown the same way** (`Settings::SHOW_EAN`, `Settings::EAN_LABEL`, off by default),
  read from WooCommerce's own `global_unique_id` rather than from a meta key of this plugin's. Core
  prints only the SKU, the categories and the tags, so without this the EAN sits in a field nobody
  but an admin sees. A product whose EAN another product already holds has none — the import passes
  over the duplicate — so it shows no row rather than an empty one.
- **`Verkaufsmenge` and `Verkaufsmenge_staffel` are the quantities an article is sold in** — the
  smallest that may be bought and the step it goes up in. Both keys are **always present and either
  can be null**. They become `ProductSync::META_MIN_QTY` (`_wksync_min_qty`) and
  `ProductSync::META_QTY_STEP` (`_wksync_qty_step`), and `Frontend\Quantities` is what holds the shop
  to them. An article of 6 with a step of 2 is bought as 6, 8, 10 and so on.
  - **They are imported on every run whatever the setting says, and the setting decides only whether
    a customer is held to them.** The figures are what Kontor states about the goods; enforcement is
    a decision about this shop. Keeping the two apart is what lets `enforce_order_quantities` be
    turned on and take effect at once, rather than after a full catalogue walk had been round writing
    figures an earlier run deliberately skipped. It is off by default, like every other setting here
    that changes what the shop does.
  - **They are in the change hash**, so a sales quantity that moves and nothing else still reaches
    the shop. Adding them rewrote the whole catalogue once, on the first run after 0.12.0 — the same
    intended cost as changing the shop type.
  - **1, 0, null, a negative, a fraction or a non-number all store nothing.** One is WooCommerce's
    own default, so recording it would put a row of meta on every product in the catalogue to say
    what was already true. A fraction is not a number of pieces and there is no honest way to round
    it into one, so it is logged and ignored rather than turned into a rule nobody chose.
  - **Everything hangs off `woocommerce_quantity_input_min` and `woocommerce_quantity_input_step`.**
    They are the one point every part of WooCommerce reads these numbers through —
    `WC_Product::get_min_purchase_quantity()` and `get_purchase_quantity_step()` apply them,
    `wc_get_quantity_input_args()` reads those, and the Store API's `QuantityLimits` reads that in
    turn — so the classic quantity box, the cart block and the checkout block all agree without
    being addressed separately. There was no need for the `woocommerce_store_api_product_quantity_*`
    filters.
  - **What those filters do not do is refuse a quantity that arrives anyway.** A `min` and `step`
    attribute is a courtesy to a browser. The Store API validates against its own limits, but the
    classic add-to-cart and cart-update handlers take whatever number is posted, so
    `woocommerce_add_to_cart_validation` and `woocommerce_update_cart_validation` are checked too.
  - **The minimum is raised to a multiple of the step**, exactly as
    `QuantityLimits::get_add_to_cart_limits()` does, because WooCommerce judges a quantity by asking
    whether it is a multiple of the step rather than whether it is the minimum plus some number of
    steps. A minimum of 5 with a step of 2 is therefore 6, 8, 10. Doing the raising in
    `Quantities::limits()` rather than leaving it to the Store API is what stops the classic quantity
    box offering a five the cart block then refuses.
  - **Add-to-cart judges the quantity the cart would end up holding**, not the one being added —
    which is what WooCommerce's own `CartController::add_to_cart()` does before it merges a line.
    Two additions that are each unobjectionable can still leave an invalid total.
  - **`woocommerce_check_cart_items` is checked as well**, or the rule would only apply on the way
    in: a cart filled before the setting was turned on would carry an invalid quantity through
    checkout and into an order Kontor cannot fulfil.
  - **Order screens and refunds are never restricted.** WooCommerce seeds the admin quantity step
    from the same product method, so `woocommerce_quantity_input_step_admin` is answered with 1.
    Refunding one item of six is an ordinary thing to do, and a shop manager repairing an order is
    not a customer being sold to.
  - **They are shown, read-only, on the product's Inventory tab** (`Admin\ProductFields`). Without
    the panel there is nowhere to see them at all — the meta keys are protected, so the Custom
    Fields box will not show them, which is the whole point of the prefix — and a shop manager has
    no way to find out why a product refuses a quantity.
    - **Deliberately not a form field**: no `input`, no `name`, nothing submitted, and no save
      handler behind it. Every sync rewrites both figures, so an editable box would accept a change
      that quietly disappeared at the next run. They are changed in the ERP, and the panel says so.
    - **Simple products only** (`show_if_simple`). WooCommerce reads these figures from the
      *variation* rather than the parent, so a value on a variable product would mean nothing.
    - **Nothing stored reads as "None"**, not as 0. A stored 1 cannot arise — the import treats it
      as no constraint and stores nothing at all.
    - The product comes from **`$product_object`**, the global WooCommerce's own inventory template
      reads, rather than a re-fetch from the post ID: the meta box sets it to the product being
      edited, so it carries unsaved changes.
  - **Both are served on `/wc/v3/products`** as `min_order_quantity` and `order_quantity_step`, for
    the same reason as `msrp`: the meta keys are protected, so a headless storefront would otherwise
    offer quantities the shop refuses. They report **1 rather than null** when there is no
    constraint — unlike a retail price, every product has an answer to how few of it can be bought —
    and they report **what this shop will accept rather than what Kontor stated**, so they read 1
    while enforcement is off. Both come from `Quantities::limits()`, the same method the cart is held
    to, so a client obeying them cannot be turned away by a shop that disagrees.
- **`Categories` is deliberately ignored**, and so is `Ek` on the shop types that do not price from
  it; neither is part of the change hash there. The hash covers the fields in
  `ProductSync::mapped_fields()`; hashing the whole row would rewrite every product whenever
  purchase prices moved. `Ek` **joins** that list on a wholesale shop, because it is the price
  there — left out, a price rise that moved nothing else would never reach the shop. `Herstellerid`
  *is* in the hash on every shop type, because brands are matched on it — an article skipped as
  unchanged never reaches `Brands::resolve()`, so a manufacturer that moved would never be followed.
- **The configured shop type is hashed alongside the row.** Without it, switching a shop from
  wholesale to retail would leave every product priced at `Ek` for good: both are sent the *same*
  request, so the row that comes back is identical and nothing in it changes. This is also why
  changing the shop type rewrites the whole catalogue on the next run, which is the intended cost.
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
- **Two things hold an article out of the shop — `Ws_aktiv` and the image requirement — and both
  answer the same way: import it and leave it a draft.** `ProductSync::withheld_reason()` is the one
  place that decides which, if either, applies, and everything below follows from it.
  - **A withheld article is created, not passed over.** The shop ends up holding the whole
    catalogue — priced, stocked, branded and pictured — with the part it may not sell sitting one
    status change away, which is what lets an article switched on in the ERP appear in the shop on
    the next run instead of being imported from scratch. Nothing else in the plugin would notice a
    missing product until it was too late: a product that does not exist has no URL to keep, no
    reviews and no place in an order.
  - **Its data keeps following the feed while it is drafted.** The write path is the ordinary one,
    so a price that moves while an article is switched off is right when it comes back, and the
    change hash still short-circuits the runs where nothing moved — the status and the marker are
    settled once, not rewritten on every pass.
  - **Its images are downloaded like anyone else's.** A shop manager opening the draft should see
    the article rather than a placeholder, and one switched on tomorrow should go in front of
    customers complete. Images are already queued below every other job
    (`Scheduler::PRIORITY_IMAGES`), so the extra downloads cannot delay a sync that matters.
  - **A product this plugin never imported is left entirely alone** — not drafted *and not
    rewritten*. `import_article()` returns before touching it. Adopting it would only mean drafting
    it on the run after this one, once the stamp it wrote made it ours, which is a worse answer than
    either doing it at once or not at all.
  - **A status this sync does not own is left where it was put**, `private`, `pending` or anything
    another plugin registered. The article's data is still written over it; only the status and the
    marker are withheld, because marking it would hand a later run the right to publish something
    somebody deliberately took out of the shop.
  - **Each reason is counted and named in the run summary**, never folded into `created` or
    `updated`. "3 created … Held 827 back as drafts, switched off for the webshop in Kontor" is the
    sentence that tells a shop manager where a fifth of the catalogue went.
- **`Ws_aktiv` is Kontor saying whether an article belongs in the webshop at all**, and it is
  obeyed unconditionally — there is no setting, because it is not this shop's decision to make. A
  false article is **imported as a draft** and marked `ProductSync::META_INACTIVE_DRAFTED`
  (`_wksync_drafted_inactive`); true publishes it again through `restore_if_sync_drafted()`. A
  product already in the right state is left untouched in either direction.
  - **It is not a rare flag.** Measured across the whole live catalogue: 4386 articles, **3559 true
    and 827 false**, a real JSON boolean on every row, never absent and never any other value. The
    first run after this change therefore drafts a fifth of the shop — the same intended cost as
    narrowing the manufacturer filter, and reversible the same way.
  - **Only an unmistakable false withholds an article.** A missing key, a null, or a word this does
    not recognise reads as active, because the two ways of being wrong are not equal: reading
    "active" as "inactive" takes a fifth of the shop dark the day the field changes shape, while the
    reverse leaves a few articles on sale until somebody notices.
  - **Its own marker**, for the reason `META_NO_IMAGE_DRAFTED` has one: the conditions clear at
    different moments, and sharing a marker would let a picture arriving republish an article Kontor
    has switched off. It blocks the stock sync's release paths for the same reason.
  - **Deliberately not in the change hash.** `withheld_reason()` is read off the row before the
    unchanged-article shortcut on every run, so hashing it would buy nothing and rewrite the whole
    catalogue once to buy it.
  - **It outranks the image requirement**, and `withheld_reason()` is the one place that says so:
    Kontor's verdict is the ERP's statement about the article, the requirement is this shop's
    setting, and an article switched off is not one whose pictures anybody needs to weigh. Exactly
    one reason answers per run, so a product that is both switched off and imageless carries the
    inactive marker alone; switching it on hands the reason to the image gate rather than
    publishing it.
- **The import can be restricted to articles Kontor lists an image for**, via the
  `require_main_image` setting, off by default. An article with no image is **imported as a draft**
  (`no_image`) and marked with `ProductSync::META_NO_IMAGE_DRAFTED` — the same answer as every other
  reason this plugin takes a product out of the shop, so a briefly incomplete feed costs nothing
  that cannot be got back.
  - **The decision is made on the feed row, never on the product.** Images are downloaded in a
    chained action of their own, so a product written moments ago legitimately has no featured image
    yet; judging by the shop would draft products whose pictures were merely still on their way.
  - It also **does not depend on `image_base_url`**. Whether the shop can fetch the file is a
    separate question from whether Kontor has one, and tying them together would mean clearing the
    base URL silently drafted the whole catalogue.
  - **Any image counts, not `MainImageURL` alone.** The featured image is the first image the
    article carries, so an article whose only picture is `ImageURL_1` does end up with one; reading
    `MainImageURL` alone would draft a product about to get exactly what the setting asks for.
  - **Checked before the unchanged-article shortcut**, or turning the setting on would leave the
    existing catalogue published until every article in it happened to change.
  - **Asked only about an article Kontor is willing to sell here**, because `Ws_aktiv` is checked
    first. A shop's own setting does not get to decide which of the ERP's articles are named in the
    run summary.
  - **Its own marker, not `META_SYNC_DRAFTED`.** That one means "Kontor stopped listing this
    article" and this one means "Kontor lists it, without a picture" — two conditions that clear at
    different moments, and sharing a marker would let an article returning to the catalogue
    republish itself while it is still imageless. Turning the setting off, or the image coming back,
    clears it through `restore_if_sync_drafted()`. Both are checked before the restore path is
    entered, so a still-imageless article is withheld again rather than freed.
  - The checkbox is paired with a **hidden `0` field**, so "off" is a value that arrives rather than
    one inferred from a browser's silence. Absent still means "keep the stored value" — same
    reasoning as the intervals, the shop and the manufacturers.
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
    - **A separate action is not enough on its own — they also carry
      `Scheduler::PRIORITY_IMAGES` (20), below the default 10 everything else uses.** Action
      Scheduler claims by `priority, attempts, scheduled_date, action_id`, and a page queues its
      images *before* it queues the next page, so at equal priority page N+1 sat behind all ~200 of
      page N's downloads: the walk advanced one page per image backlog rather than one page per
      read, and `finalise()` waited out the last page's tail. Sunk below the default, the whole walk
      is claimed first and the downloads drain after it. Priority orders *claiming*, not execution,
      so a page action can still wait out a batch of images already in flight — one batch, not the
      backlog. Every other job's chunks outrank images for the same reason: a stock sync due every
      fifteen minutes must not queue behind a first run's four thousand downloads.
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
  - **It is narrower than the catalogue, and as of 0.13.0 the difference is a non-event.** Measured
    against the live account: the catalogue lists **4386** articles and the stock entity returns
    **2945**. A product whose article the stock feed does not carry keeps the level it already had
    and stays published; `StockSync` writes levels and nothing else.
  - **This sync used to draft that difference, and no longer does.** `StockSync::finalise()` drafted
    anything ours the feed had not stamped, exactly as `ProductSync::finalise()` drafts an article
    the catalogue drops — 1082 products on the development site's first run, a fifth of the
    published catalogue going dark for articles Kontor still sells. Absence from the stock feed is a
    routine gap, not a verdict; whether an article belongs in the shop is the catalogue's answer to
    give, and the product sync is the one pass that acts on it. Gone with it: `META_STOCK_AT`,
    `META_STOCK_DRAFTED`, `restore_if_stock_drafted()`, `FINALISE_BATCH` and
    `Scheduler::ACTION_SYNC_STOCK_FINALISE`. **The last chunk now calls `complete()` directly** —
    there is no finalising action left to chain to, and a chunk that reached the end without closing
    the run would strand the job as `running` for `Status::STALE_AFTER`. The hook name lives on as
    `Scheduler::ACTION_LEGACY_STOCK_FINALISE` so an action queued before the upgrade still closes
    its run; see the chain-ownership rule below for why an upgrade cannot be trusted to sweep it.
  - **`apply()` never touches a product's status**, in either direction, unless the drafting below
    is switched on. It does not republish either, so a product a person or the product sync drafted
    quietly keeps its level updated and stays hidden.
  - **A shop can ask for the drafting back, with `Settings::DRAFT_MISSING_STOCK`
    (`draft_missing_stock`), off by default.** The paragraph above is what a shop does unless
    somebody decides otherwise; this is for the account where the two feeds agree, or whose ERP is
    kept so that no stock record really does mean no longer sellable. Everything the pass needs is
    switched on with it, and nothing when it is off — in particular the run stamp, because writing
    one per product on a feed of three thousand articles every fifteen minutes is not a cost to
    carry for a pass that is not going to run. Nothing is lost by that: `apply()` stamps the whole
    feed before `finalise()` looks at anything, so the first pass after the setting is turned on
    already sees a complete set of stamps and only drafts what the feed genuinely left out.
    - **The marker is `_wksync_stock_drafted`, not the key this sync used before 0.13.0.** That one
      is `ProductSync::META_LEGACY_STOCK_DRAFTED` and now means the *opposite* — a draft nothing
      will ever clear, which the product sync therefore treats as reason enough to publish. Writing
      it here would have every product this pass drafts republished by the next product sync.
    - **The new marker blocks `restore_if_sync_drafted()`, exactly as the old one used to.** A shop
      that switched the drafting on is saying an article with no stock record does not belong in the
      shop, and the catalogue listing it again says nothing about whether Kontor holds stock of it.
      Each sync clears only its own marker, so a product missing from both feeds stays drafted until
      both have seen the article again.
    - **`Scheduler::ACTION_SYNC_STOCK_DRAFT` is its own hook**, never
      `ACTION_LEGACY_STOCK_FINALISE`. That one belongs to the removed pass and answers by closing
      the run and drafting nothing, which is right for an action queued by a version that no longer
      exists here and exactly wrong for one this version queues on purpose.
    - **Turning the setting off releases what the pass drafted**, in `StockSync::finalise()`, which
      is why the pass is entered on the setting's *current* value rather than the one the action was
      queued under. Nothing else could: those products are absent from the stock feed by definition,
      so `apply()` never reaches them and clearing the setting would otherwise leave them hidden for
      good. Both halves are batched at `FINALISE_BATCH` and chained, and each chains again only when
      it actually changed something, so a batch of products `wc_get_product()` cannot load stops
      rather than repeating for ever.
    - **A run with the setting off still asks whether there is anything to release**, once, with a
      single-row indexed lookup in `has_stock_drafts()`. On the overwhelmingly common path there is
      none and the last chunk closes the run directly, which is 0.13.0's behaviour.
    - **The summary only mentions drafting when there was some**, so a shop that leaves the setting
      alone reads the same sentence it read before the setting existed.
  - **`ProductSync::META_LEGACY_STOCK_DRAFTED` is `_wksync_drafted_by_stock` kept alive to undo it.**
    Nothing writes it. Every product still carrying it is hidden for a reason nothing would ever
    clear again, so `restore_if_sync_drafted()` treats it as spent: on its own it is enough to
    republish, and it is cleared alongside this sync's own markers the next time the catalogue lists
    the article. It is *not* a blocker any more — that check is what used to hold a product drafted
    until both feeds agreed, and `StockSync::META_STOCK_DRAFTED` is what does it now, for the shops
    that ask. Drop the constant when no shop upgrading from before 0.13.0 is left.
  - **An empty response finishes the run early.** `start()` returns before queuing anything on no
    rows. It mattered far more when finalise existed — it was the guard against an authentication
    failure drafting the whole catalogue — but it still avoids a pointless chain.
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
- **`overwrite_all` stays `false` on every automatic path, and that is the idempotency mechanism.**
  Kontor deduplicates on `orderNumber`: re-sending an order already there comes back as `fehler` /
  *Dublette* rather than creating a second one. `OrderSync` therefore treats a Dublette as
  **success** — the order is in the ERP, which is the goal — instead of retrying it forever. Kontor
  does not return the existing `Auftrnr` in that reply; the delivery sync backfills it.
  - **The consequence is that nothing automatic can ever *update* an order in the ERP.** An order
    edited after it was sent is answered with a Dublette and the edit never lands. WooCommerce locks
    line items once an order leaves `pending`/`on-hold`, so the edits that realistically happen are
    the address, the phone and the customer note — which is precisely the set a warehouse needs.
  - **`Settings::FORCE_PUSH_CONFIRMATION` and `OrderSync::force_push()` are the deliberate way out**,
    and the only place in the plugin that sends `overwrite_all: true`. Its behaviour was **never
    established against the live account** — everything else known about this API here was found by
    probing, and this was not — so the screen says so, the single-order path exists to be tried
    first, and the reply is printed verbatim rather than summarised. If it turns out Kontor
    overwrites more than the batch, that display is the only thing that would show it.
  - **It runs in the request that asked for it**, with no Action Scheduler behind it, which is the
    one place this plugin deliberately breaks its own rule. The rule exists so nobody waits on
    Kontor; here the answer is the entire point, and a queued job would put it in a log instead of
    in front of the person who pressed the button. `OrderSync::FORCE_LIMIT` (100) is what keeps that
    honest — four round trips at `Client::REQUEST_TIMEOUT` — and the batch is chunked at
    `BATCH_SIZE` so the request shape is one Kontor has already accepted.
  - **It never touches `Status`.** A run belongs to a scheduled job; marking one here would collide
    with a real sweep, and a request cut short would strand the job as `running` for the whole of
    `Status::STALE_AFTER`. `test_force_push_leaves_the_job_status_alone` is the guard.
  - **Under overwrite, a Dublette is a failure rather than a success**, and
    `interpret_force_rows()` is separate from `interpret_rows()` for that reason as much as for the
    Status one. On an ordinary push it means the order is in the ERP, which is the goal; here the
    goal was to replace what the ERP holds, so the same row means the edit did not land.
  - **The bulk scope is orders already sent, and requires typing `OVERWRITE`** — checked on the
    server, because a JavaScript confirm is a courtesy to a browser in exactly the way a `min`
    attribute is, and this request can be made without ever loading the page. The word is
    **deliberately untranslated**: a confirmation is only a confirmation if what has to be typed is
    exact. Orders never sent are left out — the ordinary sweep is already sending those, and
    overwriting them would replace nothing.
  - **`Client::SHAPE_ENVELOPE` is what keeps the reply.** `SHAPE_ROWS` returns `data` and `meta` and
    discards the envelope, which is right for every other caller and useless here. It also attaches
    the decoded body to the `WP_Error` on a refusal, which is where Kontor says most. Only response
    bodies are ever shown — the key travels in a request header, and headers are never put in there.
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

**One job drafts products, and it is the product sync.** It ends in a finalising pass that
unpublishes what the catalogue no longer carries, chained across actions rather than tacked onto the
last page — the walk is unbounded, which is the one thing that would put a chunk action over a slow
host's execution limit. The stock sync used to do the same for its own feed and no longer does; see
the `stock` entity above for why, and for the marker left behind to undo it.

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
  **All five jobs chain**, including the order sweep: `OrderSync::start()` fixes the list of pending
  orders, caches the IDs in a transient and queues `ACTION_SYNC_ORDERS_BATCH`, and
  `send_batch()` sends one batch per action. It used to send every batch inside the action that
  started it — up to `SWEEP_LIMIT` orders and several round trips to Kontor in one request, the one
  job a slow link could see cut short, which for an upload means the rest silently wait for the next
  sweep.
  - **The list is fixed at the start rather than re-queried per batch.** `pending_orders()` asks for
    orders that have never been sent, and a rejected order still has not been sent, so re-querying
    would hand the same failures back for ever instead of finishing.
  - **Each order is re-read when its batch runs**, not carried across actions as an object. The
    checkout hook can push an order, or someone can cancel it, between the sweep starting and the
    batch running; an order already stamped `_wksync_pushed_at` is skipped rather than sent twice.
- **Runs report where they have got to**, which is what the settings screen draws a bar from.
  `Status` carries `total` and `processed` beside the counts — the counts say what happened to each
  record, these say how far along the run is. Every chunked job already knew its total and threw it
  away: the catalogue's `totalCount`, the length of the stock, delivery, invoice or order payload.
  - **A total of zero means "not known", and shows an indeterminate bar rather than 0%.** Zero
    percent is a claim — that the run started and achieved nothing — and until the first page comes
    back that claim is not true yet.
  - **`Status::percentage()` clamps to 0–100.** `totalCount` is what the API promised at page one
    about a catalogue that can change underneath the walk, and `import_page()` deliberately advances
    by the rows actually returned, so the two genuinely can disagree.
  - **The product walk measures itself from the first page only.** Kontor repeats `totalCount` on
    every page; taking it each time would move the goalposts under a bar that is already half full.
  - **Image downloads are counted, not measured.** They outlive the run that queued them, so folding
    them into the product bar would hold it short of the end for something that is not holding
    anything up. `Scheduler::pending_count()` asks Action Scheduler for a **count** rather than
    fetching the IDs — a first run queues one action per article, and reading four thousand rows to
    render a sentence would be worse than not showing it.
  - **The screen polls `wksync_job_progress`** every 5 seconds, and only while something is running
    or images are still queued — on a normally idle site it never starts. The whole answer is one
    non-autoloaded option read plus that count query.
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
  sweep. `ACTION_SYNC_ORDERS_BATCH` *is* listed, because that one is part of the sweep and a batch
  that dies has to close the run behind it. A failure carrying a superseded `run`, or arriving after
  the job reported its own reason, is ignored.
  - **Deleting a chained action is breaking the chain, and an upgrade does not sweep the queue for
    you.** WordPress deactivates a plugin **silently** before replacing it — core's own comment on
    `Plugin_Upgrader::deactivate_plugin_before_upgrade()` reads *"Prevent deactivation hooks from
    running"* — and under cron, where automatic updates happen, it does not deactivate at all. So
    `Deactivator::deactivate()` does **not** run on an update, and an action queued by the outgoing
    version is still there when the incoming one loads. Unanswered it fires into nothing and strands
    its run for the full six hours. `Scheduler::ACTION_LEGACY_STOCK_FINALISE` is what that costs:
    the removed stock finalising pass, still listened for, answered by
    `StockSync::close_legacy_run()`, which closes the run and drafts nothing. Removing a chained
    action means keeping its hook until no shop can still be upgrading across the change.
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

## REST API

The outward-facing half of the sync layer: **start the product or stock sync, and report on a run.**
`Rest\Jobs` is all of it — `GET /jobs`, `GET /jobs/{job}`, `POST /jobs/{job}/run` — and
`docs/rest-api.md` is the client-facing reference. The `msrp` and quantity fields on `/wc/v3/products`
are a separate thing and stay documented where they are, beside the Kontor fields they publish.

- **The namespace is `wc-wksync/v1`, and the `wc-` is load-bearing, not decoration.**
  `WC_REST_Authentication` authenticates a consumer key on `determine_current_user`, but only for a
  request `is_request_to_rest_api()` recognises — and that method decides by looking for `wc/` or
  `wc-` in the request URI. A namespace of `wksync/v1` registers and routes perfectly and then
  answers 401 to every client holding a key, because the credentials are never read at all. The
  prefix is WooCommerce's documented opening for exactly this; core's comment beside it reads *"Allow
  third party plugins use our authentication methods"*.
  `RestJobsTest::test_the_namespace_keeps_woocommerces_authentication_prefix` is the guard, because
  renaming it would break nothing else in the suite and every integration in the field. WooCommerce
  also gates a key **by method**, so the GETs need a Read key and the POST a Write one, refused
  before any callback here runs.
- **Only `products` and `stock` are exposed**, as a path segment with an `enum` of
  `Jobs::EXPOSED`, so WordPress answers `orders` — a real job with a real action behind it — or a
  typo with `rest_invalid_param` before `Scheduler::trigger()` is reached, and there is one list of
  served jobs rather than two to keep in step. The other three are not refused on principle: they
  need a shop, they push financial records, and the delivery sync completes orders, which emails
  customers.
  - **Trap: do not add a `sanitize_callback` to that argument.** Core injects no default
    `validate_callback` for a route argument, and the enum would otherwise be enforced only by the
    fallback `sanitize_params()` reaches for — which a declared `sanitize_callback` silently
    replaces, switching the validation off. `validate_callback` is therefore declared explicitly.
- **The four refusal codes are the admin path's codes, unchanged**, and the HTTP status is added by
  re-wrapping in `Jobs::refusal()` — never by putting a `status` on `Scheduler::trigger()`'s
  `WP_Error`s, which also travel into the settings screen's redirect as bare codes and have no notion
  of HTTP. `wksync_already_running` is **409**; the three "this shop is not ready" refusals are
  **503**, because there is nothing wrong with the request to correct and a setting nobody filled in
  is not a bug; anything unrecognised stays 500 rather than being dressed up as one of the four.
- **202 is what a trigger can honestly promise, and the docs say what it cannot.** `trigger()` runs
  the local gates only and makes no request to Kontor, so a 202 is entirely compatible with
  credentials that do not authenticate. That answer arrives later, in the job's own status.
- **The response cannot carry a handle for the run it just asked for**, because nothing has minted
  one: `Status::start()` does that from inside the action. So the payload publishes `run_id` — the
  stored `started`, as an opaque identifier — and a caller watches for it to change. `previous_run_id`
  is read *before* the enqueue and the embedded `progress` *after*, so the comparison still holds when
  the queue is quick enough to have started the run before the response was assembled.
  - **The REST layer must not call `Status::start()` itself** to mint one. It would mark the job
    running before any work existed, the action's own `Status::start()` would mint a second identifier
    that every chained action then failed `is_current_run()` against, and a trigger whose action never
    ran would leave the job running — and `trigger()` refusing — for the whole of `STALE_AFTER`.
  - **`Status::fail()` keeps the old `started`**, so a preflight refusal inside the action never
    changes `run_id`. Watching the identifier alone would therefore wait for ever on the likeliest
    failure of all. The contract in `docs/rest-api.md` names that case explicitly, and the one below
    it: `abandon_run()` returns early unless the stored state is `running`, so an action that fatals
    before `Status::start()` records nothing at all.
- **`Scheduler::queued_count()` exists because two obvious queries both give a useless answer.**
  `pending_count()` counts only actions still waiting, so it reads zero for the whole time the action
  is executing — which is exactly where the preflight's round trip to Kontor happens — and a caller
  would take that zero for "answered, nothing changed". And counting everything pending is worse: a
  job with an interval keeps a recurring action in the queue permanently, days out, so `queued` would
  read true on every scheduled shop for ever. Measured on the development site, which is how this was
  found: both jobs reported `queued: true` with nothing whatever happening. So it counts pending
  **and** in-progress, **due by now** — which still catches an overdue recurring action, correctly,
  since that is about to run and will stamp a run of its own. An async action is stored with its save
  time as its scheduled date, so it is always due. `pending_count()` keeps the image sentence, where
  the file currently downloading is not one left to do.
- **The image queue is on the collection, never inside a job object.** Downloads outlive the run that
  queued them, so a run object mentioning them would make a finished product sync look unfinished —
  and `image_queue: 0` on the stock job would be a field that can only ever be zero, while putting it
  on products alone would make the object's shape depend on which job was asked for. There are no
  conditional keys in the payload.
- **Raw numbers, not sentences.** `Admin\Settings::describe_status()` and its three siblings stay
  protected. Publishing them would owe every machine a stable *translated* wording and give a client
  nothing to key on but text. For the same reason `label` and `message` are documented as display
  only, and every refusal is keyed on `code`.
- **`percent` is `int|null` and `null` is not `0`**, `total: 0` means "not known", and `counts` is
  cast to an object — `Status`' default is `array()`, which would encode as `[]` and hand an idle job
  a list where every other response has an object. All three are pinned by tests, including on the
  encoded JSON.
- **No nonce on the POST, and that is not a hole in a state change.** Core authenticates a REST
  request before the callback — a signed WooCommerce key, or `X-WP-Nonce` for a cookie client, which
  WordPress verifies itself — and the authorisation half is the `permission_callback`, gated on
  `Settings::CAPABILITY`. What would be wrong is `__return_true`.
- **A poll is one non-autoloaded option read plus a couple of counting queries.** Call
  `Scheduler::next_run()` once per job into a variable; `Admin\Settings::handle_job_progress()` calls
  it twice per job, which is a small defect there and not a pattern to copy.
- **A wrong method is answered by WordPress with 404 `rest_no_route`, not 405.** Worth knowing before
  reaching for a 405 assertion.
- `Rest\Jobs` holds `REST_NAMESPACE` itself rather than there being a registrar class with one line
  in it. **When a second controller appears, move the constant to a `Rest\RestApi`** that registers
  both, or two classes end up importing it off one of their own siblings.

## Working agreement

- Run `composer lint` before calling any change done. The hook covers files you edit; the full run
  catches everything else.
- Add or update a test with each behaviour change — `composer test`.
- Update `docs/rest-api.md` whenever a route, a field or an error code changes. It is what somebody
  integrating with the shop reads, and it cannot be checked by a test.
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
removed from the build so nobody is invited to run Composer inside a live plugins directory. `docs/`
is left out by construction — the script whitelists the three directories above — because it is
written for whoever integrates with the REST API, not for the site that runs the plugin.

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
