<?php

namespace Wexample\SymfonyRemoteSenat\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wexample\SymfonyRemoteSenat\Class\SittingCalendar;
use Wexample\SymfonyRemoteSenat\Service\SenatReportParser;

class SittingCalendarTest extends TestCase
{
    public function testTheDumpGivesTheDaysTheSenatSat(): void
    {
        $dump = fopen(__DIR__.'/../Fixtures/debats.sql', 'r');
        $calendar = SittingCalendar::fromDump($dump);
        fclose($dump);

        $this->assertSame(['2024-11-13', '2025-12-04'], $calendar->days);
        $this->assertTrue($calendar->isSitting('2024-11-13'));
        $this->assertFalse($calendar->isSitting('2024-11-12'));
        $this->assertTrue($calendar->isAfterLastSitting('2025-12-10'));
        $this->assertSame('2025-12-04', $calendar->getSittingBefore('2025-12-05'));
        $this->assertNull($calendar->getSittingBefore('2024-11-13'));
    }

    public function testAReportAnnouncesItsNextSitting(): void
    {
        $parser = new SenatReportParser();

        $this->assertSame('2025-12-05', $parser->readNextSitting(file_get_contents(__DIR__.'/../Fixtures/d20261204.xml'))?->format('Y-m-d'));
        $this->assertNull($parser->readNextSitting(file_get_contents(__DIR__.'/../Fixtures/d20241113.xml')));
    }
}
