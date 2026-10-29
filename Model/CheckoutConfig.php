<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session;
use Magento\Store\Model\StoreManagerInterface;

class CheckoutConfig implements ConfigProviderInterface
{
    private Options $options;
    private Config $config;
    private Session $session;
    private StoreManagerInterface $stores;
    public function __construct(Options $options, Config $config, Session $session, StoreManagerInterface $stores)
    {
        $this->options = $options;
        $this->config = $config;
        $this->session = $session;
        $this->stores = $stores;
    }
    public function getConfig()
    {
        $storeId = (int)$this->stores->getStore()->getId();
        $options = [];
        $notice = '';
        if ($this->config->ready($storeId)) {
            try {
                $options = $this->options->all($storeId, (string)$this->session->getQuote()->getQuoteCurrencyCode());
                if (!$options) {
                    $notice = (string)__('No payment options are available for this checkout currency.');
                }
            } catch (\Magento\Framework\Exception\LocalizedException $e) {
                $notice = (string)__('This payment option is currently unavailable. Please choose another method or contact the store.');
            } catch (\Throwable $e) {
                $notice = (string)__('Payments are currently unavailable. Please contact the store.');
            }
        }
        return ['payment' => ['zyntrodata' => ['options' => $options, 'notice' => $notice]]];
    }
}
