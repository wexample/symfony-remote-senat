<?php

namespace Wexample\SymfonyRemoteSenat\Service;

use LogicException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Wexample\PhpRemote\Class\RemoteStatus;
use Wexample\PhpRemote\Exception\RemoteException;
use Wexample\PhpRemote\Interface\RemoteInterface;
use Wexample\SymfonyRemoteSenat\Class\DebatePoint;
use ZipArchive;

/**
 * The verbatim reports of the Sénat as data.senat.fr publishes them (Licence
 * Ouverte): every sitting since 2003 in one zip, made again each night, a file
 * per day (`cri/d20241113.xml`).
 *
 * The zip is unpacked under the cache directory once downloaded: a reading
 * started again takes the reports from disk, until it asks for them anew. A
 * download that fails, or a zip that does not open, raises a RemoteException,
 * and the reports already on disk stay as they were.
 */
class SenatDebatesClient implements RemoteInterface
{
    public const string KEY = 'senat_debates';

    public const string DEFAULT_URL = 'https://data.senat.fr/data/debats/cri.zip';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SenatReportParser $parser,
        private readonly Filesystem $filesystem,
        private readonly string $cacheDir,
        private readonly string $url = self::DEFAULT_URL,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getLabel(): string
    {
        return 'Sénat, comptes rendus des séances';
    }

    public function checkStatus(): RemoteStatus
    {
        if (! class_exists(ZipArchive::class)) {
            return RemoteStatus::unconfigured('The zip extension is missing.');
        }

        $headers = $this->httpClient->request('HEAD', $this->url)->getHeaders();

        return RemoteStatus::up('Made '.($headers['last-modified'][0] ?? 'at an unknown date').'.');
    }

    /**
     * @param bool $refresh download the archive again, for the sittings since
     *
     * @return list<string> the paths of the reports, the most recent first
     */
    public function getReports(bool $refresh = false): array
    {
        $dir = $this->cacheDir.'/cri';

        if ($refresh || ! is_dir($dir)) {
            $this->download($dir);
        }

        $reports = glob($dir.'/d*.xml') ?: [];
        rsort($reports);

        return $reports;
    }

    /**
     * @return list<DebatePoint>
     */
    public function readReport(string $path): array
    {
        return $this->parser->parse(file_get_contents($path), basename($path));
    }

    /**
     * Downloaded and unpacked aside, then moved in place: a directory there is a
     * complete one.
     */
    private function download(string $dir): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new LogicException(sprintf('The zip extension is missing: unpack "%s" into "%s" by other means.', $this->url, $dir));
        }

        $archive = $this->cacheDir.'/cri.zip';
        $partial = $dir.'.part';
        $this->filesystem->remove([$archive, $partial]);
        $this->filesystem->mkdir($this->cacheDir);

        try {
            $response = $this->httpClient->request('GET', $this->url, ['timeout' => 120]);
            $file = fopen($archive, 'w');

            foreach ($this->httpClient->stream($response) as $chunk) {
                fwrite($file, $chunk->getContent());
            }

            fclose($file);
        } catch (ExceptionInterface $exception) {
            $this->filesystem->remove($archive);

            throw new RemoteException(self::KEY, sprintf('Unable to download "%s": %s', $this->url, $exception->getMessage()), $exception);
        }

        $zip = new ZipArchive();

        if (true !== $zip->open($archive) || ! $zip->extractTo($partial)) {
            $this->filesystem->remove([$archive, $partial]);

            throw new RemoteException(self::KEY, sprintf('"%s" is no zip the package can open.', $this->url));
        }

        $zip->close();

        // The zip holds a `cri/` directory of its own.
        if (! is_dir($partial.'/cri')) {
            $this->filesystem->remove([$archive, $partial]);

            throw new RemoteException(self::KEY, 'The zip holds no `cri/` directory: its format changed.');
        }

        $this->filesystem->remove([$archive, $dir]);
        $this->filesystem->rename($partial.'/cri', $dir);
        $this->filesystem->remove($partial);
    }
}
