<?php

namespace Wexample\SymfonyRemoteSenat\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Wexample\PhpRemote\Exception\RemoteException;
use Wexample\SymfonyRemoteSenat\Service\SenatDebatesClient;
use Wexample\SymfonyRemoteSenat\Service\SenatReportParser;
use ZipArchive;

class SenatDebatesClientTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/senat-tests-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->cacheDir);
    }

    private function client(MockHttpClient $httpClient): SenatDebatesClient
    {
        return new SenatDebatesClient($httpClient, new SenatReportParser(), new Filesystem(), $this->cacheDir, 'https://senat.example/cri.zip');
    }

    public function testTheArchiveIsDownloadedOnceAndReadFromDisk(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('No zip extension.');
        }

        $zipPath = $this->cacheDir.'.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('cri/d20241113.xml', file_get_contents(__DIR__.'/../Fixtures/d20241113.xml'));
        $zip->addFromString('cri/d20241112.xml', file_get_contents(__DIR__.'/../Fixtures/d20241113.xml'));
        $zip->close();

        $client = $this->client(new MockHttpClient([new MockResponse(file_get_contents($zipPath))]));
        unlink($zipPath);

        $reports = $client->getReports();
        $this->assertSame(['d20241113.xml', 'd20241112.xml'], array_map('basename', $reports));
        // Read from disk: the mock has no second answer.
        $this->assertCount(2, $client->getReports());
        $this->assertCount(3, $client->readReport($reports[0]));
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
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('No zip extension.');
        }

        $this->expectException(RemoteException::class);

        $this->client(new MockHttpClient([new MockResponse('<html>Maintenance</html>')]))->getReports();
    }
}
