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

```yaml
wexample_symfony_remote_senat:
  cache_dir: '%kernel.project_dir%/var/remote/senat'
  debates:
    url: 'https://data.senat.fr/data/debats/cri.zip'
```
