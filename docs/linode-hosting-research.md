# SpaceTraders on Taylor's Linode

Researched November 1, 2026, Pacific time. Source checkout: `9c3d555`. This is a hosting assessment, not a deployment or load test.

## Recommendation

Hosting this client at `spacetraders.tayloroyer.com` on the existing Linode is a reasonable next step for personal use. Try the existing machine before buying more capacity. A 1 GB instance is plausible with a small PHP process pool, one queue worker, SQLite, and production assets built elsewhere. Its existing workload determines whether that is comfortable. A 2 GB instance offers more room when the main site also runs a database or other applications. These are engineering judgments, not measured requirements.

SpaceTraders hosts the game and API. The Linode would host this Laravel client and execute its approved actions. Travel does not require a dedicated process per ship. The local runner saves an arrival or cooldown deadline and returns from the job; a scheduled pass dispatches the next step when due. See [MiningPlanner](../app/Gameplay/MiningPlanner.php), [AutomationRunner](../app/Gameplay/AutomationRunner.php), and the separate [gameplay research](travel-and-manual-play-research.md).

## The saved trip took four minutes

A read-only query of the configured local SQLite database found this live-client snapshot for `GIANTSOUP-1`:

- Departure: `2026-11-01T06:53:13.395Z`.
- Arrival: `2026-11-01T06:57:16.395Z`.
- Elapsed travel: 243 seconds, or 4 minutes, 3 seconds.
- Local times: October 31, 2026, 11:53:13 PM to 11:57:16 PM PDT.
- Mode: `CRUISE`. Recorded engine speed: 36.
- The saved observation at `2026-11-01 06:59:10` already shows `DOCKED`.

At inspected revision `9c3d555`, the [flight deck](../resources/js/Pages/Agent/Dashboard.vue) and [guided flight](../resources/js/guidedFlight.js) displayed raw API arrival timestamps. The `Z` means UTC. During this trip, UTC was seven hours ahead of Pacific time. This is a plausible explanation for an apparent hours-long wait, but it does not establish which screen or route prompted the question. No authenticated upstream refresh or game action was performed for this research. A subsequent requested change localizes visible timestamps in the browser while retaining UTC in storage and API values.

## What runs on the server

The inspected project uses Laravel 13 and requires PHP `~8.4.1`, which restricts production to PHP 8.4.1 or a later 8.4 patch. See [composer.json](../composer.json). Composer platform checks should establish the required extensions on the actual host.

For one operator, the proposed runtime is:

- An existing Nginx or Apache host serving this application's `public` directory through PHP-FPM.
- SQLite on local disk, with the existing database queue and file cache/session configuration.
- One supervised `queue:work database --queue=spacetraders --sleep=1 --tries=1 --timeout=120` process. Both manual gameplay commands and mining steps use this queue.
- One scheduler mechanism. Production cron can invoke `schedule:run` every minute; Laravel keeps sub-minute schedules active through the minute. This application's dispatch task runs every five seconds. Alternatively, supervise `schedule:work`, but do not run duplicate scheduler mechanisms.

The code does not require Redis, MySQL, a continuously running Node process, browser automation, or an LLM to play. Node and pnpm compile the Vue assets at build time. Building those assets off-server avoids an unnecessary memory spike on a 1 GB host. See [package.json](../package.json), [the environment template](../.env.example), [queue jobs](../app/Jobs/ExecuteShipCommand.php), and [the scheduler](../app/Console/Kernel.php).

Laravel documents [queue-worker process supervision](https://laravel.com/framework/docs/13.x/queues#supervisor-configuration), [production scheduling and sub-minute tasks](https://laravel.com/framework/docs/13.x/scheduling#running-the-scheduler), and [serving the public directory](https://laravel.com/framework/docs/13.x/deployment#server-configuration). Its worker memory threshold is a restart threshold, not a measurement of this application's resident memory or an operating-system memory cap.

## Capacity and cost

Akamai's current North America shared-CPU pricing lists:

| Plan | RAM | vCPUs | SSD | Monthly compute price |
| --- | ---: | ---: | ---: | ---: |
| Nanode 1 GB | 1 GB | 1 | 25 GB | $5 |
| Linode 2 GB | 2 GB | 1 | 50 GB | $12 |
| Linode 4 GB | 4 GB | 2 | 80 GB | $24 |

Source: [Akamai CPU pricing](https://www.akamai.com/cloud/pricing/cpu). Region, taxes, backups, and add-ons can affect the bill. The subdomain does not itself require another compute instance.

There is no verified RAM benchmark for this client. Long game timers do not imply heavy CPU consumption, because the runner persists deadlines rather than holding an HTTP request open for the trip. PHP web workers, the queue process, and the scheduler still consume memory while idle. The practical risk on the smallest plan is their combined memory use alongside the existing website.

Before committing to the existing host, a read-only check should establish total and available RAM during normal load, swap pressure, free disk, PHP version and extensions, existing PHP-FPM process counts, and the main site's database usage. Measure the added processes during one representative trip. Do not assume the main site's unused RAM from its plan name. No Linode connection or capacity inspection was performed for this report.

## Subdomain and deployment implications

An `A` record for `spacetraders.tayloroyer.com` can point to the existing Linode IPv4 address. The web server then selects a separate virtual host for that name, with its own application directory and HTTPS certificate. The apex website can keep its current virtual host. See [Akamai's DNS overview](https://www.akamai.com/cloud/guides/dns-overview/) and [Laravel's Nginx example](https://laravel.com/framework/docs/13.x/deployment#nginx).

This project currently documents a single-operator local deployment. Before publishing it, prepare private access, production environment settings, a separate PHP-FPM pool where appropriate, worker supervision, bounded logs, and consistent database/key backups. The current auth routes expose registration. A private personal deployment should restrict that route or put access control in front of the application. Serving only `public` keeps the SQLite file, `.env`, and other private application files outside the document root.

Existing agent tokens are encrypted with `APP_KEY`. Moving existing data requires its matching key and a consistent SQLite backup. Use one active command executor for the migrated agents, and stop and reconcile work before the move. Host-local locks in [HostLock](../app/Gameplay/HostLock.php) do not coordinate a Mac and Linode that each have their own database and lock directory.

An always-on server also does not remove the current runner's approval bounds. [GameplayRequest](../app/Http/Requests/GameplayRequest.php) limits a preview to 1–60 minutes and 1–10 trips, and [AutomationRunner](../app/Gameplay/AutomationRunner.php) honors expiry. Hosting the current code will not create an unattended overnight miner without an explicitly designed change to those bounds.

## Evidence limits

This research inspected code, official public documentation, and selected navigation fields in the existing local database through a read-only connection. At the research stage, only these documents and the capacity chart were added. The later requested display localization changes frontend code and verification scripts. Credentials, existing environment settings, DNS, services, server configuration, and live game state remain untouched. No production readiness, server capacity, or live gameplay test is claimed.
