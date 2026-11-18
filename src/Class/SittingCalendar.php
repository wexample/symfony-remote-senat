<?php

namespace Wexample\SymfonyRemoteSenat\Class;

/**
 * The days the Sénat sat, as its base of the debates (`debats.zip`) records them —
 * a few days behind the verbatim reports, the base being made after them.
 */
final readonly class SittingCalendar
{
    /**
     * @param list<string> $days `Y-m-d`, in order
     */
    public function __construct(
        public array $days,
    ) {
    }

    /**
     * Reads the sittings' table out of the base's PostgreSQL dump, line by line.
     *
     * @param resource $dump
     */
    public static function fromDump($dump): self
    {
        $days = [];
        $inSittings = false;

        while (false !== ($line = fgets($dump))) {
            if (str_starts_with($line, 'COPY ')) {
                $inSittings = str_starts_with($line, 'COPY debats ');
            } elseif (str_starts_with($line, '\\.')) {
                $inSittings = false;
            } elseif ($inSittings) {
                $days[substr($line, 0, 10)] = true;
            }
        }

        $days = array_keys($days);
        sort($days);

        return new self($days);
    }

    public function isSitting(string $day): bool
    {
        return in_array($day, $this->days, true);
    }

    /**
     * Whether $day is past the last sitting the base knows of: too recent to be
     * checked yet, not wrong.
     */
    public function isAfterLastSitting(string $day): bool
    {
        return [] === $this->days || $day > $this->days[array_key_last($this->days)];
    }

    /**
     * The last sitting before $day, if any.
     */
    public function getSittingBefore(string $day): ?string
    {
        $before = null;

        foreach ($this->days as $sitting) {
            if ($sitting >= $day) {
                break;
            }

            $before = $sitting;
        }

        return $before;
    }
}
