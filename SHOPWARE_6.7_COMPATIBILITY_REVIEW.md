# Listrak — Shopware 6.7 compatibility review

> **Update — 2026-09-23:** All C1–C7 and E1–E5 findings below have been addressed in the working tree for plugin 1.1.0. See [Shopware 6.7 fixes and validation](SHOPWARE_6.7_FIXES.md) for the implementation, test results, migration behavior, and remaining validation limits. This document preserves the original audit findings.

**Result: plugin version 1.0.26 is not ready for Shopware 6.7.** Changing its Composer constraint alone will not make it compatible. This review found **six confirmed runtime or packaging incompatibilities**, plus **one PHPUnit configuration incompatibility**. An existing missing service definition also prevents a standalone installation from compiling its container.

| Review detail | Value |
| --- | --- |
| Review date | 23 September 2026 |
| Plugin | `solution25/listrak`, version `1.0.26` |
| Reviewed commit | `b7a1c2565b0615c0320fbc539f676024b1d52971` |
| Directory | `custom/plugins/listrak-shopware-6-solution25` |
| Declared core support | `~6.6.0` |
| Primary target checked | **Shopware 6.7.13.1**, installed in the surrounding project and recorded in its `composer.lock` |
| Supporting comparisons | Official 6.7 upgrade guidance; selected core files from `v6.7.0.0` and `v6.6.10.0` |
| Relevant installed dependencies | Symfony 7.4, Doctrine DBAL 4.4.4, Twig 3.28.0 |
| Local verification runtime | PHP 8.4.10; Node.js 22.13.1; isolated frontend probes with the project's Vue 3.5.22 and Pinia 2.3.1 versions |

The findings below distinguish changes introduced by 6.7 from existing plugin defects. A deprecation scheduled for 6.8 is not counted as a 6.7 incompatibility. This is a source review with targeted executable checks, not a completed installation or end-to-end certification of every 6.7 patch release.

## Confirmed incompatibilities

| ID | Severity | Area | Failure on 6.7 |
| --- | --- | --- | --- |
| C1 | Blocker | Composer metadata | The core constraint excludes every 6.7 release. |
| C2 | High | Scheduled retries | The handler calls its parent constructor without the now-required logger. |
| C3 | High | Administration distribution | The committed administration assets lack the Vite entry points used by 6.7. |
| C4 | High | Flow Builder state | The modal passes a Vuex store name to the Pinia `mapState` helper. |
| C5 | High | Flow Builder editing | Recipient and profile-field methods call removed Vue compatibility helpers. |
| C6 | High | Flow Builder field values | The textarea binding listens to an event that the 6.7 wrapper does not emit. |
| C7 | Medium, tooling only | PHPUnit configuration | The coverage configuration is invalid under PHPUnit 11's schema. |

### C1. The Composer core constraint rejects Shopware 6.7

**Location:** [composer.json](composer.json), lines 14–17.

```json
"shopware/core": "~6.6.0",
"shopware/storefront": "^6.6"
```

`~6.6.0` permits the 6.6 series and excludes 6.7. This blocks resolving this plugin together with the project's locked `shopware/core` version `6.7.13.1`.

The storefront constraint is different: `^6.6` already permits 6.7. It is specifically the **core** constraint that rejects the upgrade.

**Verification:** Executed the installed Composer Semver library against both constraints:

```text
6.6.10.0 core allowed  storefront allowed
6.7.0.0  core rejected storefront allowed
6.7.13.1 core rejected storefront allowed
```

**Smallest safe fix:** After resolving the code and asset issues, publish a 6.7 release with core and storefront constraints matching the versions actually tested. For a dedicated 6.7 release, `~6.7.0` on both packages expresses that boundary. Keep the 6.6 release available separately because its administration artifacts differ.

**Regression and validation:** Resolve the release against its lowest supported 6.7 version and the project's 6.7.13.1 installation. Verify both a fresh installation and an update from an existing Listrak installation; widening the constraint is not itself evidence of compatibility.

### C2. The retry task omits the required parent logger

**Location:** [src/ScheduledTask/RequestRetryTaskHandler.php](src/ScheduledTask/RequestRetryTaskHandler.php), lines 24–31.

The plugin already receives a logger, but calls:

