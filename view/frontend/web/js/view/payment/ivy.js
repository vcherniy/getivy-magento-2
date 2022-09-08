define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'ivy',
                component: 'Esparksinc_IvyPayment/js/view/payment/method-renderer/ivy-method'
            }
        );
        return Component.extend({});
    }
);