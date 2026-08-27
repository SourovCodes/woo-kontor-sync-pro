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

**The suite refuses to start if the orders table is not empty.** Every test builds its orders inside
the transaction `WP_UnitTestCase` rolls back, so a row surviving between runs means a run died
before its rollback — and the damage lands somewhere else entirely, because anything asking
`wc_get_orders()` about the whole shop counts the strays too. Three `JobProgressTest` assertions
failed exactly that way, in a file with nothing wrong with it, for as long as six orphaned orders
sat there. `wksync_refuse_leftover_orders()` in `tests/bootstrap.php` is a hard stop rather than a
cleanup: a crashed run is worth knowing about, and silently deleting the rows would hide both the
crash and the fact that the run before it was never trustworthy. The installer will not clear them —
it creates the database only when it is absent — so the way out is to drop `woo_kontor_tests` and
provision it again.

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
- **`Ek` is ignored on the shop types that do not price from it**, and `Categories` on a shop that
  does not import them; neither is part of the change hash there. The hash covers the fields in
  `ProductSync::mapped_fields()`; hashing the whole row would rewrite every product whenever
  purchase prices moved. `Ek` **joins** that list on a wholesale shop, because it is the price
  there — left out, a price rise that moved nothing else would never reach the shop. `Categories`
  joins it on a shop with `Settings::categories_enabled()`, because it is the filing this sync
  writes; adding or removing the key changes the hashed JSON for every article, which is what makes
  turning the setting on or off rewrite the whole catalogue once. `Herstellerid`
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
- **Three things hold an article out of the shop — `Ws_aktiv`, the image requirement and the
  category requirement — and all three answer the same way: import it and leave it a draft.**
  `ProductSync::withheld_reason()` is the one place that decides which, if any, applies, and
  everything below follows from it. Kontor's own verdict is asked first; the shop's two settings
  follow in the order they were added, which is what keeps an existing shop reading exactly what it
  read before — an article with neither a picture nor a category is still reported as having no
  picture.
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
    - **It is counted as `unmanaged`, never as the reason itself.** Both outcomes used to be the
      same string, so the summary's "Held N back as drafts" counted products that were still
      published and on sale — a drafting reported that had not happened, on the one case where the
      shop and the ERP openly disagree. It has its own sentence for the same reason.
    - **It carries `ProductSync::META_UNMANAGED` (`_wksync_unmanaged`), and that marker is the only
      record it leaves.** Nothing drafts it, so it holds none of the markers `Admin\HeldProducts`
      was built on, and nothing stamps it, so no later run mentions it again. Before this there was
      nowhere in wp-admin to find out that the shop was publicly selling an article Kontor had
      switched off. `HeldProducts::UNMANAGED` turns it into a view, kept in `unmanaged()` rather
      than `reasons()` because everything built on that map is about products taken *out* of the
      shop — `total()` counts drafts and the settings screen calls them drafts, and a published
      product belongs in neither sentence.
    - **Cleared the moment the product is adopted**, which is what happens as soon as Kontor stops
      holding the article back. A product whose article leaves the catalogue altogether keeps the
      marker, because nothing looks at that article again; the reason it names was true when it was
      written, and the trash sweep is what removes such a product where a shop has asked for it.
  - **A status this sync does not own is left where it was put**, `private`, `pending` or anything
    another plugin registered. The article's data is still written over it; only the status and the
    marker are withheld, because marking it would hand a later run the right to publish something
    somebody deliberately took out of the shop.
  - **Each reason is counted and named in the run summary**, never folded into `created` or
    `updated`. "3 created … Held 827 back as drafts, switched off for the webshop in Kontor" is the
    sentence that tells a shop manager where a fifth of the catalogue went.
  - **`Admin\HeldProducts` is where the run summary's number becomes a list of products.** Every
    marker is `_wksync_`-prefixed and therefore protected, so before this a shop manager reading
    "Held 827 back as drafts" had no way anywhere in wp-admin to find out which 827, or why any one
    product was a draft. It adds a view per reason to the products list
    (`views_edit-product` → `edit.php?post_type=product&wksync_held=inactive`) and names the reason
    beside the product with `display_post_states`. Read-only, like `ProductFields` and `OrderPanel`:
    the markers are rewritten by background jobs, so the way back into the shop is the ERP.
    - **A view only appears for a reason currently holding something**, so a shop where nothing is
      held back sees no new links rather than a row of zeroes. The `any` value gathers all of them,
      which is what the settings screen links to.
    - **`none` is the inverse — the drafts no reason of ours accounts for**, which are the ones a
      person made. Core's Drafts view stops being useful the moment eight hundred of the ERP's are
      sitting in it, and this is the whole of what a **custom post status** would have bought.
      Registering one was considered and rejected: `get_post_statuses()` is hardcoded with no
      filter, so the status could never appear in `/wc/v3/products`' `status` enum, and the Publish
      box's dropdown is hardcoded the same way — a product in a custom status renders that select
      with nothing selected, so the first save posts `pending`, which `hold_back()` then refuses to
      touch, stranding the product for good. WooCommerce registers custom statuses for orders,
      where it owns the whole screen, and none for products.
    - **The inverse is offered only where something is actually held back**, and counted only then,
      so a shop with nothing held back neither sees a link that duplicates core's Drafts view nor
      pays for the query behind it. It carries `post_status=draft` in the URL rather than forcing a
      status onto the query, so core's own status handling stays in charge.
    - **`clauses()` is shared by the filter and the counts**, so a view cannot promise a number the
      list it opens then disagrees with. A single reason is a flat clause rather than a group of
      one, which is every case but `any` and `none`.
    - **`any` is one clause over every key — `compare_key` `IN` — never a group of `EXISTS` clauses
      joined by `OR`.** WP_Meta_Query gives each clause in an OR group its own `INNER JOIN` on the
      meta table, so five of them multiply out: every combination of five meta rows on the same
      product, before the `WHERE` picks any of them. It shipped that way in 0.22.0 and below and the
      view **never returned at all** on the development site's 4386 articles — 829 rows in 5ms once
      it was a single indexed join. Nothing about the rows coming back can tell the two apart, which
      is why the correctness test passed the whole time and why the guard
      (`test_every_reason_at_once_joins_the_meta_table_once`) counts joins in the SQL instead.
    - **The same trap caught `StockSync::draft_batch()`, milder, and it is now hand-written SQL.**
      Two unconstrained joins rather than five — ~777 intermediate rows per product instead of 17
      million — so it returned, at 0.57s a batch on 4398 products against 0.027s now. It needs an OR
      ("no stamp at all, or a stamp older than this run") and cannot avoid one, and WP_Meta_Query
      drops `meta_key` from every non-`NOT EXISTS` `ON` clause the moment an OR appears anywhere in
      the query, so `get_posts()` could not express it cheaply at all. **The rule to carry: a join
      whose `ON` does not name `meta_key` matches every meta row the product has, and N of them cost
      (rows per product)^N.** Join count alone is not the signal — `none` has five and is fine.
    - **`none` cannot be written the same way and is deliberately not.** `compare_key` with
      `NOT EXISTS` builds a `LEFT JOIN` with no `ON` clause at all, which the database refuses. The
      group it keeps costs nothing like `any` did: a `NOT EXISTS` clause is a `LEFT JOIN` tested for
      `NULL`, matching at most one row per key per product rather than multiplying.
    - **Counted on the marker alone, never joined to the post status.** A product somebody published
      by hand still carries its marker and the next sync will draft it again; leaving it out of the
      count would hide the one case worth seeing.
    - **The meta query is appended, not assigned.** WooCommerce's own stock filter puts one on the
      same query, and replacing it would silently widen whatever the shop manager had narrowed.
    - **Core marks "All" current by the absence of its own filters, and ours is not one of them**, so
      the class takes that marking off the other views when a reason is being looked at. Otherwise
      two views are highlighted at once.
    - The slugs in the URL are `withheld_reason()`'s own vocabulary — `inactive`, `no_image`,
      `no_category` — rather than the meta keys, which are this plugin's storage and not a published
      name. All six markers are listed, including `META_LEGACY_STOCK_DRAFTED`: a product still
      carrying it is hidden right now, whatever the marker's future is.
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
- **Kontor can own the shop's product categories**, via `Settings::SYNC_CATEGORIES`
  (`sync_categories`), off by default. `Sync\Categories` builds the tree and files each product from
  the article's `Categories` field; `Sync\CategoryPush` is the separate, manual way back.
  - **`Settings::categories_enabled()` asks two questions with one answer** — the setting is on
    *and* a well-formed `shop_id` is stored. The tree is per-shop and the entity returns nothing
    without one, so acting on half the answer is never right. The change hash, the assignments and
    the withheld reason all read this one method, so the three cannot disagree.
  - **The shop picker therefore moved out of the Orders section into Connection**, rendered when
    either orders or categories want it. That keeps 0.22.0's promise exactly — a shop that only
    imports the catalogue is still never asked to choose one — without the same field existing twice
    and being able to disagree with itself.
  - **The tree is read once per page action and a failure stops the run.** This is the dangerous
    edge in the whole feature: an unreadable tree reads as "no article has a category", so with
    `require_category` on it would draft the entire shop. **An empty reply is treated as a failure
    too**, and that is not defensive padding — zero rows is exactly what the entity returns when the
    request carries no shop. Same trap `Preflight` exists to keep the catalogue walk out of.
  - **Reconciliation is driven by the tree fetch, not by the article rows**, so a category renamed
    or moved in Kontor is followed even on a run where every article is skipped as unchanged.
  - **Terms are matched on `Katid` alone, never on a name.** But *creating* one has to deal with
    WordPress refusing a second term of the same name under the same parent, which is a real case:
    a term with **no** Katid is adopted and stamped — narrower than `Brands`' adoption, since the
    parent must match too — while one already carrying a *different* Katid is left alone and a
    distinct term created beside it with an explicit slug. Stealing it would collapse two of
    Kontor's categories into one and re-stamp it on every run.
    - **Adoption is what makes this usable on a shop that already has categories, and the margin is
      not close.** Measured on the ToysOnline site, which has 103 product categories built from the
      same source: **100 of Kontor's 101 categories already existed there under the same name *and*
      the same parent**. Only "Ohne Kategorie" was new. Without adoption the first run would have
      created a hundred duplicates — "kuscheltiere-2" and the like — beside the categories the shop
      was already selling from. The live run created exactly one term, edited none, and changed no
      slug, name, parent or product count on any of the hundred it adopted.
  - **WordPress puts every term name through `sanitize_text_field()` and `_wp_specialchars()`** on
    `pre_term_name`, so "Rabatt 20%ab Lager" is stored as "Rabatt 20 Lager" and an `&` comes back
    `&amp;` — whether the name came from Kontor or was typed into wp-admin. Nothing can be done
    about it and nothing should be; what matters is that `Categories::follow()` compares against
    `sanitize_term_field( 'name', …, 'db' )` rather than against the raw name. Comparing raw would
    never match and would call `wp_update_term()` on 74 of one live shop's 141 categories **on every
    run, for ever**. `test_an_unchanged_tree_is_not_rewritten_on_the_next_run` is the guard.
  - **Only the terms the tree currently accounts for are managed.** A category a shop manager
    created, and one Kontor has stopped listing, are both left on their products — which also means
    **nothing here ever deletes a term**. A term takes its URL and its manual assignments with it,
    and there is no draft state for a taxonomy.
  - **`require_category` is the third withheld reason** (`Settings::REQUIRE_CATEGORY`, off by
    default), marker `ProductSync::META_NO_CATEGORY_DRAFTED`. Its own marker for the reason the
    others have theirs: the conditions clear at different moments, and a shared one would let a
    picture arriving republish an article still filed nowhere. Decided on the feed row against the
    tree, never on the product's terms, exactly as `has_image()` is — the terms are written after
    the save. **It is a large number**: 2056 of 4389 on the account measured, which is why the
    settings screen says so before anybody reads the run summary as a fault.
  - **The push is `overwrite_all: true` and nothing else.** Kept in its own class for the reason
    `interpret_force_rows()` is kept apart from `interpret_rows()`: it destroys data in the ERP and
    the routine path must have no way of drifting into it. It runs in the request that asked for it,
    like `OrderSync::force_push()`, never touches `Status`, and is confirmed by typing **`REPLACE`**
    — deliberately not the order screen's `OVERWRITE`, so muscle memory from one cannot fire the
    other. `test_the_confirmation_word_differs_from_the_order_one` pins that.
    - **A category already carrying a `Katid` is sent back under it**, which is the whole of what
      keeps its product assignments attached through a replace. One created here is minted
      `wc-{term_id}` — prefixed, because three of the four shops sampled use bare integers as Katids
      and an unprefixed one would eventually collide. Minting is **deterministic**, so a retry sends
      the same tree and the stamp written afterwards is bookkeeping rather than correctness.
    - **The whole taxonomy goes in one request and is never batched**, and a tree over `MAX_TERMS`
      is **refused rather than truncated** — a truncated payload under `overwrite_all` is the
      destructive outcome, not a smaller one.
    - **The preview sends nothing**, and it is not a convenience. It is the only way to see what a
      replace would contain without performing one, which on a live account is the difference
      between checking and finding out.
