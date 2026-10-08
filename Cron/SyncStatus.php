<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Cron;

use Zyntrodata\Payments\Model\ApiClient;
use Zyntrodata\Payments\Model\Polling;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

class SyncStatus
{
    private ApiClient $client;
    private CollectionFactory $collections;
    private OrderRepositoryInterface $orders;
    private LockManagerInterface $locks;
    private LoggerInterface $logger;
    private Polling $polling;
    private \Zyntrodata\Payments\Model\Lifecycle $lifecycle;
    public function __construct(ApiClient $client, CollectionFactory $collections, OrderRepositoryInterface $orders, LockManagerInterface $locks, LoggerInterface $logger, Polling $polling, \Zyntrodata\Payments\Model\Lifecycle $lifecycle)
    { $this->client = $client; $this->collections = $collections; $this->orders = $orders; $this->locks = $locks; $this->logger = $logger; $this->polling = $polling; $this->lifecycle=$lifecycle; }
    public function execute(): void
    {
        if (!$this->locks->lock('zyntrodata_status_sync', 0)) { return; }
        $deadline = microtime(true) + 25;
        try {
            $collection = $this->collections->create();
            $collection->getSelect()->join(['zp' => $collection->getTable('sales_order_payment')], 'zp.parent_id = main_table.entity_id', [])
                ->where('zp.method = ?', 'zyntrodata')
                ->where('zp.zyntrodata_next_poll_at <= ?', gmdate('Y-m-d H:i:s'))
                ->order('zp.zyntrodata_next_poll_at ASC');
            $collection->setPageSize(50);
            foreach ($collection as $order) {
                if (microtime(true) > $deadline - 5) { break; }
                $lock = 'zyntrodata_order_' . $order->getId();
                if (!$this->locks->lock($lock, 0)) { continue; }
                try {
                    $payment = $order->getPayment();
                    if (Polling::finished($order)) {
                        $payment->setData('zyntrodata_next_poll_at', null);
                        $this->orders->save($order);
                        continue;
                    }
                    $until = strtotime((string)$payment->getData('zyntrodata_poll_until') . ' UTC');
                    if (!$until || $until <= time() || !$this->polling->sameScope($order)) {
                        $payment->setData('zyntrodata_next_poll_at', null);
                        $order->addCommentToStatusHistory(__('Zyntrodata automatic checks stopped: polling window ended or merchant configuration changed. Review the portal/backoffice.'));
                        $this->orders->save($order);
                        continue;
                    }
                    try {
                        if (!$payment->getAdditionalInformation('zyntrodata_webhook_registered')) {
                            $this->lifecycle->register($order);
                            continue;
                        }
                        $result = $this->client->request((int)$order->getStoreId(), 'GET', '/magento/payment-collections/' . rawurlencode((string)$payment->getData('zyntrodata_reference')));
                        $status = (string)($result['status'] ?? '');
                        if ($status === '') { throw new \RuntimeException('Missing payment status'); }
                        $this->lifecycle->apply($order, $result);
                        if ($status !== (string)$payment->getAdditionalInformation('zyntrodata_remote_status')) {
                            $order->addCommentToStatusHistory(__('Zyntrodata payment status updated: %1. Verify in the portal before fulfilment.', $status));
                        }
                        $payment->setAdditionalInformation('zyntrodata_remote_status', $status);
                        $payment->setAdditionalInformation('zyntrodata_transaction', (string)($result['transactionNo'] ?? ''));
                        $payment->setAdditionalInformation('zyntrodata_client_status', (string)($result['clientStatus'] ?? ''));
                        $payment->setData('zyntrodata_poll_failures', 0);
                        $payment->setData('zyntrodata_next_poll_at', Polling::terminal($status) ? null : gmdate('Y-m-d H:i:s', min($until, time() + 300)));
                    } catch (\Throwable $e) {
                        $failures = (int)$payment->getData('zyntrodata_poll_failures') + 1;
                        $payment->setData('zyntrodata_poll_failures', $failures);
                        $payment->setData('zyntrodata_next_poll_at', $failures >= 8 ? null : gmdate('Y-m-d H:i:s', min($until, time() + min(3600, 900 * (2 ** min(3, $failures - 1))))));
                        if ($failures >= 8) { $order->addCommentToStatusHistory(__('Zyntrodata automatic checks stopped after 8 consecutive failures. Review the portal/backoffice.')); }
                        $this->logger->warning('Zyntrodata status retrieval failed.', ['order_id' => $order->getId()]);
                    }
                    $this->orders->save($order);
                } finally { $this->locks->unlock($lock); }
            }
        } finally { $this->locks->unlock('zyntrodata_status_sync'); }
    }
}
