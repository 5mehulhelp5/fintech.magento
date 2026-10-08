<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Model;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Exception\LocalizedException;

class ApiClient
{
    private Config $config;
    private CurlFactory $curlFactory;
    private Traffic $traffic;
    public function __construct(Config $config, CurlFactory $curlFactory, Traffic $traffic)
    { $this->config = $config; $this->curlFactory = $curlFactory; $this->traffic = $traffic; }
    public function request(int $storeId, string $method, string $path, ?array $payload = null, bool $fresh = false): array
    {
        if (!$this->config->ready($storeId)) { throw new LocalizedException(__('Zyntrodata is not configured for this store.')); }
        $scope = $this->config->scope($storeId);
        $deadline = microtime(true) + ($method === 'POST' && $path === '/magento/payment-collections' ? 45 : 5);
        if ($this->traffic->read($scope . ':cooldown')) { throw new LocalizedException(__('Zyntrodata payments are temporarily unavailable. Please try again shortly.')); }
        $cachedRead = $method === 'GET' && ($path === '/magento/payment-options' || strpos($path, '/instructions') !== false);
        $key = $scope . ':read:' . $path;
        if ($cachedRead && !$fresh && ($cached = $this->traffic->read($key)) !== null) { return $cached; }
        $work = function () use ($storeId, $method, $path, $payload, $scope, $deadline, $cachedRead, $key, $fresh) {
            if ($cachedRead && !$fresh && ($cached = $this->traffic->read($key)) !== null) { return $cached; }
            $token = $this->token($storeId, $scope, $deadline);
            try {
                $result = $this->send($storeId, $method, $path, $payload, $token, $deadline, $scope);
            } catch (LocalizedException $e) {
                // Read retries only. Never replay a payment creation request.
                if ($e->getCode() !== 401 || $method !== 'GET' || microtime(true) >= $deadline) { throw $e; }
                $token = $this->token($storeId, $scope, $deadline);
                $result = $this->send($storeId, $method, $path, $payload, $token, $deadline, $scope);
            }
            if ($cachedRead) { $this->traffic->write($key, $result, $path === '/magento/payment-options' ? 30 : Traffic::instructionTtl($result)); }
            return $result;
        };
        return $cachedRead ? $this->traffic->locked($key, $work) : $work();
    }
    private function token(int $storeId, string $scope, float $deadline): string
    {
        $key = $scope . ':token';
        if ($cached = $this->traffic->read($key)) { return (string)$cached['token']; }
        return $this->traffic->locked($key, function () use ($storeId, $scope, $deadline, $key) {
            if ($cached = $this->traffic->read($key)) { return (string)$cached['token']; }
            $login = $this->send($storeId, 'POST', '/auth/login', $this->config->credentials($storeId), '', min($deadline, microtime(true) + 5), $scope);
            $token = (string)($login['accessToken'] ?? '');
            if ($token === '') { throw new LocalizedException(__('Zyntrodata authentication failed.')); }
            $expiry = strtotime((string)($login['expiresAt'] ?? '')) ?: time();
            $this->traffic->write($key, ['token' => $token], max(0, min(900, $expiry - time() - 60)));
            return $token;
        });
    }
    private function send(int $storeId, string $method, string $path, ?array $payload, string $token, float $deadline, string $scope): array
    {
        $origin = rtrim(trim($this->config->get('api_url', $storeId)), '/');
        $parts = parse_url($origin);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path'])) {
            throw new LocalizedException(__('Zyntrodata API origin must be HTTPS without a path.'));
        }
        $remaining = (int)(($deadline - microtime(true)) * 1000);
        if ($remaining < 1) { throw new LocalizedException(__('Zyntrodata request timed out. Please try again shortly.')); }
        $client = $this->curlFactory->create();
        $client->setOption(CURLOPT_TIMEOUT_MS, $remaining);
        $client->setOption(CURLOPT_CONNECTTIMEOUT_MS, min(1500, $remaining));
        $client->setOption(CURLOPT_FOLLOWLOCATION, false);
        $client->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $client->setOption(CURLOPT_SSL_VERIFYHOST, 2);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($token !== '') { $headers['Authorization'] = 'Bearer ' . $token; }
        $client->setHeaders($headers);
        try {
            if ($method === 'GET') { $client->get($origin . '/api/integrations/partner' . $path); }
            else { $client->post($origin . '/api/integrations/partner' . $path, json_encode($payload, JSON_THROW_ON_ERROR)); }
            $status = $client->getStatus();
            $body = json_decode($client->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->traffic->write($scope . ':cooldown', [1], 15);
            throw new LocalizedException(__('Zyntrodata could not complete this request. Please contact the store.'));
        }
        if ($status === 401) {
            $this->traffic->remove($scope . ':token');
            if ($path === '/auth/login') { $this->traffic->write($scope . ':cooldown', [1], 15); }
            throw new LocalizedException(__('Zyntrodata authentication failed.'), null, 401);
        }
        if ($status === 429 || $status >= 500) { $this->traffic->write($scope . ':cooldown', [1], 15); }
        if ($path === '/magento/payment-options' && ($body['success'] ?? false) === false) {
            $this->traffic->remove($scope . ':read:' . $path);
            $this->traffic->write($scope . ':cooldown', [1], 15);
            if (($body['message'] ?? '') === 'Magento is not enabled for this merchant.') {
                throw new LocalizedException(__('Magento is not enabled for this merchant. Please contact the store.'));
            }
        }
        if ($status < 200 || $status >= 300 || !is_array($body) || ($body['success'] ?? false) !== true || !is_array($body['data'] ?? null)) {
            throw new LocalizedException(__('Zyntrodata did not accept this request. Please contact the store.'));
        }
        return $body['data'];
    }
}
