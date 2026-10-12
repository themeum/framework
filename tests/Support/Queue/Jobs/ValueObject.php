<?php

namespace Framework\Tests\Support\Queue\Jobs;

class ValueObject
{
    public $amount;

    protected $currency;

    public function __construct(int $amount, string $currency)
    {
        $this->amount = $amount;
        $this->currency = $currency;
    }

    public function currency(): string
    {
        return $this->currency;
    }
}
