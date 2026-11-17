<?php

namespace Wexample\SymfonyRemoteSenat\Class;

/**
 * What one speaker said, in a row, within a debate point.
 */
final readonly class Intervention
{
    /**
     * @param string|null $speaker null for the report's own words (a vote, a suspension); as printed otherwise
     *                             (« M. le président », « Mme Nicole Belloubet »)
     * @param string|null $record  the speaker's identifier at the house, when the report gives it
     * @param string|null $name    the speaker's own name, when the report gives it apart from how it prints them
     */
    public function __construct(
        public ?string $speaker,
        public ?string $record,
        public string $text,
        public ?string $name = null,
    ) {
    }
}
