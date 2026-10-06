<?php

declare(strict_types=1);

namespace Calisero\SymfonySms\Tests\Doubles;

/**
 * An event listener that keeps every event it is given, in order.
 */
final class EventRecorder
{
    /** @var list<object> */
    public array $events = [];

    public function __invoke(object $event): void
    {
        $this->events[] = $event;
    }

    /**
     * The recorded events of a class.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function of(string $class): array
    {
        return array_values(array_filter($this->events, static fn (object $event): bool => $event instanceof $class));
    }

    /**
     * The classes of the recorded events, in order.
     *
     * @return list<class-string>
     */
    public function classes(): array
    {
        return array_map(static fn (object $event): string => $event::class, $this->events);
    }
}
