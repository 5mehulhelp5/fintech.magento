<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zyntrodata\Payments\Model\Payload;
use Zyntrodata\Payments\Model\RequestData;

class ValidateOrder implements ObserverInterface
{
    private Payload $payload;
    private RequestData $requestData;
    public function __construct(Payload $payload, RequestData $requestData)
    {
        $this->payload = $payload;
        $this->requestData = $requestData;
    }
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if ($order->getPayment()->getMethod() === 'zyntrodata') {
            try { $this->requestData->setPayload($this->payload->build($order, $this->requestData->bank())); }
            catch (\Throwable $e) {
                throw new \Magento\Framework\Exception\LocalizedException(__('We could not confirm your payment request. Please check your checkout details or contact the store before trying again.'));
            }
        }
    }
}
