<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Model;

use Magento\Sales\Model\Order;

class Polling
{
    private Config $config;
    public function __construct(Config $config) { $this->config = $config; }
    public function schedule(Order $order): void
    {
        $payment = $order->getPayment();
        $reference = (string)$payment->getAdditionalInformation('zyntrodata_reference');
        $payment->setData('zyntrodata_reference', $reference ?: null);
        $until = (strtotime((string)$order->getCreatedAt() . ' UTC') ?: time()) + 14 * 86400;
        $payment->setData('zyntrodata_poll_until', gmdate('Y-m-d H:i:s', $until));
        $payment->setData('zyntrodata_poll_scope', $this->config->scope((int)$order->getStoreId()));
        $payment->setData('zyntrodata_poll_failures', 0);
        $payment->setData('zyntrodata_next_poll_at', $reference && $until > time() && !self::finished($order)
            ? gmdate('Y-m-d H:i:s', time() + 300) : null);
        if ($reference && $until <= time() && !self::finished($order)) {
            $order->addCommentToStatusHistory(__('Zyntrodata automatic status checks ended after 14 days. Review the portal/backoffice.'));
        }
    }
    public static function terminal(string $status): bool
    {
        // APPROVED/READY_FOR_BILLING are processing milestones, not confirmed settlement.
        return in_array(strtoupper($status), ['VERIFICATION_DECLINED', 'FRAUD_DECLINED', 'CUSTOMER_CANCELLED', 'CANCELLED', 'EXPIRED', 'PAYMENT_REJECTED', 'REFUNDED', 'BANK_RETURNED'], true);
    }
    public static function finished(Order $order): bool
    {
        return in_array($order->getState(), ['complete', 'closed', 'canceled'], true)
            || self::terminal((string)$order->getPayment()->getAdditionalInformation('zyntrodata_remote_status'));
    }
    public function sameScope(Order $order): bool
    {
        return hash_equals((string)$order->getPayment()->getData('zyntrodata_poll_scope'), $this->config->scope((int)$order->getStoreId()));
    }
    public static function protect(Order $order): void
    {
        if ($order->getState() === 'pending_payment' || $order->getStatus() === 'pending_payment') {
            $order->setHoldBeforeState(Order::STATE_NEW)->setHoldBeforeStatus('pending');
            $order->setState(Order::STATE_HOLDED)->setStatus('holded');
            $order->addCommentToStatusHistory(__('Zyntrodata order held pending payment verification. Verify in the portal before unholding and invoicing or cancelling.'));
        }
    }
}
