import './component/full-customer-sync';
import './component/full-order-sync';

import FullCustomerSyncService from './service/full-customer-sync.service';
import FullOrderSyncService from './service/full-order-sync.service';

Shopware.Service().register('fullCustomerSync', () => {
    return new FullCustomerSyncService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService')
    );
});
Shopware.Service().register('fullOrderSync', () => {
    return new FullOrderSyncService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService')
    );
});

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
