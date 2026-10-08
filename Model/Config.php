<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private ScopeConfigInterface $config;
    private EncryptorInterface $encryptor;
    public function __construct(ScopeConfigInterface $config, EncryptorInterface $encryptor)
    {
        $this->config = $config;
        $this->encryptor = $encryptor;
    }
    public function get(string $key, int $storeId): string
    {
        return (string)$this->config->getValue('payment/zyntrodata/' . $key, ScopeInterface::SCOPE_STORE, $storeId);
    }
    public function credentials(int $storeId): array
    {
        return [
            'partnerCode' => trim($this->get('partner_code', $storeId)),
            'secureKey' => $this->encryptor->decrypt($this->get('secure_key', $storeId)),
            'secureIv' => $this->encryptor->decrypt($this->get('secure_iv', $storeId))
        ];
    }
    public function ready(int $storeId): bool
    {
        return $this->get('active', $storeId) === '1' && $this->get('api_url', $storeId) !== ''
            && !in_array('', $this->credentials($storeId), true);
    }
    public static function validHostedReturnUrl(string $value): bool
    {
        if ($value === '' || strlen($value) > 2000 || preg_match('/[\s\\\\]/', $value)) return false;
        $url = parse_url($value);
        if (!$url || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) return false;
        $host = strtolower(trim($url['host'], '[]'));
        $local = $host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1'
            || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && str_starts_with($host, '127.'));
        return ($url['scheme'] ?? '') === 'https' || (($url['scheme'] ?? '') === 'http' && $local);
    }
    public function hostedReturnUrl(\Magento\Sales\Model\Order $order): string
    {
        $url = trim($this->get('hosted_return_url', (int)$order->getStoreId()));
        if ($url === '') {
            $url = $order->getStore()->getUrl('checkout/onepage/success', ['_nosid'=>true, 'zyntrodata_return'=>1]);
        }
        if (!self::validHostedReturnUrl($url)) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Configure a valid Zyntrodata Hosted Payment Return URL. Use HTTPS, or HTTP localhost for development.'));
        }
        return $url;
    }
    public function scope(int $storeId): string
    {
        return hash('sha256', json_encode([$storeId, rtrim($this->get('api_url', $storeId), '/'), $this->credentials($storeId)]));
    }
}
