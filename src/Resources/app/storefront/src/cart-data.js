export default class CartData {
    init(data) {
        this.send(data);
    }

    send(data) {
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
            if (data.email) {
                _ltk.SCA.Update('email', data.email);
            }

            if (data.lineItems && data.lineItems.length) {
                data.lineItems.forEach((lineItem) => {
                    _ltk.SCA.AddItemWithLinks(
                        lineItem.sku,
                        lineItem.quantity,
                        lineItem.price,
                        lineItem.title,
                        lineItem.imageUrl,
                        lineItem.productUrl
                    );
                });
            }
            _ltk.SCA.Total = data.totalPrice;
            _ltk.SCA.CartLink = data.cartLink;
            _ltk.SCA.Submit();
        });
    }
}
