<?php

namespace Wexample\SymfonyRemoteSenat\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wexample\PhpRemote\Exception\RemoteException;
use Wexample\SymfonyRemoteSenat\Service\SenatReportParser;

class SenatReportParserTest extends TestCase
{
    /**
     * @return list<\Wexample\SymfonyRemoteSenat\Class\DebatePoint>
     */
    private function parse(string $name): array
    {
        return (new SenatReportParser())->parse(file_get_contents(__DIR__.'/../Fixtures/'.$name), $name);
    }

    public function testAReportIsReadIntoItsPoints(): void
    {
        $points = $this->parse('d20241113.xml');

        $this->assertSame(
            ['Questions d’actualité au Gouvernement', 'Questions d’actualité au Gouvernement : Fret ferroviaire', 'Loi de finances pour 2025 : Article 3'],
            array_map(static fn ($point): string => $point->title, $points)
        );
        $this->assertSame('20241113:par_21', $points[1]->key);
        $this->assertSame('2024-11-13', $points[1]->date->format('Y-m-d'));
    }

    public function testATitleInsideAnInterventionCutsIt(): void
    {
        [$opening, $question] = $this->parse('d20241113.xml');

        $this->assertCount(1, $opening->interventions);
        $this->assertSame('L’ordre du jour appelle les questions.', $opening->interventions[0]->text);
        $this->assertCount(3, $question->interventions);
    }

    public function testSpeakersCarryTheirRecordAndName(): void
    {
        [, $question, $bill] = $this->parse('d20241113.xml');
        [$chair, $member, $minister] = $question->interventions;

        $this->assertSame('M. le président', $chair->speaker);
        $this->assertSame('14179A', $chair->record);
        $this->assertSame('Loïc Hervé', $chair->name);
        $this->assertSame('M. Pierre Barros', $member->speaker);
        $this->assertSame("Monsieur le ministre, le fret recule.\nIl recule encore. (Applaudissements.)", $member->text);
        $this->assertSame('M. François Durovray', $minister->speaker);
        $this->assertSame('21409M', $minister->record);
        $this->assertSame('Il avance.', $minister->text);
        $this->assertSame('Marie-Pierre de La Gontrie', $bill->interventions[0]->name);
    }

    public function testReportsBefore2005GiveNoPoint(): void
    {
        $this->assertSame([], $this->parse('d20030114.xml'));
    }

    public function testALaterReportOfAnotherFormatIsARemoteFailure(): void
    {
        $this->expectException(RemoteException::class);

        $this->parse('d20241114.xml');
    }
}
