import PageLoadingIndicatorUtil from 'src/utility/loading-indicator/page-loading-indicator.util';
import FormSerializeUtil from 'src/utility/form/form-serialize.util';

const { PluginBaseClass } = window;

export default class ListrakNewsletterCheckbox extends PluginBaseClass {
    static options = {
        ajaxContainerSelector: '.js-listrak-newsletter-wrapper',
        newsletterCheckbox: '#listrakNewsletterRegister',
        autoFocus: true,
        focusHandlerKey: 'listrak-auto-submit',
        errorMessage: 'The newsletter setting could not be saved. Please try again.',
    };

    init() {
        this._form = this.el.closest('form');
        this._checkbox = this._form?.querySelector(this.options.newsletterCheckbox);
        if (!this._form || !this._checkbox) {
            throw new Error('The newsletter checkbox requires a form and checkbox.');
        }
        this._subscribed = this._checkbox.checked;
        this._pending = false;
        this._onChange = this._onSubmit.bind(this);
        this._form.addEventListener('change', this._onChange);
        if (this.options.autoFocus) {
            window.focusHandler.resumeFocusState(this.options.focusHandlerKey);
        }
    }

    async _onSubmit(event) {
        if (event.target !== this._checkbox || this._pending) {
            return;
        }
        event.preventDefault();
        this.$emitter.publish('beforeSubmit');
        if (this.options.autoFocus && this._checkbox.dataset.focusId) {
            window.focusHandler.saveFocusState(this.options.focusHandlerKey,
                `[data-focus-id="${this._checkbox.dataset.focusId}"]`);
        }
        return this.sendAjaxFormSubmit();
    }

    async sendAjaxFormSubmit() {
        const desired = this._checkbox.checked;
        const data = FormSerializeUtil.serialize(this._form);
        // Unchecked checkboxes are omitted by FormData; always send the intended action.
        data.set('option', desired ? 'subscribe' : 'unsubscribe');
        this._pending = true;
        this._checkbox.disabled = true;
        this._abortController = new AbortController();
        PageLoadingIndicatorUtil.create();
        try {
            const response = await fetch(this._form.getAttribute('action'), {
                method: 'POST', body: data,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: this._abortController.signal,
            });
            if (!response.ok) {
                throw new Error('Newsletter request failed.');
            }
            const messages = await response.json();
            if (!Array.isArray(messages)) {
                throw new Error('Unexpected newsletter response.');
            }
            if (messages.some(message => message.type === 'success')) {
                this._subscribed = desired;
            }
            const container = this._form.querySelector(this.options.ajaxContainerSelector);
            if (container) {
                container.innerHTML = messages.map(message => message.alert).join('');
            }
            this.$emitter.publish('onAfterAjaxSubmit');
        } catch (error) {
            const container = this._form.querySelector(this.options.ajaxContainerSelector);
            if (container && error.name !== 'AbortError') {
                container.textContent = this.options.errorMessage;
            }
        } finally {
            this._checkbox.checked = this._subscribed;
            this._checkbox.value = this._subscribed ? 'unsubscribe' : 'subscribe';
            this._checkbox.disabled = false;
            this._pending = false;
            PageLoadingIndicatorUtil.remove();
        }
    }

    destroy() {
        this._form.removeEventListener('change', this._onChange);
        this._abortController?.abort();
    }
}
