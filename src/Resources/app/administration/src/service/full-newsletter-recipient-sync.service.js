const ApiService = Shopware.Classes.ApiService;

export default class FullNewsletterRecipientSyncService extends ApiService {
    constructor(
        httpClient,
        loginService,
        apiEndpoint = 'listrak-newsletter-recipient-sync'
    ) {
        super(httpClient, loginService, apiEndpoint);
    }

    importNewsletterRecipients() {
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
