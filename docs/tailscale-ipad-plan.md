# Plan: view SpaceTraders on the iPad over Tailscale

Status: implemented 2026-11-12. The machine is `spacetraders` on tailnet `taile5cff3.ts.net`. See "Implementation notes" for where the build departs from the draft.

## Goal

Open the live SpaceTraders site (the Herd checkout at `C:\Users\taylo\Herd\spacetraders`)
on the iPad at an HTTPS address with a real certificate, from home or away, without
opening any port on the router or the Windows firewall. The setup should keep working
after a reboot and be reusable for other things on the tailnet later.

Target address: `https://spacetraders.<tailnet-name>.ts.net`

## How it fits together

```
iPad (Tailscale app, Safari)
   │  WireGuard tunnel, HTTPS to spacetraders.<tailnet>.ts.net
   ▼
TAYLORPC: tailscaled ── `tailscale serve` (Let's Encrypt cert, port 443 on the tailnet only)
   │  plain HTTP on loopback
   ▼
127.0.0.1:8081  PHP built-in server (headless conhost, single process) in the Herd checkout
   │  same .env, SQLite database, sessions and operator login as spacetraders.test
   ▼
Laravel app
```

`spacetraders.test` through Herd stays exactly as it is on the PC.

### Why a second PHP server instead of pointing Tailscale at Herd's nginx

Herd's nginx picks the site by the `Host` header. To reach it, the proxy would have to
rewrite `Host` to `spacetraders.test`. The app's engine controls
(`app/Gameplay/LocalEngineManager.php:26`) are allowed only when the host is
`spacetraders.test` and the client IP is `127.0.0.1`. A proxied iPad request would pass
both checks, so engine install, start and stop would open up to the iPad. Serving on a
separate loopback port with the iPad's own host name keeps that rule in force: the iPad
can view and play, but engine controls answer 403, as they do today for anything that
isn't the PC itself.

## Decisions for you

1. **Which name to use.** Recommendation: **rename this PC's Tailscale machine name to
   `spacetraders`.** This changes only the name in Tailscale, not the Windows computer
   name. It's the simplest option. Its limit is that anything else served from this PC
   later shares that name, on another port or path (for example `:8443` or `/other`).
   - Alternative: keep the machine as `taylorpc` and later create a **Tailscale Service**
     named `spacetraders`. A Service has its own name, separate from the machine, but it
     needs a tagged device and ACL setup. It's worth doing once a second app needs its own
     name, not now.
2. **Tailnet name.** The `<tailnet-name>` part of the address is either a random
   `tailXXXX` name or a friendlier name that Tailscale offers in its DNS settings, such
   as `cat-crocodile`. Pick one before turning on HTTPS, because the name ends up on the
   certificate.
3. **Always on.** Recommendation: yes. A Scheduled Task starts the PHP server when you log
   in (step 5), and `tailscale serve --bg` already survives reboots.

## Steps

Steps marked **(you)** need your browser, a UAC prompt or the iPad. Claude does the rest.

### 1. Install Tailscale on the PC (you approve UAC)

- `winget install --id Tailscale.Tailscale -e`, or the installer from
  https://tailscale.com/download/windows.
- Sign in from the tray icon **(you)**: this creates the tailnet on the free Personal
  plan. Use the account you want to keep long term (Apple, Google, GitHub or Microsoft).
- In the tray: **Preferences → Run unattended** so the tailnet stays up even when you're
  logged out of Windows.
- Check: `tailscale status` shows this PC as online.

### 2. Configure the tailnet in the admin console (you)

At https://login.tailscale.com/admin:

