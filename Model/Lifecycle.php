<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Model;

class Lifecycle
{
    public static bool $applying = false;
    private ApiClient $api;
    private Config $config;
    private \Magento\Framework\Encryption\EncryptorInterface $encryptor;
    private \Magento\Sales\Api\OrderRepositoryInterface $orders;
    private \Magento\Sales\Model\Service\InvoiceService $invoices;
    private \Magento\Framework\DB\TransactionFactory $transactions;
    private \Magento\Sales\Model\Order\CreditmemoFactory $creditmemos;
    private \Magento\Sales\Model\Service\CreditmemoService $refunds;
    public function __construct(ApiClient $api, Config $config, \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Sales\Api\OrderRepositoryInterface $orders, \Magento\Sales\Model\Service\InvoiceService $invoices,
        \Magento\Framework\DB\TransactionFactory $transactions, \Magento\Sales\Model\Order\CreditmemoFactory $creditmemos,
        \Magento\Sales\Model\Service\CreditmemoService $refunds)
    { $this->api=$api; $this->config=$config; $this->encryptor=$encryptor; $this->orders=$orders;
      $this->invoices=$invoices; $this->transactions=$transactions; $this->creditmemos=$creditmemos; $this->refunds=$refunds; }

    public function register($order): void
    {
        $payment = $order->getPayment();
        if ($payment->getAdditionalInformation('zyntrodata_webhook_registered')) return;
        if (!$payment->getAdditionalInformation('zyntrodata_webhook_secret')) {
            $payment->setAdditionalInformation('zyntrodata_webhook_secret', $this->encryptor->encrypt(bin2hex(random_bytes(32))));
            $payment->setAdditionalInformation('zyntrodata_webhook_scope', $this->config->scope((int)$order->getStoreId()));
            $this->orders->save($order);
        }
        $this->scope($order);
        $url = $order->getStore()->getUrl('zyntrodata/webhook/status', ['_secure'=>true, '_nosid'=>true, 'order'=>(int)$order->getId()]);
        $this->api->request((int)$order->getStoreId(), 'POST', $this->path($order) . '/webhook',
            ['callbackUrl'=>$url, 'signingSecret'=>$this->encryptor->decrypt($payment->getAdditionalInformation('zyntrodata_webhook_secret'))]);
        $payment->setAdditionalInformation('zyntrodata_webhook_registered', true); $this->orders->save($order);
    }
    private function path($order): string
    { return '/magento/payment-collections/' . rawurlencode((string)$order->getPayment()->getAdditionalInformation('zyntrodata_reference')); }
    private function scope($order): void
    {
        if (!hash_equals((string)$order->getPayment()->getAdditionalInformation('zyntrodata_webhook_scope'), $this->config->scope((int)$order->getStoreId())))
            throw new \RuntimeException('Merchant configuration changed. Review the portal.');
    }
    public function authenticate($order, string $timestamp, string $body, string $signature): bool
    {
        if ($order->getPayment()->getMethod() !== 'zyntrodata' || !ctype_digit($timestamp) || abs(time()-(int)$timestamp)>300 || strlen($body)>4096) return false;
        $secret = $this->encryptor->decrypt((string)$order->getPayment()->getAdditionalInformation('zyntrodata_webhook_secret'));
        return strlen($secret)===64 && hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature);
    }
    public function refresh($order): void
    {
        $this->scope($order);
        $result = $this->api->request((int)$order->getStoreId(), 'GET', $this->path($order));
        $this->apply($order, $result);
    }
    public function apply($order, array $result): void
    {
        if (($result['reference'] ?? '') !== $order->getPayment()->getAdditionalInformation('zyntrodata_reference') ||
            ($result['invoiceNumber'] ?? '') !== $order->getIncrementId() || ($result['currency'] ?? '') !== $order->getOrderCurrencyCode() ||
            abs((float)($result['amount'] ?? -1)-(float)$order->getGrandTotal())>0.001) throw new \RuntimeException('Order does not match payment.');
        self::$applying = true;
        try {
            if (!empty($result['paymentConfirmed']) && in_array($order->getState(), ['holded','new','pending_payment'], true)) {
                if ($order->canUnhold()) $order->unhold();
                if ($order->canInvoice() && (float)$order->getTotalPaid() <= 0) {
                    $invoice = $this->invoices->prepareInvoice($order);
                    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
                    $invoice->setTransactionId((string)$result['reference']); $invoice->register();
                    $order->setIsInProcess(true);
                    $order->addCommentToStatusHistory(__('Zyntrodata confirmed payment. Offline invoice recorded; no additional charge issued.'));
                    $this->transactions->create()->addObject($invoice)->addObject($order)->save();
                }
            }
            if (!empty($result['rejected']) && (float)$order->getTotalPaid() <= 0 && !$order->hasInvoices() && !$order->hasShipments()) {
                if ($order->canUnhold()) $order->unhold();
                if ($order->canCancel()) { $order->cancel(); $order->addCommentToStatusHistory(__('Zyntrodata rejected this transaction.')); }
            }
            foreach ($result['refunds'] ?? [] as $refund) {
                $marker = 'zyntrodata-refund-' . (int)$refund['id'];
                if (($refund['status'] ?? '') !== 'REFUND_COMPLETED') {
                    if ($order->getPayment()->getAdditionalInformation($marker) !== $refund['status']) {
                        $order->addCommentToStatusHistory(__('Zyntrodata refund #%1: %2', $refund['id'], $refund['status']));
                        $order->getPayment()->setAdditionalInformation($marker, $refund['status']);
                    }
                    continue;
                }
                $exists = false;
                foreach ($order->getCreditmemosCollection() as $memo) if ($memo->getTransactionId()===$marker) $exists=true;
                if ($exists) continue;
                $amount = (float)($refund['completedAmount'] ?? 0);
                $invoice = null;
                foreach ($order->getInvoiceCollection() as $candidate) {
                    if ($candidate->getState() == \Magento\Sales\Model\Order\Invoice::STATE_PAID &&
                        (float)$candidate->getGrandTotal()-(float)$candidate->getTotalRefunded()+0.001 >= $amount) { $invoice=$candidate; break; }
                }
                if (!$invoice || $amount<=0 || empty($refund['mailedAt']) || $amount>(float)$order->getTotalPaid()-(float)$order->getTotalRefunded()+0.001) {
                    if ($order->getPayment()->getAdditionalInformation($marker)!=='REVIEW') {
                        $order->addCommentToStatusHistory(__('Zyntrodata refund #%1 completed by mailed check. Credit memo requires manual reconciliation.', $refund['id']));
                        $order->getPayment()->setAdditionalInformation($marker, 'REVIEW');
                    }
                    continue;
                }
                $qtys=[]; foreach ($order->getAllItems() as $item) $qtys[$item->getId()]=0;
                $memo=$this->creditmemos->createByInvoice($invoice, ['qtys'=>$qtys,'shipping_amount'=>0,'adjustment_positive'=>$amount]);
                $memo->setTransactionId($marker);
                $memo->addComment(__('Zyntrodata approved refund check mailed. No provider refund or restock requested.'));
                $this->refunds->refund($memo, true);
            }
            $order->getPayment()->setAdditionalInformation('zyntrodata_remote_status', (string)($result['status'] ?? ''));
            $this->orders->save($order);
        } finally { self::$applying=false; }
    }
    public function beforeCancel($order): void
    {
        if (self::$applying || $order->getPayment()->getMethod()!=='zyntrodata' || !$order->getPayment()->getAdditionalInformation('zyntrodata_reference')) return;
        if ((float)$order->getTotalPaid()>0 || $order->hasInvoices() || $order->hasShipments())
            throw new \Magento\Framework\Exception\LocalizedException(__('Use Request Refund in the Zyntrodata portal for a paid or invoiced order.'));
        try {
            $this->scope($order);
            $this->api->request((int)$order->getStoreId(), 'POST', $this->path($order).'/cancel', ['reason'=>'Cancelled by the customer or store administrator.']);
        } catch (\Throwable $e) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Cancellation was not confirmed by Zyntrodata. Review the portal; use Request Refund if payment was received.'));
        }
    }
}
