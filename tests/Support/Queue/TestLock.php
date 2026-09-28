<?php

namespace Framework\Tests\Support\Queue;

/**
 * An in-memory lock whose held state is shared by name, as a real lock's row would be.
 */
class TestLock
{
    public static array $held = [];

    protected string $name;

    protected bool $owned = false;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function acquire()
    {
        if (!empty(static::$held[$this->name])) {
            return false;
        }

        static::$held[$this->name] = true;
        $this->owned = true;

        return true;
    }

    public function release()
    {
        if (!$this->owned) {
            return false;
        }

        unset(static::$held[$this->name]);
        $this->owned = false;

        return true;
    }
}