- **Images are deduplicated on their source URL**, recorded on the attachment as
  `ProductSync::META_IMAGE_SOURCE`. The same photograph is shared across articles often enough that
  downloading per product would multiply the media library. That meta doubles as the marker for
  "this plugin downloaded this file".
  - **A shop's own image is adopted where it is provably the same file.** A shop moving onto this
    sync has usually been filled from the same place: on the account this was built against, **6682
    of the 7242** images Kontor lists for the catalogue were already in the media library under
    exactly the name Kontor gives them, and every one sampled was byte for byte identical to what
    the host serves. Downloading them again would spend hours re-fetching files the shop has, write
    some **2.7GB** of duplicates, and detach the originals into orphans.
    - **Only images already on that product**, matched on filename. A filename is not a globally
      unique thing, and adopting a stranger's file because it happened to be called `image1.jpg`
      would put somebody else's photograph on a product.
    - **A matching name is never enough.** Each candidate is checked with a HEAD and adopted only
      when the host reports exactly the length the file on disk has. A different length, a non-200
      or a host that will not answer all fall through to a download, which is the safe outcome every
      time.
    - **The HEADs run concurrently**, at the download width. One costs about **660ms** against that
      host — only three times less than fetching the file — so serially, verifying a catalogue's
      worth would take longer than downloading it and the exercise would be pointless. Measured
      live: 15 candidates verified and adopted in 2.1s.
    - **Length, not content.** Fetching the body to compare it *is* the download, so it would save
      nothing. Identical name plus identical byte count, on this very product, is as far as this can
      honestly go.
    - **The match is case-insensitive, the URL is not.** The shop's copy need not have kept the
      feed's capitalisation; the host is nginx and answers 404 to the wrong case, so the URL is
      always built from the feed's spelling.
    - **Adopted images are stamped `META_IMAGE_SOURCE`, which makes them ours** — shared with other
      articles rather than downloaded again, and swept by `discard_unused_images()` once no product
      uses them. That is exactly the treatment the identical file would have had if this had
      downloaded it.
    - **It only ever helps the first run.** After that every image is stamped and
      `attachment_for_source()` answers first. `woo_kontor_sync_adopt_existing_images` turns it off.
  - **The lookup is hand-written SQL, and the reason is measurement.** Through `get_posts()` it took
    **26.4ms** against the development site's library and **0.44ms** written out — sixty times, all
    of it `WP_Query`'s own setup rather than the database, which answers from the `meta_key` index
    either way. One URL is resolved per image, so on that catalogue's 10665 images the wrapper alone
    accounted for some four and a half minutes of a job otherwise bound by how fast somebody else's
    image host replies. Cross-checked against the old query on 50 real URLs: identical answers.
