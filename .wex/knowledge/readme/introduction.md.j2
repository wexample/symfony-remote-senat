`symfony-remote-senat` reads the open data of the Sénat (data.senat.fr, Licence Ouverte) from a Symfony app. It holds one source so far: the verbatim reports of the sittings, 2005 on, read into debate points — a title, a date, and who said what, each speaker with their record at the Sénat (`14179A`), ministers included, and their name.

`SenatDebatesClient` is a `symfony-remote`, so `remote:status` checks the archive. It knows nothing of the app's entities: what to make of the points is the app's.

A file's name is not always its day: the Sénat published `d20261204.xml` for a sitting of 2025-12-04. Each report is checked against the base of the debates — the days the Sénat sat —, and one misnamed is read at its true day, signalled as an anomaly through php-remote's `RemoteReporter`.

A download that fails, an archive that does not open, a report of a format the parser no longer knows raise php-remote's `RemoteException`; the reports already on disk stay as they were. Run the calls through `RemoteGuard` where the app must go on without them.
