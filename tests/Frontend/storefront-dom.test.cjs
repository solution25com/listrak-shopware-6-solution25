const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { JSDOM } = require('jsdom');
const deepmerge = require('deepmerge');
const root = path.resolve(__dirname, '../..');
const shopware = process.env.SHOPWARE_ROOT || path.resolve(root, '../../..');
const core = path.join(shopware, 'vendor/shopware/storefront/Resources/app/storefront/src');
const source = path.join(root, 'src/Resources/app/storefront/src');

function storefront(html, fetchImpl) {
    const dom = new JSDOM(html, { url: 'https://shop.example.com', runScripts: 'outside-only' });
    const sandbox = dom.getInternalVMContext();
    const { window } = dom;
    window.deepmerge = deepmerge;
    window.AbortController = AbortController;
    window.fetch = fetchImpl;
    window.COOKIE_CONFIGURATION_UPDATE = 'cookie-update';
    window.CookieStorage = { getItem: () => undefined };
    window.PageLoadingIndicatorUtil = { create() {}, remove() {} };
    window.focusHandler = { saveFocusState() {}, resumeFocusState() {} };
    const instances = new WeakMap();
    window.PluginManager = {
        getPluginInstancesFromElement(el) {
            if (!instances.has(el)) instances.set(el, new Map());
            return instances.get(el);
        },
        getPlugin: () => new Map([['instances', []]]),
        initializePlugins() {},
    };
    function load(file, name) {
        const code = fs.readFileSync(file, 'utf8').replace(/^import .*;\s*$/gm, '')
            .replace('export default class ', 'class ').replace('export function ', 'function ');
        vm.runInContext(`{ ${code}\nwindow.${name} = ${name}; }`, sandbox);
    }
    load(path.join(core, 'helper/string.helper.js'), 'StringHelper');
    load(path.join(core, 'helper/emitter.helper.js'), 'NativeEventEmitter');
    load(path.join(core, 'plugin-system/plugin.class.js'), 'Plugin');
    window.PluginBaseClass = window.Plugin;
    window.document.$emitter = new window.NativeEventEmitter(window.document);
    load(path.join(core, 'utility/form/form-serialize.util.js'), 'FormSerializeUtil');
    load(path.join(source, 'plugins/listrak-newsletter-checkbox/listrak-newsletter-checkbox.js'), 'ListrakNewsletterCheckbox');
    return { dom, window, load };
}

const markup = `<form class="listrak-newsletter-form" action="/form/newsletter">
    <input type="hidden" name="email" value="fixture@example.com">
    <input type="checkbox" id="listrakNewsletterRegister" name="option" value="subscribe" data-focus-id="newsletter">
    <div class="js-listrak-newsletter-wrapper"></div>
</form>`;

test('a rejected newsletter submission restores the checkbox and the next request can succeed', async () => {
    let succeed = false;
    const submitted = [];
    const f = storefront(markup, async (url, options) => {
        submitted.push(options.body.get('option'));
        return { ok: true, json: async () => [{ type: succeed ? 'success' : 'danger', alert: 'fixture response' }] };
    });
    try {
        const form = f.window.document.querySelector('form');
        const plugin = new f.window.ListrakNewsletterCheckbox(form);
        const checkbox = form.querySelector('input[type="checkbox"]');
        checkbox.checked = true;
        await plugin._onSubmit({ target: checkbox, preventDefault() {} });
        assert.equal(checkbox.checked, false);
        assert.equal(checkbox.disabled, false);
        succeed = true;
        checkbox.checked = true;
        await plugin._onSubmit({ target: checkbox, preventDefault() {} });
        checkbox.checked = false;
        await plugin._onSubmit({ target: checkbox, preventDefault() {} });
        assert.deepEqual(submitted, ['subscribe', 'subscribe', 'unsubscribe']);
    } finally { f.dom.window.close(); }
});

test('newsletter network errors release the loading state and repeated forms stay scoped', async () => {
    const f = storefront(markup + markup, async () => { throw new Error('offline'); });
    try {
        const forms = f.window.document.querySelectorAll('form');
        const plugin = new f.window.ListrakNewsletterCheckbox(forms[1]);
        const checkbox = forms[1].querySelector('input[type="checkbox"]');
        checkbox.checked = true;
        await plugin._onSubmit({ target: checkbox, preventDefault() {} });
        assert.equal(plugin._form, forms[1]);
        assert.equal(checkbox.checked, false);
        assert.equal(checkbox.disabled, false);
        assert.equal(forms[0].querySelector('.js-listrak-newsletter-wrapper').textContent, '');
        assert.ok(forms[1].querySelector('.js-listrak-newsletter-wrapper').textContent);
    } finally { f.dom.window.close(); }
});

test('a real Shopware plugin instance reads template options and starts only after consent', () => {
    const f = storefront('<template data-listrak-tracking data-listrak-tracking-options="{&quot;merchantId&quot;:&quot;fixture&quot;,&quot;requiresCookieConsent&quot;:true}"></template>');
    try {
        f.load(path.join(source, 'listrak-cookie-consent/listrak-cookie-consent.js'), 'whenListrakReady');
        f.load(path.join(source, 'cart-data.js'), 'CartData');
        f.load(path.join(source, 'order-data.js'), 'OrderData');
        f.load(path.join(source, 'plugins/listrak-tracking/listrak-tracking.js'), 'ListrakTracking');
        const template = f.window.document.querySelector('template');
        const plugin = new f.window.ListrakTracking(template);
        assert.equal(plugin.options.merchantId, 'fixture');
        assert.equal(plugin._initialized, true);
        assert.equal(f.window.document.querySelector('#ltkSDK'), null);
        f.window.CookieStorage.getItem = () => '1';
        f.window.document.$emitter.publish('cookie-update', { listrakTracking: true });
        assert.equal(f.window.document.querySelectorAll('#ltkSDK').length, 1);
        plugin.destroy();
    } finally { f.dom.window.close(); }
});