- **Every image is given alt text, because Kontor's carry none.** `media_handle_sideload()` writes
  `_wp_attachment_image_alt` only when the file itself supplies one, and measured across the 10522
  images downloaded on the development site **not one did** — so before this every product image in
  the shop reached a customer with an empty alt attribute, and every search engine with nothing to
  read. `ProductSync::describe()` writes the product's name, and `attach()` passes it as the
  attachment title too, in place of a filename like `abel-AB12_001`.
  - **`ProductSync::META_ALT` is core's key, deliberately unprefixed.** The alt attribute is read by
    themes, blocks, the media library and every SEO plugin there is; a key of this plugin's own
    would be invisible to all of them.
  - **Never overwritten.** An alt already there was either written for the article that first
    fetched a shared photograph or typed by a person, and both know more about the picture than this
    does. That is also what makes it safe on the reuse path, where `resolve_images()` describes an
    attachment it did not download.
  - **A gallery of five photographs gets the same sentence five times**, and numbering them was
    considered and rejected: images are deduplicated on their source URL, so the file that is second
    here is first somewhere else and a number would be wrong on one of the two. Nothing in the feed
    distinguishes one photograph of an article from another. A repeated description is a far smaller
    failure than none.
  - **Not `sanitize_text_field()`**, which eats percent-encoded octets — a product called
    "Rabatt 20%ab Lager" would lose three characters out of its description.
  - Images already in the library keep their empty alt until their product's image set changes,
    since nothing re-examines an article whose hash still matches.
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
- **The walk ends on an empty page, and on nothing else.** Up to 0.28.0 it ended when `skip` reached
  the `totalCount` the first page reported, which made a number *describing* the catalogue the
  authority on where the catalogue stopped. An absent `totalCount` reads as `0`, so one missing
  field would have ended the walk after a single page and handed `finalise()` the other 4186
  articles as ones Kontor had dropped — the whole shop dark, from a field nobody promised. The count
  is still read on the first page for the progress bar, where being wrong costs nothing. A short
  page is not the end either, for the reason in the cap above: `skip` advances by the rows actually
  returned. The cost of all this is one extra request per run, to be told there is nothing left.
  - **`ProductSync::MAX_PAGES` (1000) is what keeps that terminating**, since a pager that ignored
    `skip` would otherwise walk for ever. Reaching it **fails the run rather than finalising it** —
    a walk that did not finish has no business deciding which articles Kontor has stopped listing.
- **A page that fails transiently is waited out, not the end of the run.** `retry_page()` queues the
  same page again through `Scheduler::chain_later()` on `ProductSync::PAGE_RETRY_DELAYS`
  (5 minutes, 15, then an hour) before giving up. The product sync runs as seldom as once a month,
  so a blip lasting seconds used to cost weeks of a stale catalogue with one line on a settings
  screen to say so. **Only a failure the Client called transient is retried** — it has already spent
  its own three attempts and its own backoff on those, while a refusal it called final is a bad key
  or a bad request, and asking again in five minutes is a slower way of writing the same message an
  hour later. The run stays `running` across the wait, which is what stops a schedule starting a
  second walk over the top of it; the delays sum to well inside `Status::STALE_AFTER`.
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
  and, as of 0.27.0, which **category tree** is imported, so all of those need it set before they
  can run.
  - **A shop that imports nothing but the catalogue never has to choose one**, and as of 0.22.0 is
    never asked to. `Settings::SYNC_ORDERS` (`sync_orders`) is a master switch over the whole order
    side: off, the push, the delivery import and the invoice import are refused by `Preflight`,
    their recurring actions are cancelled, no order is queued at checkout, `Admin\OrderPanel` and
    the force-push section do not render. It is the one setting here that **defaults to on**,
    because off is the value that takes a capability away — an update must leave a shop doing what
    it did yesterday.
    - **The shop picker itself belongs to neither switch now.** It lives in the Connection section
      and is shown when orders *or* categories want it, because two unrelated features need the
      same field and rendering it twice would let one copy disagree with the other. Hidden rather
      than left out, so it goes on submitting and a stored shop survives a save made with the row
      closed — and a shop that wants neither still never sees it.
  - **Absent reads as on**, in `Settings::orders_enabled()`, not as off. `get_settings()` fills the
    key in from the defaults, so this only arises for a settings array handed in from elsewhere —
    and the two ways of being wrong are not equal, exactly as with `Ws_aktiv`. Reading "on" as
    "off" silently stops a working shop sending orders and nobody notices until the warehouse asks;
    the reverse queues an upload the next gate refuses and logs.
  - **The intervals are ignored, never cleared.** `Scheduler::sync_schedules()` reads an order-side
    job as `INTERVAL_NEVER` while the switch is off, so turning it back on restores all three
    schedules as they were rather than asking for them again.
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
    honest — four round trips at `Client::REQUEST_TIMEOUT`, which holds only because the
    push is made with `Client::SINGLE_ATTEMPT`: at the ordinary allowance a batch is three
    timeouts plus six seconds of backoff, so those four round trips would be six minutes of
    a blank screen and then whatever the host's execution limit does about it. Retrying is
    the wrong favour to do somebody who is watching and can press it again. The batch is
    chunked at `BATCH_SIZE` so the request shape is one Kontor has already accepted.
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
- **`taxRate` is the opposite: read off the order, never derived from the line.** It is the one
  figure here that is not money, and the arithmetic that is right for the prices is wrong for it.
  A tax amount is stored rounded to two decimals, so `tax ÷ total × 100` magnifies a rounding of
  half a rappen into a tenth of a percentage point — at 8.1%, a line of 4.15 came back as 8.19, one
  of 9.90 as 8.08 and one of 0.95 as 8.42. Kontor holds one rate per article and rejected the
  variation, correctly. **Shipped that way up to 0.22.2**, which is why an order sent before 0.22.3
  carries whatever the division produced; nothing automatic can correct it, because Kontor answers a
  resend with a Dublette.
  - **`OrderSync::order_tax_rates()` maps the order's own tax lines**, `rate_id` → `rate_percent`.
    WooCommerce freezes that percentage onto the order at checkout, so it is the rate the order was
    *placed* under — which is what the derivation was reaching for, and gets without the drift. A
    rate edited or deleted since cannot move what an old order reports.
    `WC_Tax::_get_tax_rate()` fills in only for an order predating `rate_percent` (WooCommerce 3.7).
  - **A line's rate comes from the rate IDs in its tax data, not from its amounts**, so a line
    discounted to nothing still reports the rate it was sold under instead of looking tax exempt.
    Several rates on one line are added, this field holding a single figure; exact unless they
    compound.
  - **`derived_tax_rate()` survives as the fallback for a line nothing resolves for**, and is
    reached by a genuinely untaxed line, where its zero is the right answer.
- **`provider` and `trackinginfo` arrive as `null`, not absent** — confirmed against live data, where
  all 7 rows for one shop had both null. Anything reading them has to treat null as empty.
- **An order the upsert reply says nothing about is counted as failed.** Nothing is written on it, so
  the next sweep sends it again; leaving it out of the counts instead would report a batch of
  twenty-five as "five sent" and give nobody a reason to look.
