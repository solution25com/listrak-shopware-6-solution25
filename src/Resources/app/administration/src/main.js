import './component/data-api-test';
import './component/email-api-test';
import './extension/sw-flow-sequence-action';
import './component/sw-flow-listrak-mail-send-modal';

import DataApiTestService from './service/data-api-test.service';
import EmailApiTestService from './service/email-api-test.service';

Shopware.Service().register('dataApiTest', () => {
    return new DataApiTestService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService')
    );
});

Shopware.Service().register('emailApiTest', () => {
    return new EmailApiTestService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService')
    );
});

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
