import CookieStorage from 'src/helper/storage/cookie-storage.helper';
import { COOKIE_CONFIGURATION_UPDATE } from 'src/plugin/cookie/cookie-configuration.plugin';
import { whenListrakReady } from '../../listrak-cookie-consent/listrak-cookie-consent';
import OrderData from '../../order-data';
import CartData from '../../cart-data';

const { PluginBaseClass } = window;

export default class ListrakTracking extends PluginBaseClass {
    static options = {
        listrakTrackingCookie: 'listrakTracking',
        requiresCookieConsent: true,
        trackPage: true,
    };

    init() {
        this._generation = 0;
        this._active = false;
        this._activityTracked = false;
        this._orderTracked = false;
        this._onConsentChange = this.onConsentChange.bind(this);
        document.$emitter.subscribe(COOKIE_CONFIGURATION_UPDATE, this._onConsentChange);
        this.startTracking();
    }

    hasConsent() {
        return !this.options.requiresCookieConsent || ['1', 'true'].includes(CookieStorage.getItem(this.options.listrakTrackingCookie));
    }

    onConsentChange(event) {
        if (!this.el.isConnected) {
            this.destroy();
            return;
        }
        if (!Object.prototype.hasOwnProperty.call(event.detail, this.options.listrakTrackingCookie)) {
            return;
        }
        if (this.hasConsent()) {
            this.startTracking();
        } else {
            this._active = false;
            this._generation += 1;
            this._abortController?.abort();
            if (window._ltk || document.getElementById('ltkSDK')) {
                whenListrakReady(() => {
                    if (!this.hasConsent()) {
                        window._ltk.Session.setPersonalizedStatus(false);
                    }
                });
            }
        }
    }

    startTracking() {
        if (!this.options.merchantId || !this.hasConsent() || this._active) {
            return;
        }
        this._active = true;
        const generation = ++this._generation;
        this._abortController = new AbortController();
        this.whenAllowed(() => {
            window._ltk.Session.setPersonalizedStatus(true);
            if (!this._activityTracked) {
                const email = this.options.email || this.options.data?.email;
                if (email) {
                    window._ltk.SCA.Update('email', email);
                }
                if (this.options.trackPage) {
                    window._ltk.Activity.AddPageBrowse();
                }
                if (this.options.productNumber) {
                    window._ltk.Activity.AddProductBrowse(this.options.productNumber);
                }
                window._ltk.Activity.Submit();
                this._activityTracked = true;
            }
            if (this.options.data?.placement === 'order' && !this._orderTracked) {
                new OrderData().init(this.handleOrder());
                this._orderTracked = true;
            }
        }, generation);

        if (!window._ltk && !document.getElementById('ltkSDK')) {
            const script = document.createElement('script');
            script.id = 'ltkSDK';
            script.async = true;
            script.src = `https://cdn.listrakbi.com/scripts/script.js?m=${encodeURIComponent(this.options.merchantId)}&v=1`;
            document.head.appendChild(script);
        }
        if (this.options.data?.placement === 'cart') {
            this.getCart(generation);
        }
    }

    whenAllowed(callback, generation = this._generation) {
        whenListrakReady(() => {
            if (this.el.isConnected && this._active && generation === this._generation && this.hasConsent()) {
                try {
                    callback();
                } catch (error) {
                    console.warn('Listrak tracking could not be completed.', error.message);
                }
            }
        });
    }

    handleOrder() {
        const payload = { ...this.options.data };
        for (const field of ['taxTotal', 'orderTotal', 'shippingTotal', 'itemTotal']) {
            payload[field] = this.convertToUsd(payload[field]);
        }
        payload.lineItems = this.mapLineItems(payload.lineItems);
        return payload;
    }

    async getCart(generation) {
        try {
            const response = await fetch(this.options.cartUrl, { signal: this._abortController.signal });
            if (!response.ok) {
                throw new Error('Cart request failed.');
            }
            const cart = await response.json();
            if (!this.hasConsent() || generation !== this._generation) {
                return;
            }
            if (cart.lineItems.length) {
                await this.handleCartItems(cart, generation);
            } else {
                this.whenAllowed(() => window._ltk.SCA.ClearCart(), generation);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.warn('Listrak cart tracking could not be completed.', error.message);
            }
        }
    }

    convertToUsd(amount) {
        const value = Number(amount);
        if (!Number.isFinite(value)) {
            throw new Error('Invalid tracking amount.');
        }
        if (String(this.options.data.currencyIsoCode).toUpperCase() === 'USD') {
            return this.round2(value);
        }
        const fromFactor = Number(this.options.data.currencyFactor);
        const usdFactor = Number(this.options.data.usdCurrency?.factor);
        if (!(fromFactor > 0) || !(usdFactor > 0)) {
            throw new Error('Currency conversion factors are missing.');
        }
        return this.round2(value * usdFactor / fromFactor);
    }

    round2(value) {
        return Math.round((Number(value) + Number.EPSILON) * 100) / 100;
    }

    async handleCartItems(cart, generation) {
        const payload = { ...this.options.data, totalPrice: this.convertToUsd(cart.price.totalPrice) };
        const productIds = cart.lineItems.filter(item => item.type === 'product' && item.referencedId)
            .map(item => item.referencedId);
        let urls = {};
        if (productIds.length) {
            const response = await fetch(this.options.productUrl, {
                method: 'POST',
                signal: this._abortController.signal,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids: [...new Set(productIds)] }),
            });
            if (!response.ok) {
                throw new Error('Product URL request failed.');
            }
            ({ urls = {} } = await response.json());
        }
        payload.lineItems = this.mapLineItems(cart.lineItems.map(item => ({
            ...item, productUrl: urls[item.referencedId] || item.productUrl || '',
        })));
        this.whenAllowed(() => new CartData().init(payload), generation);
    }

    mapLineItems(items = []) {
        return items.filter(item => item.type !== 'promotion').map(item => {
            const unitPrice = item.price?.unitPrice ?? 0;
            const price = typeof item.price === 'number' ? item.price
                : Math.max(unitPrice, item.price?.listPrice?.price ?? unitPrice);
            const type = ['product', 'container', 'discount', 'credit'].includes(item.type) ? item.type : 'custom';
            return {
                sku: item.payload?.productNumber || item.sku || `${type.toUpperCase()}_ITEM_${item.id ?? item.referencedId ?? ''}`,
                quantity: item.quantity,
                price: this.convertToUsd(price),
                title: item.label ?? item.name,
                totalPrice: this.convertToUsd(item.price?.totalPrice ?? item.totalPrice ?? 0),
                imageUrl: item.cover?.url ?? item.imageUrl ?? '',
                productUrl: item.productUrl,
            };
        });
    }

    destroy() {
        this._active = false;
        this._generation += 1;
        this._abortController?.abort();
        document.$emitter.unsubscribe(COOKIE_CONFIGURATION_UPDATE, this._onConsentChange);
    }
}
