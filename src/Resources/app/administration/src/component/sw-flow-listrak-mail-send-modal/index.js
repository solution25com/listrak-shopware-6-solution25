import { email as emailValidation } from '../../service/validation.service';
import template from './sw-flow-listrak-mail-send-modal.html.twig';
import './sw-flow-listrak-mail-send-modal.scss';

const {
    Component,
    Utils,
    Classes: { ShopwareError },
} = Shopware;
const { Criteria } = Shopware.Data;
const { mapState } = Component.getComponentHelper();

Component.register('sw-flow-listrak-mail-send-modal', {
    template,

    inject: ['repositoryFactory'],

    emits: ['modal-close', 'process-finish'],

    props: {
        sequence: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            showRecipientEmails: false,
            mailRecipient: null,
            recipients: [],
            selectedRecipient: null,
            selectedProfileField: null,
            recipientGridError: null,
            transactionalMessageId: null,
            profileFields: [],
            profileFieldsGridError: null,
            transactionalMessageIdError: null,
        };
    },

    computed: {
        isNewMail() {
            return !this.sequence?.id;
        },

        recipientCustomer() {
            return [
                {
                    value: 'default',
                    label: this.$tc('listrakMailSendAction.labelCustomer'),
                },
            ];
        },

        recipientAdmin() {
            return [
                {
                    value: 'admin',
                    label: this.$tc('listrakMailSendAction.labelAdmin'),
                },
            ];
        },

        recipientCustom() {
            return [
                {
                    value: 'custom',
                    label: this.$tc('listrakMailSendAction.labelCustom'),
                },
            ];
        },

        recipientDefault() {
            return [
                {
                    value: 'default',
                    label: this.$tc('listrakMailSendAction.labelDefault'),
                },
            ];
        },

        recipientContactFormMail() {
            return [
                {
                    value: 'contactFormMail',
                    label: this.$tc(
                        'listrakMailSendAction.labelContactFormMail'
                    ),
                },
            ];
        },

        entityAware() {
            return [
                'CustomerAware',
                'UserAware',
                'OrderAware',
                'CustomerGroupAware',
                'CartAware',
            ];
        },

        recipientOptions() {
            const allowedAwareOrigin = this.triggerEvent.aware ?? [];
            const allowAwareConverted = [];
            allowedAwareOrigin.forEach((aware) => {
                aware = aware.slice(aware.lastIndexOf('\\') + 1);
                const awareUpperCase =
                    aware.charAt(0).toUpperCase() + aware.slice(1);
                if (!allowAwareConverted.includes(awareUpperCase)) {
                    allowAwareConverted.push(awareUpperCase);
                }
            });

            if (allowAwareConverted.length === 0) {
                return this.recipientCustom;
            }

            if (this.triggerEvent.name === 'contact_form.send') {
                return [
                    ...this.recipientDefault,
                    ...this.recipientContactFormMail,
                    ...this.recipientAdmin,
                    ...this.recipientCustom,
                ];
            }
            if (
                [
                    'newsletter.confirm',
                    'newsletter.register',
                    'newsletter.unsubscribe',
                ].includes(this.triggerEvent.name)
            ) {
                return [
                    ...this.recipientCustomer,
                    ...this.recipientAdmin,
                    ...this.recipientCustom,
                ];
            }

            const hasEntityAware = allowAwareConverted.some((allowedAware) =>
                this.entityAware.includes(allowedAware)
            );

            if (hasEntityAware) {
                return [
                    ...this.recipientCustomer,
                    ...this.recipientAdmin,
                    ...this.recipientCustom,
                ];
            }

            return [...this.recipientAdmin, ...this.recipientCustom];
        },

        recipientColumns() {
            return [
                {
                    property: 'email',
                    label: 'listrakMailSendAction.columnRecipientMail',
                    inlineEdit: 'string',
                },
                {
                    property: 'name',
                    label: 'listrakMailSendAction.columnRecipientName',
                    inlineEdit: 'string',
                },
            ];
        },

        profileFieldsColumns() {
            return [
                {
                    property: 'field-id',
                    label: 'listrakMailSendAction.columnProfileFieldId',
                    inlineEdit: 'number',
                },
                {
                    property: 'field-value',
                    label: 'listrakMailSendAction.columnProfileFieldValue',
                    inlineEdit: 'string',
                },
            ];
        },

        replyToOptions() {
            if (this.triggerEvent.name === 'contact_form.send') {
                return [
                    ...this.recipientDefault,
                    ...this.recipientContactFormMail,
                    ...this.recipientCustom,
                ];
            }

            return [...this.recipientDefault, ...this.recipientCustom];
        },

        ...mapState('swFlowState', [
            'mailTemplates',
            'triggerEvent',
            'triggerActions',
        ]),
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.transactionalMessageId =
                this.sequence?.config?.transactionalMessageId || null;
            this.mailRecipient = this.recipientOptions[0].value;

            if (!this.isNewMail) {
                const { config } = this.sequence;

                this.mailRecipient = config.recipient?.type;

                if (config.recipient?.type === 'custom') {
                    Object.entries(config.recipient.data).forEach(
                        ([key, value]) => {
                            const newId = Utils.createId();
                            this.recipients.push({
                                id: newId,
                                email: key,
                                name: value,
                                isNew: false,
                            });
                        }
                    );

                    this.addRecipient();
                    this.showRecipientEmails = true;
                }
                if (config?.profileFields) {
                    Object.entries(config.profileFields).forEach(
                        ([id, value]) => {
                            const newId = Utils.createId();
                            this.profileFields.push({
                                id: newId,
                                fieldId: id,
                                fieldValue: value,
                                isNew: false,
                            });
                        }
                    );
                }
            }
            this.addProfileField();
        },

        onClose() {
            this.$emit('modal-close');
        },

        getRecipientData() {
            const recipientData = {};
            if (this.mailRecipient !== 'custom') {
                return recipientData;
            }

            this.recipients.forEach((recipient) => {
                if (!recipient.email && !recipient.name) {
                    return;
                }

                Object.assign(recipientData, {
                    [recipient.email]: recipient.name,
                });
            });
            return recipientData;
        },

        getProfileFieldsData() {
            const profileFieldsData = {};

            this.profileFields.forEach((profileField) => {
                if (!profileField.fieldId && !profileField.fieldValue) {
                    return;
                }

                Object.assign(profileFieldsData, {
                    [profileField.fieldId]: profileField.fieldValue,
                });
            });
            return profileFieldsData;
        },

        isTransactionalMessageIdError() {
            if (!this.transactionalMessageId) {
                this.transactionalMessageIdError = new ShopwareError({
                    code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
                });
                return true;
            }
            console.log(this.transactionalMessageId);
            const isNumeric = /^\d+$/.test(this.transactionalMessageId);
            if (!isNumeric) {
                this.transactionalMessageIdError = new ShopwareError({
                    code: 'INVALID_TRANSACTIONAL_MESSAGE_ID',
                    detail: this.$tc(
                        'listrakMailSendAction.transactionalMessageIdValueInvalid'
                    ),
                });
                return true;
            }
            return false;
        },

        isRecipientGridError() {
            if (this.mailRecipient !== 'custom') {
                return false;
            }

            if (
                this.recipients.length === 1 &&
                !this.recipients[0].email &&
                !this.recipients[0].name
            ) {
                this.validateRecipient(this.recipients[0], 0);
                return true;
            }

            const invalidItemIndex = this.recipients
                .filter((item) => !item.isNew)
                .findIndex(
                    (recipient) =>
                        !recipient.name ||
                        !recipient.email ||
                        !emailValidation(recipient.email)
                );

            if (invalidItemIndex >= 0) {
                this.validateRecipient(
                    this.recipients[invalidItemIndex],
                    invalidItemIndex
                );
            }

            return invalidItemIndex >= 0;
        },
        isProfileFieldsGridError() {
            if (
                this.profileFields.length === 1 &&
                !this.profileFields[0].fieldId &&
                !this.profileFields[0].fieldValue
            ) {
                this.profileFields = [];
                return false;
            }

            const invalidItemIndex = this.profileFields
                .filter((item) => !item.isNew)
                .findIndex(
                    (profileField) =>
                        !profileField.fieldId || !profileField.fieldValue
                );

            if (invalidItemIndex >= 0) {
                this.validateProfileField(
                    this.profileFields[invalidItemIndex],
                    invalidItemIndex
                );
            }

            return invalidItemIndex >= 0;
        },

        onAddAction() {
            this.recipientGridError = this.isRecipientGridError();
            this.profileFieldsGridError = this.isProfileFieldsGridError();
            const transactionalMessageError =
                this.isTransactionalMessageIdError();
            if (
                this.profileFieldsGridError ||
                this.recipientGridError ||
                transactionalMessageError
            ) {
                return;
            }

            this.resetError();

            const sequence = {
                ...this.sequence,
                config: {
                    transactionalMessageId: this.transactionalMessageId,
                    recipient: {
                        type: this.mailRecipient,
                        data: this.getRecipientData(),
                    },
                    profileFields: this.getProfileFieldsData(),
                },
            };

            this.$nextTick(() => {
                this.$emit('process-finish', sequence);
            });
        },

        onChangeRecipient(recipient) {
            if (recipient === 'custom') {
                this.showRecipientEmails = true;
                this.addRecipient();
            } else {
                this.showRecipientEmails = false;
            }
        },

        addRecipient() {
            const newId = Utils.createId();

            this.recipients.push({
                id: newId,
                email: '',
                name: '',
                isNew: true,
            });

            this.$nextTick().then(() => {
                setTimeout(() => {
                    const grid = this.$refs.recipientsGrid;
                    if (grid) {
                        grid.currentInlineEditId = newId;
                        grid.enableInlineEdit();
                    }
                }, 100);
            });
        },
        addProfileField() {
            const newId = Utils.createId();
            this.profileFields.push({
                id: newId,
                fieldId: '',
                fieldValue: '',
                isNew: true,
            });
            this.$nextTick().then(() => {
                setTimeout(() => {
                    const grid = this.$refs.profileFieldsGrid;
                    if (grid) {
                        grid.startInlineEdit = newId;
                        grid.enableInlineEdit();
                    }
                }, 100);
            });
        },

        saveRecipient(recipient) {
            const index = this.recipients.findIndex((item) => {
                return item.id === recipient.id;
            });

            if (this.validateRecipient(recipient, index)) {
                this.$nextTick(() => {
                    this.$refs.recipientsGrid.currentInlineEditId =
                        recipient.id;
                    this.$refs.recipientsGrid.enableInlineEdit();
                });
                return;
            }

            if (recipient.isNew) {
                this.addRecipient();
                this.recipients[index].isNew = false;
            }

            this.resetError();
        },

        saveProfileField(profileField) {
            const index = this.profileFields.findIndex((item) => {
                return item.id === profileField.id;
            });
            if (this.validateProfileField(profileField, index)) {
                this.$nextTick().then(() => {
                    const grid = this.$refs.profileFieldsGrid;
                    if (grid) {
                        grid.currentInlineEditId = profileField.id;
                        grid.enableInlineEdit();
                    }
                });
                return;
            }

            if (profileField.isNew) {
                this.addProfileField();
                this.profileFields[index].isNew = false;
            }

            this.resetError();
        },
        cancelSaveRecipient(recipient) {
            if (!recipient.isNew) {
                const index = this.recipients.findIndex((item) => {
                    return item.id === this.selectedRecipient.id;
                });

                // Reset data when saving is cancelled
                this.recipients[index] = this.selectedRecipient;
            } else {
                recipient.name = '';
                recipient.email = '';
            }

            this.resetError();
        },
        cancelSaveProfileField(profileField) {
            if (!profileField.isNew) {
                const index = this.profileFields.findIndex((item) => {
                    return item.id === this.selectedProfileField.id;
                });

                // Reset data when saving is cancelled
                this.profileFields[index] = this.selectedProfileField;
            } else {
                profileField.fieldId = '';
                profileField.fieldValue = '';
            }

            this.resetError();
        },

        onEditRecipient(item) {
            const index = this.recipients.findIndex((recipient) => {
                return item.id === recipient.id;
            });

            // Recheck error in current item
            if (!item.name && !item.email) {
                if (this.isCompatEnabled('INSTANCE_SET')) {
                    this.$set(this.recipients, index, {
                        ...item,
                        errorName: null,
                    });
                    this.$set(this.recipients, index, {
                        ...item,
                        errorMail: null,
                    });
                } else {
                    this.recipients[index] = { ...item, errorName: null };
                    this.recipients[index] = { ...item, errorMail: null };
                }
            } else {
                this.validateRecipient(item, index);
            }

            this.$refs.recipientsGrid.currentInlineEditId = item.id;
            this.$refs.recipientsGrid.enableInlineEdit();
            this.selectedRecipient = { ...item };
        },

        onDeleteRecipient(itemIndex) {
            this.recipients.splice(itemIndex, 1);
        },

        onEditProfileField(item) {
            const index = this.profileFields.findIndex((profileField) => {
                return item.id === profileField.id;
            });

            // Recheck error in current item
            if (!item.fieldId && !item.fieldValue) {
                if (this.isCompatEnabled('INSTANCE_SET')) {
                    this.$set(this.profileFields, index, {
                        ...item,
                        errorId: null,
                    });

                    this.$set(this.profileFields, index, {
                        ...item,
                        errorValue: null,
                    });
                } else {
                    this.profileFields[index] = { ...item, errorId: null };
                    this.profileFields[index] = { ...item, errorValue: null };
                }
            } else {
                this.validateProfileField(item, index);
            }

            this.$refs.profileFieldsGrid.currentInlineEditId = item.id;
            this.$refs.profileFieldsGrid.enableInlineEdit();
            this.selectedProfileField = { ...item };
        },

        onDeleteProfileField(itemIndex) {
            this.profileFields.splice(itemIndex, 1);
        },

        setNameError(name) {
            const error = !name
                ? new ShopwareError({
                      code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
                  })
                : null;

            return error;
        },

        setMailError(mail) {
            let error = null;

            if (!mail) {
                error = new ShopwareError({
                    code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
                });
            }

            if (!emailValidation(mail)) {
                error = new ShopwareError({
                    code: 'INVALID_MAIL',
                });
            }

            return error;
        },
        setIdError(id) {
            let error = null;

            if (!id) {
                error = new ShopwareError({
                    code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
                });
            }
            const isNumeric = /^\d+$/.test(id);
            if (!isNumeric) {
                error = new ShopwareError({
                    code: 'INVALID_PROFILE_FIELD_ID',
                    detail: this.$tc(
                        'listrakMailSendAction.profileFieldIdValueInvalid'
                    ),
                });
            }

            return error;
        },
        setValueError(value) {
            let error = null;

            if (!value) {
                error = new ShopwareError({
                    code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
                });
            }

            return error;
        },
        validateRecipient(item, itemIndex) {
            const errorName = this.setNameError(item.name);
            const errorMail = this.setMailError(item.email);

            if (this.isCompatEnabled('INSTANCE_SET')) {
                this.$set(this.recipients, itemIndex, {
                    ...item,
                    errorName,
                    errorMail,
                });
            } else {
                this.recipients[itemIndex] = {
                    ...item,
                    errorName,
                    errorMail,
                };
            }

            return errorName || errorMail;
        },

        validateProfileField(item, itemIndex) {
            const errorId = this.setIdError(item.fieldId);
            const errorValue = this.setValueError(item.fieldValue);

            if (this.isCompatEnabled('INSTANCE_SET')) {
                this.$set(this.profileFields, itemIndex, {
                    ...item,
                    errorId,
                    errorValue,
                });
            } else {
                this.profileFields[itemIndex] = {
                    ...item,
                    errorId,
                    errorValue,
                };
            }
            return errorId || errorValue;
        },

        resetError() {
            this.recipientGridError = null;
            this.recipients.forEach((item) => {
                item.errorName = null;
                item.errorMail = null;
            });
            this.profileFieldsGridError = null;
            this.profileFields.forEach((item) => {
                item.errorId = null;
                item.errorValue = null;
            });
            this.transactionalMessageIdError = null;
        },

        allowDeleteRecipient(itemIndex) {
            return itemIndex !== this.recipients.length - 1;
        },
        allowDeleteProfileField(itemIndex) {
            return itemIndex !== this.profileFields.length - 1;
        },
    },
});
