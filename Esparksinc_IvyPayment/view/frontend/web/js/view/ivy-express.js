define([
    'jquery',
    'Magento_Ui/js/form/form',
    'Magento_Checkout/js/model/totals',
    'mage/url'
], function($, Component, totals, url) {
    'use strict';
    return Component.extend({
        initialize: function() {
            this._super();
            // component initialization logic
            return this;
        },

        getTotal: function() {
            return totals.totals().base_grand_total;
        },

        getCurrency: function() {
            return totals.totals().quote_currency_code;
        },

        getMcc: function() {
            return window.checkoutConfig.mcc;
        },

        getLocale: function() {
            return window.checkoutConfig.locale;
        },

        isActive: function() {
            return window.checkoutConfig.is_active;
        },

        /**
         * Form submit handler
         *
         * This method can have any name.
         */
        onSubmit: function() {
            var linkUrl = url.build('ivypayment/checkout/index/express/1');
            $.ajax({
                url: linkUrl,
                type: 'POST',
                success: function(response) {
                    window.startIvyCheckout(response.redirectUrl, 'popup')
                },
                error: function(xhr, status, errorThrown) {
                    messageList.addErrorMessage({ message: errorThrown });
                }
            });
        }
    });
});