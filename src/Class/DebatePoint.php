<?php

namespace Wexample\SymfonyRemoteSenat\Class;

use DateTimeImmutable;

/**
 * One point of a sitting, read from its verbatim report: what was debated, when,
 * and who said what, in order.
 */
final readonly class DebatePoint
{
    /**
     * @param string             $key           stable from one reading of the report to the next
     * @param list<Intervention> $interventions
     */
    public function __construct(
        public string $key,
        public string $title,
        public DateTimeImmutable $date,
        public array $interventions,
    ) {
    }

    public function getLength(): int
    {
        return array_sum(array_map(static fn (Intervention $intervention): int => mb_strlen($intervention->text), $this->interventions));
    }

    /**
     * @return list<string>
     */
    public function getSpeakers(): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (Intervention $intervention): ?string => $intervention->speaker, $this->interventions))));
    }
}
