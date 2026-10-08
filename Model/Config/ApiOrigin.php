<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Model\Config;

use Magento\Framework\Exception\LocalizedException;

class ApiOrigin extends \Magento\Framework\App\Config\Value
{
    public function beforeSave()
    {
        $value = rtrim(trim((string)$this->getValue()), '/');
        if ($value !== '') {
            $url = parse_url($value);
            if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
                || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query'])
                || isset($url['fragment']) || !empty($url['path'])) {
                throw new LocalizedException(__('Enter an HTTPS API origin without a path, query, or credentials.'));
            }
        }
        $this->setValue($value);
        return parent::beforeSave();
    }
}
