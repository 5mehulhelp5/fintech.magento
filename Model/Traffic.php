<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Exception\LocalizedException;

class Traffic
{
    private CacheInterface $cache;
    private EncryptorInterface $encryption;
    private LockManagerInterface $locks;
    public function __construct(CacheInterface $cache, EncryptorInterface $encryption, LockManagerInterface $locks)
    { $this->cache = $cache; $this->encryption = $encryption; $this->locks = $locks; }
    private function key(string $key): string { return 'zyntrodata_' . hash('sha256', $key); }
    public function read(string $key): ?array
    {
        $raw = $this->cache->load($this->key($key));
        if (!$raw) { return null; }
        try {
            $value = json_decode($this->encryption->decrypt($raw), true, 512, JSON_THROW_ON_ERROR);
            return is_array($value) && ($value['until'] ?? 0) > time() ? $value['data'] : null;
        } catch (\Throwable $e) { $this->remove($key); return null; }
    }
    public function write(string $key, array $data, int $ttl): void
    {
        if ($ttl > 0) {
            $this->cache->save($this->encryption->encrypt(json_encode(['until' => time() + $ttl, 'data' => $data], JSON_THROW_ON_ERROR)), $this->key($key), ['ZYNTRODATA'], $ttl);
        }
    }
    public function remove(string $key): void { $this->cache->remove($this->key($key)); }
    public function locked(string $key, callable $work)
    {
        $lock = 'zyntrodata_' . substr(hash('sha256', $key), 0, 48);
        if (!$this->locks->lock($lock, 0)) { throw new LocalizedException(__('Zyntrodata is busy. Please try again shortly.')); }
        try { return $work(); } finally { $this->locks->unlock($lock); }
    }
    public function allow(string $scope, string $quote, string $ip): bool
    {
        return $this->locked('rate:' . $scope, function () use ($scope, $quote, $ip) {
            foreach (['quote:' . $quote => 20, 'ip:' . $ip => 60, 'merchant' => 300] as $bucket => $limit) {
                $key = 'rate:' . $scope . ':' . $bucket . ':' . intdiv(time(), 60);
                $count = (int)($this->read($key)['count'] ?? 0);
                if ($count >= $limit) { return false; }
                $this->write($key, ['count' => $count + 1], 65);
            }
            return true;
        });
    }
    public static function instructionTtl(array $data): int
    {
        if (empty($data['qrCodeViewUrl'])) { return 30; }
        parse_str((string)parse_url($data['qrCodeViewUrl'], PHP_URL_QUERY), $query);
        $parts = explode('.', (string)($query['token'] ?? ''));
        $claims = count($parts) === 3 ? json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
        // Decode expiry only to shorten caching; this is not token authentication.
        return max(0, min(30, (int)($claims['exp'] ?? 0) - time() - 15));
    }
}
