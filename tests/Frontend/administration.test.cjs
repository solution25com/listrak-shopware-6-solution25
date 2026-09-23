const { test, afterEach } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<!doctype html><html><body></body></html>');
for (const key of ['window', 'document', 'Element', 'HTMLElement', 'SVGElement', 'Node']) global[key] = dom.window[key];
const { mount } = require('@vue/test-utils');
const { defineComponent, h, nextTick } = require('vue');
const { mapState, createPinia, setActivePinia, defineStore } = require('pinia');
const ts = require('typescript');
const root = path.resolve(__dirname, '../..');
const admin = path.join(root, 'src/Resources/app/administration/src');
const shopwareRoot = process.env.SHOPWARE_ROOT || path.resolve(root, '../../..');
const wrappers = [];
afterEach(() => { for (const wrapper of wrappers.splice(0)) wrapper.unmount(); });

function modal(sequence = { config: {} }, event = { name: 'checkout.order.placed', aware: ['CustomerAware'] }) {
    const pinia = createPinia();
    setActivePinia(pinia);
    const store = defineStore('swFlow', { state: () => ({ triggerEvent: event, triggerActions: [], mailTemplates: [] }) })(pinia);
    let config;
    let id = 0;
    const sandbox = {
        template: '', emailValidation: email => /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email),
        Shopware: { Store: { get: () => store }, Utils: { createId: () => `row-${++id}` },
            Classes: { ShopwareError: class extends Error { constructor(data) { super(data.code); Object.assign(this, data); } } },
            Component: { getComponentHelper: () => ({ mapState }), register: (name, value) => { config = value; } } },
    };
    vm.runInNewContext(fs.readFileSync(path.join(admin, 'component/sw-flow-listrak-mail-send-modal/index.js'), 'utf8').replace(/^import[\s\S]*?;\s*$/gm, ''), sandbox);
    const wrapper = mount({ ...config, render: () => h('div') }, {
        props: { sequence }, global: { plugins: [pinia], provide: { repositoryFactory: {} }, mocks: { $tc: key => key, $t: key => key } },
    });
    wrappers.push(wrapper);
    return wrapper;
}

test('Pinia exposes the selected trigger and initializes a new action under native Vue 3', () => {
    const wrapper = modal();
    assert.equal(wrapper.vm.triggerEvent.name, 'checkout.order.placed');
    assert.equal(wrapper.vm.mailRecipient, 'default');
    assert.equal(wrapper.vm.profileFields.length, 1);
    assert.equal(wrapper.vm.isCompatEnabled, undefined);
    assert.equal(wrapper.vm.$set, undefined);
});

test('editing a saved action preserves recipients, template fields, and unrelated configuration', async () => {
    const wrapper = modal({ id: 'existing', config: { customFlag: true, transactionalMessageId: 123,
        recipient: { type: 'custom', data: { 'alice@example.com': 'Alice' } }, profileFields: { 42: '{{ customer.firstName }}' } } });
    wrapper.vm.recipients[0].name = 'Updated Alice';
    wrapper.vm.profileFields[0].fieldValue = '{{ customer.lastName }}';
    wrapper.vm.onAddAction();
    await nextTick();
    const result = wrapper.emitted('process-finish')[0][0];
    assert.equal(result.config.customFlag, true);
    assert.equal(result.config.recipient.data['alice@example.com'], 'Updated Alice');
    assert.equal(result.config.profileFields[42], '{{ customer.lastName }}');
    assert.equal(Object.keys(result.config.profileFields).length, 1);
});

test('invalid and partially entered new rows block saving and carry reactive validation errors', async () => {
    const wrapper = modal({}, { name: 'custom.trigger', aware: [] });
    wrapper.vm.transactionalMessageId = 123;
    wrapper.vm.recipients[0].email = 'alice@example.com';
    wrapper.vm.profileFields[0].fieldValue = 'missing ID';
    wrapper.vm.onAddAction();
    await nextTick();
    assert.equal(wrapper.emitted('process-finish'), undefined);
    assert.ok(wrapper.vm.recipients[0].errorName);
    assert.ok(wrapper.vm.profileFields[0].errorId);
    wrapper.vm.recipients[0].name = 'Alice';
    wrapper.vm.profileFields[0].fieldId = 42;
    wrapper.vm.onAddAction();
    await nextTick();
    assert.equal(wrapper.emitted('process-finish').length, 1);
});

test('cancel and edit do not call removed Vue compatibility APIs', async () => {
    const wrapper = modal();
    wrapper.vm.recipients = [{ id: 'r', email: 'alice@example.com', name: 'Alice', isNew: false }];
    wrapper.vm.selectedRecipient = { ...wrapper.vm.recipients[0] };
    wrapper.vm.recipients[0].name = 'Changed';
    wrapper.vm.cancelSaveRecipient(wrapper.vm.recipients[0]);
    assert.equal(wrapper.vm.recipients[0].name, 'Alice');
    wrapper.vm.profileFields = [{ id: 'p', fieldId: 42, fieldValue: 'old', isNew: false }];
    wrapper.vm.selectedProfileField = { ...wrapper.vm.profileFields[0] };
    wrapper.vm.profileFields[0].fieldValue = 'new';
    wrapper.vm.cancelSaveProfileField(wrapper.vm.profileFields[0]);
    assert.equal(wrapper.vm.profileFields[0].fieldValue, 'old');
    wrapper.vm.validateRecipient(wrapper.vm.recipients[0], 0);
    wrapper.vm.validateProfileField(wrapper.vm.profileFields[0], 0);
    await nextTick();
    assert.equal(wrapper.vm.recipients[0].errorMail, null);
    assert.equal(wrapper.vm.profileFields[0].errorValue, null);
});

