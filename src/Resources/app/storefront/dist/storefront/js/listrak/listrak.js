(() => {
    'use strict';
    var t = {},
        r = {};
    function n(i) {
        var o = r[i];
        if (void 0 !== o) return o.exports;
        var a = (r[i] = { exports: {} });
        return (t[i](a, a.exports, n), a.exports);
    }
    ((n.m = t),
        (() => {
            n.d = (t, r) => {
                for (var i in r)
                    n.o(r, i) &&
                        !n.o(t, i) &&
                        Object.defineProperty(t, i, {
                            enumerable: !0,
                            get: r[i],
                        });
            };
        })(),
        (() => {
            ((n.f = {}),
                (n.e = (t) =>
                    Promise.all(
                        Object.keys(n.f).reduce((r, i) => (n.f[i](t, r), r), [])
                    )));
        })(),
        (() => {
            n.u = (t) =>
                './js/listrak/listrak.listrak-newsletter-checkbox.8420e7.js';
        })(),
        (() => {
            n.miniCssF = (t) => {};
        })(),
        (() => {
            n.g = (function () {
                if ('object' == typeof globalThis) return globalThis;
                try {
                    return this || Function('return this')();
                } catch (t) {
                    if ('object' == typeof window) return window;
                }
            })();
        })(),
        (() => {
            n.o = (t, r) => Object.prototype.hasOwnProperty.call(t, r);
        })(),
        (() => {
            var t = {};
            n.l = (r, i, o, a) => {
                if (t[r]) {
                    t[r].push(i);
                    return;
                }
                if (void 0 !== o)
                    for (
                        var l,
                            c,
                            s = document.getElementsByTagName('script'),
                            d = 0;
                        d < s.length;
                        d++
                    ) {
                        var u = s[d];
                        if (u.getAttribute('src') == r) {
                            l = u;
                            break;
                        }
                    }
                (l ||
                    ((c = !0),
                    ((l = document.createElement('script')).charset = 'utf-8'),
                    (l.timeout = 120),
                    n.nc && l.setAttribute('nonce', n.nc),
                    (l.src = r)),
                    (t[r] = [i]));
                var p = (n, i) => {
                        ((l.onerror = l.onload = null), clearTimeout(m));
                        var o = t[r];
                        if (
                            (delete t[r],
                            l.parentNode && l.parentNode.removeChild(l),
                            o && o.forEach((t) => t(i)),
                            n)
                        )
                            return n(i);
                    },
                    m = setTimeout(
                        p.bind(null, void 0, { type: 'timeout', target: l }),
                        12e4
                    );
                ((l.onerror = p.bind(null, l.onerror)),
                    (l.onload = p.bind(null, l.onload)),
                    c && document.head.appendChild(l));
            };
        })(),
        (() => {
            n.r = (t) => {
                ('undefined' != typeof Symbol &&
                    Symbol.toStringTag &&
                    Object.defineProperty(t, Symbol.toStringTag, {
                        value: 'Module',
                    }),
                    Object.defineProperty(t, '__esModule', { value: !0 }));
            };
        })(),
        (() => {
            n.g.importScripts && (t = n.g.location + '');
            var t,
                r = n.g.document;
            if (!t && r && (r.currentScript && (t = r.currentScript.src), !t)) {
                var i = r.getElementsByTagName('script');
                if (i.length)
                    for (var o = i.length - 1; o > -1 && !t; ) t = i[o--].src;
            }
            if (!t)
                throw Error(
                    'Automatic publicPath is not supported in this browser'
                );
            ((t = t
                .replace(/#.*$/, '')
                .replace(/\?.*$/, '')
                .replace(/\/[^\/]+$/, '/')),
                (n.p = t + '../../'));
        })(),
        (() => {
            var t = { 74244: 0 };
            n.f.j = (r, i) => {
                var o = n.o(t, r) ? t[r] : void 0;
                if (0 !== o) {
                    if (o) i.push(o[2]);
                    else {
                        var a = new Promise((n, i) => (o = t[r] = [n, i]));
                        i.push((o[2] = a));
                        var l = n.p + n.u(r),
                            c = Error();
                        n.l(
                            l,
                            (i) => {
                                if (
                                    n.o(t, r) &&
                                    (0 !== (o = t[r]) && (t[r] = void 0), o)
                                ) {
                                    var a =
                                            i &&
                                            ('load' === i.type
                                                ? 'missing'
                                                : i.type),
                                        l = i && i.target && i.target.src;
                                    ((c.message =
                                        'Loading chunk ' +
                                        r +
                                        ' failed.\n(' +
                                        a +
                                        ': ' +
                                        l +
                                        ')'),
                                        (c.name = 'ChunkLoadError'),
                                        (c.type = a),
                                        (c.request = l),
                                        o[1](c));
                                }
                            },
                            'chunk-' + r,
                            r
                        );
                    }
                }
            };
            var r = (r, i) => {
                    var o,
                        a,
                        [l, c, s] = i,
                        d = 0;
                    if (l.some((r) => 0 !== t[r])) {
                        for (o in c) n.o(c, o) && (n.m[o] = c[o]);
                        s && s(n);
                    }
                    for (r && r(i); d < l.length; d++)
                        ((a = l[d]),
                            n.o(t, a) && t[a] && t[a][0](),
                            (t[a] = 0));
                },
                i = (self.webpackChunk = self.webpackChunk || []);
            (i.forEach(r.bind(null, 0)),
                (i.push = r.bind(null, i.push.bind(i))));
        })());
    class i {
        static isSupported() {
            return 'undefined' !== document.cookie;
        }
        static setItem(t, r, n) {
            if (null == t)
                throw Error('You must specify a key to set a cookie');
            let i = new Date();
            i.setTime(i.getTime() + 864e5 * n);
            let o = '';
            ('https:' === location.protocol && (o = 'secure'),
                (document.cookie = ''
                    .concat(t, '=')
                    .concat(r, ';expires=')
                    .concat(i.toUTCString(), ';path=/;sameSite=lax;')
                    .concat(o)));
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
    class o {
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
                (_ltk.Order.SetCustomer(t.email, t.firstName, t.lastName),
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
                    _ltk.Order.Submit());
            });
        }
    }
    class a {
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
                (t.email && _ltk.SCA.Update('email', t.email),
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
                    (_ltk.SCA.CartLink = t.cartLink),
                    _ltk.SCA.Submit());
            });
        }
    }
    let { PluginBaseClass: l } = window;
    class c extends l {
        init() {
            let t = this.options.merchantId,
                r = this.options.requiresCookieConsent,
                n = this.options.email,
                l = i.getItem(this.options.listrakTrackingCookie);
            if (t) {
                if (r && !l) return;
                if (
                    ((function (t, r, n) {
                        if ('undefined' == typeof _ltk) {
                            var i = t.createElement('script');
                            ((i.id = 'ltkSDK'),
                                (i.src =
                                    'https://cdn.listrakbi.com/scripts/script.js?m=' +
                                    r +
                                    '&v=' +
                                    n),
                                t.querySelector('head').appendChild(i));
                        }
                    })(document, t, '1'),
                    (function (t) {
                        'undefined' == typeof _ltk
                            ? document.addEventListener
                                ? document.addEventListener(
                                      'ltkAsyncListener',
                                      function () {
                                          _ltk_util.ready(t);
                                      }
                                  )
                                : (((e =
                                      document.documentElement).ltkAsyncProperty =
                                      0),
                                  e.attachEvent(
                                      'onpropertychange',
                                      function (r) {
                                          'ltkAsyncProperty' ==
                                              r.propertyName &&
                                              _ltk_util.ready(t);
                                      }
                                  ))
                            : _ltk_util.ready(t);
                    })(function () {
                        (n && _ltk.SCA.Update('email', n),
                            _ltk.Activity.AddPageBrowse(),
                            _ltk.Activity.Submit());
                    }),
                    this.options.data)
                ) {
                    let t = this.options.data.placement;
                    if ('order' === t) {
                        this._orderData = new o();
                        let t = this.handleOrder();
                        this._orderData.init(t);
                    } else
                        'cart' === t &&
                            ((this._cartData = new a()), this.getCart());
                }
            }
        }
        handleOrder() {
            let t = this.options.data;
            return (
                (t.taxTotal = this.convertToUsd(t.taxTotal)),
                (t.orderTotal = this.convertToUsd(t.orderTotal)),
                (t.shippingTotal = this.convertToUsd(t.shippingTotal)),
                (t.itemTotal = this.convertToUsd(t.itemTotal)),
                (t.lineItems = this.mapLineItems(t.lineItems)),
                t
            );
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
        convertToUsd(t) {
            let r = this.options.data.currencyIsoCode,
                n = this.options.data.usdCurrency.factor;
            return 'USD' === r.toUpperCase()
                ? t
                : Math.round(t * n * 100) / 100;
        }
        async handleCartItems(t) {
            let r = this.options.data;
            r.totalPrice = this.convertToUsd(t.price.totalPrice);
            let n = ((null == t ? void 0 : t.lineItems) || [])
                    .filter((t) => 'product' === t.type && t.referencedId)
                    .map((t) => t.referencedId),
                i = document.querySelector('meta[name="csrf-token"]'),
                o = i ? { 'X-CSRF-Token': i.getAttribute('content') } : {},
                a = await fetch('/listrak/product-url', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        csrfToken: o,
                    },
                    body: JSON.stringify({ ids: [...new Set(n)] }),
                }),
                { urls: l } = await a.json(),
                c = l || {};
            (t.lineItems.forEach((t) => {
                'product' === t.type &&
                    t.referencedId &&
                    (t.productUrl = c[t.referencedId]);
            }),
                (r.lineItems = this.mapLineItems(t.lineItems)),
                this._cartData.init(r));
        }
        mapLineItems(t) {
            let r = [];
            return (
                t
                    .filter((t) => 'promotion' !== t.type)
                    .forEach((t) => {
                        var n, i, o, a, l, c, s, d, u, p;
                        let m =
                            t.price.listPrice &&
                            t.price.listPrice.price > t.price.unitPrice
                                ? t.price.listPrice.price
                                : t.price.unitPrice;
                        m || (m = t.price);
                        let h =
                                (l =
                                    (a =
                                        (n = t.price) === null || void 0 === n
                                            ? void 0
                                            : n.totalPrice) !== null &&
                                    void 0 !== a
                                        ? a
                                        : t.totalPrice) !== null && void 0 !== l
                                    ? l
                                    : 0,
                            k = {
                                sku:
                                    (s =
                                        (c =
                                            (i = t.payload) === null ||
                                            void 0 === i
                                                ? void 0
                                                : i.productNumber) !== null &&
                                        void 0 !== c
                                            ? c
                                            : t.sku) !== null && void 0 !== s
                                        ? s
                                        : '',
                                quantity: t.quantity,
                                price: this.convertToUsd(m),
                                title:
                                    (d = t.label) !== null && void 0 !== d
                                        ? d
                                        : t.name,
                                totalPrice: this.convertToUsd(h),
                                imageUrl:
                                    (p =
                                        (u =
                                            (o = t.cover) === null ||
                                            void 0 === o
                                                ? void 0
                                                : o.url) !== null &&
                                        void 0 !== u
                                            ? u
                                            : t.imageUrl) !== null &&
                                    void 0 !== p
                                        ? p
                                        : '',
                                productUrl: t.productUrl,
                            };
                        r.push(k);
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
    ((c.options = { listrakTrackingCookie: 'listrakTracking' }),
        window.PluginManager.register(
            'ListrakTracking',
            c,
            '[data-listrak-tracking]'
        ),
        window.PluginManager.register(
            'ListrakNewsletterCheckbox',
            () => n.e(84437).then(n.bind(n, 437)),
            '[data-listrak-newsletter-checkbox]'
        ));
})();
