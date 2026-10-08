<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Controller\Checkout;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Checkout\Model\Session;
use Zyntrodata\Payments\Model\Options;
use Zyntrodata\Payments\Model\ApiClient;

class Instructions implements HttpPostActionInterface
{
    private RequestInterface $request;
    private JsonFactory $results;
    private Validator $formKey;
    private Session $session;
    private Options $options;
    private ApiClient $client;
    private \Zyntrodata\Payments\Model\Traffic $traffic;
    private \Zyntrodata\Payments\Model\Config $config;
    public function __construct(RequestInterface $request, JsonFactory $results, Validator $formKey, Session $session, Options $options, ApiClient $client, \Zyntrodata\Payments\Model\Traffic $traffic, \Zyntrodata\Payments\Model\Config $config)
    {
        $this->request = $request;
        $this->results = $results;
        $this->formKey = $formKey;
        $this->session = $session;
        $this->options = $options;
        $this->client = $client;
        $this->traffic = $traffic;
        $this->config = $config;
    }
    public function execute()
    {
        $result = $this->results->create()->setHeader('Cache-Control', 'no-store', true);
        if (!$this->formKey->validate($this->request)) {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => (string)__('Refresh checkout and try again.')]);
        }
        try {
            $quote = $this->session->getQuote();
            if (!$quote->getId() || !$quote->getIsActive()) { throw new \RuntimeException('No active checkout'); }
            $storeId = (int)$quote->getStoreId();
            if (!$this->traffic->allow($this->config->scope($storeId), (string)$quote->getId(), (string)$this->request->getServer('REMOTE_ADDR'))) {
                return $result->setHttpResponseCode(429)->setHeader('Retry-After', '60', true)->setData(['success' => false, 'message' => (string)__('Too many instruction requests. Please wait one minute and try again.')]);
            }
            $payeeId = (int)$this->request->getParam('payeeId');
            $option = $this->options->selected($storeId, (string)$quote->getQuoteCurrencyCode(), $payeeId);
            $path = '/magento/payment-options/' . $payeeId . '/instructions';
            if ($option['paymentMethodCode'] === 'BANK_TRANSFER') {
                $country = strtoupper(trim((string)$this->request->getParam('countryCode')));
                if (!in_array($country, array_column($option['bankTransferCountries'] ?? [], 'countryCode'), true)) {
                    throw new \RuntimeException('Country unavailable');
                }
                $path .= '?countryCode=' . rawurlencode($country);
            }
            return $result->setData(['success' => true, 'data' => $this->client->request($storeId, 'GET', $path)]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => (string)__('Payment instructions are unavailable. Refresh checkout or choose another method.')]);
        }
    }
}
