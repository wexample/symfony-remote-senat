<?php

namespace Wexample\SymfonyRemoteSenat\Service;

use DateTimeImmutable;
use Wexample\PhpRemote\Exception\RemoteException;
use Wexample\SymfonyRemoteSenat\Class\DebatePoint;
use Wexample\SymfonyRemoteSenat\Class\Intervention;

/**
 * Reads a verbatim report of the Sénat (`cri/dYYYYMMDD.xml`, as data.senat.fr
 * publishes it) into its debate points.
 *
 * The files are XHTML laced with `cri:` tags, not always well formed: they are
 * read as a stream of the tags that matter. A title (`cri:titreS1` to `S3`, an
 * article of a bill) opens a point, read under its parents' titles; a `cri:intervenant` is an intervention,
 * its speaker's record in `mat` — senators and ministers alike. A title met inside
 * an intervention cuts it: what follows belongs to the next point. Words outside
 * any intervention — the texts voted on — are no one's and left out.
 *
 * Files before 2005 have no `cri:intervenant`: they give no point. A file of 2005
 * on without any — or without a `cri:corps` — is one of another format, and
 * raises a RemoteException.
 */
class SenatReportParser
{
    private const array MONTHS = [
        'janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6,
        'juillet' => 7, 'août' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12,
    ];

    private const string TOKENS = '#(<cri:titreS1\b.*?</cri:titreS1>|<cri:titreS2\b.*?</cri:titreS2>|<cri:titreS3\b.*?</cri:titreS3>|<cri:mentionarticle\b.*?</cri:mentionarticle>|<cri:intervenant\b[^>]*>|</cri:intervenant>|<cri:orateurnom>.*?</cri:orateurnom>|<cri:orateurqualite>.*?</cri:orateurqualite>|</p>)#s';

