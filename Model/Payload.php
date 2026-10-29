<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

class Payload
{
    private Options $options;
    private Config $config;
    public function __construct(Options $options, Config $config)
    {
        $this->options = $options;
        $this->config = $config;
    }
    public function build(Order $order, array $bank): array
    {
        $storeId = (int)$order->getStoreId();
        $payment = $order->getPayment();
        $payeeId = (int)$payment->getAdditionalInformation('zyntrodata_payee_id');
        $option = $this->options->selected($storeId, (string)$order->getOrderCurrencyCode(), $payeeId, true);
        $method = $option['paymentMethodCode'];
        $address = $order->getBillingAddress();
        if (!$address) { throw new LocalizedException(__('Enter your billing address.')); }
        $customer = [
            'firstName' => trim((string)$address->getFirstname()),
            'lastName' => trim((string)$address->getLastname()),
            'email' => trim((string)($order->getCustomerEmail() ?: $address->getEmail())),
            'mobileNumber' => trim((string)$address->getTelephone())
        ];
        if (in_array('', $customer, true) || !filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
            throw new LocalizedException(__('Complete your billing name, email, and telephone before placing this order.'));
        }
        $invoice = trim((string)$order->getIncrementId());
        $amount = (float)$order->getGrandTotal();
        if ($invoice === '' || !is_finite($amount) || $amount <= 0) {
            throw new LocalizedException(__('A valid order number and positive total are required.'));
        }
        $storeDomain = strtolower((string)parse_url(
            $order->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB), PHP_URL_HOST
        ));
        $payload = ['payeeId' => $payeeId, 'customer' => $customer, 'invoiceNumber' => $invoice,
            'amount' => $amount, 'storeDomain' => $storeDomain];
        if (in_array($method, ['QR', 'BANK_TRANSFER'], true)) {
            $reference = trim((string)$payment->getAdditionalInformation('zyntrodata_confirmation_reference'));
            if ($reference === '') { throw new LocalizedException(__('Enter your payment confirmation reference.')); }
            $payload['confirmationReference'] = $reference;
        }
        if ($method === 'BANK_TRANSFER') {
            $country = strtoupper((string)$payment->getAdditionalInformation('zyntrodata_country_code'));
            $codes = array_column($option['bankTransferCountries'] ?? [], 'countryCode');
            if (!in_array($country, $codes, true)) {
                throw new LocalizedException(__('Choose an available bank transfer country.'));
            }
            $payload['bankTransferCountryCode'] = $country;
        }
        if ($method === 'ECHECK') {
            $street = $address->getStreet();
            $payload['address'] = [
                'addressLine1' => trim((string)($street[0] ?? '')),
                'addressLine2' => implode(' ', array_slice($street, 1)),
                'country' => (string)$address->getCountryId(),
                'stateProvince' => (string)$address->getRegion(),
                'city' => (string)$address->getCity(),
                'zipCode' => (string)$address->getPostcode()
            ];
            foreach (['addressLine1', 'country', 'stateProvince', 'city', 'zipCode'] as $field) {
                if (trim($payload['address'][$field]) === '') {
                    throw new LocalizedException(__('Complete your billing address for E-Check.'));
                }
            }
            // Magento location IDs do not identify Zyntrodata location rows.
            $payload['hostedEcheck'] = true;
            $payload['returnUrl'] = $this->config->hostedReturnUrl($order);
        }
        if ($method === 'CREDIT_CARD') {
            $payload['address'] = ['country' => strtoupper(trim((string)$address->getCountryId()))];
            if ($payload['address']['country'] !== 'US') {
                throw new LocalizedException(__('Credit Card is available for US customers only.'));
            }
            if (($option['processingProvider'] ?? '') === 'NMI'
                && (strlen('CC-' . $invoice) > 255 || round($amount, 2) != $amount)) {
                throw new LocalizedException(__('The order amount or invoice number is not valid for this card option.'));
            }
        }
        $payment->setAdditionalInformation('zyntrodata_method', $method);
        $payment->setAdditionalInformation('zyntrodata_payee_name', (string)$option['payeeName']);
        return $payload;
    }
}
