## The sittings' reports

```php
public function __construct(private SenatDebatesClient $senat, private RemoteGuard $guard) {}

// The archive is made again each night: refresh to read the last sittings.
$reports = $this->guard->attempt($this->senat, fn () => $this->senat->getReports(refresh: true), []);

foreach ($reports as $report) {                       // most recent first
    foreach ($this->guard->attempt($this->senat, fn () => $this->senat->readReport($report), []) as $point) {
        $point->key;      // '20241113:par_21', the day and the title's paragraph
        $point->title;    // 'Questions d’actualité au Gouvernement : Fret ferroviaire'
        foreach ($point->interventions as $intervention) {
            $intervention->speaker;   // 'M. le président', as printed
            $intervention->record;    // '14179A'
            $intervention->name;      // 'Loïc Hervé'
            $intervention->text;
        }
    }
}
```

A refresh that fails leaves the reports of the last download in place.

## The day of a report

The file's name gives the day, checked against the calendar — the days the Sénat sat, from the base of the debates (`debats.zip`, downloaded with the reports, kept as `calendar.json`):

- named after a sitting, or after the last sitting the calendar knows of — the base comes a few days after the reports —: read as named;
- otherwise — a day in the future, a day the Sénat did not sit —: read at the last sitting before the one the report announces at its end (« prochaine séance … »), and signalled `misdated_report` (`SenatDebatesClient::CODE_MISDATED`), the file as fingerprint, `named` and `day` in the context;
- announcing none: left out, signalled `undated_report`; the signal recovers once a later reading dates it.

The announcement alone is right for 98 % of the reports — some announce a day with a typo of their own —: it is only asked when the name is wrong.

```yaml
wexample_symfony_remote_senat:
  cache_dir: '%kernel.project_dir%/var/remote/senat'
  debates:
    url: 'https://data.senat.fr/data/debats/cri.zip'
    calendar_url: 'https://data.senat.fr/data/debats/debats.zip'
```