test('the plugin textarea binding receives the actual Shopware 6.7 model event', async () => {
    const source = fs.readFileSync(path.join(shopwareRoot, 'vendor/shopware/administration/Resources/app/administration/src/app/component/form/sw-textarea-field/index.ts'), 'utf8');
    const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
    const exported = {};
    vm.runInNewContext(output, { exports: exported, require: () => ({ default: '' }), Shopware: { Component: { wrapComponentConfig: config => config } } });
    const setter = exported.default.computed.realValue.set;
    const twig = fs.readFileSync(path.join(admin, 'component/sw-flow-listrak-mail-send-modal/sw-flow-listrak-mail-send-modal.html.twig'), 'utf8');
    const binding = twig.match(/<sw-textarea-field[\s\S]*?\s(v-model(?::[\w-]+)?)="item.fieldValue"/)[1];
    const Textarea = defineComponent({ props: ['modelValue', 'value'], emits: ['update:modelValue', 'update:value'],
        setup(props, { emit }) { return () => h('textarea', { value: props.modelValue ?? props.value, onInput: event => setter.call({ $emit: emit }, event.target.value) }); } });
    const wrapper = mount({ components: { SwTextareaField: Textarea }, data: () => ({ item: { fieldValue: 'original' } }),
        template: `<sw-textarea-field ${binding}="item.fieldValue" />` });
    wrappers.push(wrapper);
    await wrapper.find('textarea').setValue('edited {{ customer.firstName }}');
    assert.equal(wrapper.vm.item.fieldValue, 'edited {{ customer.firstName }}');
});

test('flow description delegates other actions and describes Listrak recipients', () => {
    let config;
    vm.runInNewContext(fs.readFileSync(path.join(admin, 'extension/sw-flow-sequence-action/index.js'), 'utf8').replace(/^import[\s\S]*?;\s*$/gm, ''), {
        ACTION: { LISTRAK_MAIL_SEND: 'action.listrak.mail.send' }, Shopware: { Component: { override: (name, value) => { config = value; } } },
    });
    const context = { $t: key => key, $tc: key => key, $super: () => 'core description' };
    assert.equal(config.methods.getActionDescriptions.call(context, { actionName: 'other' }), 'core description');
    assert.match(config.methods.getActionDescriptions.call(context, { actionName: 'action.listrak.mail.send', config: { recipient: { type: 'custom' } } }), /labelCustom/);
});

test('cancel restores rows edited through the grid double-click path, including after a save', () => {
    const wrapper = modal({ id: 'existing', config: { transactionalMessageId: 123,
        recipient: { type: 'custom', data: { 'alice@example.com': 'Alice' } }, profileFields: { 42: 'original' } } });
    wrapper.vm.recipients[0].name = 'Unsaved';
    wrapper.vm.cancelSaveRecipient(wrapper.vm.recipients[0]);
    assert.equal(wrapper.vm.recipients[0].name, 'Alice');
    wrapper.vm.profileFields[0].fieldValue = 'Unsaved';
    wrapper.vm.cancelSaveProfileField(wrapper.vm.profileFields[0]);
    assert.equal(wrapper.vm.profileFields[0].fieldValue, 'original');
    wrapper.vm.profileFields[0].fieldValue = 'Saved';
    wrapper.vm.saveProfileField(wrapper.vm.profileFields[0]);
    wrapper.vm.profileFields[0].fieldValue = 'Another unsaved edit';
    wrapper.vm.cancelSaveProfileField(wrapper.vm.profileFields[0]);
    assert.equal(wrapper.vm.profileFields[0].fieldValue, 'Saved');
});

test('API test buttons merge inherited credentials and tolerate a missing config parent', () => {
    for (const name of ['data-api-test', 'email-api-test']) {
        let config;
        vm.runInNewContext(fs.readFileSync(path.join(admin, `component/${name}/index.js`), 'utf8').replace(/^import.*;$/gm, ''), {
            template: '', Shopware: { Component: { register: (key, value) => { config = value; } }, Mixin: { getByName: () => ({}) } },
        });
        const parent = { currentSalesChannelId: 'channel', actualConfigData: {
            null: { clientId: 'global-id', clientSecret: 'global-secret' }, channel: { clientId: 'channel-id', clientSecret: null },
        } };
        const result = config.computed.pluginConfig.call({ $parent: { $parent: parent } });
        assert.equal(result.clientId, 'channel-id');
        assert.equal(result.clientSecret, 'global-secret');
        assert.deepEqual(Object.keys(config.computed.pluginConfig.call({ $parent: null })), []);
    }
});