- **An order Kontor keeps refusing is set aside, or the sweep starves.** `pending_orders()` asks for
  orders that have never reached Kontor, oldest first, capped at `SWEEP_LIMIT` — and an order
  refused for a reason in its own data never reaches Kontor, so it stayed in that set for ever *and
  sorted to the front of it*. Two hundred of those and no order placed afterwards would ever be sent
  again, silently, with every sweep dutifully re-sending the same rejections.
  `OrderSync::MAX_PUSH_ATTEMPTS` (5) is the allowance, counted in
  `META_PUSH_ATTEMPTS` (`_wksync_push_attempts`).
  - **Only a refusal about *this order* counts** — one Kontor named in a result row, one it said
    nothing about, or one `build_payload()` could not map. **A batch that failed in transit counts
    against nothing**: it says nothing about any order in it, and counting it would set the whole
    queue aside over a week of somebody else's network trouble.
  - **An order this plugin cannot map is now recorded on the order**, which it was not before: such
    an order was refused by our own code on every sweep for ever with no meta anywhere to say why —
    the same starvation as a Kontor rejection and harder to find, because Kontor never saw it.
  - **`META_PUSH_GIVEN_UP` (`_wksync_push_given_up`) is a separate marker rather than a comparison
    against the count**, and the reason is the shape of the query. `pending_orders()` would
    otherwise need "no count at all, or a count below the limit", and `WP_Meta_Query` drops
    `meta_key` from every `ON` clause the moment an OR appears — the trap that made `HeldProducts`'
    `any` view never return. Two `NOT EXISTS` clauses joined by AND each match one row per order.
  - **A successful push clears both**, or the order screen would say an order was set aside while it
    is plainly in Kontor.
  - **The way back is `Admin\OrderActions`' third entry**, which clears the markers and queues the
    single-order push. Without it the marker is a one-way door: those orders are out of
    `pending_orders()` by definition, so no sweep would pick one up however thoroughly it was fixed.
    Clearing the count as well as the marker is deliberate — a fixed order deserves the full
    allowance rather than one attempt before it is set aside again.
  - **`Admin\StuckOrders` is where the count becomes a list.** The marker is `_wksync_`-prefixed and
    therefore protected, so the run summary would otherwise name orders nothing in wp-admin could
    find. Deliberately smaller than `Admin\HeldProducts`: one condition, so one link and no views
    apparatus. The meta query is appended rather than assigned, for the reason `HeldProducts`'
    is — WooCommerce's own screen puts clauses on the same query.
  - **The run summary mentions it only when it happened**, so a shop whose orders all go through
    reads the sentence it has always read. It is the one number there that will not resolve itself.
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
    outside what the web server publishes.** On such a host a PDF can be fetched at its own address
    in the uploads folder and `Download::permitted()` is never reached at all; only the random names
    protect it. A shop that wants the directory closed adds a `deny` rule for it in the server
    configuration — nothing here can do that on its behalf.
    - **The plugin used to probe for that and warn, and as of 0.20.2 it does not.**
      `Storage::is_exposed()` fetched a probe file over HTTP once a day and the settings screen
      printed the `location` block to paste. It cost a loopback request the site made to itself and
      a permanent `protection-probe.pdf` sitting among the invoices, to report a condition whose
      realistic exposure is an address escaping through a server log or a backup rather than
      somebody finding one. And its notice was read as saying the *download links* were insecure,
      which they are not: a link carries the order key and is meant to work for whoever holds it,
      since a guest checkout has nothing else. Saying it once here beats saying it daily on a
      screen. `Storage::protect()` deletes the stale probe, because it only ever added files and
      would otherwise leave ours behind for good.
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
    the invoice reachable. `woo_kontor_sync_attach_invoices` narrows which emails carry them. That
    only ever reached a customer if some later order email happened to be sent, which is what
    `Emails\CustomerInvoice` exists to fix; the attachment filter is what puts the PDF on it.
  - **A shop manager reaches one through `Admin\OrderPanel`**, on the order screen. Before that the
    only rendering was the customer's own order page, so the shop had no way to open an invoice at
    all. `InvoiceSync::label()` and `InvoiceSync::find()` are public statics rather than living
    beside one of the three places that display an invoice, so one wording cannot become three.
  - **Uninstalling deletes neither the files nor the option naming their directory.** They are
    records the shop may be required to keep, and dropping the option would generate a new directory
    on reinstall and strand everything already there.
- **The `categories` entity returns nothing without `filter.shopid`, and a whole tree with one.**
  This was recorded here for a long time as an entity that returns zero rows "filtered or not",
  which was wrong: it had never been sent a shop. Row counts across four shops on the account:
  3, 101, 141 and 554. No paging, like `stock`, `shops` and `manufacturer` — the largest came back
  whole in 2ms. Each row is a `Katid`, a `Katidparent` (empty at the top level) and a `Katname`.
- **An article's `Categories` is a union across every shop on the account** unless the products
  request carries `filter.shopid`. `abel-AB12` comes back with three IDs unfiltered — two of them
  ToysOnline's and one the Shopware shop's — and exactly the two when filtered; **334 distinct
  foreign IDs** appear across the catalogue. The unfiltered value is a *superset*, which is why
  `Sync\Categories` filters client-side against the loaded tree rather than changing a products
  request every existing shop already depends on.
- **`Katid` is an opaque string, and the shapes differ per shop.** Canonical GUIDs on one, 32-char
  hex without hyphens on another, bare integers (`15`, `1435`) on two more. Casting collides them
  exactly as casting `Herstellerid` would collide `084` with `84`.
- **Category names repeat inside one tree**, so a term can never be matched on its name.
  "Soziales Lernen" appears **6 times** on one shop and "Piraten" **4 times** on another, and that
  shop carries two "Waldtiere" under the *same* parent. Matching is on `Katid`, held in term meta as
  `Categories::TERM_META_ID` (`_wksync_katid`) — the same arrangement `Brands` uses.
- **`Katname` can arrive HTML-encoded** — 74 of 141 rows on one shop, e.g. `Emotionen &amp; Empathie`
  — and the tree reaches **five levels deep** on the largest shop, so a parent must be created before
  its children. Measured coverage on ToysOnline: **2333 of 4389 articles carry a category, 2056
  carry none**, 1–8 each, and 9 of the 101 categories are used by nothing.
- **`/upsert` also writes categories**, selected by `name: categories` with `params.shopid`,
  `params.overwrite_all` and a `categories` list of `katid` / `katidparent` / `katname`. **Its
  behaviour has never been established against the live account** — it is the one thing here found
  from a supplied description rather than by probing — and per that description `overwrite_all: true`
  replaces the shop's whole tree, so **a category the payload leaves out loses its product
  assignments in the ERP**. That single sentence is why `Sync\CategoryPush` sends everything in one
  request, refuses rather than truncates above `MAX_TERMS`, and sits behind a typed confirmation.
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

Five jobs are implemented: **product sync** (1–30 days), **stock sync** (15 minutes–1 day), **order
sync** pushing to Kontor, **delivery sync** pulling status and tracking back, and **invoice sync**
(1 hour–1 day) downloading invoice PDFs. Nothing shorter than an hour for invoices: the listing has
no incremental filter, so a tighter schedule only re-reads the same history more often.

**One job drafts products, and it is the product sync.** It ends in a finalising pass that
unpublishes what the catalogue no longer carries, chained across actions rather than tacked onto the
last page — the walk is unbounded, which is the one thing that would put a chunk action over a slow
host's execution limit. The stock sync used to do the same for its own feed and no longer does; see
the `stock` entity above for why, and for the marker left behind to undo it.

**Nothing is drafted on the strength of a catalogue that came back a fraction of its usual size.**
`Preflight` settles whether Kontor answers at all; it cannot settle whether what came back was the
whole catalogue, and `finalise()` cannot tell the two apart — an article missing because Kontor
stopped listing it and an article missing because the feed came back short look identical from
there, and both are drafted. So `catalogue_is_credible()` stops a run that read markedly fewer
articles than the last one to finish, and `ProductSync::CATALOGUE_OPTION`
(`woo_kontor_sync_catalogue`) is where the measurement lives — its own option, because it is
something the plugin measured rather than something anybody chose, and a save of the settings screen
must not rewrite it.

