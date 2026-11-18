<?php

namespace Wexample\SymfonyRemoteSenat\Tests\Fixtures\Reporter;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Keeps what is dispatched, for a test to read.
 */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
