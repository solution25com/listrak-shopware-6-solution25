const ApiService = Shopware.Classes.ApiService;

export default class FullOrderSyncService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'listrak-order-sync') {
        super(httpClient, loginService, apiEndpoint);
    }

    importOrders() {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(`_action/${this.getApiBasePath()}`, '', {
                headers,
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }
}
