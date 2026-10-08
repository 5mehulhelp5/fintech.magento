<?php
declare(strict_types=1);
namespace Zyntrodata\Payments\Model;

/** Request-scoped only: never persist bank inputs on quote or order payment. */
class RequestData
{
    private array $bank = [];
    private array $payload = [];
    public function setBank(array $bank): void { $this->bank = $bank; }
    public function bank(): array { return $this->bank; }
    public function setPayload(array $payload): void { $this->payload = $payload; }
    public function takePayload(): array
    {
        $payload = $this->payload;
        $this->payload = [];
        $this->bank = [];
        return $payload;
    }
}
