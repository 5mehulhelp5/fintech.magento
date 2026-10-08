<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Controller\Webhook;
class Status implements \Magento\Framework\App\Action\HttpPostActionInterface, \Magento\Framework\App\CsrfAwareActionInterface
{
    private \Magento\Framework\App\RequestInterface $request;
    private \Magento\Framework\Controller\Result\JsonFactory $results;
    private \Magento\Sales\Api\OrderRepositoryInterface $orders;
    private \Magento\Framework\Lock\LockManagerInterface $locks;
    private \Zyntrodata\Payments\Model\Lifecycle $lifecycle;
    public function __construct(\Magento\Framework\App\RequestInterface $request, \Magento\Framework\Controller\Result\JsonFactory $results,
        \Magento\Sales\Api\OrderRepositoryInterface $orders, \Magento\Framework\Lock\LockManagerInterface $locks, \Zyntrodata\Payments\Model\Lifecycle $lifecycle)
    { $this->request=$request; $this->results=$results; $this->orders=$orders; $this->locks=$locks; $this->lifecycle=$lifecycle; }
    public function createCsrfValidationException(\Magento\Framework\App\RequestInterface $request): ?\Magento\Framework\App\Request\InvalidRequestException { return null; }
    public function validateForCsrf(\Magento\Framework\App\RequestInterface $request): ?bool { return true; }
    public function execute()
    {
        $result=$this->results->create()->setHeader('Cache-Control','no-store',true);
        try {
            $order=$this->orders->get((int)$this->request->getParam('order'));
            $body=(string)$this->request->getContent();
            if (!$this->lifecycle->authenticate($order,(string)$this->request->getHeader('X-Zyntrodata-Timestamp'),$body,(string)$this->request->getHeader('X-Zyntrodata-Signature')))
                return $result->setHttpResponseCode(401)->setData(['received'=>false]);
            $event=json_decode($body,true);
            if (($event['channel']??'')!=='magento' || ($event['reference']??'')!==$order->getPayment()->getAdditionalInformation('zyntrodata_reference'))
                return $result->setHttpResponseCode(400)->setData(['received'=>false]);
            $lock='zyntrodata_order_'.$order->getId();
            if (!$this->locks->lock($lock,0)) return $result->setHttpResponseCode(503)->setData(['received'=>false]);
            try { $order->load($order->getId()); $this->lifecycle->refresh($order); } finally { $this->locks->unlock($lock); }
            return $result->setData(['received'=>true]);
        } catch (\Throwable $e) { return $result->setHttpResponseCode(503)->setData(['received'=>false]); }
    }
}
