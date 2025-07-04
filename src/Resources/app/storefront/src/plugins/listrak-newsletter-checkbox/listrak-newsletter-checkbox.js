const { PluginBaseClass } = window;
import PageLoadingIndicatorUtil from 'src/utility/loading-indicator/page-loading-indicator.util';
import FormSerializeUtil from 'src/utility/form/form-serialize.util';
export default class ListrakNewsletterCheckbox extends PluginBaseClass {
    static options = {
        ajaxContainerSelector: '.js-listrak-newsletter-wrapper',
        newsletterCheckbox: '#listrakNewsletterRegister',
        autoFocus: true,
        focusHandlerKey: 'listrak-auto-submit',
        useAjax: true,
    };

    init() {
        this.formSubmittedByCaptcha = false;

        this._getForm();

        if (!this._form) {
            throw new Error(
                `No form found for the plugin: ${this.constructor.name}`
            );
        }
        if (this.options.useAjax) {
            if (!this.options.ajaxContainerSelector) {
                throw new Error(
                    `[${this.constructor.name}] The option "ajaxContainerSelector" must be given when using ajax.`
                );
            }
        }
        this._registerEvents();
        this._resumeFocusState();
    }

    _registerEvents() {
        const onSubmit = this._onSubmit.bind(this);

        this._form.removeEventListener('change', onSubmit);
        this._form.addEventListener('change', onSubmit);
    }

    _getForm() {
        this._form = document.querySelector('.listrak-newsletter-form');
    }

    _onSubmit(event) {
        event.preventDefault();
        PageLoadingIndicatorUtil.create();

        this.$emitter.publish('beforeSubmit');

        this._saveFocusState(event.target);

        if (!this.formSubmittedByCaptcha) {
            this.sendAjaxFormSubmit();
        }
    }

    sendAjaxFormSubmit() {
        const data = FormSerializeUtil.serialize(this._form);
        const action = this._form.getAttribute('action');

        fetch(action, {
            method: 'POST',
            body: data,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((response) => response.json())
            .then((content) => this._onAfterAjaxSubmit(content));
    }

    _onAfterAjaxSubmit(response) {
        PageLoadingIndicatorUtil.remove();
        const replaceContainer = document.querySelector(
            this.options.ajaxContainerSelector
        );
        const listrakCheckbox = document.querySelector(
            this.options.newsletterCheckbox
        );
        listrakCheckbox.value =
            listrakCheckbox.value === 'subscribe' ? 'unsubscribe' : 'subscribe';
        let html = '';
        response.forEach((msg) => {
            html += msg.alert;
        });

        replaceContainer.innerHTML = html;
        window.PluginManager.initializePlugins();

        this.$emitter.publish('onAfterAjaxSubmit');
    }

    _saveFocusState(element) {
        if (!this.options.autoFocus) {
            return;
        }

        window.focusHandler.saveFocusState(
            this.options.focusHandlerKey,
            `[data-focus-id="${element.dataset.focusId}"]`
        );
    }

    _resumeFocusState() {
        if (!this.options.autoFocus) {
            return;
        }

        window.focusHandler.resumeFocusState(this.options.focusHandlerKey);
    }
}
