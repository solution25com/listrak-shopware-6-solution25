# Shopware 6.7 fixes and validation

Date: 2026-09-23  
Plugin version: **1.1.0**  
Branch: **main-6.7** (the original `main` branch is unchanged)  
Supported Shopware range: **6.7.x** (`~6.7.0` for Core and Storefront)  
Runtime validation: **Shopware 6.7.13.1**, PHP 8.3.30, MariaDB 10.11; additional unit tests on PHP 8.4.10.

All seven compatibility findings and all five existing defects in [the original review](SHOPWARE_6.7_COMPATIBILITY_REVIEW.md) have been addressed. The original review remains a record of the code before these changes. No commit or push was made, and the existing shop database was not used for installation or migration tests.

## Resolution of the original findings

| Finding | Implementation | Verification |
| --- | --- | --- |
| C1 — incompatible Composer constraint | Core and Storefront now require `~6.7.0`; PHP requires `^8.2`. Version increased to 1.1.0 so an existing installation can run the update. | Composer validation; isolated installation, activation, and update from 1.0.26 to 1.1.0. |
| C2 — scheduled-task constructor | Pass the logger to `ScheduledTaskHandler`. | Full container validation, service construction in the integration suite, and a task test covering multiple sales channels. |
| C3 — administration assets | Replace the old Webpack administration output with a Vite build, including `.vite/entrypoints.json`, `.vite/manifest.json`, JS, and CSS. Rebuild storefront assets too. | Successful build against Shopware 6.7.0.0; the actual 6.7.13.1 Vite accessor discovers the plugin entry and its files. |
| C4 — Vuex mapping | Resolve `swFlow` through `Shopware.Store` and map the Pinia store instance. | Native Vue 3 + Pinia tests initialize both new and saved actions. |
| C5 — removed Vue compatibility helpers | Replace `isCompatEnabled`/`$set` branches with reactive array updates. Correct grid initialization and validate partially entered rows before saving. | Recipient/profile-field edit, cancel, validation, and action-save tests. |
| C6 — textarea model event | Use `v-model` for `sw-textarea-field`. | A mounted Vue test applies the plugin binding to the actual Shopware 6.7 textarea setter and verifies that the edited value is saved. |
| C7 — PHPUnit configuration | Use the PHPUnit 11 schema and `<source>` configuration. Separate unit and integration suites and add test bootstraps. | Both suites run under PHPUnit 11.5.56. |
| E1 — missing filesystem service | Inject `shopware.filesystem.private`; store local exports under its `listrak/` directory. | Real service-container validation and a filesystem write/read/temporary-file cleanup test. |
| E2 — obsolete Flow description hook | Override `getActionDescriptions(sequence)` for the Listrak action and delegate other actions to Shopware. | Description/delegation regression test. |
| E3 — consent lost on checkout pages | Read the consent configuration inside cart/order markup. Handle consent changes, check consent again before delayed SDK calls, prevent duplicate SDK loading, and stop pending cart work after revocation. Product browsing uses the same guarded plugin. | Rendered cart/order options plus tests for rejection, acceptance, revocation during loading and fetching, SDK deduplication, and order deduplication. |
| E4 — wrong credential gate | Data synchronization checks Data API credentials; email synchronization retains its own credential checks. | Separate positive/negative credential-gate tests. |
| E5 — authentication and retry scope | Cache tokens by sales channel, integration, and credential fingerprint. Persist channel and integration on failed requests, obtain current authorization for replays, and refresh once on HTTP 401. Retry at most 100 eligible rows per channel per run. | Mock HTTP tests for cross-channel isolation, integration isolation, 401 refresh, authentication failure, API errors, and successful replay; real DAL persistence and channel-filtering tests. |

## Additional corrections found during validation

- Validate missing sales-channel IDs explicitly. Channel-wide export commands and workers no longer require an unrelated customer or order as a context restorer.
- Build background contexts for the message's own sales channel and use supported entity-collection accessors.
- Add repository generic types and correct nullable data-access cases identified by Shopware's analyzer.
- Scope newsletter status lookup to the current sales channel.
- Reject transactional flows without a valid sales channel and accept an omitted/empty profile-field configuration. An integration test renders profile-field Twig and verifies the resulting transactional message data without sending it externally.
- Correct newsletter CSV value lookup and import-column positions when only some profile fields are configured. Temporary streams close reliably.
- Keep original order tracking data unchanged during currency conversion, preserve zero prices, and accept USD amounts without requiring another currency record.
- Generate cart/product request URLs through Shopware routes, preserving storefront path prefixes. Escape tracking JSON as an HTML attribute.
- Store the consent cookie as the string `1`, while accepting the previous `true` cookie value for existing visitors.
- Avoid persisting authorization headers or OAuth form credentials in failed requests. API request/response payloads and transport exception messages are no longer copied into the API debug log.

