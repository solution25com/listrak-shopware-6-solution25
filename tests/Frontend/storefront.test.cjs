const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../../src/Resources/app/storefront/src');

function fixture({ consent = false, ready = false, fetchImpl } = {}) {
    let cookie = consent ? '1' : undefined;
    const events = new Map();
    const subscriptions = new Set();
    const scripts = [];
    const calls = [];
    const context = {
        AbortController, console, encodeURIComponent,
        COOKIE_CONFIGURATION_UPDATE: 'consent',
        CookieStorage: { getItem: () => cookie },
        fetch: fetchImpl || (async () => ({ ok: true, json: async () => ({ lineItems: [] }) })),
        document: {
            addEventListener(name, callback) { const list = events.get(name) || []; list.push(callback); events.set(name, list); },
            createElement: () => ({}),
            getElementById: id => scripts.find(script => script.id === id),
            head: { appendChild: script => scripts.push(script) },
            $emitter: { subscribe: (name, callback) => subscriptions.add(callback), unsubscribe: (name, callback) => subscriptions.delete(callback) },
        },
        window: { PluginBaseClass: class {} },
    };
    const sandbox = vm.createContext(context);
    const load = (name, variable) => vm.runInContext(fs.readFileSync(path.join(root, name), 'utf8')
        .replace(/^import .*;\s*$/gm, '')
        .replace('export default class ', 'class ')
        .replace('export function ', 'function ') + (variable ? `\nglobalThis.${variable} = ${variable};` : ''), sandbox);
    load('listrak-cookie-consent/listrak-cookie-consent.js');
    load('cart-data.js', 'CartData');
    load('order-data.js', 'OrderData');
    load('plugins/listrak-tracking/listrak-tracking.js', 'ListrakTracking');
    function sdkReady() {
        context.window._ltk_util = { ready: callback => callback() };
        context.window._ltk = Object.fromEntries(['Session', 'Activity', 'SCA', 'Order'].map(group => [group,
            new Proxy({}, { get: (target, name) => (...args) => calls.push([`${group}.${name}`, ...args]), set: (target, name, value) => { calls.push([`${group}.${name}=`, value]); return true; } })]));
        for (const fn of events.get('ltkAsyncListener') || []) fn();
        events.delete('ltkAsyncListener');
    }
    if (ready) sdkReady();
    const create = (options = {}) => {
        const instance = new context.ListrakTracking();
        instance.options = { ...context.ListrakTracking.options, merchantId: 'merchant', cartUrl: '/de/checkout/cart.json', productUrl: '/de/listrak/product-url', ...options };
        instance.el = { isConnected: true };
        instance.init();
        return instance;
    };
    return { calls, scripts, create, sdkReady, consent(value) {
        cookie = value ? '1' : undefined;
        for (const fn of [...subscriptions]) fn({ detail: { listrakTracking: value } });
    } };
}
const flush = () => new Promise(resolve => setImmediate(resolve));

test('no SDK, browse, order, or cart requests before consent; acceptance starts immediately', async () => {
    let requests = 0;
    const f = fixture({ fetchImpl: async () => { requests++; return { ok: true, json: async () => ({ lineItems: [] }) }; } });
    f.create({ productNumber: 'product', data: { placement: 'cart' } });
    assert.equal(f.scripts.length, 0);
    assert.equal(requests, 0);
    assert.equal(f.calls.length, 0);
    f.consent(true);
    assert.equal(f.scripts.length, 1);
    await flush();
    f.sdkReady();
    assert.equal(requests, 1);
    assert.equal(f.calls.filter(c => c[0] === 'Activity.AddProductBrowse').length, 1);
    assert.equal(f.calls.filter(c => c[0] === 'SCA.ClearCart').length, 1);
});

test('revocation while SDK is loading cancels queued tracking and disables personalization', () => {
    const f = fixture({ consent: true });
    f.create();
    f.consent(false);
    f.sdkReady();
    assert.deepEqual(f.calls, [['Session.setPersonalizedStatus', false]]);
    f.consent(true);
    assert.equal(f.calls.filter(c => c[0] === 'Activity.AddPageBrowse').length, 1);
});

test('multiple tracking elements load the SDK only once', () => {
    const f = fixture({ consent: true });
    f.create(); f.create({ trackPage: false, productNumber: 'quickview' });
    assert.equal(f.scripts.length, 1);
});

test('revocation during cart fetch prevents all cart submissions', async () => {
    let resolveCart;
    const f = fixture({ consent: true, ready: true, fetchImpl: () => new Promise(resolve => { resolveCart = resolve; }) });
    f.create({ data: { placement: 'cart', currencyIsoCode: 'USD' } });
    f.consent(false);
    resolveCart({ ok: true, json: async () => ({ lineItems: [] }) });
    await flush();
    assert.equal(f.calls.filter(c => ['SCA.ClearCart', 'SCA.Submit'].includes(c[0])).length, 0);
});

test('order conversion leaves input unchanged and never resubmits after reacceptance', () => {
    const f = fixture({ consent: true, ready: true });
    const data = { placement: 'order', currencyIsoCode: 'EUR', currencyFactor: 1, usdCurrency: { factor: 2 },
        taxTotal: 1, orderTotal: 12, shippingTotal: 1, itemTotal: 10, lineItems: [{ sku: 'free', price: 0, quantity: 1 }] };
    const instance = f.create({ data });
    assert.equal(data.orderTotal, 12);
    assert.equal(instance.handleOrder().orderTotal, 24);
    assert.equal(instance.handleOrder().lineItems[0].price, 0);
    f.consent(false); f.consent(true);
    assert.equal(f.calls.filter(c => c[0] === 'Order.Submit').length, 1);
});

test('USD and zero prices do not require a conversion currency', () => {
    const f = fixture();
    const instance = f.create({ data: { currencyIsoCode: 'USD' } });
    assert.equal(instance.convertToUsd(0), 0);
    assert.equal(instance.convertToUsd(12.34), 12.34);
    assert.equal(instance.mapLineItems([{ price: { unitPrice: 0, totalPrice: 0 } }])[0].price, 0);
});

test('configured consent-free tracking starts, while disconnected offcanvas instances stop', () => {
    const f = fixture({ ready: true });
    const instance = f.create({ requiresCookieConsent: false });
    assert.equal(f.calls.filter(c => c[0] === 'Activity.AddPageBrowse').length, 1);
    instance.el.isConnected = false;
    f.consent(false);
    assert.equal(instance._active, false);
});

test('custom and credit items retain negative prices and receive usable SKUs', () => {
    const instance = fixture().create({ data: { currencyIsoCode: 'USD' } });
    const items = instance.mapLineItems([
        { id: 'credit-id', type: 'credit', price: { unitPrice: -5, totalPrice: -5 }, quantity: 1 },
        { id: 'custom-id', type: 'custom', price: { unitPrice: 2, totalPrice: 2 }, quantity: 1 },
    ]);
    assert.equal(items[0].price, -5);
    assert.equal(items[0].sku, 'CREDIT_ITEM_credit-id');
    assert.equal(items[1].sku, 'CUSTOM_ITEM_custom-id');
});