    /**
     * @return list<DebatePoint>
     */
    public function parse(
        string $xml,
        string $name
    ): array {
        if (! preg_match('/d(\d{8})/', $name, $match)) {
            return [];
        }

        $date = DateTimeImmutable::createFromFormat('!Ymd', $match[1]);
        $body = $this->toUtf8($xml);
        $start = strpos($body, '<cri:corps');

        if (false === $date) {
            return [];
        }

        if (false === $start || ! str_contains($body, '<cri:intervenant')) {
            if ((int) $match[1] < 20050101) {
                return [];
            }

            throw new RemoteException(SenatDebatesClient::KEY, sprintf('"%s" is no verbatim report the parser knows.', $name));
        }

        $points = [];
        $titles = [];
        $point = null;
        $intervention = null;

        foreach (preg_split(self::TOKENS, substr($body, $start), -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $token) {
            if (str_starts_with($token, '<cri:titreS') || str_starts_with($token, '<cri:mentionarticle')) {
                // An article of a bill (« Article 9 », « Après l'article 8 ») is a part
                // of its debate, as a second-level title.
                $level = str_starts_with($token, '<cri:titreS') ? (int) $token[11] : 2;
                $title = $this->readTitle($token);

                // The chair changing hands is a title of its own, not a new debate.
                if ('' === $title || str_starts_with(mb_strtolower($title), 'présidence de')) {
                    continue;
                }

                $titles = array_filter($titles, static fn (int $parent): bool => $parent < $level, ARRAY_FILTER_USE_KEY);
                $titles[$level] = $title;
                $this->close($points, $point, $intervention);
                $point = ['key' => $match[1].':'.($this->readAttribute($token, 'parid') ?: 't'.$index), 'title' => $this->joinTitles($titles), 'interventions' => []];
            } elseif (str_starts_with($token, '<cri:intervenant')) {
                $this->closeIntervention($point, $intervention);
                $record = $this->readAttribute($token, 'mat');
                $intervention = [
                    'speaker' => null,
                    'record' => '' === $record ? null : strtoupper($record),
                    'name' => $this->toDisplayName($this->readAttribute($token, 'nom')),
                    'text' => '',
                ];
            } elseif ('</cri:intervenant>' === $token) {
                $this->closeIntervention($point, $intervention);
                $intervention = null;
            } elseif (str_starts_with($token, '<cri:orateurnom>')) {
                if (null !== $intervention) {
                    $intervention['speaker'] ??= rtrim($this->clean($token), '.,: ') ?: null;
                }
            } elseif (str_starts_with($token, '<cri:orateurqualite>')) {
                continue;
            } elseif ('</p>' === $token) {
                if (null !== $intervention) {
                    $intervention['text'] .= "\n";
                }
            } elseif (null !== $intervention && null !== $point) {
                $intervention['text'] .= $token;
            }
        }

        $this->close($points, $point, $intervention);

        return array_map(
            static fn (array $point): DebatePoint => new DebatePoint(
                $point['key'],
                $point['title'],
                $date,
                array_map(static fn (array $intervention): Intervention => new Intervention(...$intervention), $point['interventions'])
            ),
            $points
        );
    }

    /**
     * The day of the next sitting, as the report announces it at its end (« Prochaine
     * séance, … vendredi 5 décembre 2025 »): what tells the day of a report whose
     * file is misnamed. Null when it announces none.
     */
    public function readNextSitting(string $xml): ?DateTimeImmutable
    {
        $text = $this->clean($this->toUtf8($xml));
        $found = preg_match_all(
            '/prochaine s[ée]ance.{0,400}?(?:lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+(\d{1,2})(?:er)?\s+(\p{L}+)\s+(\d{4})/iu',
            $text,
            $matches,
            PREG_SET_ORDER
        );
        $month = $found ? (self::MONTHS[mb_strtolower(end($matches)[2])] ?? null) : null;

        return null === $month
            ? null
            : DateTimeImmutable::createFromFormat('!Y-n-j', sprintf('%d-%d-%d', end($matches)[3], $month, end($matches)[1]));
    }

    /**
     * The files say they are Latin-1; some are UTF-8 all the same.
     */
    private function toUtf8(string $xml): string
    {
        return mb_check_encoding($xml, 'UTF-8') ? $xml : mb_convert_encoding($xml, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * @param list<array{key: string, title: string, interventions: list<array>}> $points
     */
    private function close(
        array &$points,
        ?array &$point,
        ?array &$intervention
    ): void {
        $this->closeIntervention($point, $intervention);

        if (null !== $point && [] !== $point['interventions']) {
            $points[] = $point;
        }

        $point = null;
    }

    /**
     * Writes what the intervention said so far into the point, and leaves it open
     * with nothing said: a title may cut it, the rest going to the next point.
     */
    private function closeIntervention(
        ?array &$point,
        ?array &$intervention
    ): void {
        if (null === $point || null === $intervention) {
            return;
        }

        $text = implode("\n", array_filter(array_map($this->clean(...), explode("\n", $intervention['text'])), static fn (string $line): bool => '' !== $line));

        if ('' !== $text) {
            $point['interventions'][] = ['speaker' => $intervention['speaker'], 'record' => $intervention['record'], 'name' => $intervention['name'], 'text' => $text];
        }

        $intervention['text'] = '';
    }

    /**
     * The title as printed; early files sometimes print none, their `libelle` then
     * the only one.
     */
    private function readTitle(string $token): string
    {
        return $this->clean($token) ?: $this->readAttribute($token, 'libelle');
    }

    /**
     * « Projet de loi de finances pour 2025 : Article 3 », under its parents.
     *
     * @param array<int, string> $titles
     */
    private function joinTitles(array $titles): string
    {
        $titles = array_values($titles);

        return 1 === count($titles) ? $titles[0] : $titles[0].' : '.$this->capitalize(end($titles));
    }

    private function readAttribute(
        string $tag,
        string $name
    ): string {
        return preg_match('/\b'.$name.'="([^"]*)"/', $tag, $match) ? $this->clean($match[1]) : '';
    }

    /**
     * « Loïc HERVÉ » reads « Loïc Hervé », « Marie-Pierre de LA GONTRIE » « Marie-Pierre de La Gontrie ».
     */
    private function toDisplayName(string $name): string
    {
        return mb_substr(implode(' ', array_map(
            static fn (string $word): string => mb_strtoupper($word) === $word ? mb_convert_case(mb_strtolower($word), MB_CASE_TITLE) : $word,
            explode(' ', $name)
        )), 0, 255);
    }

    private function capitalize(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
