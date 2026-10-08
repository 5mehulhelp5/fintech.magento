<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Model;

class Method extends \Magento\Payment\Model\Method\AbstractMethod
{
    protected $_code = 'zyntrodata';
    protected $_isInitializeNeeded = true;
    protected $_canUseInternal = false;
    protected $_canUseForMultishipping = false;
    protected $_canRefund = false;
    protected $_canCapture = false;
    protected $_infoBlockType = \Zyntrodata\Payments\Block\Info::class;

    public function initialize($paymentAction, $stateObject)
    {
        $order = $this->getInfoInstance()->getOrder();
        $order->setHoldBeforeState(\Magento\Sales\Model\Order::STATE_NEW);
        $order->setHoldBeforeStatus('pending');
        $stateObject->setState(\Magento\Sales\Model\Order::STATE_HOLDED);
        $stateObject->setStatus('holded');
        $stateObject->setIsNotified(false);
        return $this;
    }
}