`CookieProviderInterface` remains intentionally supported for the entire 6.7 range. Its replacement cookie event is absent in 6.7.0.0; its deprecation concerns 6.8. The narrow analyzer exceptions document that compatibility requirement and temporary-file/stream operations that the analyzer cannot identify automatically.

## Second review: additional reproduced defects and fixes

A second review expanded the tests beyond mocks to real Shopware 6.7.13.1 newsletter, customer, product, order, and guest-checkout records. It found additional defects; the first test pass was not sufficient to establish these paths worked.

| Surface | Failure reproduced or confirmed | Correction and evidence |
| --- | --- | --- |
| Newsletter contact updates | `optIn` was mapped to `Unsubscribed`; the salutation association could become an object in the API payload. | Map confirmed `direct`/`optIn` states to `Subscribed`, keep pending/opted-out states unsubscribed, and export the salutation display name. Unit and real DAL tests pass. |
| Newsletter bulk export | Only `direct` recipients were selected. | Include confirmed `optIn` recipients; exclude `notSet` and `optOut`. The real CSV fixture checks all four statuses and profile-field values. |
| Background export contexts | Newsletter updates and product exports required an arbitrary existing customer; customer/order batches reused a context restored from one record. | Use the sales-channel context factory. Four command tests and real export tests work without a restorer ID. Existing serialized message fields remain readable for queued jobs. |
| Delayed unsubscribe jobs | An old unsubscribe message could overwrite a later confirmed subscription. | Check the current recipient status before applying the unsubscribe. The integration test verifies that a confirmed subscriber is not changed by a stale unsubscribe job. |
| Order line items | The DAL returned `PartialEntity` objects, causing a `TypeError` in the full-entity SKU mapper. | Select the required line-item fields explicitly and read the shared entity interface. Real order export with product line items passes. |
| Order currencies and dates | Mixed-currency batches used one shared currency, and a timezone offset could be labeled as UTC without conversion. | Convert each order using its currency and stored factor, preserve USD values, and normalize timestamps to UTC. Tests cover EUR and USD orders in one batch and a non-UTC timestamp. Checkout tracking uses the order snapshot currency too. |
| Product CSV and failed exports | PHP 8.4 deprecates an omitted CSV escape argument; mapping exceptions could leave temporary files open. Export failures could be acknowledged as completed queue jobs. | Supply the CSV escape explicitly, close/delete failed temporary exports, calculate the product conversion rate once per feed, and propagate failures for Messenger retry. Real product feed and failed local export tests pass. |
| Administration credential tests | A channel override containing inherited/null values hid global API credentials. | Merge global values with non-null channel overrides and safely handle a missing configuration parent. Both buttons pass regression tests. |
| Flow Builder cancel | Double-click editing did not create the snapshot used by cancel. | Keep snapshots of loaded/saved rows. Cancel restores the last saved values regardless of how editing starts. Native Vue tests cover cancel before and after saving. |
| Guest checkout newsletter | Network/validation failures left the checkbox displaying an unsaved state; repeated forms used global selectors. The guest form used the public CAPTCHA endpoint without its CAPTCHA flow. | Scope DOM access, serialize the intended option explicitly, restore state on failures, and use a POST-only authenticated guest route backed by Shopware's newsletter pagelet service. Real tests verify subscribe/unsubscribe, server-owned identity, and anonymous rejection. Shopware still controls double opt-in. |
| Credit/custom cart tracking | Negative credit prices became zero, and non-product items could have an empty SKU. | Preserve negative values and generate type-specific fallback SKUs; correct credit/discount Twig branches. Regression test passes. |

The storefront DOM tests instantiate **Shopware's actual plugin base class, option parsing, event emitter, and form serializer**. SDK calls and HTTP transport remain simulated. The administration tests still use focused Vue component fixtures rather than a full browser session.

The README explicitly selects `main-6.7`, and this branch's pull-request workflow targets `main-6.7`. No branch was merged, committed, or pushed.

## Retry migration and existing failed requests

`Migration1790146800ScopeFailedRequests` adds nullable `sales_channel_id` and `integration_type` columns and a retry lookup index. It is repeatable and processes legacy rows in batches.

Old records never stored their original sales channel. The migration therefore:

1. Retains their business request body and endpoint for investigation.
2. Removes stored authentication options and replaces the old response with a migration explanation.
3. Sets their retry count to the existing maximum of three and leaves the unknown channel unset.

These legacy rows are not automatically replayed. Review them against the originating shop's records before resynchronizing data. Do not assign every old row to an arbitrary sales channel. Newly failed requests have the information required for safe automatic replay.

## Validation results

