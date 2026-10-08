define(['uiComponent'], function (Component) {
    'use strict';
    return Component.extend({
        defaults: {template: 'Zyntrodata_Payments/payment/notice'},
        notice: function () {
            return ((window.checkoutConfig.payment || {}).zyntrodata || {}).notice || '';
        }
    });
});
