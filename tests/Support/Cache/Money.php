<?php

namespace Framework\Tests\Support\Cache;

/**
 * A plain value object a consumer would list in cache.allowed_classes. It counts constructor calls
 * so a test can prove a cached instance is restored without running it.
 */
class Money
{
    public static int $constructed = 0;

    public $amount;

    protected $currency;

    public function __construct(int $amount, string $currency)
    {
        static::$constructed++;

        $this->amount = $amount;
        $this->currency = $currency;
    }

    public function currency(): string
    {
        return $this->currency;
    }
}
