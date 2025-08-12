import CookieStorage from 'src/helper/storage/cookie-storage.helper';
import OrderData from '../../order-data';
import CartData from '../../cart-data';

const {PluginBaseClass} = window;

export default class ListrakTracking extends PluginBaseClass {
    static options = {
        listrakTrackingCookie: 'listrakTracking',
    };

    init() {
        let merchant = this.options.merchantId;
        let requiresCookieConsent = this.options.requiresCookieConsent;
        let email = this.options.email;
        const listrakCookie = CookieStorage.getItem(
            this.options.listrakTrackingCookie
        );
        if (merchant) {
            if (requiresCookieConsent && !listrakCookie) {
                return;
            }
            (function (d, tid, vid) {
                if (typeof _ltk != 'undefined') {
                    return;
                }
                var js = d.createElement('script');
                js.id = 'ltkSDK';
                js.src =
                    'https://cdn.listrakbi.com/scripts/script.js?m=' +
                    tid +
                    '&v=' +
                    vid;
                d.querySelector('head').appendChild(js);
            })(document, merchant, '1');

            (function (d) {
                if (typeof _ltk == 'undefined') {
                    if (document.addEventListener)
                        document.addEventListener(
                            'ltkAsyncListener',
                            function () {
                                _ltk_util.ready(d);
                            }
                        );
                    else {
                        e = document.documentElement;
                        e.ltkAsyncProperty = 0;
                        e.attachEvent('onpropertychange', function (e) {
                            if (e.propertyName == 'ltkAsyncProperty') {
                                _ltk_util.ready(d);
                            }
                        });
                    }
                } else {
                    _ltk_util.ready(d);
                }
            })(function () {
                if (email) {
                    _ltk.SCA.Update('email', email);
                }
                _ltk.Activity.AddPageBrowse();
                _ltk.Activity.Submit();
            });

            if (this.options.data) {
                const placement = this.options.data.placement;
                if (placement === 'order') {
                    this._orderData = new OrderData();
                    let payload = this.handleOrder();

                    this._orderData.init(payload);
                } else if (placement === 'cart') {
                    this._cartData = new CartData();
                    this.getCart();
                }
            }
        }
    }

    handleOrder() {
        const payload = this.options.data;
        payload.taxTotal = this.convertToUsd(payload.taxTotal);
        payload.orderTotal = this.convertToUsd(payload.orderTotal);
        payload.shippingTotal = this.convertToUsd(payload.shippingTotal);
        payload.itemTotal = this.convertToUsd(payload.itemTotal);
        payload.lineItems = this.mapLineItems(payload.lineItems);
        return payload;
    }

    getCart() {
        fetch('/checkout/cart.json')
            .then((response) => response.json())
            .then((cart) => {
                if (cart.lineItems.length) {
                    this.handleCartItems(cart);
                } else {
                    this.clearCart();
                }
            })
            .catch((error) => {
                console.error('Error fetching cart:', error);
            });
    }

    convertToUsd(amount) {
        let fromCurrencyIso = this.options.data.currencyIsoCode;
        let usdCurrencyFactor = this.options.data.usdCurrency.factor;
        if (fromCurrencyIso.toUpperCase() === 'USD') {
            return amount;
        }
        const amountInUsd = amount * usdCurrencyFactor;

        return Math.round(amountInUsd * 100) / 100;
    }

    async handleCartItems(cart) {
        const payload = this.options.data;
        payload.totalPrice = this.convertToUsd(cart.price.totalPrice);

        const productIds = (cart?.lineItems || [])
            .filter((i) => i.type === 'product' && i.referencedId)
            .map((i) => i.referencedId);

        const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
        const csrfToken = csrfTokenMeta
            ? {'X-CSRF-Token': csrfTokenMeta.getAttribute('content')}
            : {};
        const urlsRes = await fetch('/listrak/product-urls', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', csrfToken},
            body: JSON.stringify({ids: [...new Set(productIds)]}),
        });
        const {urls} = await urlsRes.json();

        const urlById = urls || {};
        cart.lineItems.forEach((i) => {
            if (i.type === 'product' && i.referencedId) {
                i.productUrl = urlById[i.referencedId];
            }
        });
        payload.lineItems = this.mapLineItems(cart.lineItems);
        this._cartData.init(payload);
    }

    mapLineItems(items) {
        const processedLineItems = [];
        items
            .filter((el) => el.type !== 'promotion')
            .forEach((el) => {
                let unitPrice =
                    el.price.listPrice &&
                    el.price.listPrice.price > el.price.unitPrice
                        ? el.price.listPrice.price
                        : el.price.unitPrice;
                if (!unitPrice) {
                    unitPrice = el.price;
                }
                let totalPrice = el.price?.totalPrice ?? el.totalPrice ?? 0;

                const productData = {
                    sku: el.payload?.productNumber ?? el.sku ?? '',
                    quantity: el.quantity,
                    price: this.convertToUsd(unitPrice),
                    title: el.label ?? el.name,
                    totalPrice: this.convertToUsd(totalPrice),
                    imageUrl: el.cover?.url ?? el.imageUrl ?? '',
                    productUrl: el.productUrl,
                };
                processedLineItems.push(productData);
            });
        return processedLineItems;
    }

    getProductUrl(product) {
        return (
            location.protocol + '//' + location.host + '/detail/' + product.id
        );
    }

    clearCart() {
        (function (d) {
            if (typeof _ltk == 'undefined') {
                if (document.addEventListener) {
                    document.addEventListener('ltkAsyncListener', function () {
                        _ltk_util.ready(d);
                    });
                } else {
                    e = document.documentElement;
                    e.ltkAsyncProperty = 0;
                    e.attachEvent('onpropertychange', function (e) {
                        if (e.propertyName == 'ltkAsyncProperty') {
                            _ltk_util.ready(d);
                        }
                    });
                }
            } else {
                _ltk_util.ready(d);
            }
        })(function () {
            _ltk.SCA.ClearCart();
        });
    }

    setEmail(email) {
        (function (d) {
            if (typeof _ltk == 'undefined') {
                if (document.addEventListener) {
                    document.addEventListener('ltkAsyncListener', function () {
                        _ltk_util.ready(d);
                    });
                } else {
                    e = document.documentElement;
                    e.ltkAsyncProperty = 0;
                    e.attachEvent('onpropertychange', function (e) {
                        if (e.propertyName == 'ltkAsyncProperty') {
                            _ltk_util.ready(d);
                        }
                    });
                }
            } else {
                _ltk_util.ready(d);
            }
        })(function () {
            _ltk.SCA.Update('email', email);
        });
    }
}
