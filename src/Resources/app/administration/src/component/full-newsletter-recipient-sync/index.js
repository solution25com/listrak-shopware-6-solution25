import template from './full-newsletter-recipient-sync.html.twig';
import './../base.scss';

const { Component, Mixin } = Shopware;

Component.register('full-newsletter-recipient-sync', {
    template,
    props: ['label'],
    inject: ['fullNewsletterRecipientSync'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: false,
            isImportSuccessful: false,
        };
    },

    computed: {
        pluginConfig() {
            let $parent = this.$parent;

            while ($parent.actualConfigData === undefined) {
                $parent = $parent.$parent;
            }

            const salesChannelId = $parent.currentSalesChannelId;

            // Fallback: if no sales channel selected, use global.
            return (
                $parent.actualConfigData[salesChannelId] ||
                $parent.actualConfigData.null
            );
        },
    },

    methods: {
        importFinish() {
            this.isImportSuccessful = false;
        },

        importNewsletterRecipients() {
            this.isLoading = true;
            this.fullNewsletterRecipientSync
                .importNewsletterRecipients()
                .then((res) => {
                    if (res.success) {
                        this.isImportSuccessful = true;
                        this.createNotificationSuccess({
                            title: this.$tc(
                                'fullNewsletterRecipientSyncButton.title'
                            ),
                            message: this.$tc(
                                'fullNewsletterRecipientSyncButton.success'
                            ),
                        });
                    } else {
                        this.createNotificationError({
                            title: this.$tc(
                                'fullNewsletterRecipientSyncButton.title'
                            ),
                            message: this.$tc(
                                'fullNewsletterRecipientSyncButton.error'
                            ),
                        });
                    }

                    this.isLoading = false;
                });
        },
    },
});
