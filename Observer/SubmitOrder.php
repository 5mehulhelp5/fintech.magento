<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Zyntrodata\Payments\Model\ApiClient;
use Zyntrodata\Payments\Model\RequestData;
use Psr\Log\LoggerInterface;

class SubmitOrder implements ObserverInterface
{
    private ApiClient $client;
    private RequestData $requestData;
    private OrderRepositoryInterface $orders;
    private LockManagerInterface $locks;
    private LoggerInterface $logger;
    private \Zyntrodata\Payments\Model\Polling $polling;
    public function __construct(ApiClient $client, RequestData $requestData, OrderRepositoryInterface $orders, LockManagerInterface $locks, LoggerInterface $logger, \Zyntrodata\Payments\Model\Polling $polling)
    {
        $this->client = $client;
        $this->requestData = $requestData;
        $this->orders = $orders;
        $this->locks = $locks;
        $this->logger = $logger;
        $this->polling = $polling;
    }
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if ($order->getPayment()->getMethod() !== 'zyntrodata') { return; }
        $payload = $this->requestData->takePayload();
        $lock = 'zyntrodata_order_' . $order->getId();
        if (!$this->locks->lock($lock, 0)) { return; }
        try {
            $payment = $order->getPayment();
            \Zyntrodata\Payments\Model\Polling::protect($order);
            if ($payment->getAdditionalInformation('zyntrodata_submission')) { return; }
            if (!$payload) { throw new \RuntimeException('Missing request-scoped payload'); }
            // Persist the attempt before sending: interruptions must never trigger an automatic second payment.
            $payment->setAdditionalInformation('zyntrodata_submission', 'SUBMITTING');
            $this->orders->save($order);
            $result = $this->client->request((int)$order->getStoreId(), 'POST', '/magento/payment-collections', $payload);
            if (empty($result['reference'])) { throw new \RuntimeException('Missing payment reference'); }
            if (!empty($payload['hostedEcheck'])) {
                $hosted = (string)($result['guestPaymentUrl'] ?? '');
                $parts = parse_url($hosted);
                if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) throw new \RuntimeException('Hosted payment URL unavailable');
                $payment->setAdditionalInformation('zyntrodata_hosted_url', $hosted);
            }
            $payment->setAdditionalInformation('zyntrodata_reference', (string)$result['reference']);
            $payment->setAdditionalInformation('zyntrodata_transaction', (string)($result['transactionNo'] ?? ''));
            $payment->setAdditionalInformation('zyntrodata_collection_id', $result['paymentCollectionRequestId'] ?? null);
            $payment->setAdditionalInformation('zyntrodata_remote_status', (string)($result['status'] ?? ''));
            $payment->setAdditionalInformation('zyntrodata_submission', 'SUBMITTED');
            $payment->setAdditionalInformation('zyntrodata_poll_after', time() + 300);
            $this->polling->schedule($order);
            $order->addCommentToStatusHistory(__('Zyntrodata payment submitted. Payment verification is pending.'));
            $this->orders->save($order);
        } catch (\Throwable $e) {
            // Order already exists. Do not throw back into checkout, where a customer might place another order.
            try {
                $order->getPayment()->setAdditionalInformation('zyntrodata_submission', 'RECONCILIATION_REQUIRED');
                $order->addCommentToStatusHistory(__('Zyntrodata submission needs reconciliation. Do not resubmit or fulfil until reviewed in the portal/backoffice.'));
                $this->orders->save($order);
            } catch (\Throwable $saveError) {
                $this->logger->error('Zyntrodata reconciliation flag could not be saved.', ['order_id' => $order->getId()]);
            }
            $this->logger->warning('Zyntrodata submission needs reconciliation.', ['order_id' => $order->getId()]);
        } finally {
            $payload = [];
            $this->locks->unlock($lock);
        }
    }
}
