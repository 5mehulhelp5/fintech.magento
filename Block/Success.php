<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Block;

class Success extends \Magento\Framework\View\Element\Template
{
    private \Magento\Checkout\Model\Session $session;
    public function __construct(\Magento\Framework\View\Element\Template\Context $context, \Magento\Checkout\Model\Session $session, array $data = [])
    {
        parent::__construct($context, $data);
        $this->session = $session;
    }
    public function zyntrodataPayment()
    {
        $order = $this->session->getLastRealOrder();
        return $order->getId() && $order->getPayment()->getMethod() === 'zyntrodata' ? $order->getPayment() : null;
    }
}
