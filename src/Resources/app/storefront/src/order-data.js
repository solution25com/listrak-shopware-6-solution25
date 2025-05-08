export default class OrderData {
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
            _ltk.Order.SetCustomer(data.email, data.firstName, data.lastName);
            _ltk.Order.OrderNumber = data.orderNumber;
            _ltk.Order.ItemTotal = data.itemTotal;
            _ltk.Order.ShippingTotal = data.shippingTotal;
            _ltk.Order.TaxTotal = data.taxTotal;
            _ltk.Order.OrderTotal = data.orderTotal;
            if (data.lineItems && data.lineItems.length) {
                data.lineItems.forEach((lineItem) => {
                    _ltk.Order.AddItem(
                        lineItem.sku,
                        lineItem.quantity,
                        lineItem.price
                    );
                });
            }

            _ltk.Order.Submit();
        });
    }
}
