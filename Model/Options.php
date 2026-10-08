<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Model;

use Magento\Framework\Exception\LocalizedException;

class Options
{
    private ApiClient $client;
    private array $cache = [];
    public function __construct(ApiClient $client)
    {
        $this->client = $client;
    }
    public function all(int $storeId, string $currency, bool $fresh = false): array
    {
        if ($fresh || !isset($this->cache[$storeId])) {
            $this->cache[$storeId] = $this->client->request($storeId, 'GET', '/magento/payment-options', null, $fresh);
        }
        return array_values(array_filter($this->cache[$storeId], function (array $option) use ($currency) {
            $method = $option['paymentMethodCode'] ?? '';
            return in_array($method, ['ECHECK', 'PHYSICAL_CHECK', 'QR', 'BANK_TRANSFER', 'CREDIT_CARD'], true)
                && $currency === ($option['currency'] ?? 'USD');
        }));
    }
    public function selected(int $storeId, string $currency, int $payeeId, bool $fresh = false): array
    {
        foreach ($this->all($storeId, $currency, $fresh) as $option) {
            if ((int)$option['payeeId'] === $payeeId) {
                return $option;
            }
        }
        throw new LocalizedException(__('This payment option is no longer available. Refresh checkout.'));
    }
}
