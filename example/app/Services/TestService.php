<?php

namespace Example\App\Services;

class TestService
{
    protected $value = 0;

    public function value()
    {
        return $this->value;
    }

    public function increase(int $amount = 1)
    {
        $this->value += $amount;
    }

    public function decrease(int $amount = 1)
    {
        $this->value -= $amount;
    }
}
