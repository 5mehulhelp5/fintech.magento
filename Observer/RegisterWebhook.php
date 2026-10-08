<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Observer;
class RegisterWebhook implements \Magento\Framework\Event\ObserverInterface
{
    private \Zyntrodata\Payments\Model\Lifecycle $lifecycle;
    private \Psr\Log\LoggerInterface $logger;
    public function __construct(\Zyntrodata\Payments\Model\Lifecycle $lifecycle, \Psr\Log\LoggerInterface $logger)
    { $this->lifecycle=$lifecycle; $this->logger=$logger; }
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $order=$observer->getEvent()->getOrder();
        if ($order->getPayment()->getMethod()!=='zyntrodata' || !$order->getPayment()->getAdditionalInformation('zyntrodata_reference')) return;
        try { $this->lifecycle->register($order); }
        catch (\Throwable $e) { $this->logger->warning('Zyntrodata webhook registration deferred to status cron.', ['order_id'=>$order->getId()]); }
    }
}
