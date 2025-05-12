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
            let $parent = this.$parent;

            while ($parent.actualConfigData === undefined) {
                $parent = $parent.$parent;
            }

            return $parent.actualConfigData.null;
        },
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        check() {
            this.isLoading = true;

            this.dataApiTest.check(this.pluginConfig).then((res) => {
                if (res.success) {
                    this.isSaveSuccessful = true;
                    this.createNotificationSuccess({
                        title: this.$tc('dataApiTest.title'),
                        message: this.$tc('dataApiTest.success'),
                    });
                } else {
                    this.createNotificationError({
                        title: this.$tc('dataApiTest.title'),
                        message: this.$tc('dataApiTest.error'),
                    });
                }

                this.isLoading = false;
            });
        },
    },
});
