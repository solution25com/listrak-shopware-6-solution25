export default class OrderData {
    init(data) {
        this.send(data);
    }

    send(data) {

        window._ltk.Order.SetCustomer(data.email, data.firstName, data.lastName);
        window._ltk.Order.OrderNumber = data.orderNumber;
        window._ltk.Order.ItemTotal = data.itemTotal;
        window._ltk.Order.ShippingTotal = data.shippingTotal;
        window._ltk.Order.TaxTotal = data.taxTotal;
        window._ltk.Order.OrderTotal = data.orderTotal;
        if (data.lineItems && data.lineItems.length) {
            data.lineItems.forEach((lineItem) => {
                window._ltk.Order.AddItem(
                    lineItem.sku,
                    lineItem.quantity,
                    lineItem.price
                );
            });
        }

        window._ltk.Order.Submit();
    }
}
