#!/usr/bin/env bash
set -euo pipefail

# Update the Mac production checkout (Herd, spacetraders.test and the tailnet site) to origin/main.
# Mirrors scripts/windows/deploy.ps1.
#
# Run from the production checkout only (/Users/homemac/Sites/spacetraders).
# Without --yes it previews incoming commits and migrations and changes nothing.
# With --yes it gracefully ends a running engine, takes a verified SQLite+.env backup,
# fast-forwards to origin/main, installs dependencies, migrates, builds, refreshes the
# tailnet nginx LaunchAgent if loaded, checks all addresses, and restarts the engine.
#
# Usage:
#   ./scripts/mac/deploy.sh
#   ./scripts/mac/deploy.sh --yes

usage() {
    cat <<'EOF'
Usage: ./scripts/mac/deploy.sh [--yes]

Options:
  --yes, -y   Apply the deployment (default is a read-only preview).
  -h, --help  Show this help message.
EOF
}

yes=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --yes|-y)
            yes=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"

php_bin="$root/scripts/herd-php"
composer_bin="$root/scripts/composer"

engine_ended=0
leaderboard_stopped=0
holder_pid=
leaderboard_lock_dir=
leaderboard_fd_open=0
leaderboard_lock_ready=0
leaderboard_label="local.spacetraders.leaderboard-capture"
leaderboard_installer="$root/scripts/mac/leaderboard-capture.sh"
launchctl_bin="${LAUNCHCTL_BIN:-launchctl}"
uid_num="$(id -u)"

deploy_reminders() {
    if [[ "$engine_ended" -eq 1 ]]; then
        echo "The engine was ended for this deploy and was not restarted; start it from spacetraders.test or run ./scripts/herd-php artisan spacetraders:engine-service start." >&2
    fi
    if [[ "$leaderboard_stopped" -eq 1 ]]; then
        echo "Leaderboard capture was stopped for this deploy. Run ./scripts/mac/leaderboard-capture.sh --install to restore it." >&2
    fi
}

on_err() {
    local code=$?
    deploy_reminders
    exit "$code"
}

abort_deploy() {
    echo "$1" >&2
    deploy_reminders
    exit 1
}

release_leaderboard_lock() {
    if [[ "$leaderboard_fd_open" -eq 1 ]]; then
        exec 8>&-
        leaderboard_fd_open=0
    fi
    if [[ -n "$holder_pid" ]]; then
        # Bash 3.2 can still hold its saved copy of FD 8 when ERR exits the protected function.
        if [[ "$leaderboard_lock_ready" -eq 0 || "${1:-0}" -ne 0 ]]; then
            kill "$holder_pid" 2>/dev/null || true
        fi
        wait "$holder_pid" || true
        holder_pid=
        leaderboard_lock_ready=0
    fi
    if [[ -n "$leaderboard_lock_dir" ]]; then
        rm -f "$leaderboard_lock_dir/hold" "$leaderboard_lock_dir/status"
        rmdir "$leaderboard_lock_dir"
        leaderboard_lock_dir=
    fi
}

trap on_err ERR
trap 'code=$?; release_leaderboard_lock "$code" || true; exit "$code"' EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

quiesce_leaderboard() {
    local service="gui/$uid_num/$leaderboard_label"
    local wait_seconds="${LEADERBOARD_QUIESCE_SECONDS:-15}"
    if ! [[ "$wait_seconds" =~ ^[1-9][0-9]*$ ]] || [[ "${#wait_seconds}" -gt 9 ]]; then
        abort_deploy "LEADERBOARD_QUIESCE_SECONDS must be a positive integer of up to nine digits."
    fi
    if "$launchctl_bin" print "$service" >/dev/null 2>&1; then
        "$launchctl_bin" bootout "$service" 2>/dev/null || true
        leaderboard_stopped=1
        if "$launchctl_bin" print "$service" >/dev/null 2>&1; then
            abort_deploy "Could not bootout $leaderboard_label. Deployment stopped before engine shutdown."
        fi
    fi

    leaderboard_lock_dir="$(mktemp -d)"
    local fifo="$leaderboard_lock_dir/hold"
    local status_file="$leaderboard_lock_dir/status"
    mkfifo "$fifo"
    # macOS and Linux allow opening a FIFO read-write when no other process has opened it.
    exec 8<>"$fifo"
    leaderboard_fd_open=1
    "$php_bin" -r '
        require $argv[1]."/app/Gameplay/HostLock.php";
        $path = App\Gameplay\HostLock::leaderboardCaptureLockPath($argv[1]);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            exit(1);
        }
        $lock = @fopen($path, "c");
        if (!$lock) {
            exit(1);
        }
        $deadline = hrtime(true) + (int) $argv[2] * 1000000000;
        do {
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                echo "LOCKED\n";
                fflush(STDOUT);
                stream_get_contents(STDIN);
                exit(0);
            }
            usleep(50000);
        } while (hrtime(true) < $deadline);
        exit(1);
    ' -- "$root" "$wait_seconds" <"$fifo" 8>&- >"$status_file" &
    holder_pid=$!

    local ready_deadline=$((SECONDS + wait_seconds + 2))
    local status
    while (( SECONDS <= ready_deadline )) && kill -0 "$holder_pid" 2>/dev/null; do
        if [[ -f "$status_file" ]] && IFS= read -r status <"$status_file" && [[ "$status" == "LOCKED" ]] \
            && kill -0 "$holder_pid" 2>/dev/null; then
            leaderboard_lock_ready=1
            return
        fi
        sleep 0.05 8>&-
    done
    abort_deploy "Leaderboard capture lock is busy or the lock holder failed to start. Deployment stopped before engine shutdown."
}