- **It stops the run once, and a second run is what confirms the shrink.** The count that tripped it
  is recorded, and a later run reading about the same number again goes ahead: two runs, two
  requests, two independent readings agreeing. A catalogue Kontor really has cut in half costs one
  run's delay and then proceeds on its own, and a blip costs nothing at all, because the run after
  it sees the full catalogue and never asks the question. A run that shrank *further* is held back
  again rather than believed.
- **Deliberately not a setting and not a confirmation prompt.** Something a shop manager has to
  find, read and switch off would be switched off during the incident it exists for, and a dialog
  would be answered by nobody at four in the morning, which is when the sync runs.
  `woo_kontor_sync_catalogue_shrink_limit` is the developer's way out; at `1` any shrink passes.
- **`CATALOGUE_SHRINK_LIMIT` is 0.3**, measured against the article count rather than the product
  count, so the reasons that hold articles *back* — `Ws_aktiv`, the image and category requirements
  — do not enter into it. They change what happens to an article, not whether Kontor listed it.
- **Narrowing the manufacturer filter clears the measurement**, in
  `ProductSync::forget_catalogue_size()` on `update_option_`. It is the one thing a shop can do that
  legitimately takes a fifth of the catalogue away in a single run — it is documented above as
  drafting the excluded articles — so left alone, the old measurement would stop the very run the
  change was made to produce. Nothing else needs it: the shop type does not change which articles
  come back, only their prices.
- **The first run of all is never held back.** With no stored size there is no shrink to measure,
  and a shop with nothing imported yet has nothing to lose either way.

**One job removes products, and only where a shop has asked for it.**
`Settings::TRASH_UNMANAGED` (`trash_unmanaged`, off by default) chains
`Scheduler::ACTION_SYNC_PRODUCTS_TRASH` after the finalising pass, and
`ProductSync::trash_unmanaged()` moves every product this plugin does not manage to the trash. It
is for the shop whose catalogue is Kontor's and nothing else; every other shop leaves it alone and
reads the same summary sentence it always read.

- **Two conditions, and the second is what makes it safe.** The product carries no
  `META_SYNCED_AT`, so this plugin never imported it — the same test `finalise()` and
  `StockSync::apply()` make. *And* its article number was not in this run's catalogue. Asking only
  the first question would sweep away precisely the products `import_article()` goes out of its way
  to protect.
- **`ProductSync::META_SEEN_AT` (`_wksync_seen_at`) is what answers the second**, because the pass
  runs in a later action with the feed long gone. It is written to the products a run declines to
  adopt and nothing else: one held back for an article we do not own (the `withheld` early return),
  one of several sharing an article number, one whose save failed. Every other product in the feed
  carries `META_SYNCED_AT`, which says the same thing and more.
  - **Deliberately not `META_SYNCED_AT`.** That key means "this plugin imported this product", and
    writing it here would adopt a product the sync had just decided was none of its business,
    handing `finalise()` the right to draft it on the next run.
  - **Written whatever the setting says**, unlike `StockSync`'s run stamp, which is skipped while its
    own pass is off. The two differ in cost and in what going without costs: that stamp is one write
    per article across a feed of three thousand every fifteen minutes, this one reaches only the
    handful a run declines to adopt — and the gap it would leave is destructive rather than
    recoverable, because a setting turned on between the walk and the pass would find no markers and
    trash every product the walk had protected.
  - **A marker from an earlier run does not protect a product.** The comparison is `< $run`, so an
    article withheld last month and dropped from the catalogue since is swept like anything else.
- **Trashed, never deleted, and the images are kept.** Trashing is the whole of the safety: a run
  that swept too widely — a catalogue that came back short, a manufacturer filter narrowed by
  mistake — is undone from Products → Trash, and an attachment deleted alongside could not be. It is
  also what makes the chain terminate, since `post_status NOT IN ( 'trash', 'auto-draft' )` takes a
  swept product out of the next batch.
- **Every status is swept** — published, private and draft alike — and so is a product with no SKU
  at all, which cannot be in the catalogue by definition.
- **Hand-written SQL, for `StockSync::draft_batch()`'s reason.** The query needs an OR ("no marker
  at all, or one from an earlier run") and `WP_Meta_Query` drops `meta_key` from every `ON` clause
  the moment an OR appears. Both joins here name their key, so each matches at most one row per
  product.
- **The setting is read at the pass, not at the queue.** Clearing the box stops the sweeping at the
  next pass rather than the next run, and `trash_unmanaged()` then closes the run rather than
  leaving it hanging. Clearing it does **not** empty the trash and does not restore anything; that
  is Products → Trash's job, and the settings screen says so.
- **`ProductSync::complete()` exists because two passes can now end the chain.** The drafting pass
  hands off to the trash pass when the setting is on and closes the run itself when it is not, so
  the summary wording lives in one place rather than two that could drift.

**No job runs until its preconditions hold** — `Preflight::check()`, called at the top of every
`start()`. Four gates, cheapest first: the API base URL and key are set; the shop exchanges orders
with Kontor at all; every job that talks to Kontor about orders — the push, the delivery import and
the invoice import — additionally has a shop selected; and the credentials actually authenticate.
The middle two apply to the same three jobs, which is why one list (`Preflight::$order_jobs`)
answers both, and the orders gate is asked first: "this shop does not do orders" is the truer answer
for a shop that deliberately has no shop ID, and naming the shop field would send whoever read the
refusal looking for something that is not on their screen. This is not defensive padding. An
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
- **What a chunked run is working through lives in an option, never a transient.** Four jobs fetch
  everything in one request and apply it a chunk per action — the stock levels, the delivery rows,
  the invoice listing, the order IDs a sweep fixed at the start — and `Sync\Payload` is where that
  sits between actions.
  - **A transient is not storage on a site with a persistent object cache.** Redis and Memcached
    hold it *instead of* the database, so it can be evicted under memory pressure at any moment —
    and neither the eviction nor the failure that follows says anything: `set_transient()` returns
    true, the chunks are queued, and the first one finds nothing. On the stock sync that is a
    failure every fifteen minutes, for ever, reported as the payload having *expired*, which is the
    one thing that had not happened. A non-autoloaded option is cache-*backed* rather than
    cache-*only*, so a flush costs a read instead of the value.
    - **Eviction is the realistic failure, not the size limit.** Measured on the development site,
      a stock payload of 3000 rows serialises to **68KB** — memcached's default megabyte slab is
      some forty thousand rows away, so no feed on this account comes near it. What does not depend
      on size is the cache deciding it needs the memory back, which it may do at any point in the
      hours a run can span.
  - **The key is the job, not the run.** A per-run key needed a TTL to clean up after a run that
    died and left a row behind for every one that did. Only one run of a job can be in flight —
    `start()` refuses otherwise — and every chunked action checks `Status::is_current_run()` before
    it reads, so a superseded action never gets as far as asking. One row per job, overwritten by
    the next run. `complete()` therefore takes no run identifier on any of the four.
  - **The write is read back, once per run.** `update_option()` returns false both for a write that
    failed and for one that stored a value identical to what was there, which is exactly what a job
    re-running over an unchanged feed does — so its return cannot answer this. One extra read per
    *run* turns a job that would fail on every chunk for ever into one accurate failure at the
    start, before a single chunk is queued.
  - **`Deactivator` and `uninstall.php` drop them by name.** The uninstall used to call
    `delete_expired_transients()`, which by definition never caught the ones that mattered.
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
    or images are still queued — on a normally idle site it never starts, and it pauses while the
    tab is hidden. The whole answer is two non-autoloaded option reads plus that count query.
    - **The second of those is `Scheduler::next_runs()`, and it exists because the obvious version
      was expensive.** Reporting when a job is next due is a scan rather than a lookup — the kind
      of an action is not in the queue's index, so `recurring_action()` fetches up to
      `RECURRING_LOOKUP` of a hook's queued actions and asks each one whether it repeats. Called
      per job it was five of those every five seconds, per open tab, to redraw a timestamp that
      moves once an interval. The plural is cached for `NEXT_RUN_TTL` (1 minute) and dropped by
      `sync_schedules()` whenever the queue is touched; `next_run()` itself stays exact, because a
      caller asking about one job is not in a loop and `docs/rest-api.md` publishes that figure.
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
- **Cancelling a schedule cancels the schedule, not the hook.** `Scheduler::cancel_recurring()`
  looks the recurring action up by id and cancels that one. `as_unschedule_all_actions()` takes
  everything queued on the hook, which includes whatever Run now has put there — so saving the
  settings threw away a manual run somebody had started moments before, and setting a job to Never
  did the same, which is the opposite of what "the job stays manual" means. It went silently, since
  a cancelled async action leaves nothing behind to notice, and on a shop whose queue runs behind
  the window between pressing Run now and pressing Save is not milliseconds. `unschedule_all()` is
  the one place that still empties the whole group, which is right: it runs on deactivation.
