#!/usr/bin/env bash
set -euo pipefail

# Snapshot effective SQLite with VACUUM INTO and preserve .env privately on macOS.
# Mirrors scripts/windows/backup.ps1.
#
# Usage:
#   ./scripts/mac/backup.sh [--destination <dir>] [--force]

usage() {
    cat <<'EOF'
Usage: ./scripts/mac/backup.sh [--destination <dir>] [--force]

Creates a private SQLite VACUUM INTO snapshot verified with PRAGMA integrity_check
alongside a SHA-256-verified copy of .env.

Options:
  --destination <dir>  Optional new backup directory (must not already exist).
  --force              Allow an online SQLite snapshot while the engine may be active.
  -h, --help           Show this help message.
EOF
}

destination=""
force=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --destination)
            [[ $# -ge 2 ]] || { echo "Missing value for --destination." >&2; exit 1; }
            destination="$2"
            shift 2
            ;;
        --force)
            force=1
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
php_bin="$root/scripts/herd-php"
env_file="$root/.env"

if [[ ! -f "$env_file" || -L "$env_file" ]]; then
    echo "No plain checkout .env exists to back up: $env_file" >&2
    exit 1
fi

env_hash="$(shasum -a 256 "$env_file" | awk '{print $1}')"

info_json="$("$php_bin" "$root/scripts/windows/application.php" info)"
connection="$(printf '%s' "$info_json" | "$php_bin" -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["connection"] ?? "";')"
database="$(printf '%s' "$info_json" | "$php_bin" -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["database"] ?? "";')"
engine_state="$(printf '%s' "$info_json" | "$php_bin" -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["engine_state"] ?? "unknown";')"
config_cached="$(printf '%s' "$info_json" | "$php_bin" -r '$d = json_decode(stream_get_contents(STDIN), true); echo !empty($d["config_cached"]) ? "1" : "0";')"

echo "Effective database ($connection): $database; engine: $engine_state"

if [[ "$connection" != "sqlite" || ! -f "$database" ]]; then
    echo "An existing SQLite file is required." >&2
    exit 1
fi

case "$engine_state" in
    starting|running|stopping|unknown)
        echo "Warning: The engine may be writing. End the session first; --force explicitly accepts an online SQLite snapshot." >&2
        if [[ "$force" -ne 1 ]]; then
            echo "Backup refused while engine may be active." >&2
            exit 1
        fi
        ;;
esac

if [[ "$config_cached" == "1" ]]; then
    echo "Cached configuration can contain a different key. Explicitly clear it before backing up .env." >&2
    exit 1
fi

if [[ -z "$destination" ]]; then
    checkout_hash="$(printf '%s' "$root" | shasum -a 256 | awk '{print substr($1, 1, 16)}')"
    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    destination="$HOME/Library/Application Support/SpaceTraders/backups/$checkout_hash/$stamp"
fi

if [[ -e "$destination" ]]; then
    echo "Backup destination already exists; nothing will be replaced: $destination" >&2
    exit 1
fi

mkdir -p "$(dirname "$destination")"
umask 077
mkdir -m 700 "$destination"

"$php_bin" "$root/scripts/windows/application.php" backup "$destination"

if [[ ! -f "$destination/.snapshot-verified" ]]; then
    echo "Snapshot did not complete; backup is incomplete." >&2
    exit 1
fi

cp "$env_file" "$destination/.env"
chmod 600 "$destination/.env" "$destination/database.sqlite"

copied_hash="$(shasum -a 256 "$destination/.env" | awk '{print $1}')"
if [[ "$copied_hash" != "$env_hash" ]]; then
    echo "Environment changed during backup; this folder is incomplete and must not be restored." >&2
    exit 1
fi

echo "Backup: $destination"
echo "Restore with app/engine processes stopped: preserve current files, restore database.sqlite and its matching .env (APP_KEY), adjust DB_DATABASE if moved, clear configuration, then integrity-check before restarting. Never mix keys and databases."