| Check | Result |
| --- | --- |
| PHP unit suite | **17 tests, 78 assertions passed** |
| Native Vue / Pinia and storefront suite | **19 tests passed** |
| Shopware integration suite | **12 tests, 104 assertions passed** |
| Plugin installation and activation | **Passed** in a separate Shopware 6.7.13.1 project/database |
| Plugin update, 1.0.26 → 1.1.0 | **Passed** in the isolated project |
| Full active-container `lint:container` | **Passed** |
| Storefront `lint:twig` | **All 8 templates passed** |
| PHP syntax | **All 57 source/test PHP files passed** |
| JavaScript source syntax | **Passed** |
| Shopware CLI PHPStan / ESLint / Stylelint selection | **No findings**, for both `--check-against lowest` and `highest` within the declared 6.7 range |
| Administration and storefront build | **Passed** against 6.7.0.0; regenerated assets included in the working tree |
| Composer metadata syntax | **Valid**; Composer gives its usual advisory about an explicit plugin version |
| Whitespace / conflict-marker check | **Passed** |

The disposable test databases and project scaffolding were removed after validation. The tests use inert/mocked Listrak HTTP responses and SDK methods. Installation and migration checks used separate `listrak_67_test` and `listrak_67_second_test` databases, never the existing shop's application database.

### Reproduce the checks

Run from the plugin directory, with the Shopware installation's Composer dependencies and the test dependencies from this plugin's `require-dev` available. Use a PHPUnit 11 executable:

```sh
LISTRAK_TEST_AUTOLOAD=/path/to/shop/vendor/autoload.php \
  php /path/to/phpunit-11.phar --configuration phpunit.xml

npm ci
SHOPWARE_ROOT=/path/to/shop npm test

shopware-cli extension validate . --full --check-against lowest \
  --only phpstan,eslint,stylelint
shopware-cli extension validate . --full --check-against highest \
  --only phpstan,eslint,stylelint
shopware-cli extension build .
```

For integration tests, use a **disposable Shopware project with its own test database**. The Shopware test bootstrap installs/activates the plugin and can initialize the test database:

```sh
PROJECT_ROOT=/path/to/disposable-shop \
DATABASE_URL='mysql://user:password@host/listrak_67_test' \
LISTRAK_TEST_AUTOLOAD=/path/to/disposable-shop/vendor/autoload.php \
  php /path/to/phpunit-11.phar --configuration phpunit.integration.xml
```

The Shopware project used for the frontend model-contract test must include the Administration package. PHP integration testing also needs the Symfony BrowserKit/CSS Selector development dependencies. `nicolab/php-ftp-client` remains a required runtime dependency and must be installed through Composer or shipped correctly in a distributable plugin package.

## Applying the update to a shop

The changes are in the working tree, including built assets. They have not been installed into the existing shop.

After deploying the plugin and resolving Composer dependencies for Shopware 6.7, run the normal Shopware plugin update and asset steps from that shop's root:

```sh
bin/console plugin:refresh
bin/console plugin:update Listrak
bin/console assets:install
bin/console theme:compile
bin/console cache:clear
```

For a new installation, use `bin/console plugin:install --activate Listrak` instead of `plugin:update`. Restart long-running Messenger workers through the deployment's normal process so they load the new classes. Keep the generated `.vite` directory in the deployed package; omitting hidden files recreates C3.

## Remaining validation limits

- **Live Listrak delivery and FTP were not exercised.** Authentication, credentials, list/message/profile IDs, account settings, network access, and actual delivery need a controlled check with the intended Listrak account. No real customer export or transactional email was sent during this work.
- **Store submission metadata is separate from runtime compatibility.** The unrestricted extension validator still reports the existing `src/Resources/config/plugin.png` as oversized (900 × 885; maximum 256 × 256). The logo was not redesigned or resized as part of the 6.7 code fixes. This prevents claiming a clean Shopware Store submission check, and the existing CI job that runs unrestricted validation will still flag it. The compatibility code checks listed above pass.
- **Other extensions and customized themes were not tested together.** The runtime tests used an isolated Shopware installation with Listrak active. Native Vue tests use focused component stubs around the plugin and an actual Shopware textarea contract; storefront DOM tests exercise the real Shopware plugin base class; they are not a full manual administration-browser acceptance session.
- CodeRabbit's installed CLI was not authenticated. Direct code review, Shopware's analyzers, builds, and the regression suites supplied the validation evidence instead.

The validation supports the fixes described above; it does not establish that every possible shop configuration or live Listrak account will work without a deployment acceptance check.

## Version references

- [Shopware 6.7 upgrade guide](https://github.com/shopware/shopware/blob/v6.7.0.0/UPGRADE-6.7.md)
- [Shopware 6.7 update guide](https://docs.shopware.com/en/shopware-6-en/update-guides/update-guide-shopware-67)
- [PHPUnit 11.5 configuration schema](https://schema.phpunit.de/11.5/phpunit.xsd)
