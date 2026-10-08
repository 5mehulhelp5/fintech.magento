<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Model\Config;

class HostedReturnUrl extends \Magento\Framework\App\Config\Value
{
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value !== '' && !\Zyntrodata\Payments\Model\Config::validHostedReturnUrl($value)) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Enter an HTTPS return URL without credentials or a fragment. HTTP is allowed only for localhost development.'));
        }
        $this->setValue($value);
        return parent::beforeSave();
    }
}
