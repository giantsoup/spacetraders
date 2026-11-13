# Agent notes

## Production and development checkouts

- **Production**: `C:\Users\taylo\Herd\spacetraders`, always on `main`. It serves
  http://spacetraders.test on the PC and https://spacetraders.chimp-hamal.ts.net on the
  Tailscale tailnet (a Herd nginx site on 127.0.0.1:8081 that `scripts\windows\web.ps1`
  writes, plus `tailscale serve`). It holds the live
  `.env`, SQLite database, operator account and engine task. It changes only through
  `deploy.ps1`.
- **Development**: `C:\Users\taylo\Projects\spacetraders` and its worktrees. Herd serves this
  checkout at http://spacetraders-dev.test, with its own `.env` and database and the engine
  off. All coding, testing and committing happens here.

## "Push to main and update production"

When the user asks to push or merge to main and update production (or the Tailscale site,
or "prod", or to deploy), do these steps in order:

1. **Ship to main** from the development checkout. Commit the requested work, push its
   branch, then open and merge a PR into `main` with `gh`. Run `git log origin/main..HEAD`
   first. Other sessions commit to the same branches, so ask before shipping commits you
   didn't make.
2. **Deploy** from the production checkout:
   `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\windows\deploy.ps1` previews
   the incoming commits and migrations. Run it again with `-Yes` to apply. The script backs
   up, fast-forwards to `origin/main`, installs dependencies, migrates, builds, restarts the
   web server and checks all three addresses. The deploy ends a running engine gracefully
   and restarts it afterwards. Ending cancels unsent previews and stops active automation
   runs, which the user re-approves afterwards. A faulted or stopping engine makes the
   deploy refuse; then ask the user to review it on spacetraders.test.
3. **Report** the deployed commit and the script's `OK:` lines.

Keep Herd Settings → AI → **Enable Herd Developer Tools** off. When it's on, Herd injects
a widget into every `.test` page, and the widget starts `php artisan dev` (Vite,
`artisan serve`, `queue:listen`) for any site viewed in a browser, production included.
Vite writes `public/hot`, which breaks assets on the tailnet. `deploy.ps1` removes
`public/hot` and fails if a Vite server is running from production.
