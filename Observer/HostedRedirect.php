<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Observer;

class HostedRedirect implements \Magento\Framework\Event\ObserverInterface
{
    private \Magento\Checkout\Model\Session $session;
    private \Magento\Framework\App\ActionFlag $flags;
    public function __construct(\Magento\Checkout\Model\Session $session, \Magento\Framework\App\ActionFlag $flags)
    { $this->session=$session; $this->flags=$flags; }
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $controller=$observer->getEvent()->getControllerAction();
        if ($controller->getRequest()->getParam('zyntrodata_return')) return;
        $order=$this->session->getLastRealOrder();
        if (!$order->getId() || $order->getState() !== 'holded' || $order->getPayment()->getMethod()!=='zyntrodata' ||
            $this->session->getZyntrodataRedirectedOrder()==$order->getId()) return;
        $url=$order->getPayment()->getAdditionalInformation('zyntrodata_hosted_url');
        if (!$url) return;
        $this->session->setZyntrodataRedirectedOrder($order->getId());
        $this->flags->set('', \Magento\Framework\App\ActionInterface::FLAG_NO_DISPATCH, true);
        $controller->getResponse()->setRedirect($url);
    }
}
