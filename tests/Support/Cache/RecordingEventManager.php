<?php

namespace Framework\Tests\Support\Cache;

/**
 * Stands in for the event manager so a test can see what the cache dispatched.
 *
 * Only the two methods the cache calls are implemented: whether anything is listening, and the
 * dispatch itself.
 */
class RecordingEventManager
{
    public array $listening = [];

    public array $dispatched = [];

    public function listen_for(string $event_class): self
    {
        $this->listening[] = $event_class;

        return $this;
    }

    public function has_listeners(string $event_class): bool
    {
        return in_array($event_class, $this->listening, true);
    }

    public function dispatch($event): array
    {
        $this->dispatched[] = $event;

        return [];
    }

    public function dispatched_classes(): array
    {
        return array_map('get_class', $this->dispatched);
    }
}
