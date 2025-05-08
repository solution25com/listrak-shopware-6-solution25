import CookieStorage from 'src/helper/storage/cookie-storage.helper';
import OrderData from '../../order-data';
import CartData from '../../cart-data';
const { PluginBaseClass } = window;

export default class AbandonedCartTracking extends PluginBaseClass {
    static options = {
        listrakTrackingCookie: 'listrakCartAbandonmentTracking',
    };

    init() {
        let merchant = this.options.merchantId;
        const listrakCookie = CookieStorage.getItem(
            this.options.listrakTrackingCookie
        );
        if (listrakCookie && merchant) {
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

            this._orderData = new OrderData();
            this._cartData = new CartData();

            if (this.options.data) {
                const orderCompleted = this.options.data.orderCompleted;
                if (orderCompleted) {
                    this._orderData.init(this.options.data);
                } else {
                    this.getCart();
                }
            }
        }
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

    handleCartItems(cart) {
        const payload = this.options.data;
        payload.totalPrice = cart.price.totalPrice;
        payload.lineItems = this.mapLineItems(cart.lineItems);
        this._cartData.init(payload);
    }

    mapLineItems(items) {
        const processedLineItems = [];
        items
            .filter((el) => el.type !== 'promotion')
            .forEach((el) => {
                const unitPrice =
                    el.price.listPrice &&
                    el.price.listPrice.price > el.price.unitPrice
                        ? el.price.listPrice.price
                        : el.price.unitPrice;

                const productData = {
                    sku: el.payload.productNumber || '',
                    quantity: el.quantity,
                    price: unitPrice,
                    title: el.label,
                    totalPrice: el.price.totalPrice,
                    imageUrl: el.cover ? el.cover.url : '',
                    productUrl: this.getProductUrl(el),
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
}
