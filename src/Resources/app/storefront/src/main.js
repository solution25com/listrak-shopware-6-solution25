import ListrakTracking from './plugins/listrak-tracking/listrak-tracking';
// import ListrakNewsletterCheckbox from './plugins/listrak-newsletter-checkbox/listrak-newsletter-checkbox';

window.PluginManager.register(
    'ListrakTracking',
    ListrakTracking,
    '[data-listrak-tracking]'
);

window.PluginManager.register(
    'ListrakNewsletterCheckbox',
    () =>
        import(
            './plugins/listrak-newsletter-checkbox/listrak-newsletter-checkbox'
        ),
    '[data-listrak-newsletter-checkbox]'
);

// window.PluginManager.register(
//     'ListrakNewsletterCheckbox',
//     ListrakNewsletterCheckbox,
//     '[data-listrak-newsletter-checkbox]'
// );
