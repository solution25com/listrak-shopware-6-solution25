export default class CartData {
    init(data) {
        this.send(data);
    }

    send(data) {

        if (data.email) {
            window._ltk.SCA.Update('email', data.email);
        }

        if (data.lineItems && data.lineItems.length) {
            data.lineItems.forEach((lineItem) => {
                window._ltk.SCA.AddItemWithLinks(
                    lineItem.sku,
                    lineItem.quantity,
                    lineItem.price,
                    lineItem.title,
                    lineItem.imageUrl,
                    lineItem.productUrl
                );
            });
        }
        window._ltk.SCA.Total = data.totalPrice;
        window._ltk.SCA.CartLink = data.cartLink;
        window._ltk.SCA.Submit();
    }
}
