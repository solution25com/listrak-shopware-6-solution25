import ListrakTracking from './plugins/listrak-tracking/listrak-tracking';

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
