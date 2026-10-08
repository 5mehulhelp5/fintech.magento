<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zyntrodata\Payments\Model\Config;
use Zyntrodata\Payments\Model\Options;

class Availability implements ObserverInterface
{
    private Config $config;
    private Options $options;
    public function __construct(Config $config, Options $options) { $this->config = $config; $this->options = $options; }
    public function execute(Observer $observer)
    {
        $event = $observer->getEvent();
        if ($event->getMethodInstance()->getCode() !== 'zyntrodata') { return; }
        $quote = $event->getQuote();
        try {
            $available = $quote && $this->config->ready((int)$quote->getStoreId())
                && count($this->options->all((int)$quote->getStoreId(), (string)$quote->getQuoteCurrencyCode())) > 0;
        } catch (\Throwable $e) { $available = false; }
        if (!$available) { $event->getResult()->setData('is_available', false); }
    }
}
