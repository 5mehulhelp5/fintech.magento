<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Zyntrodata\Payments\Model\RequestData;

class AssignData implements ObserverInterface
{
    private RequestData $requestData;
    public function __construct(RequestData $requestData) { $this->requestData = $requestData; }
    public function execute(Observer $observer)
    {
        $data = $observer->getEvent()->getData('data')->getData('additional_data');
        if ($data instanceof \Magento\Framework\DataObject) { $data = $data->getData(); }
        $data = is_array($data) ? $data : [];
        $payment = $observer->getEvent()->getData('payment_model');
        foreach (['payee_id', 'country_code', 'confirmation_reference'] as $key) {
            $value = $data[$key] ?? '';
            $payment->setAdditionalInformation('zyntrodata_' . $key, is_scalar($value) ? trim((string)$value) : '');
        }
        $this->requestData->setBank([]);
    }
}
