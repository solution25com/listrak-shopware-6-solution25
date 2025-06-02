const ApiService = Shopware.Classes.ApiService;

export default class EmailApiTestService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'listrak-email-api') {
        super(httpClient, loginService, apiEndpoint);
    }

    check(values) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(`_action/${this.getApiBasePath()}/test`, values, {
                headers,
            })
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }
}
