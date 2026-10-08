define([
    'jquery', 'ko', 'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/quote', 'mage/url', 'mage/translate', 'mage/cookies'
], function ($, ko, Component, quote, url, $t) {
    'use strict';
    return Component.extend({
        defaults: {template: 'Zyntrodata_Payments/payment/zyntrodata'},
        initialize: function () {
            this._super();
            this.options = (window.checkoutConfig.payment.zyntrodata || {}).options || [];
            this.payee = ko.observable('');
            this.country = ko.observable('');
            this.confirmation = ko.observable('');
            this.routingNumber = ko.observable('');
            this.accountNumber = ko.observable('');
            this.accountType = ko.observable('CHECKING');
            this.checkNumber = ko.observable('');
            this.instructions = ko.observable(null);
            this.loadingInstructions = ko.observable(false);
            this.instructionError = ko.observable('');
            this.requestSequence = 0;
            this.selected = ko.pureComputed(function () {
                var id = String(this.payee());
                return this.options.find(function (option) { return String(option.payeeId) === id; }) || null;
            }, this);
            this.method = ko.pureComputed(function () { return (this.selected() || {}).paymentMethodCode || ''; }, this);
            this.countries = ko.pureComputed(function () { return (this.selected() || {}).bankTransferCountries || []; }, this);
            this.needsReference = ko.pureComputed(function () { return ['QR', 'BANK_TRANSFER'].indexOf(this.method()) >= 0; }, this);
            this.destinationFields = ko.pureComputed(function () {
                var info = this.instructions(), fields;
                if (!info) { return []; }
                fields = this.method() === 'BANK_TRANSFER' ? {
                    bankName: 'Bank', accountName: 'Account name', accountNumber: 'Account number',
                    routingNumber: 'Routing number', sortCode: 'Sort code', bsbCode: 'BSB code',
                    swiftCode: 'SWIFT code', iban: 'IBAN', instructions: 'Instructions'
                } : {
                    payableToName: 'Payable to', recipientEmail: 'Recipient email', recipientPhone: 'Recipient phone',
                    recipientUsername: 'Recipient username', mailingAddressLine1: 'Mailing address',
                    mailingAddressLine2: 'Address continued', mailingCity: 'City', mailingState: 'State',
                    mailingZipCode: 'Postal code', mailingCountry: 'Country', instructions: 'Instructions'
                };
                info = this.method() === 'BANK_TRANSFER' ? (info.bankTransferInstructions || {}) : info;
                return Object.keys(fields).filter(function (key) { return !!info[key]; }).map(function (key) {
                    return {label: $t(fields[key]), value: String(info[key])};
                });
            }, this);
            this.qrUrl = ko.pureComputed(function () {
                var value = (this.instructions() || {}).qrCodeViewUrl || '';
                return this.method() === 'QR' && /^https:\/\//i.test(value) ? value : '';
            }, this);
            this.payee.subscribe(function () {
                this.country('');
                this.confirmation('');
                this.clearBank();
                this.loadInstructions();
            }, this);
            this.country.subscribe(this.loadInstructions, this);
            if (this.options.length === 1) { this.payee(String(this.options[0].payeeId)); }
            return this;
        },
        optionLabel: function (option) { return option.paymentMethodCode.replace(/_/g, ' ') + ' — ' + option.payeeName; },
        loadInstructions: function () {
            var self = this, sequence = ++this.requestSequence, payeeId = String(this.payee()),
                option = this.options.find(function (item) { return String(item.payeeId) === payeeId; }),
                method = (option || {}).paymentMethodCode;
            // Read the new selection directly: bound computed values can still be updating.
            this.instructions(null);
            this.instructionError('');
            this.loadingInstructions(false);
            if (!option || method === 'ECHECK' || method === 'CREDIT_CARD'
                || (method === 'BANK_TRANSFER' && !this.country())) { return; }
            this.loadingInstructions(true);
            $.ajax({
                url: url.build('zyntrodata/checkout/instructions'),
                type: 'POST', dataType: 'json', timeout: 12000,
                data: {form_key: $.mage.cookies.get('form_key'), payeeId: payeeId, countryCode: this.country()}
            }).done(function (response) {
                if (sequence !== self.requestSequence) { return; }
                if (response.success) { self.instructions(response.data); }
                else { self.instructionError($t('Instructions are unavailable. Refresh checkout or choose another payment method.')); }
            }).fail(function (response) {
                if (sequence === self.requestSequence) { self.instructionError(response && response.status === 429
                    ? $t('Too many instruction requests. Please wait one minute and try again.') : $t('Unable to load payment instructions.')); }
            }).always(function () {
                if (sequence === self.requestSequence) { self.loadingInstructions(false); }
            });
        },
        validate: function () {
            var message = '', billing = quote.billingAddress();
            if (!this.selected()) { message = $t('Choose a payment option.'); }
            else if (!billing || !billing.firstname || !billing.lastname || !billing.telephone) {
                message = $t('Complete your billing name and telephone.');
            } else if (this.method() === 'BANK_TRANSFER' && !this.country()) {
                message = $t('Choose a bank transfer country.');
            } else if (['QR', 'BANK_TRANSFER', 'PHYSICAL_CHECK'].indexOf(this.method()) >= 0 && !this.instructions()) {
                message = $t('Load the payment instructions before placing your order.');
            } else if (this.needsReference() && !this.confirmation().trim()) {
                message = $t('Enter your payment confirmation reference.');
            }
            if (message) { this.messageContainer.addErrorMessage({message: message}); return false; }
            return true;
        },
        getData: function () {
            var data = {
                payee_id: String(this.payee()), country_code: String(this.country()),
                confirmation_reference: this.confirmation()
            };
            return {method: this.getCode(), additional_data: data};
        },
        clearBank: function () {
            this.routingNumber(''); this.accountNumber(''); this.checkNumber('');
        },
        afterPlaceOrder: function () { this.clearBank(); }
    });
});
