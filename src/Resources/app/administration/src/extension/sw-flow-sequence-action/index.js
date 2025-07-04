import {
    ACTION,
    GROUP,
} from '../../constant/listrak-mail-send-action.constant';

const { Component } = Shopware;

Component.override('sw-flow-sequence-action', {
    computed: {
        modalName() {
            if (this.selectedAction === ACTION.LISTRAK_MAIL_SEND) {
                return 'sw-flow-listrak-mail-send-modal';
            }

            return this.$super('modalName');
        },
        actionDescription() {
            const actionDescriptionList = this.$super('actionDescription');

            return {
                ...actionDescriptionList,
                [ACTION.LISTRAK_MAIL_SEND]: (config) =>
                    this.getListrakMailSendActionDescription(config),
            };
        },
    },

    methods: {
        getListrakMailSendActionDescription(config) {
            const recipient = config.recipient.type;
            return this.$tc(
                `Recipient: ${recipient.charAt(0).toUpperCase() + recipient.slice(1)}`
            );
        },

        getActionTitle(actionName) {
            if (actionName === ACTION.LISTRAK_MAIL_SEND) {
                return {
                    value: actionName,
                    icon: 'regular-envelope',
                    label: this.$tc('listrakMailSendAction.titleSendMail'),
                    group: GROUP,
                };
            }

            return this.$super('getActionTitle', actionName);
        },
    },
});
