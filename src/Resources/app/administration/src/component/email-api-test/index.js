import template from './email-api-test.html.twig';

const { Component, Mixin } = Shopware;

Component.register('email-api-test', {
    template,

    props: ['label'],
    inject: ['emailApiTest'],

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

            this.emailApiTest
                .check(this.pluginConfig)
                .then(() => {
                    this.isSaveSuccessful = true;
                    this.createNotificationSuccess({
                        title: this.$tc('emailApiTest.title'),
                        message: this.$tc('emailApiTest.success'),
                    });
                })
                .catch(() => {
                    this.createNotificationError({
                        title: this.$tc('emailApiTest.title'),
                        message: this.$tc('emailApiTest.error'),
                    });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
    },
});
