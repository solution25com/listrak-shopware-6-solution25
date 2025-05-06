import template from './full-order-sync.html.twig';
import './../base.scss';


const { Component, Mixin } = Shopware;

Component.register('full-order-sync', {
    template,
    props: ['label'],
    inject: ['fullOrderSync'],

    mixins: [
        Mixin.getByName('notification')
    ],

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
        }
    },

    methods: {
        importFinish() {
            this.isImportSuccessful = false;
        },

        importOrders() {
            this.isLoading = true;
            this.fullOrderSync.importOrders().then((res) => {
                if (res.success) {
                    this.isImportSuccessful = true;
                    this.createNotificationSuccess({
                        title: this.$tc('fullOrderSyncButton.title'),
                        message: this.$tc('fullOrderSyncButton.success')
                    });
                } else {
                    this.createNotificationError({
                        title: this.$tc('fullOrderSyncButton.title'),
                        message: this.$tc('fullOrderSyncButton.error')
                    });
                }

                this.isLoading = false;
            });
        }
    }
})