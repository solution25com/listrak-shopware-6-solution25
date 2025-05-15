import ListrakTracking from './plugins/listrak-tracking/listrak-tracking';

window.PluginManager.register(
    'ListrakTracking',
    ListrakTracking,
    '[data-listrak-tracking]'
);
