<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Block;

class Info extends \Magento\Payment\Block\Info
{
    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $fields = [
            'zyntrodata_payee_name' => 'Payment option',
            'zyntrodata_reference' => 'Payment reference',
            'zyntrodata_transaction' => 'Transaction',
            'zyntrodata_remote_status' => 'Payment status',
            'zyntrodata_submission' => 'Submission status'
        ];
        $data = [];
        foreach ($fields as $key => $label) {
            $value = $this->getInfo()->getAdditionalInformation($key);
            if (is_scalar($value) && (string)$value !== '') { $data[(string)__($label)] = (string)$value; }
        }
        return $transport->addData($data);
    }
}
