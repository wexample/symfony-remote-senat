<?php

namespace Wexample\SymfonyRemoteSenat\Service;

use DateTimeImmutable;
use LogicException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;
use Wexample\PhpRemote\Class\RemoteReporter;
use Wexample\PhpRemote\Class\RemoteStatus;
use Wexample\PhpRemote\Exception\RemoteException;
use Wexample\PhpRemote\Interface\RemoteInterface;
use Wexample\SymfonyRemoteSenat\Class\DebatePoint;
use Wexample\SymfonyRemoteSenat\Class\SittingCalendar;
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
 *
 * A file's name is not always its day — `d20261204.xml` was held on 2025-12-04 —.
 * A report named after a day the Sénat sat, or after the last sitting the base of
 * the debates (`debats.zip`, the calendar) knows of, is read as named. Another one
 * is read at the last sitting before the one it announces at its end, signalled
 * `misdated_report`; one announcing none, `undated_report`, and left out.
 */
class SenatDebatesClient implements RemoteInterface, ResetInterface
{
    public const string KEY = 'senat_debates';

    public const string DEFAULT_URL = 'https://data.senat.fr/data/debats/cri.zip';

    public const string DEFAULT_CALENDAR_URL = 'https://data.senat.fr/data/debats/debats.zip';

    public const string CODE_MISDATED = 'misdated_report';

    public const string CODE_UNDATED = 'undated_report';

    private ?SittingCalendar $calendar = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SenatReportParser $parser,
        private readonly Filesystem $filesystem,
        private readonly RemoteReporter $reporter,
        private readonly string $cacheDir,
        private readonly string $url = self::DEFAULT_URL,
        private readonly string $calendarUrl = self::DEFAULT_CALENDAR_URL,
    ) {
    }

    /**
     * The calendar is read from disk once per run: a worker living longer reads it again.
     */
    public function reset(): void
    {
        $this->calendar = null;
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

        if ($refresh || ! is_file($this->calendarPath())) {
            $this->downloadCalendar();
        }

        $reports = glob($dir.'/d*.xml') ?: [];
        rsort($reports);

        return $reports;
    }

    /**
     * The report's points, at the day the calendar gives them.
     *
     * @return list<DebatePoint>
     */
    public function readReport(string $path): array
    {
        $xml = file_get_contents($path);
        $name = basename($path);
        $points = $this->parser->parse($xml, $name);

        if ([] === $points) {
            return [];
        }

        $named = $points[0]->date->format('Y-m-d');
        $day = $this->findDay($xml, $named);

        if (null === $day) {
            $this->reporter->anomaly(self::KEY, self::CODE_UNDATED, sprintf('The sittings\' calendar does not date "%s": left out until it does.', $name), ['file' => $name, 'named' => $named], $name);

            return [];
        }

        $this->reporter->recovery(self::KEY, self::CODE_UNDATED, $name);

        if ($day === $named) {
            return $points;
        }

        $this->reporter->anomaly(self::KEY, self::CODE_MISDATED, sprintf('"%s" was held on %s.', $name, $day), ['file' => $name, 'named' => $named, 'day' => $day], $name);
        $date = new DateTimeImmutable($day);

        return array_map(static fn (DebatePoint $point): DebatePoint => $point->withDate($date), $points);
    }

    /**
     * The day of its name, when the Sénat sat then or the calendar does not reach
     * it yet; else the last sitting before the one it announces. Never after today.
     */
    private function findDay(
        string $xml,
        string $named
    ): ?string {
        $calendar = $this->getCalendar();
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        if ($named <= $today && ($calendar->isSitting($named) || $calendar->isAfterLastSitting($named))) {
            return $named;
        }

        $next = $this->parser->readNextSitting($xml);
        $day = null === $next ? null : $calendar->getSittingBefore($next->format('Y-m-d'));

        return null !== $day && $day <= $today ? $day : null;
    }

    private function getCalendar(): SittingCalendar
    {
        if (null !== $this->calendar) {
            return $this->calendar;
        }

        if (! is_file($this->calendarPath())) {
            $this->downloadCalendar();

            return $this->calendar;
        }

        return $this->calendar = new SittingCalendar(json_decode(file_get_contents($this->calendarPath()), true));
    }

    /**
     * The base of the debates, read into the calendar, kept as JSON: the dump
     * itself is a hundred megabytes of SQL, of which the days are all it takes.
     */
    private function downloadCalendar(): void
    {
        $archive = $this->cacheDir.'/debats.zip';
        $this->fetchTo($this->calendarUrl, $archive);
        $zip = $this->openZip($archive);
        $dump = $zip->getStream('debats.sql');

        if (false === $dump) {
            $zip->close();
            $this->filesystem->remove($archive);

            throw new RemoteException(self::KEY, 'The base of the debates holds no `debats.sql`: its format changed.');
        }

        $calendar = SittingCalendar::fromDump($dump);
        fclose($dump);
        $zip->close();
        $this->filesystem->remove($archive);

        if ([] === $calendar->days) {
            throw new RemoteException(self::KEY, 'The base of the debates lists no sitting: its format changed.');
        }

        $this->filesystem->dumpFile($this->calendarPath(), json_encode($calendar->days));
        $this->calendar = $calendar;
    }

    private function calendarPath(): string
    {
        return $this->cacheDir.'/calendar.json';
    }

    /**
     * Downloaded and unpacked aside, then moved in place: a directory there is a
     * complete one.
     */
    private function download(string $dir): void
    {
        $archive = $this->cacheDir.'/cri.zip';
        $partial = $dir.'.part';
        $this->filesystem->remove($partial);
        $this->fetchTo($this->url, $archive);
        $zip = $this->openZip($archive);

        if (! $zip->extractTo($partial)) {
            $zip->close();
            $this->filesystem->remove([$archive, $partial]);

            throw new RemoteException(self::KEY, sprintf('"%s" does not unpack.', $this->url));
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

    /**
     * Streamed to disk: the archives weigh hundreds of megabytes.
     */
    private function fetchTo(
        string $url,
        string $path
    ): void {
        if (! class_exists(ZipArchive::class)) {
            throw new LogicException(sprintf('The zip extension is missing: unpack "%s" into "%s" by other means.', $url, $this->cacheDir));
        }

        $this->filesystem->remove($path);
        $this->filesystem->mkdir($this->cacheDir);

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 120]);
            $file = fopen($path, 'w');

            foreach ($this->httpClient->stream($response) as $chunk) {
                fwrite($file, $chunk->getContent());
            }

            fclose($file);
        } catch (ExceptionInterface $exception) {
            $this->filesystem->remove($path);

            throw new RemoteException(self::KEY, sprintf('Unable to download "%s": %s', $url, $exception->getMessage()), $exception);
        }
    }

    private function openZip(string $path): ZipArchive
    {
        $zip = new ZipArchive();

        if (true !== $zip->open($path)) {
            $this->filesystem->remove($path);

            throw new RemoteException(self::KEY, sprintf('"%s" is no zip the package can open.', basename($path)));
        }

        return $zip;
    }
}
