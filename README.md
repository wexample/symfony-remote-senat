# symfony-remote-senat

Version: 2.0.0

The Sénat's open data (data.senat.fr), as symfony-remotes: the verbatim reports of its sittings, read into debate points

## Table of Contents

- [Installation](#installation)
- [Introduction](#introduction)

## Installation

```php
// config/bundles.php
Wexample\SymfonyRemote\WexampleSymfonyRemoteBundle::class => ['all' => true],
Wexample\SymfonyRemoteSenat\WexampleSymfonyRemoteSenatBundle::class => ['all' => true],
```

The archive is a zip: PHP's `zip` extension unpacks it. Without it, the remote reads Unconfigured, and the reports must be unpacked into the cache by other means. They are kept under `var/remote/senat` (`cache_dir`), out of the kernel's cache; they weigh about 3 GB, worth a volume in production.

`symfony-remote-senat` reads the open data of the Sénat (data.senat.fr, Licence Ouverte) from a Symfony app. It holds one source so far: the verbatim reports of the sittings, 2005 on, read into debate points — a title, a date, and who said what, each speaker with their record at the Sénat (`14179A`), ministers included, and their name.

`SenatDebatesClient` is a `symfony-remote`, so `remote:status` checks the archive. It knows nothing of the app's entities: what to make of the points is the app's.

A download that fails, an archive that does not open, a report of a format the parser no longer knows raise php-remote's `RemoteException`; the reports already on disk stay as they were. Run the calls through `RemoteGuard` where the app must go on without them.