- **Whether a job is scheduled is `Scheduler::has_recurring()`, never
  `as_next_scheduled_action()`.** That function collapses three different states into two return
  values: a timestamp for a scheduled action, but a bare `true` both for an action already
  executing *and* for a pending async one — which is exactly what Run now queues. So a manual run
  waiting in the queue reads as a schedule, `sync_schedules()` skips the job, and the interval
  silently stops applying. On a shop whose queue runs behind, that window is not milliseconds: a
  Run now sat behind 2723 actions on toysonline.ch for the best part of an hour, and the
  fifteen-minute stock schedule it displaced was never put back — the settings screen meanwhile
  reporting a next run of **1 January 1970**, which is `(int) true`.
  - **Only the schedule attached to the action tells them apart**, so `recurring_action()` fetches
    the hook's pending and in-progress actions and asks each one `is_recurring()`. There is no
    cheaper question: the kind of an action is not in the queue's index.
  - **In-progress actions have to count as scheduled.** Action Scheduler queues the next occurrence
    *after* the current one finishes (`ActionScheduler_Abstract_QueueRunner::process_action()` calls
    `schedule_next_instance()` at the very end), so for the whole length of a run a recurring job
    has no pending action at all. Reading that as unscheduled queues a second recurring action
    beside the first, and the shop then syncs twice as often for ever.
  - **`RECURRING_LOOKUP` (20) bounds the scan**, because an async action carries the moment it was
    saved as its scheduled date and therefore sorts *ahead* of a recurring action due later. One
    schedule plus however many times somebody pressed the button is the whole of what can be there.
  - **`next_run()` reports the schedule alone** and answers 0 for a job whose only queued action is
    a manual run — which is what `docs/rest-api.md` already promised for `next_run_gmt`, and what
    `queued` is there to answer instead.
  - **Never no longer cancels a pending manual run.** The cancel used to be reached by the same
    truthy test; now it fires only when a recurring action actually exists. A reconciliation
    deciding a job has no interval says nothing about whether somebody still wants the run they
    asked for.
- **The guard is claimed for `GUARD_ATTEMPT` (5 minutes) before the work and extended to
  `GUARD_SETTLED` (1 hour) only once it finishes.** Set to the full hour up front, a request that
  dies mid-reconciliation — a fatal, an execution limit, or the file swap of a plugin update —
  leaves the guard standing with nothing scheduled, and every later request returns early for the
  rest of the hour while the settings screen shows each interval as configured. Found on
  3ag.education: guard claimed 04:50:27, the 0.27.1 files landing at 04:51, and **no recurring
  action of any kind had ever existed on the site**. Five minutes is PHP's usual outer execution
  limit, so a request killed by that limit frees the guard about when it dies.
  - **It cannot simply be set afterwards.** Two concurrent requests would both find no guard, both
    read the job as unscheduled and both queue a recurring action — and the shop then syncs twice
    as often for ever, which is a worse failure than the one being fixed and a permanent one.
    `test_the_guard_is_already_held_while_the_work_runs` pins the claim happening first.
  - **Run now cannot rescue any of this**, which is what makes it hard to recognise from wp-admin:
    `trigger()` queues a one-off async action and never touches a schedule, so the obvious thing a
    shop manager reaches for has no effect at all.
- **`Plugin::maybe_upgrade()` reconciles after a version change, because nothing else does.**
  WordPress runs neither the deactivation nor the activation hook when it replaces a plugin, so
  after an update the only thing left is the once-an-hour `init` check — which is exactly what the
  update is most likely to have interrupted. It compares `Plugin::VERSION_KEY` against
  `WKSYNC_VERSION`, and `Activator` seeds that option with `add_option`, which never updates it.
  - **It only asks for the reconciliation, it cannot perform one.** `Plugin::init()` runs on
    `plugins_loaded`, and while Action Scheduler's functions are defined by then its table names
    are not registered on `$wpdb` until its store initialises on `init` — scheduling from there
    builds SQL against an empty table name (`SELECT a.action_id FROM  a LEFT JOIN  g …`) and fails.
    So it calls `Scheduler::forget_guard()` and lets `ensure_recurring_actions()`, already hooked to
    `init`, do the work later in the same request. `Scheduler::is_available()` does not catch this:
    it tests for the functions, not the store.
  - **The stamp is written whatever the reconciliation then decides**, or a shop whose settings say
    Never would ask again on every request for the rest of that version's life. It is **autoloaded**
    from 0.27.2, since it is now read on every request.
- **`Scheduler::SCHEDULE_GUARD` means "the queue matches the settings"**, which is why
  `unschedule_all()` deletes it: it stops being true the moment the queue is emptied, and leaving
  it set makes `ensure_recurring_actions()` return early for the rest of the hour. A plugin
  deactivated and immediately reactivated would otherwise sit with no recurring actions at all,
  with the settings screen still showing every interval as configured. `Activator::activate()` goes
  further and calls `Scheduler::restore_schedules()`, so the schedules are back before the next
  `init` rather than after it.
- **Never sync inside a request that a customer is waiting on.** Checkout and order-status hooks
  enqueue an action; they do not call Kontor.
