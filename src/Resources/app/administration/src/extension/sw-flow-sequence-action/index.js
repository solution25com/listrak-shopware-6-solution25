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
    },

    methods: {
        getActionDescriptions(sequence) {
            if (sequence.actionName !== ACTION.LISTRAK_MAIL_SEND) {
                return this.$super('getActionDescriptions', sequence);
            }

            const labels = {
                default: 'labelDefault',
                admin: 'labelAdmin',
                custom: 'labelCustom',
                contactFormMail: 'labelContactFormMail',
            };
            const recipient = sequence.config?.recipient?.type ?? 'default';
            const label = labels[recipient] ?? 'labelDefault';

            return `${this.$t('listrakMailSendAction.labelRecipient')}: ${this.$t(`listrakMailSendAction.${label}`)}`;
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