- **DNS page:** check that **MagicDNS** is on (it's the default for new tailnets), choose
  the tailnet name (decision 2), then turn on **HTTPS Certificates**.
- **Machines page:** open this PC → **Edit machine name** → `spacetraders` (decision 1).
- Optional: **Disable key expiry** for this PC so it doesn't drop off the tailnet every 180
  days.

Note: HTTPS certificates are recorded in public Certificate Transparency logs, so
`spacetraders.<tailnet-name>.ts.net` becomes publicly visible as a name. The site itself
is still reachable only from devices signed into your tailnet.

### 3. Install Tailscale on the iPad (you)

- Install **Tailscale** from the App Store, sign in with the same account, and allow the
  VPN configuration.
- Check: the iPad and `spacetraders` both show as connected in the app.

### 4. Add the web server script (Claude, dev checkout)

New `scripts/windows/web.ps1 Install | Uninstall | Status`, built the same way as
`engine.ps1` and using `common.ps1` helpers (`Get-HerdPhp`, `Get-RepositoryRoot`):

- **Install** registers a Scheduled Task `SpaceTraders-Web-<checkout id>`. It runs at
  logon, restarts if it fails, and runs in a hidden window. It runs
  `php artisan serve --host=127.0.0.1 --port=8081 --no-reload` with
  `PHP_CLI_SERVER_WORKERS=4`, from the checkout it was installed in.
- **Uninstall** stops the task and removes it.
- **Status** reports whether the task exists, whether it's running, and whether
  `http://127.0.0.1:8081/up` (or the login page) answers.
- The server binds to loopback only, so it's never reachable from the LAN and needs no
  firewall rule.

Docs: add a short "Viewing on the iPad" section to `README.md` or
`docs/windows-local-mvp-plan.md` that covers the commands, the address and the
engine-controls limit.

Tests: run the existing suite (`scripts\windows\verify.ps1`). Check that a feature test
already covers the case where engine controls return 403 when the host isn't
`gameplay.local_host`, and add one if not.

Commit on `feature/windows-local-mvp`, then bring it into the Herd checkout the usual way
(back up first, then `git fetch C:/Users/taylo/Projects/spacetraders <branch>:<branch>`
and update). The engine doesn't need reinstalling, because no `.env` values change.

### 5. Start the web server (Claude, Herd checkout)

- `.\scripts\windows\web.ps1 Install`, then `web.ps1 Status`.
- Check: `curl http://127.0.0.1:8081` from the PC returns the login page.

### 6. Publish it on the tailnet (Claude)

- `tailscale serve --bg 8081`. This serves `https://spacetraders.<tailnet>.ts.net` →
  `http://127.0.0.1:8081`, tailnet only, and keeps doing so across restarts.
- Check: `tailscale serve status` shows the mapping. Do **not** use `tailscale funnel`,
  which would put the site on the public internet.

### 7. Verify end to end

From the PC browser over the tailnet first, then on the iPad **(you)**:

- [ ] The address loads with a valid padlock and no warning.
- [ ] Log in. The session sticks and the "remember me" cookie works.
- [ ] Links, redirects and form posts stay on `https://spacetraders.<tailnet>.ts.net`,
      with no jumps to `http://` or `spacetraders.test`. If they jump, Tailscale is
      rewriting the `Host` header. The fix is to trust `X-Forwarded-Host` from
      `127.0.0.1` in `app/Http/Middleware/TrustProxies.php` (today it trusts only
      `X-Forwarded-Proto`). That's a small reviewed change, never a `Host` rewrite to
      `spacetraders.test`.
- [ ] CSS and JS load (from `public/build`, with no Vite dev server).
- [ ] Fleet pages update while the engine runs.
- [ ] Engine install, start and stop return 403 from the iPad and still work from
      `spacetraders.test` on the PC.
- [ ] After a reboot and logon, the site comes back without any manual steps.
- [ ] "Add to Home Screen" on the iPad opens it like an app.

## Implementation notes

- **No worker pool.** `PHP_CLI_SERVER_WORKERS` isn't supported on Windows, so the server
  handles one request at a time. That's fine for one iPad. Herd at `spacetraders.test`
  stays the main server on the PC.
- **No `artisan serve`.** The task runs `php.exe -S 127.0.0.1:8081 -t public` directly with
  Laravel's router script. It runs inside `conhost.exe --headless`, so no console window
  appears. `php-win.exe` exits with code 255 when given `-S`. `web.ps1` also stops that PHP
  child process on Install (reinstall) and Uninstall, because ending the task stops only
  conhost.
- **Machine name set from the command line.** `tailscale set --hostname=spacetraders` renamed
  the node. It needed no admin console visit.
- **Existing test coverage.** `test_engine_endpoint_denies_a_wrong_host_or_non_loopback_client_even_for_operator`
  already covers a loopback request with a non-`spacetraders.test` host getting 403, so
  no new test was added.

## Rollback

- `tailscale serve reset` stops publishing the site.
- `.\scripts\windows\web.ps1 Uninstall` removes the PHP server task.
- Uninstalling Tailscale (Settings → Apps) removes the rest. Herd and the engine are
  untouched throughout.

## Not in scope

- Public access (`tailscale funnel`), custom domains, or a Tailscale Service name.
- Engine controls from the iPad. That would be a deliberate security change, needing its
  own plan.
- Changes to the Herd site, `APP_URL`, or engine `.env` values.