- **When a paid order is sent is a setting**, `Settings::ORDER_PUSH_MODE` (`order_push_mode`),
  defaulting to `PUSH_IMMEDIATE` — the status hook queues the upload the moment the order is paid,
  which is what the plugin has always done. `PUSH_SWEEP` leaves every order to the scheduled sweep
  instead. Nothing is lost either way: `META_PUSHED_AT` is only written by a push that happened, so
  an order held back is pending exactly as one Kontor rejected is.
  - **Read inside `OrderSync::enqueue()`, not around the `add_action()` in `Scheduler::register()`.**
    Gating the hook would mean reading the settings option on every request the site serves in order
    to decide about the few that are checkouts. Read here it costs nothing until an order is paid,
    and it sits beside `pushable_statuses()`, which is the other half of the same rule.
  - **Sweep-only with the sweep set to Never is allowed**, and the settings screen says what it
    means: nothing sends orders on its own, and Run now is the only path left. Never is a legitimate
    choice on every schedule here, and a save that silently did something other than what was
    submitted would be the worse answer.
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
- **Two customer emails cover what nothing else says**, `Emails\CustomerInvoice` and
  `Emails\CustomerTracking`, both real `WC_Email` types registered through
  `woocommerce_email_classes`. Everything before this told a customer nothing about an invoice or
  about a shipment that did not complete the order: the invoice arrives hours later in a job of its
  own, long after the confirmation mail, and reached anybody only if some later order email happened
  to be sent.
  - **`WC_Email` rather than a setting of this plugin's**, so the switch, the subject, the heading,
    the additional content and the HTML/plain choice all live in WooCommerce → Settings → Emails
    where a shop manager already manages email. The cost is discoverability, paid for with one
    description line on the settings screen pointing there. It is also mechanical: `customer_email`
    being true is what gets the invoice PDF attached, because `WC_Email::get_attachments()` fires
    the filter `Frontend\Invoices::attach()` already answers.
  - **Both classes are in the global namespace**, named `WKSYNC_Customer_Invoice`,
    `WKSYNC_Customer_Tracking` and `WKSYNC_Order_Email` after core's own convention, and autoloaded
    by a Composer `classmap` rather than PSR-4. That is the one place this plugin breaks its own
    file-layout rule, and a backslash is why: **WooCommerce publishes the class name in URLs**, so
    it has to survive being put in one.
    - **The settings section** is derived twice, and the two must agree — the link is built with
      `strtolower( $email_key )`, the submitted section is matched with `sanitize_title( $email_key )`.
      Namespaced, the link pointed at `wookontorsync\emails\customerinvoice` while the save path
      looked for `wookontorsyncemailscustomerinvoice`, nothing matched, and
      `WC_Settings_Emails::save()` fell through to saving the general email settings. The screen
      rendered perfectly and the Enable checkbox would not stick: **0.20.0 shipped with both emails
      impossible to switch on.**
    - **The email preview** puts it in a query string —
      `?preview_woocommerce_mail=true&type=<class>` — and `EmailPreview::set_email_type()` matches
      on an exact `get_class()`, with no filter anywhere in the path. **nginx answers 403 to a
      backslash there** before WordPress is reached at all, so the preview and the test-email button
      were dead on any nginx host. Found in production on shop.3ag.ch.
    - `Emails::INVOICE_KEY` and `TRACKING_KEY` are the class names, and
      `test_the_email_keys_survive_woocommerces_section_matching` asserts both properties: the two
      section functions agree, and the name is unchanged by `rawurlencode()`. The stored option name
      comes from `$this->id` rather than the class, so none of this moved it.
    - **`Emails.php` imports them explicitly**, and must. It is namespaced, so a bare
      `WKSYNC_Customer_Invoice` there resolves to `WooKontorSync\Emails\WKSYNC_Customer_Invoice`,
      which PSR-4 maps back to the same file — the class declares itself twice and PHP fatals on the
      redeclaration rather than on anything that names the real mistake. The test files need the
      same imports for the same reason.
  - **`woocommerce_email_actions` is not optional, and skipping it fails silently.** The classes are
    only ever constructed by `WC_Emails::init()`, which runs when something calls `WC()->mailer()` —
    and inside the Action Scheduler job that downloads an invoice, nothing has. A bare `do_action()`
    would fire into a hook with no listeners and say nothing about it.
    `WC_Emails::init_transactional_emails()` hooks every name on that list to
    `send_transactional_email`, which instantiates the mailer before re-firing the hook with
    `_notification` appended. **Scalars only** in the arguments — WooCommerce may defer a
    transactional email and replay it from a queue, so an order object would cross that gap stale.
  - **No `get_attachments()` override, and no `templates/` directory.** The base implementation is
    what fires the attachment filter, so overriding and appending is how the same PDF arrives twice.
    The bodies are composed from the actions every core email template fires
    (`woocommerce_email_header`, `..._order_details`, `..._order_meta`, `..._customer_details`,
    `..._footer`), which is what makes `Frontend\Tracking` and `Frontend\Invoices` render into them
    for nothing: `email-order-details.php` ends by firing `woocommerce_email_after_order_table`, in
    the plain-text variant too. Template files would mean a new top-level directory, and
    `bin/build-zip.sh` whitelists only `includes/`, `assets/` and `languages/` — a release whose
    emails rendered as nothing at all, with the suite green because it runs against the checkout.
  - **Both are disabled by default, and that is the mechanism as much as the default.** Neither
    listing has an incremental filter, so the first run after this version lands records the shop's
    whole invoice history and every order the delivery sync has not yet touched. Enabled, an update
    would mail the entire back catalogue in one chain. Disabled, by the time anyone switches them on
    that run has been and gone and there is nothing left to announce — and each email's
    `description` says so, which is the only thing covering a shop that switches them on first.
  - **No plugin filter for "should this send".** WooCommerce already fires
    `woocommerce_email_enabled_{$id}` with the order, which is the hook a WooCommerce developer
    looks for and one fewer published API to keep working.
  - **Orders that already carry their tracking or their invoice are never announced**, and there is
    deliberately **no bulk backfill**. That falls straight out of the stored meta being the record —
    the first run after the upgrade matches on every order the syncs have already touched — and it
    is the same mechanism as the back-catalogue protection, not a separate rule. A parcel that
    arrived last month is not news, and a mail saying it is on its way is worse than silence. The
    resend entry on the order screen is the route to one specific customer, which is the scale at
    which telling somebody late is a decision rather than an accident.
    `test_an_order_that_already_had_its_tracking_announces_nothing` and its invoice twin pin it.
- **The tracking mail is suppressed on the path that already mails.** `DeliverySync::apply()` reads
  the stored tracking number *before* `apply_row()` writes over it and then calls
  `announce_tracking()`, which fires `woo_kontor_sync_tracking_received` only when there is a
  tracking number, it differs from the one held, and this run is not completing the order.
  - **`apply_row()`'s own answer cannot be used.** It reports that something changed for any of four
    fields, so a status that moved or an `Auftrnr` backfilled is indistinguishable there from a
    parcel being sent.
  - **The stored meta is the whole idempotency record**, for both mails — `META_TRACKING` for the
    one and the `_wksync_invoices` entry for the other, which is why
    `woo_kontor_sync_invoice_downloaded` fires *after* `$order->save()`. There is deliberately no
    separate "announced" marker: a second record could disagree with the first, and then neither is
    trustworthy.
  - **The completed path is excluded because WooCommerce's own completion mail already carries the
    tracking block** — `apply_row()` wrote the meta before the status moved, so `Frontend\Tracking`
    renders into it. **The partial-completion path is deliberately not excluded**, because that
    status carries no email by design, which is exactly the gap being filled. A shop that has
    disabled the completion email gets nothing on the completed path; that is treated as the shop
    having decided customers are not mailed on completion, not as a case to detect.
- **Everything Kontor knows about an order is on the order screen**, in `Admin\OrderPanel` — a side
  meta box on `wc_get_page_screen_id( 'shop-order' )` carrying what was pushed, what came back and
  the invoices, with the download links the customer's own order page builds. Every `_wksync_` key
  is protected, so until this there was no way in wp-admin to read the Kontor order number, find out
  why one order never reached the ERP, or reach an invoice at all.
  - **A meta box rather than `woocommerce_admin_order_data_after_order_details`.** That hook fires
    inside the Order data box's address column, sized for a billing address and the one part of the
    screen somebody is editing — a read-only block wedged in there invites the assumption that it is
    editable. A meta box is also the user's to collapse, move or hide in Screen Options.
  - **The callback is handed a `WC_Order`, not a `WP_Post`**: HPOS calls
    `do_meta_boxes( $screen_id, 'side', $this->order )`. The `instanceof` check is a type guard, not
    a compatibility path.
  - **Read-only, and not a form at all**, for `ProductFields`' reason and more strongly: every value
    is rewritten by a background job nobody can see running, so a tracking number typed in by hand
    would survive until the next delivery sync and silently revert.
  - **Kontor's status prints raw** — `completed`, `partially_completed`, `in_progress`, `canceled` —
    rather than mapped onto a WooCommerce label, which would make it look like it agrees with the
    order status beside it when it may not. **Every group renders even when empty**, because
    "nothing has come back yet" and "this plugin has nothing to say" are different statements. A
    **push error renders as a notice** rather than a row: it is the one thing on the panel that
    wants doing something about.
  - **No new download endpoint**, because `Download::permitted()` already grants a
    `manage_woocommerce` user an invoice outright. That is why the whole panel is small.
