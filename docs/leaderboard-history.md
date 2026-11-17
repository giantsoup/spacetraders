# Leaderboard history

`GET /v2` only shows the current wipe's leaderboard, and it is gone after the weekly reset.
These commands save it locally so each wipe's final standings are kept. All reads are
anonymous and go through the shared request budget at discovery priority.

| Command | What it does |
| --- | --- |
| `php artisan leaderboard:capture` | Saves the status leaderboards (`mostCredits`, `mostSubmittedCharts`, stats, next reset) and the full public agent directory, about one request per 20 agents. `--top-only` skips the directory. |
| `php artisan leaderboard:capture --if-due` | Captures only when due, deciding from saved data without an API call: hourly, every 5 minutes in the 2 hours before the saved next reset, and once when that reset time has passed. |
| `php artisan leaderboard:history` | Lists each wipe's last snapshot, its leader and how long before the reset it was taken. |
| `php artisan leaderboard:history 2026-11-08 --limit=25` | Shows one wipe's standings from its last snapshot, ranked with ties sharing a rank. |
| `php artisan leaderboard:import <file> --captured-at=<ISO time>` | Imports a saved `GET /v2` response, such as `docs/reports/wipe-strategy-evidence-2026-11-14/status.json` (captured `2026-11-15T01:27:43Z`, 11.5 hours before that wipe's reset). |

Snapshots live in `leaderboard_snapshots`, keyed by the server's `resetDate`. The latest
snapshot of a wipe is its final record, so its accuracy depends on how close to the reset it ran.
If the directory is incomplete, the snapshot still keeps the status top lists and the command
prints a warning.

Nothing runs this automatically yet. A Windows Scheduled Task running
`leaderboard:capture --if-due` every 5 minutes, like `scripts/windows/watchdog.ps1`, would
capture final standings within 5 minutes of each reset.
