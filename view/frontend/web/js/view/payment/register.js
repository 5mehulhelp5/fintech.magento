define(['uiComponent', 'Magento_Checkout/js/model/payment/renderer-list'], function (Component, rendererList) {
    'use strict';
    rendererList.push({type: 'zyntrodata', component: 'Zyntrodata_Payments/js/view/payment/renderer'});
    return Component.extend({});
});