- **`Admin\OrderActions` adds two entries to the order actions dropdown**, and nothing else: resend
  the invoice mail, resend the tracking mail. Each appears only when it can act — an invoice whose
  file is still on disk, a tracking number that is not empty — because an entry that silently
  achieves nothing is worse than an absent one. Each sends through `OrderEmail::resend()`, which
  goes to `send()` directly rather than `send_notification()`, so a press works with the email type
  switched off; core does the same on its own invoice email. Each leaves an order note, which is the
  only feedback there is — WooCommerce answers an order save with "Order updated" whatever was
  asked. `woocommerce_order_action_*` fires from a save WooCommerce has already nonce-checked, and
  `edit_shop_order` is checked again anyway because a nonce is not authorisation.
  - **There is deliberately no per-order Kontor fetch.** The `invoices` and `orders` entities honour
    only `filter.shopid`, so no such request exists: the entry would start the whole shop-wide
    import behind a label naming one order, and the delivery equivalent completes orders and mails
    customers. Run now on the settings screen is the honest version, on a screen that shows progress
    and refuses with a reason. `OrderActionsTest` pins the absence.
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

## The settings screen

`Admin\Settings` renders one page in six tabs — Jobs, Connection, Products, Categories, Orders,
Tools — each panel a `render_*_section()` of its own rather than seven hundred lines inlined in
`render_page()`.

- **The tabs hide panels; they never leave one out.** This is the load-bearing property, not a
  detail of the implementation. The screen posts a single option array and `sanitize()` reads what
  arrives — and `api_base_url` and `image_base_url` are taken as **empty when absent**, unlike every
  other field, which keeps its stored value. So the obvious version of this feature, rendering one
  tab per request, would wipe the API URL and stop the shop syncing the first time anybody saved
  from a different tab. `test_every_tab_submits_the_whole_settings_form` is the guard, and it
  compares the whole field set rather than spot-checking.
  - It is the same reasoning that keeps the shop row `hidden` rather than left out when neither
    orders nor categories want it.
  - **Four settings panels, one form.** A form per tab would post a quarter of the option each time
    and `sanitize()` would read the rest as absent — the same failure by another route.
- **Without JavaScript every panel shows**, which is exactly what the screen did before the tabs
  existed. The class that does the hiding is added by the script, so the fallback is the old page
  rather than a blank one.
- **Jobs is first and is where the screen opens.** It is what somebody arriving on an ordinary day
  came for: what ran, how it went, and the button to run it again. The settings behind it are read
  during setup and then rarely. **Tools is last**, where nobody reaches the two destructive pushes
  by accident.
- **The Save button belongs to the form, not to a tab**, so it is hidden on Jobs and Tools, which
  put nothing into it. A Save button that appears to do nothing is worse than none.
- **A save returns to the tab it was made from.** `settings_fields()` writes `_wp_http_referer` when
  the page renders, so it names whichever tab the URL carried then; the script rewrites it when a
  tab is clicked. That update happens **before** the address-bar update and not after it —
  `replaceState` throws on a cross-origin URL, and anything below a throw there is a save quietly
  returning to the wrong tab. The history call itself is guarded and is the convenience of the two.
- **The tabs are real links carrying `tab=` in the URL**, so one can be bookmarked, `Settings::tab_url()`
  can point at one from elsewhere, and the strip works before the script has loaded. An unrecognised
  value falls back to the first tab rather than leaving every panel hidden.
- **The redirects name their tab**: Run now comes back to Jobs, and both force pushes to Tools,
  where the reply they print is.

## Saying that a sync is broken

Every other surface in this plugin has to be visited. A shop whose product sync had failed every
night for a week looked entirely normal from the dashboard, the orders list and the products list,
and the only thing saying otherwise was one line on a screen nobody opens while things are working.
`Admin\Health` is where that question is answered, and three screens ask it.

- **One reader, three surfaces.** `Health::problems()` is the only thing that decides what counts as
  broken, for the reason `InvoiceSync::label()` is a public static: three screens describing the
  same shop differently is worse than not describing it at all.
- **Three kinds, and they are genuinely different failures.** `failed` — the job ran and could not
  finish, and its own message says why. `stale` — the job has sat in `running` past
  `Status::STALE_AFTER`, so the chain behind it died with nothing left to close the status; there is
  no message because nothing wrote one, which is why it is not folded in with `failed`.
  `unscheduled` — an interval is set and no recurring action exists to run it. That last one is the
  failure **nothing else in wp-admin would ever show**: the settings screen reads the interval out
  of the settings and reports it as configured, whatever the queue actually holds, which is exactly
  how a live shop sat with no recurring action of any kind while every screen said otherwise.
- **A job can be two of them at once**, and is reported twice: one describes the last run, the other
  says there will not be another.
- **The order-side jobs are left out on a shop that does not exchange orders.** Their stored status
  can only be left over from before the switch was turned off, and the settings screen does not list
  them either.
- **The schedule half is cached and the status half is not.** `Status::get()` is one option read.
  `Scheduler::has_recurring()` has to fetch a hook's queued actions and ask each one whether it
  repeats, because the kind of an action is not in the queue's index — so the screen that runs on
  every admin page load reads a `Health::SCHEDULE_TTL` (15 minutes) copy, and the two that are
  opened deliberately do not. Saving the settings drops it, since the schedules are re-queued.

**`Admin\Notices` is the one thing here that goes looking for the reader.** An `admin_notices` error
on WooCommerce's own screens, the dashboard and the plugins screen — not every admin page, because a
notice on the post editor is in the way of somebody doing something else — and never on the Kontor
Sync screen, which says all of it in more detail a few lines down.

- **Dismissal is keyed on the job and the reason, never on the time.** A failing job records a new
  finish time every run — every fifteen minutes on the stock sync — so a fingerprint carrying one
  would change before the reader had finished reading it. Keyed on the reason, dismissing means "I
  know about this one" and a *different* failure is a new notice.
- **Per user, in user meta.** One person deciding they know about a failure is not everybody
  deciding it.
- **The fingerprints travel in the dismiss link** rather than being recomputed on arrival, so
  pressing it puts away what was read and not whatever the state has become since. `handle_dismiss()`
  authenticates and parses; `dismiss()` does the work, which is what makes it testable without a
  redirect on the end of it.

**`Admin\StatusReport` adds a section to WooCommerce → Status → Report**, which is the page a shop
manager is asked for when something is wrong and the one with a button that turns itself into text.
Before it, supporting a shop from anywhere but in front of it meant asking for screenshots — of
exactly the things nobody thinks to photograph: the shop type, whether a manufacturer filter is
narrowing the catalogue, whether the schedules are in the queue, what the drafting brake last
measured.

- **The API key is never in it**, and neither is anything derived from it. The report exists to be
  pasted into a support thread, which is the one place a credential must not end up; the key is
  reported as present or absent. `test_the_status_report_never_prints_the_api_key` is the guard.
- **A stranded run is called stranded**, not "running", so the row cannot disagree with the notice
  and Site Health about the same state.

**`Admin\SiteHealth` adds two tests**, both **direct** and neither touching the network — a direct
test runs while the page renders, and asking Kontor whether the key still works would put somebody
else's server in the middle of a page load for an answer the jobs already record.

- **Configuration is `recommended`, jobs are `critical`**, and the difference is deliberate. An
  unconfigured plugin is a site where somebody has not finished. A failing or unqueued job is a shop
  showing customers prices and stock levels that are no longer true, and sending nothing to the
  warehouse.
- **A catalogue-only shop is never asked for a shop ID**, the same judgement `Preflight` makes.

**`Health::log_url()` is the only place that builds a link to the log**, and both of WooCommerce's
log handlers — the file viewer and the database one — read the same `source` parameter, so one URL
serves whichever the shop uses. It is on the notice, on both Site Health results and in every row of
the jobs table. Every sync has always logged its decisions there and nothing anywhere pointed at it.

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
- **A poll is two non-autoloaded option reads plus a couple of counting queries.** Read the schedule
  times with `Scheduler::next_runs()` rather than calling `next_run()` per job — see the note under
  the progress bar above for why the per-job version is a scan and not a lookup.
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