find_pnpm() {
    if command -v pnpm >/dev/null 2>&1; then
        command -v pnpm
        return 0
    fi
    local candidate
    for candidate in \
        "$HOME/.local/share/pnpm/pnpm" \
        "/opt/homebrew/bin/pnpm" \
        "/usr/local/bin/pnpm" \
        "$HOME/.volta/bin/pnpm"; do
        if [[ -x "$candidate" ]]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done
    local fnm_pnpm=""
    local p
    for p in "$HOME/.local/state/fnm_multishells"/*/bin/pnpm "$HOME/Library/Application Support/fnm/node-versions"/*/installation/bin/pnpm; do
        if [[ -x "$p" ]]; then
            fnm_pnpm="$p"
        fi
    done
    if [[ -n "$fnm_pnpm" ]]; then
        printf '%s\n' "$fnm_pnpm"
        return 0
    fi
    echo "pnpm executable not found in PATH or standard macOS locations." >&2
    return 1
}

find_tailscale() {
    if command -v tailscale >/dev/null 2>&1; then
        command -v tailscale
        return 0
    fi
    if [[ -x "/Applications/Tailscale.app/Contents/MacOS/Tailscale" ]]; then
        printf '%s\n' "/Applications/Tailscale.app/Contents/MacOS/Tailscale"
        return 0
    fi
    return 1
}

find_composer() {
    if [[ -n "${COMPOSER_BIN:-}" && -f "${COMPOSER_BIN}" ]]; then
        printf '%s\n' "$COMPOSER_BIN"
        return 0
    fi
    if [[ -x "$HOME/Library/Application Support/Herd/bin/composer" ]]; then
        printf '%s\n' "$HOME/Library/Application Support/Herd/bin/composer"
        return 0
    fi
    if command -v composer >/dev/null 2>&1; then
        command -v composer
        return 0
    fi
    echo "composer executable not found." >&2
    return 1
}

assert_no_dev_processes() {
    if [[ -f "$root/public/hot" ]]; then
        rm -f "$root/public/hot"
        echo "Removed public/hot (left by a Vite dev server)."
    fi
    local vite_pid
    vite_pid="$(ps -axo pid=,command= | awk -v r="$root" 'index($0, r) && tolower($0) ~ /vite/ && $0 !~ /awk/ {print $1; exit}')"
    if [[ -n "$vite_pid" ]]; then
        echo "A Vite dev server is running from the production checkout (PID $vite_pid). Stop it and switch off Herd's automatic dev processes for this site." >&2
        return 1
    fi
}

test_page() {
    local url="$1"
    local tmp_body
    tmp_body="$(mktemp)"
    local code
    code="$(curl -sS --max-time 60 -o "$tmp_body" -w '%{http_code}' "$url")"
    if [[ "$code" != "200" ]]; then
        rm -f "$tmp_body"
        echo "$url returned HTTP $code." >&2
        return 1
    fi
    if grep -q ':5173/' "$tmp_body"; then
        rm -f "$tmp_body"
        echo "$url loads scripts from a Vite dev server; public/hot or a dev process is back." >&2
        return 1
    fi
    if ! grep -q '/build/assets/' "$tmp_body"; then
        rm -f "$tmp_body"
        echo "$url does not reference the built assets." >&2
        return 1
    fi
    rm -f "$tmp_body"
    echo "OK: $url (HTTP 200, built assets)"
}

echo "Production checkout: $root"

if [[ ! -f "$root/.env" ]]; then
    echo "Missing .env in $root." >&2
    exit 1
fi

app_url="$(grep -E '^APP_URL=' "$root/.env" | head -n 1 | sed -E 's/^APP_URL=//; s/^"//; s/"$//')"
app_host="$(printf '%s' "$app_url" | "$php_bin" -r 'echo parse_url(trim(stream_get_contents(STDIN)), PHP_URL_HOST) ?? "";')"
if [[ "$app_host" != "spacetraders.test" ]]; then
    echo "This is not the production checkout (APP_URL is $app_url). Deploy from /Users/homemac/Sites/spacetraders." >&2
    exit 1
fi

branch="$(git branch --show-current)"
if [[ "$branch" != "main" ]]; then
    echo "Production must be on main, not '$branch'." >&2
    exit 1
fi

