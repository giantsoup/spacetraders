#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
label="local.spacetraders.leaderboard-capture"
plist="$HOME/Library/LaunchAgents/$label.plist"
launchctl_bin="${LAUNCHCTL_BIN:-launchctl}"
domain="gui/$(id -u)"

usage() {
    echo "Usage: leaderboard-capture.sh [--install | --uninstall | --status | --print-plist | --run]"
}

require_production() {
    local app_url app_host
    app_url=""
    if [[ -f "$root/.env" ]]; then
        app_url="$(sed -nE 's/^APP_URL=(.*)$/\1/p' "$root/.env" | sed -n '1p')"
    fi
    app_host="$(printf '%s' "$app_url" | "$root/scripts/herd-php" -r 'echo parse_url(trim(stream_get_contents(STDIN), " \t\r\n\""), PHP_URL_HOST) ?? "";')"
    if [[ "$app_host" != "spacetraders.test" ]]; then
        echo "Leaderboard LaunchAgent install and uninstall require the production checkout (APP_URL host spacetraders.test)." >&2
        exit 1
    fi
}

render_plist() {
    local xml_root
    xml_root="$(printf '%s' "$root" | sed 's/\&/\&amp;/g; s/</\&lt;/g; s/>/\&gt;/g')"
    cat <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>$label</string>
    <key>ProgramArguments</key>
    <array>
        <string>/bin/bash</string>
        <string>$xml_root/scripts/mac/leaderboard-capture.sh</string>
        <string>--run</string>
    </array>
    <key>WorkingDirectory</key>
    <string>$xml_root</string>
    <key>StartInterval</key>
    <integer>600</integer>
    <key>RunAtLoad</key>
    <true/>
    <key>ProcessType</key>
    <string>Background</string>
    <key>StandardOutPath</key>
    <string>$xml_root/storage/logs/leaderboard-capture-launchd.log</string>
    <key>StandardErrorPath</key>
    <string>$xml_root/storage/logs/leaderboard-capture-launchd.log</string>
</dict>
</plist>
PLIST
}

loaded() {
    "$launchctl_bin" print "$domain/$label" >/dev/null 2>&1
}

current() {
    [[ -f "$plist" ]] && cmp -s "$plist" <(render_plist)
}

unload() {
    if loaded; then
        "$launchctl_bin" bootout "$domain/$label" 2>/dev/null || true
        if loaded; then
            echo "Could not unload $label." >&2
            exit 1
        fi
    fi
}

if [[ $# -gt 1 ]]; then
    usage >&2
    exit 1
fi

case "${1:---run}" in
    --install)
        require_production
        if current && loaded; then
            echo "Leaderboard LaunchAgent is already current and loaded."
            exit 0
        fi
        mkdir -p "$(dirname "$plist")" "$root/storage/logs"
        temporary_plist="$(mktemp "${plist}.XXXXXX")"
        trap 'rm -f "$temporary_plist"' EXIT
        render_plist >"$temporary_plist"
        plutil -lint -- "$temporary_plist"
        unload
        mv -f "$temporary_plist" "$plist"
        "$launchctl_bin" bootstrap "$domain" "$plist"
        loaded
        echo "Installed $label."
        ;;
    --uninstall)
        require_production
        unload
        rm -f "$plist"
        echo "Uninstalled $label."
        ;;
    --status)
        version="missing"
        if current; then
            version="current"
        elif [[ -f "$plist" ]]; then
            version="outdated"
        fi
        running="no"
        if loaded; then
            running="yes"
        fi
        echo "Leaderboard LaunchAgent: $version, loaded: $running"
        if [[ "$version" != "current" || "$running" != "yes" ]]; then
            exit 3
        fi
        ;;
    --print-plist)
        render_plist
        ;;
    --run)
        cd "$root"
        exec "$root/scripts/herd-php" artisan leaderboard:capture --if-due
        ;;
    -h|--help)
        usage
        ;;
    *)
        usage >&2
        exit 1
        ;;
esac
