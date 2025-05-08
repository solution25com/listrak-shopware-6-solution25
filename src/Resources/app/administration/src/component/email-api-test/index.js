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
            this.emailApiTest.check(this.pluginConfig).then((res) => {
                if (res.success) {
                    this.isSaveSuccessful = true;
                    this.createNotificationSuccess({
                        title: this.$tc('emailApiTest.title'),
                        message: this.$tc('emailApiTest.success'),
                    });
                } else {
                    this.createNotificationError({
                        title: this.$tc('emailApiTest.title'),
                        message: this.$tc('emailApiTest.error'),
                    });
                }

                this.isLoading = false;
            });
        },
    },
});