# public/build is tracked but regenerated by every deploy; any other change means someone edited production.
dirty="$(git status --porcelain --untracked-files=all | awk 'length($0) > 3 && substr($0, 4) !~ /^public\/build\//')"
if [[ -n "$dirty" ]]; then
    echo "The production checkout has uncommitted changes outside public/build. Production is never edited in place; resolve them first:" >&2
    printf '%s\n' "$dirty" >&2
    exit 1
fi

git fetch --quiet origin main
head_sha="$(git rev-parse HEAD)"
target_sha="$(git rev-parse origin/main)"
if ! git merge-base --is-ancestor HEAD origin/main; then
    echo "origin/main does not contain the deployed commit; a fast-forward is impossible. Investigate before deploying." >&2
    exit 1
fi

if [[ "$head_sha" == "$target_sha" ]]; then
    echo "Already at origin/main (${head_sha:0:7})."
else
    echo "Incoming commits (${head_sha:0:7}..${target_sha:0:7}):"
    git log --oneline --no-decorate HEAD..origin/main
    migrations="$(git diff --name-only --diff-filter=AM HEAD origin/main -- database/migrations)"
    if [[ -n "$migrations" ]]; then
        echo "Migrations to run:"
        printf '%s\n' "$migrations"
    else
        echo "No migrations."
    fi
fi

engine_status="$("$php_bin" artisan spacetraders:engine-service status)"
printf '%s\n' "$engine_status"
engine_state="$(printf '%s\n' "$engine_status" | sed -nE 's/^Engine state: (off|running|starting|faulted|stopping)$/\1/p' | head -n 1)"
if [[ -z "$engine_state" ]]; then
    echo "Could not read the local engine state; deployment refused." >&2
    exit 1
fi
if [[ "$engine_state" == "faulted" || "$engine_state" == "stopping" ]]; then
    echo "The local engine is $engine_state. Review it on spacetraders.test before deploying." >&2
    exit 1
fi

restart_engine=0
if [[ "$engine_state" == "running" || "$engine_state" == "starting" ]]; then
    restart_engine=1
fi

if [[ "$yes" -ne 1 ]]; then
    if [[ -x "$leaderboard_installer" ]] && ! "$leaderboard_installer" --status; then
        echo "(will install/update on --yes)"
    fi
    if [[ "$restart_engine" -eq 1 ]]; then
        echo "Engine is $engine_state; the deploy will end it gracefully and start it again afterwards. Approved campaigns resume automatically; plain automation runs need re-approval."
    fi
    echo "Preview only; nothing changed. Run again with --yes to deploy."
    exit 0
fi

pnpm_bin="$(find_pnpm)"
resolved_composer="$(find_composer)"
export COMPOSER_BIN="$resolved_composer"

set -E
quiesce_leaderboard

apply_deploy() {
    if [[ "$restart_engine" -eq 1 ]]; then
        "$php_bin" artisan spacetraders:engine-service end
        engine_ended=1
    fi

    "$php_bin" artisan config:clear
    "$root/scripts/mac/backup.sh"

    git checkout -- public/build
    git clean -fq -- public/build
    if [[ "$head_sha" != "$target_sha" ]]; then
        git merge --ff-only origin/main
    fi

    "$composer_bin" install --no-interaction
    "$pnpm_bin" install --frozen-lockfile --strict-peer-dependencies
    "$php_bin" artisan config:clear
    "$php_bin" artisan migrate --force --no-interaction
    "$pnpm_bin" build
    "$php_bin" artisan optimize:clear
    assert_no_dev_processes

    # Reload tailnet nginx LaunchAgent cleanly when installed and loaded.
    tailnet_label="local.spacetraders.tailnet-web"
    if "$launchctl_bin" print "gui/$uid_num/$tailnet_label" >/dev/null 2>&1; then
        "$launchctl_bin" kickstart -k "gui/$uid_num/$tailnet_label"
        sleep 1
        echo "OK: Reloaded $tailnet_label"
    fi

    test_page "http://spacetraders.test/login"
    test_page "http://127.0.0.1:8081/login"

    if ts_bin="$(find_tailscale)"; then
        ts_hosts="$("$ts_bin" serve status 2>/dev/null | awk '/^https:\/\// {host=$1; sub(/^https:\/\//, "", host)} /127\.0\.0\.1:8081/ && host != "" {print host}' | sort -u)"
        if [[ -z "$ts_hosts" ]]; then
            echo "Warning: tailscale serve publishes nothing that proxies to 127.0.0.1:8081; the tailnet site was not checked." >&2
        else
            while IFS= read -r host; do
                [[ -n "$host" ]] || continue
                test_page "https://$host/login"
            done <<< "$ts_hosts"
        fi
    fi

    if [[ "$restart_engine" -eq 1 ]]; then
        "$php_bin" artisan spacetraders:engine-service start
        engine_ended=0
        echo "OK: Engine restarted."
    fi
}

apply_deploy 8>&-
release_leaderboard_lock
"$leaderboard_installer" --install
leaderboard_stopped=0

echo "Deployed $(git rev-parse --short HEAD) to production."
