import AbandonedCartTracking from './plugins/abandoned-cart-tracking/abandoned-cart-tracking';

window.PluginManager.register(
    'AbandonedCartTracking',
    AbandonedCartTracking,
    '[data-abandoned-cart-tracking]'
);
