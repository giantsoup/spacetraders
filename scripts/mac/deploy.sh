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
on_err() {
    local code=$?
    if [[ "$engine_ended" -eq 1 ]]; then
        echo "The engine was ended for this deploy and was not restarted; start it from spacetraders.test or run ./scripts/herd-php artisan spacetraders:engine-service start." >&2
    fi
    exit "$code"
}
trap on_err ERR

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
    if [[ "$restart_engine" -eq 1 ]]; then
        echo "Engine is $engine_state; the deploy will end it gracefully and start it again afterwards. Approved campaigns resume automatically; plain automation runs need re-approval."
    fi
    echo "Preview only; nothing changed. Run again with --yes to deploy."
    exit 0
fi

pnpm_bin="$(find_pnpm)"
resolved_composer="$(find_composer)"
export COMPOSER_BIN="$resolved_composer"

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
uid_num="$(id -u)"
if launchctl print "gui/$uid_num/$tailnet_label" >/dev/null 2>&1; then
    launchctl kickstart -k "gui/$uid_num/$tailnet_label"
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

echo "Deployed $(git rev-parse --short HEAD) to production."
