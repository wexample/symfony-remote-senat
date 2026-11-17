## Architecture

Under `Wexample\SymfonyRemoteSenat\` (PSR-4 on `src/`):

- src/Service/SenatDebatesClient.php, the remote: the zip streamed to disk through Symfony's HttpClient, unpacked with `ZipArchive` into a `.part` directory, its `cri/` moved in place once complete — a failure on the way leaves the previous reports untouched.
- src/Service/SenatReportParser.php reads a `cri/dYYYYMMDD.xml`. The files are XHTML laced with `cri:` tags, not always well formed, Latin-1 or UTF-8 whatever they declare: they are read as a stream of the tags that matter, split by one regular expression. A title (`cri:titreS1` to `S3`, `cri:mentionarticle` for an article of a bill) opens a point under its parents' titles; « Présidence de … » is the chair changing hands, not a point. A `cri:intervenant` is an intervention, its record in `mat`, its name in `nom` (« Loïc HERVÉ » read « Loïc Hervé »); a title met inside it cuts it. Words outside any intervention — the texts voted on — are left out. Files before 2005 have no `cri:intervenant` and give no point; a later one without any raises `RemoteException`.
- src/Class/DebatePoint.php and src/Class/Intervention.php, readonly values, the package's own.

### Tests

Unit tests: the parser on a trimmed real report (`tests/Fixtures/d20241113.xml`), on a report before 2005 and on a page of another format; the client on Symfony's `MockHttpClient` with a zip built by the test — a download read once then from disk, a failed refresh keeping the reports, an answer that is no zip.
