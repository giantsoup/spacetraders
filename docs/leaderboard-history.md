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

## macOS schedule

The LaunchAgent runs `leaderboard:capture --if-due` every 10 minutes and once when it
loads after install or deploy. In the last two hours before a reset, that extra check
can capture sooner if the latest snapshot is at least five minutes old. A full capture
of about 184 agents uses about 11 public requests. The regular schedule can capture
up to six times per hour in the final window. Each read has five attempts, with up to
four waits of at most 15 seconds. The directory has a 100-page limit.

The first production deploy runs the pre-merge copy of `deploy.sh`. After the
authorized `./scripts/mac/deploy.sh --yes` succeeds, run
`./scripts/mac/leaderboard-capture.sh --install` from `/Users/homemac/Sites/spacetraders`.
Run `./scripts/mac/leaderboard-capture.sh --status` and verify it exits 0. Subsequent
deploys show the job status in preview, require capture to be idle before proceeding,
and install the current LaunchAgent after deployment.

`launchctl bootout` sends SIGTERM if the scheduled capture is running. SQLite rolls
back any uncommitted snapshot write. The deploy lock primarily waits for manual
captures, which use the same lock file as the scheduled command.
