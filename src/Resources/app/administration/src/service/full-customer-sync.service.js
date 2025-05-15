const ApiService = Shopware.Classes.ApiService;

export default class FullCustomerSyncService extends ApiService {
    constructor(
        httpClient,
        loginService,
        apiEndpoint = 'listrak-customer-sync'
    ) {
        super(httpClient, loginService, apiEndpoint);
    }

    importCustomers() {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(`_action /${this.getApiBasePath()}`, '', {
                headers,
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }
}
