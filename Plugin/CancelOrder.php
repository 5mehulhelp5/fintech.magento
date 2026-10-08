<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Plugin;
class CancelOrder
{
    private \Zyntrodata\Payments\Model\Lifecycle $lifecycle;
    public function __construct(\Zyntrodata\Payments\Model\Lifecycle $lifecycle) { $this->lifecycle=$lifecycle; }
    public function beforeCancel(\Magento\Sales\Model\Order $order): void { $this->lifecycle->beforeCancel($order); }
}
