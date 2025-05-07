import template from './full-customer-sync.html.twig';
import './../base.scss';

const { Component, Mixin } = Shopware;

Component.register('full-customer-sync', {
    template,

    props: ['label'],
    inject: ['fullCustomerSync'],

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

            return $parent.actualConfigData.null;
        },
    },

    methods: {
        importFinish() {
            this.isImportSuccessful = false;
        },

        importCustomers() {
            this.isLoading = true;
            this.fullCustomerSync.importCustomers().then((res) => {
                if (res.success) {
                    this.isImportSuccessful = true;
                    this.createNotificationSuccess({
                        title: this.$tc('fullCustomerSyncButton.title'),
                        message: this.$tc('fullCustomerSyncButton.success'),
                    });
                } else {
                    this.createNotificationError({
                        title: this.$tc('fullCustomerSyncButton.title'),
                        message: this.$tc('fullCustomerSyncButton.error'),
                    });
                }

                this.isLoading = false;
            });
        },
    },
});
