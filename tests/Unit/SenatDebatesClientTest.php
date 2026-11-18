<?php

namespace Wexample\SymfonyRemoteSenat\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Wexample\PhpRemote\Class\RemoteReporter;
use Wexample\PhpRemote\Class\RemoteSignal;
use Wexample\PhpRemote\Enum\RemoteSignalKind;
use Wexample\PhpRemote\Exception\RemoteException;
use Wexample\SymfonyRemoteSenat\Service\SenatDebatesClient;
use Wexample\SymfonyRemoteSenat\Service\SenatReportParser;
use Wexample\SymfonyRemoteSenat\Tests\Fixtures\Reporter\RecordingDispatcher;
use ZipArchive;

class SenatDebatesClientTest extends TestCase
{
    private string $cacheDir;

    private RecordingDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/senat-tests-'.bin2hex(random_bytes(4));
        $this->dispatcher = new RecordingDispatcher();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->cacheDir);
    }

    private function client(MockHttpClient $httpClient): SenatDebatesClient
    {
        return new SenatDebatesClient($httpClient, new SenatReportParser(), new Filesystem(), new RemoteReporter($this->dispatcher), $this->cacheDir, 'https://senat.example/cri.zip', 'https://senat.example/debats.zip');
    }

    /**
     * @param array<string, string> $files
     */
    private function zip(array $files): MockResponse
    {
        $path = $this->cacheDir.'-'.count($files).'.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);

        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();
        $response = new MockResponse(file_get_contents($path));
        unlink($path);

        return $response;
    }

    /**
     * The reports, and the base of the debates dating them: the Sénat sat on
     * 2024-11-13 and 2025-12-04, not on 2024-11-12.
     */
    private function archives(): MockHttpClient
    {
        $report = file_get_contents(__DIR__.'/../Fixtures/d20241113.xml');

        return new MockHttpClient([
            $this->zip([
                'cri/d20241113.xml' => $report,
                'cri/d20241112.xml' => $report,
                'cri/d20261204.xml' => file_get_contents(__DIR__.'/../Fixtures/d20261204.xml'),
            ]),
            $this->zip(['debats.sql' => file_get_contents(__DIR__.'/../Fixtures/debats.sql')]),
        ]);
    }

    /**
     * @return list<RemoteSignal>
     */
    private function anomalies(): array
    {
        return array_values(array_filter($this->dispatcher->events, static fn (RemoteSignal $signal): bool => RemoteSignalKind::Anomaly === $signal->kind));
    }

    protected function skipWithoutZip(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('No zip extension.');
        }
    }

    public function testTheArchivesAreDownloadedOnceAndReadFromDisk(): void
    {
        $this->skipWithoutZip();
        $client = $this->client($this->archives());

        $reports = $client->getReports();
        $this->assertSame(['d20261204.xml', 'd20241113.xml', 'd20241112.xml'], array_map('basename', $reports));
        // Read from disk: the mock has no answer left.
        $this->assertCount(3, $client->getReports());
        $this->assertFileExists($this->cacheDir.'/calendar.json');
    }

    public function testAReportOfASittingDayIsReadAsNamed(): void
    {
        $this->skipWithoutZip();
        $client = $this->client($this->archives());
        $client->getReports();

        $points = $client->readReport($this->cacheDir.'/cri/d20241113.xml');

        $this->assertCount(3, $points);
        $this->assertSame('2024-11-13', $points[0]->date->format('Y-m-d'));
        $this->assertSame([], $this->anomalies());
    }

    public function testAMisdatedReportIsReadAtTheSittingBeforeTheOneItAnnouncesAndSignalled(): void
    {
        $this->skipWithoutZip();
        $client = $this->client($this->archives());
        $client->getReports();

        $points = $client->readReport($this->cacheDir.'/cri/d20261204.xml');

        $this->assertSame(['2025-12-04'], array_values(array_unique(array_map(static fn ($point): string => $point->date->format('Y-m-d'), $points))));
        $this->assertSame('20261204:par_21', $points[1]->key, 'The key stays the file\'s: the point is the same one.');

        [$anomaly] = $this->anomalies();
        $this->assertSame(SenatDebatesClient::CODE_MISDATED, $anomaly->code);
        $this->assertSame('d20261204.xml', $anomaly->fingerprint);
        $this->assertSame(['file' => 'd20261204.xml', 'named' => '2026-12-04', 'day' => '2025-12-04'], $anomaly->context);
    }

    public function testAReportTheCalendarCannotDateIsLeftOutAndSignalled(): void
    {
        $this->skipWithoutZip();
        $client = $this->client($this->archives());
        $client->getReports();

        $this->assertSame([], $client->readReport($this->cacheDir.'/cri/d20241112.xml'));

        [$anomaly] = $this->anomalies();
        $this->assertSame(SenatDebatesClient::CODE_UNDATED, $anomaly->code);
    }

    public function testAFailedDownloadKeepsTheReportsOnDisk(): void
    {
        (new Filesystem())->dumpFile($this->cacheDir.'/cri/d20241113.xml', 'kept');
        $client = $this->client(new MockHttpClient([new MockResponse('', ['http_code' => 503])]));

        try {
            $client->getReports(refresh: true);
            $this->fail('No exception.');
        } catch (RemoteException $exception) {
            $this->assertSame(SenatDebatesClient::KEY, $exception->remoteKey);
        }

        $this->assertSame('kept', file_get_contents($this->cacheDir.'/cri/d20241113.xml'));
    }

    public function testAnAnswerThatIsNoZipIsARemoteFailure(): void
    {
        $this->skipWithoutZip();
        $this->expectException(RemoteException::class);

        $this->client(new MockHttpClient([new MockResponse('<html>Maintenance</html>')]))->getReports();
    }
}
