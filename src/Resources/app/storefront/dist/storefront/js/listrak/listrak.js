(() => {
    'use strict';
    class t {
        static isSupported() {
            return 'undefined' !== document.cookie;
        }
        static setItem(t, r, n) {
            if (null == t)
                throw Error('You must specify a key to set a cookie');
            let i = new Date();
            i.setTime(i.getTime() + 864e5 * n);
            let o = '';
            'https:' === location.protocol && (o = 'secure'),
                (document.cookie = ''
                    .concat(t, '=')
                    .concat(r, ';expires=')
                    .concat(i.toUTCString(), ';path=/;sameSite=lax;')
                    .concat(o));
        }
        static getItem(t) {
            if (!t) return !1;
            let r = t + '=',
                n = document.cookie.split(';');
            for (let t = 0; t < n.length; t++) {
                let i = n[t];
                for (; ' ' === i.charAt(0); ) i = i.substring(1);
                if (0 === i.indexOf(r)) return i.substring(r.length, i.length);
            }
            return !1;
        }
        static removeItem(t) {
            document.cookie = ''.concat(
                t,
                '= ; expires = Thu, 01 Jan 1970 00:00:00 GMT;path=/'
            );
        }
        static key() {
            return '';
        }
        static clear() {}
    }
    class r {
        init(t) {
            this.send(t);
        }
        send(t) {
            (function (t) {
                'undefined' == typeof _ltk
                    ? document.addEventListener
                        ? document.addEventListener(
                              'ltkAsyncListener',
                              function () {
                                  _ltk_util.ready(t);
                              }
                          )
                        : (((e = document.documentElement).ltkAsyncProperty =
                              0),
                          e.attachEvent('onpropertychange', function (r) {
                              'ltkAsyncProperty' == r.propertyName &&
                                  _ltk_util.ready(t);
                          }))
                    : _ltk_util.ready(t);
            })(function () {
                _ltk.Order.SetCustomer(t.email, t.firstName, t.lastName),
                    (_ltk.Order.OrderNumber = t.orderNumber),
                    (_ltk.Order.ItemTotal = t.itemTotal),
                    (_ltk.Order.ShippingTotal = t.shippingTotal),
                    (_ltk.Order.TaxTotal = t.taxTotal),
                    (_ltk.Order.OrderTotal = t.orderTotal),
                    t.lineItems &&
                        t.lineItems.length &&
                        t.lineItems.forEach((t) => {
                            _ltk.Order.AddItem(t.sku, t.quantity, t.price);
                        }),
                    _ltk.Order.Submit();
            });
        }
    }
    class n {
        init(t) {
            this.send(t);
        }
        send(t) {
            (function (t) {
                'undefined' == typeof _ltk
                    ? document.addEventListener
                        ? document.addEventListener(
                              'ltkAsyncListener',
                              function () {
                                  _ltk_util.ready(t);
                              }
                          )
                        : (((e = document.documentElement).ltkAsyncProperty =
                              0),
                          e.attachEvent('onpropertychange', function (r) {
                              'ltkAsyncProperty' == r.propertyName &&
                                  _ltk_util.ready(t);
                          }))
                    : _ltk_util.ready(t);
            })(function () {
                _ltk.SCA.Update('email', t.email),
                    t.lineItems &&
                        t.lineItems.length &&
                        t.lineItems.forEach((t) => {
                            _ltk.SCA.AddItemWithLinks(
                                t.sku,
                                t.quantity,
                                t.price,
                                t.title,
                                t.imageUrl,
                                t.productUrl
                            );
                        }),
                    (_ltk.SCA.Total = t.totalPrice),
                    _ltk.SCA.Submit();
            });
        }
    }
    let { PluginBaseClass: i } = window;
    class o extends i {
        init() {
            let i = this.options.merchantId;
            if (
                t.getItem(this.options.listrakTrackingCookie) &&
                i &&
                this.options.data
            ) {
                let t = this.options.data.placement;
                'order' === t
                    ? ((this._orderData = new r()),
                      this._orderData.init(this.options.data))
                    : 'cart' === t &&
                      ((this._cartData = new n()), this.getCart());
            }
        }
        getCart() {
            fetch('/checkout/cart.json')
                .then((t) => t.json())
                .then((t) => {
                    t.lineItems.length
                        ? this.handleCartItems(t)
                        : this.clearCart();
                })
                .catch((t) => {
                    console.error('Error fetching cart:', t);
                });
        }
        handleCartItems(t) {
            let r = this.options.data;
            (r.totalPrice = t.price.totalPrice),
                (r.lineItems = this.mapLineItems(t.lineItems)),
                this._cartData.init(r);
        }
        mapLineItems(t) {
            let r = [];
            return (
                t
                    .filter((t) => 'promotion' !== t.type)
                    .forEach((t) => {
                        let n =
                                t.price.listPrice &&
                                t.price.listPrice.price > t.price.unitPrice
                                    ? t.price.listPrice.price
                                    : t.price.unitPrice,
                            i = {
                                sku: t.payload.productNumber || '',
                                quantity: t.quantity,
                                price: n,
                                title: t.label,
                                totalPrice: t.price.totalPrice,
                                imageUrl: t.cover ? t.cover.url : '',
                                productUrl: this.getProductUrl(t),
                            };
                        r.push(i);
                    }),
                r
            );
        }
        getProductUrl(t) {
            return location.protocol + '//' + location.host + '/detail/' + t.id;
        }
        clearCart() {
            (function (t) {
                'undefined' == typeof _ltk
                    ? document.addEventListener
                        ? document.addEventListener(
                              'ltkAsyncListener',
                              function () {
                                  _ltk_util.ready(t);
                              }
                          )
                        : (((e = document.documentElement).ltkAsyncProperty =
                              0),
                          e.attachEvent('onpropertychange', function (r) {
                              'ltkAsyncProperty' == r.propertyName &&
                                  _ltk_util.ready(t);
                          }))
                    : _ltk_util.ready(t);
            })(function () {
                _ltk.SCA.ClearCart();
            });
        }
        setEmail(t) {
            (function (t) {
                'undefined' == typeof _ltk
                    ? document.addEventListener
                        ? document.addEventListener(
                              'ltkAsyncListener',
                              function () {
                                  _ltk_util.ready(t);
                              }
                          )
                        : (((e = document.documentElement).ltkAsyncProperty =
                              0),
                          e.attachEvent('onpropertychange', function (r) {
                              'ltkAsyncProperty' == r.propertyName &&
                                  _ltk_util.ready(t);
                          }))
                    : _ltk_util.ready(t);
            })(function () {
                _ltk.SCA.Update('email', t);
            });
        }
    }
    (o.options = { listrakTrackingCookie: 'listrakTracking' }),
        window.PluginManager.register(
            'ListrakTracking',
            o,
            '[data-listrak-tracking]'
        );
})();