```php
parent::__construct($scheduledTaskRepository);
```

In 6.6 the parent logger argument was optional and deprecated when omitted. In 6.7 it is required. Instantiating this handler therefore fails before its `run()` method can execute. Failed Listrak requests cannot be retried by this task; a worker resolving this handler receives an error.

**Verification:** Instantiated the actual plugin handler against the installed core with inert constructor dependencies. The parent call produced:

```text
ArgumentCountError: Too few arguments to function
Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler::__construct(),
1 passed ... on line 31 and exactly 2 expected
```

**Smallest safe fix:** Pass the existing logger through:

```php
parent::__construct($scheduledTaskRepository, $logger);
```

**Regression and validation:** Confirm handler construction, successful task execution, failed-request processing, and the scheduled task's final status. Test the task's failure path as well as its success path. The separate retry-design issues described later still need attention.

**Core evidence:** [6.7.13.1 ScheduledTaskHandler](https://github.com/shopware/shopware/blob/v6.7.13.1/src/Core/Framework/MessageQueue/ScheduledTask/ScheduledTaskHandler.php), compared with [6.6.10.0 ScheduledTaskHandler](https://github.com/shopware/shopware/blob/v6.6.10.0/src/Core/Framework/MessageQueue/ScheduledTask/ScheduledTaskHandler.php).

### C3. The shipped administration assets are not discoverable by the 6.7 loader

**Locations:**

- [src/Resources/public/administration/js/listrak.js](src/Resources/public/administration/js/listrak.js), line 1.
- [src/Resources/public/administration/css/listrak.css](src/Resources/public/administration/css/listrak.css), line 1.
- [src/Resources/app/administration/src/main.js](src/Resources/app/administration/src/main.js), lines 1–29, contains the registrations that need to be built.

The committed administration output is a Webpack-era bundle. There is no `src/Resources/public/administration/.vite/entrypoints.json` or corresponding Vite manifest in the checkout.

Shopware 6.7's `ViteFileAccessorDecorator` reads the Vite entry-point file. When it is absent, it returns an empty array. The administration bundle subscriber obtains plugin scripts and styles from that data. The legacy JavaScript file's presence alone does not register the plugin's administration entry point.

**Impact:** When deploying the committed artifacts as-is, the Data API and Email API test components and the Listrak Flow Builder UI are not loaded through the normal 6.7 asset mechanism. A deployment that successfully rebuilds the plugin for 6.7 can replace these artifacts, but must also address C4–C6.

**Verification:** Called the installed core's `getBundleData()` for this plugin, using the real filesystem lookup without booting the shop:

```text
Actual 6.7 Vite bundle data: []
```

**Smallest safe fix:** Build the administration source using the 6.7 toolchain after fixing its runtime incompatibilities, and include the generated Vite entry points, manifest, JavaScript, and CSS in the distributed package. No custom Webpack configuration was found in this plugin, so this finding does not imply that a custom `vite.config` must be invented.

**Regression and validation:** Install the built ZIP in a clean 6.7 environment and inspect the administration bundle configuration. Confirm that the API-test buttons render and the custom Flow Builder modal loads. Exercise a deployment that uses packaged assets, in addition to a development environment that rebuilds assets locally.

**Core evidence:** [6.7.13.1 ViteFileAccessorDecorator](https://github.com/shopware/shopware/blob/v6.7.13.1/src/Administration/Framework/Twig/ViteFileAccessorDecorator.php), [administration bundle discovery](https://github.com/shopware/shopware/blob/v6.7.13.1/src/Administration/Framework/Api/Subscriber/AdminInfoConfigBundlesSubscriber.php), and [official Vite migration guide](https://developer.shopware.com/docs/guides/upgrades-migrations/administration/vite.html).

### C4. The Flow Builder modal still maps the old Vuex store

**Location:** [src/Resources/app/administration/src/component/sw-flow-listrak-mail-send-modal/index.js](src/Resources/app/administration/src/component/sw-flow-listrak-mail-send-modal/index.js), lines 10, 103–104, 198–202, and 210–213.

The modal uses:

```js
...mapState('swFlowState', [
    'mailTemplates',
    'triggerEvent',
    'triggerActions',
]),
```

In 6.7, `Component.getComponentHelper().mapState` is Pinia's helper. It expects a store function, and Shopware's Flow Builder store is registered as `swFlow`. The modal passes the former Vuex namespace string instead.

Opening the modal evaluates `recipientOptions`, which reads `triggerEvent`. That evaluates the invalid mapped getter and prevents normal modal initialization.

**Verification:** Loaded the plugin's actual component definition with Pinia 2.3.1's real `mapState` function and evaluated the mapped getter:

```text
Flow mapState getter: TypeError: useStore is not a function
```

**Smallest safe fix:** Follow the core Flow Builder's 6.7 mapping:

```js
...mapState(() => Shopware.Store.get('swFlow'), [
    'mailTemplates',
    'triggerEvent',
    'triggerActions',
]),
```

Renaming the helper to `mapVuexState` would not restore the migrated core Flow Builder store.

**Regression and validation:** Open both new and existing Listrak actions. Test order, customer, newsletter, and contact-form triggers, because the available recipient types depend on the trigger's awareness data. Save the flow and reopen it.

**Core evidence:** [6.7 component helper registration](https://github.com/shopware/shopware/blob/v6.7.13.1/src/Administration/Resources/app/administration/src/app/init/component-helper.init.ts), [6.7 Flow Builder state mapping](https://github.com/shopware/shopware/blob/v6.7.13.1/src/Administration/Resources/app/administration/src/module/sw-flow/component/sw-flow-sequence-action/index.js), and [official Pinia migration guide](https://developer.shopware.com/docs/guides/upgrades-migrations/administration/pinia.html).

### C5. Editing and validation call removed Vue compatibility helpers

**Location:** [src/Resources/app/administration/src/component/sw-flow-listrak-mail-send-modal/index.js](src/Resources/app/administration/src/component/sw-flow-listrak-mail-send-modal/index.js).

| Method | Relevant lines |
| --- | --- |
| `onEditRecipient()` | 523–550, particularly 531–536 |
| `onEditProfileField()` | 556–586, particularly 564–570 |
| `validateRecipient()` | 649–666, particularly 653–654 |
| `validateProfileField()` | 669–687, particularly 674–675 |

These methods call `this.isCompatEnabled('INSTANCE_SET')`, with `this.$set(...)` in the compatibility branch. Native Vue 3 in Shopware 6.7 provides neither of those instance helpers. The call to `isCompatEnabled` throws before execution can reach the otherwise valid direct-assignment branch.

**Impact:** After fixing C4 so that the modal opens, saving or editing custom recipients and profile fields can still fail. Valid rows also call the validation methods, so the error is not limited to invalid input.

**Verification:** Ran the actual validation methods inside native Vue 3 component instances with valid fixture values:

```text
validateRecipient in native Vue 3:
TypeError: this.isCompatEnabled is not a function

validateProfileField in native Vue 3:
TypeError: this.isCompatEnabled is not a function
```

**Smallest safe fix:** Remove all four compatibility checks and the `$set` branches. Use Vue 3's reactive direct assignment and update both error properties together where appropriate, for example:

```js
this.recipients[itemIndex] = { ...item, errorName, errorMail };
this.profileFields[itemIndex] = { ...item, errorId, errorValue };
```

**Regression and validation:** Add, edit, cancel, delete, and save recipient and profile-field rows. Check valid input and every validation-error path. Verify that editing errors clears the appropriate error messages.

**Core evidence:** [Shopware 6.7 upgrade guide: removal of Vue 2 compatibility](https://github.com/shopware/shopware/blob/v6.7.13.1/UPGRADE-6.7.md#removal-of-vue-2-compatibility-layer), and [6.7 Vue adapter](https://github.com/shopware/shopware/blob/v6.7.13.1/src/Administration/Resources/app/administration/src/app/adapter/view/vue.adapter.ts).

### C6. Profile-field textarea edits do not update the bound value

**Location:** [src/Resources/app/administration/src/component/sw-flow-listrak-mail-send-modal/sw-flow-listrak-mail-send-modal.html.twig](src/Resources/app/administration/src/component/sw-flow-listrak-mail-send-modal/sw-flow-listrak-mail-send-modal.html.twig), lines 127–135.

The plugin binds the textarea with:

```html
<sw-textarea-field
    v-model:value="item.fieldValue"
    ...
/>
```

The 6.7 `sw-textarea-field` wrapper writes changes through `update:modelValue`. The plugin's `v-model:value` binding listens for `update:value`. The wrapper accepts a legacy `value` prop for reading, which can make an existing value display correctly, but its setter does not emit the event that updates this plugin's field.

**Impact:** A user can type a profile-field template value while `item.fieldValue` remains empty or retains the previous text. Saving the grid/action can therefore validate or persist stale content. This is independent of the Pinia and compatibility-helper failures.

**Verification:** Executed the actual wrapper setter from both the original 6.7.0.0 source and the installed 6.7.13.1 source, with the plugin's event binding:

```text
Textarea 6.7.0.0:  emitted=update:modelValue; plugin fieldValue=old value
Textarea 6.7.13.1: emitted=update:modelValue; plugin fieldValue=old value
```

**Smallest safe fix:** Change this textarea binding to `v-model="item.fieldValue"`, or migrate the field to `mt-textarea` using its native model contract. Check placeholder, error, sizing, and grid behavior when choosing the direct Meteor component.

This is a specific textarea mismatch. The installed `sw-text-field` and `sw-number-field` wrappers still bridge the legacy value events; their names and `v-model:value` usages are not automatically separate 6.7 blockers.

**Regression and validation:** Change an existing value, enter a new value, and clear a value. Save the row and the action, persist the flow, then reload it and check the stored `profileFields` configuration.

**Core evidence:** [6.7.13.1 textarea wrapper](https://github.com/shopware/shopware/blob/v6.7.13.1/src/Administration/Resources/app/administration/src/app/component/form/sw-textarea-field/index.ts), [its template](https://github.com/shopware/shopware/blob/v6.7.13.1/src/Administration/Resources/app/administration/src/app/component/form/sw-textarea-field/sw-textarea-field.html.twig), and [6.7.0.0 wrapper](https://github.com/shopware/shopware/blob/v6.7.0.0/src/Administration/Resources/app/administration/src/app/component/form/sw-textarea-field/index.ts).

### C7. The PHPUnit configuration still uses the PHPUnit 9 coverage structure

**Location:** [phpunit.xml](phpunit.xml), lines 3 and 6–10.

The file references the PHPUnit 9.3 schema and places source inclusion under `<coverage><include>`. Shopware 6.7's development tooling moves to PHPUnit 11, whose configuration places the source filter under `<source><include>`.

**Verification:** Validated the unmodified XML against the official PHPUnit 11.5 XSD:

```text
PHPUnit 11.5 schema valid: no
Element 'include': This element is not expected.
```

**Impact:** This is a test-tooling incompatibility, not a storefront failure. The existing configuration is not valid evidence of a working PHPUnit 11 test setup. There are currently no test cases in the repository; `tests/` contains only the bootstrap.

**Smallest safe fix:** Migrate `phpunit.xml` to the selected PHPUnit 11 version, including the source filter and schema reference. Add focused regression coverage for the actual failure modes identified here.

**Regression and validation:** Run PHPUnit 11 with configuration validation and confirm that it discovers real tests. Use a disposable test shop: the existing bootstrap requests forced plugin installation.

**Evidence:** [official PHPUnit 11.5 schema](https://schema.phpunit.de/11.5/phpunit.xsd), [PHPUnit source configuration](https://docs.phpunit.de/en/11.5/configuration.html#the-source-element), and [Shopware 6.7 major library updates](https://github.com/shopware/shopware/blob/v6.7.13.1/UPGRADE-6.7.md#major-library-updates).

## Existing defects that also matter when moving to 6.7

These findings are present in the reviewed plugin code, but are **not attributed to a breaking change introduced by Shopware 6.7**. They are separate from C1–C7.

### E1. An undefined filesystem service blocks standalone container compilation

**Locations:** [src/Resources/config/services.xml](src/Resources/config/services.xml), lines 18–21; [src/Service/ListrakFTPService.php](src/Service/ListrakFTPService.php), lines 20–24.

The FTP service requires `listrak.filesystem.private`. The plugin defines no service or alias with that ID, and none was found in the surrounding project's configuration. Core defines `shopware.filesystem.private`; it does not supply this Listrak-specific ID.

**Verification:** Compiled the plugin's real XML definitions in an isolated Symfony container, representing external core dependencies with placeholders. Compilation stopped with:

```text
ServiceNotFoundException: The service "Listrak\Service\ListrakFTPService"
has a dependency on a non-existent service "listrak.filesystem.private".
```

**Impact:** A standalone installation cannot compile these service definitions. A shop that has supplied an external alias may mask this defect, so check deployment-specific configuration before assuming that an existing installation proves the package is complete.

**Fix and validation:** Define the intended plugin filesystem, or inject `shopware.filesystem.private` and use an appropriate plugin-specific directory. Verify container compilation and local product-feed export without relying on another plugin's service definitions.

**Core evidence:** [6.7.13.1 filesystem definitions](https://github.com/shopware/shopware/blob/v6.7.13.1/src/Core/Framework/DependencyInjection/filesystem.xml).

### E2. The Flow Builder description override targets an obsolete extension point

**Location:** [src/Resources/app/administration/src/extension/sw-flow-sequence-action/index.js](src/Resources/app/administration/src/extension/sw-flow-sequence-action/index.js), lines 17–24.

The plugin overrides a computed `actionDescription` and calls its parent through `$super`. The checked 6.7 core renders descriptions through `getActionDescriptions()` and `flowBuilderService`; it has no parent computed property with that name. This mismatch is also present against the checked 6.6.10.0 source, so it is not a new 6.7 regression.

**Impact:** The intended custom recipient description is not integrated into the current rendering path. Do not describe this as a guaranteed crash on every Flow Builder page: an unused computed property need not be evaluated.

**Fix and validation:** Register a description callback through the current Flow Builder service, or override the actual description method with a narrow Listrak branch. Verify the description of saved custom, customer, admin, and contact-form recipient actions.

**Core evidence:** [Flow Builder description service](https://github.com/shopware/shopware/blob/v6.7.13.1/src/Administration/Resources/app/administration/src/module/sw-flow/service/flow-builder.service.ts) and [6.6.10.0 sequence component](https://github.com/shopware/shopware/blob/v6.6.10.0/src/Administration/Resources/app/administration/src/module/sw-flow/component/sw-flow-sequence-action/index.js).

### E3. Cart and order tracking can lose the configured consent requirement

**Locations:** [src/Resources/views/storefront/base.html.twig](src/Resources/views/storefront/base.html.twig), lines 4–9; [src/Resources/views/storefront/component/cart-tracker.html.twig](src/Resources/views/storefront/component/cart-tracker.html.twig), lines 91–94; [src/Resources/app/storefront/src/plugins/listrak-tracking/listrak-tracking.js](src/Resources/app/storefront/src/plugins/listrak-tracking/listrak-tracking.js), lines 12–21.

`requiresCookieConsent` is assigned inside the base template's `listrak_script` block. The cart, confirm, and finish templates replace that block without calling `parent()`. Their tracker include reads `requiresCookieConsent` without loading the setting itself; the offcanvas include has the same missing local initialization. The JavaScript consent guard only returns early when this option is truthy.

The cookie-consent listener file is also absent from the storefront entry point's import graph. This leaves live acceptance/revocation behavior incomplete even where the initial guard works.

**Fix and validation:** Read or explicitly pass the consent setting for every tracker placement and connect consent changes to the tracking lifecycle. With tracking enabled and consent required, verify that the Listrak SDK does not load on cart, offcanvas, confirm, or finish before consent. Then test acceptance and revocation on the same page. This conclusion comes from the source paths; a live browser/network test remains necessary.

### E4. Data synchronization checks the Email API credentials

**Location:** [src/Service/ListrakConfigService.php](src/Service/ListrakConfigService.php), lines 26–32.

`isDataSyncEnabled()` checks `emailClientId` and `emailClientSecret`, although customer/order exports use the Data API credentials. A shop with valid Data API settings and no Email API settings can have ongoing customer/order sync skipped.

**Fix and validation:** Check `dataClientId` and `dataClientSecret` in this method. Test a Data-only configuration and an Email-only configuration against the customer and order subscriber gates.

### E5. Token caching and retries do not preserve sales-channel authentication correctly

**Locations:** [src/Service/ListrakApiService.php](src/Service/ListrakApiService.php), lines 21–27, 281–292, and 424–430; [src/Service/FailedRequestService.php](src/Service/FailedRequestService.php), lines 36–52 and 117–125; [src/ScheduledTask/RequestRetryTaskHandler.php](src/ScheduledTask/RequestRetryTaskHandler.php), lines 44–56; [src/Migration/Migration1745381062CreateFailedRequestsTable.php](src/Migration/Migration1745381062CreateFailedRequestsTable.php), lines 19–31.

The API service caches one token per integration type, without a sales-channel key. Reusing that service for two differently configured channels can return the first channel's token for the second channel.

Failed requests persist their original options, including the authorization header, but no sales-channel field in the table. The retry task selects the first sales channel and replays all eligible rows through the unauthenticated `request()` wrapper, rather than rebuilding authorization through `authorizedRequest()`. An expired saved token is replayed without refresh, and the original channel cannot be reconstructed reliably from the stored request metadata.

**Fix and validation:** Key token caches by channel and integration, persist the originating channel and integration for retries, and obtain current authorization at replay time. Test two channels with distinct credentials and an expired-token retry. These are existing integration defects; fixing C2 alone only allows the retry handler to start.

## Items checked that are not confirmed 6.7 blockers

| Item | Result |
| --- | --- |
| `Symfony\Component\Routing\Annotation\Route` imports | Still resolve to the attribute class with the installed Symfony version. Instantiating the plugin's route attribute succeeded. Prefer the `Attribute` namespace in future cleanup, but do not report the current imports as missing classes. |
| `$tc(...)` calls in administration | Shopware retains a compatibility translation function during 6.7. The plugin's simple calls are not evidence of a 6.7 removal. Migration to `$t` is a 6.8 preparation task. |
| `sw-text-field`, `sw-number-field`, and `sw-button` | Compatibility wrappers remain registered in the checked 6.7 core. Do not count every old component tag as a missing component. C6 identifies the concrete textarea event mismatch. |
| `@import '~scss/variables'` | The installed administration Vite configuration includes a `~scss/` alias. This import alone is not a confirmed build failure. |
| `CookieProviderInterface` decoration | Still supported by a legacy conversion path in 6.7.13.1. Deprecated for 6.8; migration to `CookieGroupCollectEvent` is forward maintenance, separate from E3. |
| `availableStock` product field | Still exists in the installed product definition. No removed-field finding is justified for this plugin's usage. |
| Storefront template inheritance | All seven `@Storefront` parent targets used by the eight plugin storefront templates exist. The overridden core blocks checked also exist; the custom `listrak_script` block is defined by the plugin itself. |
| Storefront JavaScript APIs | The referenced cookie helper, loading indicator, form serialization utility, plugin registration system, and cart JSON route remain present. The administration's Vite migration is not a reason to label all storefront Webpack output incompatible. |
| PHP imports and inheritance | All 44 PHP source files loaded against the installed autoloader. Shopware/Symfony classes used by the plugin resolved. This does not validate every method's runtime input or DAL query. |
| Database migration and DBAL usage | Uses APIs retained by DBAL 4, including `executeStatement()` and `fetchAllAssociative()`. No removed DBAL call was found. The migration was inspected but not executed. |
| Extra Flow action constructor argument | `services.xml:162` supplies an unused seventh argument to a six-argument userland constructor. This is service-definition drift worth removing, but the argument count alone is not a confirmed Symfony/PHP 6.7 boot failure. |

Supporting core references: [translation compatibility guidance](https://github.com/shopware/shopware/blob/v6.7.13.1/UPGRADE-6.7.md#vue-i18n-v10-update), [component registrations](https://github.com/shopware/shopware/blob/v6.7.13.1/src/Administration/Resources/app/administration/src/app/component/index.ts), [Vite aliases](https://github.com/shopware/shopware/blob/v6.7.13.1/src/Administration/Resources/app/administration/vite.config.mts), [legacy cookie conversion](https://github.com/shopware/shopware/blob/v6.7.13.1/src/Core/Content/Cookie/Service/CookieProvider.php), and [product field definition](https://github.com/shopware/shopware/blob/v6.7.13.1/src/Core/Content/Product/ProductDefinition.php).

## Review coverage and verification limits

The review covered the plugin's **94 tracked files**, following the relevant code through the installed core. The executable source comprises 44 plugin PHP files plus the test bootstrap, 18 JavaScript files including generated bundles, 11 Twig files, two SCSS files, and one generated CSS file. Configuration, snippets, CI/build metadata, dependency declarations, and documentation were also inspected. The PNG is a static plugin icon.

| Surface | Review performed |
| --- | --- |
| Lifecycle and dependency injection | Plugin class, all service definitions and arguments, decoration, custom repository wiring, route imports, declared dependencies. |
| DAL and migrations | Failed-request definition/entity/collection, migration SQL, partial entity reads, product/order/customer/newsletter mappings. |
| Background work | All four commands, six message types and their six handlers, scheduled retry task and handler, context restoration, API/FTP integration. |
| Events and transactional mail | All five subscribers, custom flow action, awareness interface, customization event, mail-template rendering path. |
| Storefront | All eight Twig templates, all six JavaScript source files, both generated bundles, cart recreation and URL controllers, signed token codec, cookie integration. |
| Administration | Components, Flow Builder override, API services, validation helper, constants, snippets, styles, and committed administration assets. |
| Tooling | Composer metadata, extension build configuration, GitHub workflow, pre-commit scripts, PHPStan config, PHPUnit config/bootstrap. |

**Checks actually executed:**

- PHP syntax validation: **45 files, zero failures**.
- JavaScript syntax validation: **18 files, zero failures**.
- JSON/XML parsing: **eight files, zero syntax failures**. XML syntax validity does not imply PHPUnit 11 schema compatibility.
- Loading all 44 plugin PHP source files against the installed Shopware dependencies: completed.
- Composer Semver checks: confirmed C1.
- Actual retry-handler construction with inert dependencies: reproduced C2.
- Actual core Vite bundle lookup against the checkout: reproduced C3.
- Actual modal state mapping with Pinia 2.3.1: reproduced C4.
- Actual modal validation methods in native Vue 3.5.22 instances: reproduced C5.
- Actual core textarea setters from 6.7.0.0 and 6.7.13.1: reproduced C6's event mismatch.
- PHPUnit 11.5 schema validation: reproduced C7.
- Standalone Symfony container compilation of plugin XML with external core dependencies represented by placeholders: reproduced E1. This was not a full Shopware kernel boot.

**Limitations:** The plugin was not installed or activated, no database migration was run, and no full administration/storefront build or browser checkout test was performed. No synchronization, FTP upload, or transactional email was sent to Listrak. The surrounding project's autoloader does not contain `FtpClient\FtpClient` or `FtpClient\FtpException`; those belong to the plugin's declared `nicolab/php-ftp-client` dependency and need a resolved plugin installation before live export validation. Their absence here is not evidence that the package itself is incompatible with 6.7.

The CodeRabbit CLI was installed but signed out, so no CodeRabbit service review was run. The conclusions above come from direct source inspection and the stated isolated checks.

## Release validation still required

The current CI workflow selects `lowest` and `highest` versions from the extension's declared compatibility range. With the present core constraint, that does not establish support for 6.7. Update the range and explicitly verify the intended 6.7 endpoints. The repository also has a test bootstrap but no test cases.

1. Fix E1 and C2, then resolve the intended 6.7 dependency range and verify installation/container boot in a disposable shop.
2. Fix C4–C6, rebuild the administration for 6.7, and ship the complete generated assets for C3.
3. Verify the two API-test components and create/edit/save/reopen each supported Listrak Flow Builder recipient configuration.
4. Validate product, customer, order, and newsletter synchronization with fixtures and a Listrak test account. Include multiple currencies, multiple sales channels, guests, and a channel without existing customers.
5. Exercise a failed request through the scheduled retry path, including expired credentials and channels with different credentials.
6. Exercise storefront browse, cart, offcanvas, checkout confirm, finish tracking, newsletter signup, and signed cart-recreation links. Include consent-required mode and acceptance/revocation.
7. Migrate the PHPUnit configuration, add regression coverage, and validate both a fresh installation and an upgrade using the packaged release artifacts.

Only this review document was added to the plugin during the audit. The compatibility fixes described here have not been applied.
