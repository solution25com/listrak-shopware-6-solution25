import template from './data-api-test.html.twig';

const { Component, Mixin } = Shopware;

Component.register('data-api-test', {
    template,

    props: ['label'],
    inject: ['dataApiTest'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
        };
    },

    computed: {
        pluginConfig() {
            let parent = this.$parent;
            while (parent && parent.actualConfigData === undefined) {
                parent = parent.$parent;
            }
            if (!parent) {
                return {};
            }
            const values = { ...parent.actualConfigData.null };
            const channelValues = parent.actualConfigData[parent.currentSalesChannelId] ?? {};
            Object.entries(channelValues).forEach(([key, value]) => {
                if (value !== null && value !== undefined) {
                    values[key] = value;
                }
            });
            return values;
        },
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        check() {
            this.isLoading = true;
            this.dataApiTest
                .check(this.pluginConfig)
                .then(() => {
                    this.isSaveSuccessful = true;
                    this.createNotificationSuccess({
                        title: this.$tc('dataApiTest.title'),
                        message: this.$tc('dataApiTest.success'),
                    });
                })
                .catch(() => {
                    this.createNotificationError({
                        title: this.$tc('dataApiTest.title'),
                        message: this.$tc('dataApiTest.error'),
                    });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
    },
});
