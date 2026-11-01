# App styling

The [design brief](design-brief.md) defines the approved direction for new UI work, including the Horizon, Logbook, and Voyage references. This page describes the current terminal-style implementation; the redesigned screens remain prototypes until their application workflows are implemented and verified.

## Current implementation

Every Inertia page uses `resources/js/Layouts/AppShell.vue`, either directly or through `AuthenticatedLayout` or `GuestLayout`. The shell owns the dark background, navigation, and keyboard skip link. Navigation wraps on small screens without hiding account links behind a menu.

`resources/css/command.css` defines the existing command-terminal palette as Tailwind theme variables. It also owns panels, form controls, buttons, focus outlines, and the account dialog. The guide keeps its sans-serif reading text and uses the shared terminal header.

`PrimaryButton`, `SecondaryButton`, `DangerButton`, `TextInput`, `InputLabel`, `InputError`, and `Checkbox` use these shared styles. Page styles describe content-specific layouts. The unused starter navigation and logo components have been removed.

`resources/views/errors/minimal.blade.php` provides the same dark appearance for Laravel's standard HTTP error screens. Its small inline stylesheet works without a JavaScript bundle or asset manifest. Error responses retain their status codes, and JSON requests keep their JSON responses. Development exception diagnostics remain framework-managed.

## Verify page coverage

Run `node scripts/verify-pages-browser.mjs` after building production assets. Set `PLAYWRIGHT_MODULE` to an existing Playwright module if it is unavailable through normal package resolution. Set `PLAYWRIGHT_EXECUTABLE` when the matching Chromium executable needs an explicit path.

The check creates a private SQLite fixture and a temporary loopback server. It uses real local handlers and synthetic SpaceTraders responses. It keeps the checkout's environment and live game data unchanged. Browser requests to external services are blocked. Email uses the array transport.

The check renders every Inertia page at 1440px, 390px, and 320px. It fails if a new page has no coverage entry, omits the shared shell, contains duplicate element IDs, or causes horizontal page overflow. It checks both system color preferences and exercises authentication, registration, password recovery, profile updates, agent import, and the deletion dialog's keyboard behavior. It does not delete an account.

Results and screenshots are saved under `storage/app/mvp-evidence/style-refactor/after`. Set `QA_ARTIFACT_DIR` to use a different output directory. The existing agents, tutorial, and guided-flight browser scripts cover the related game journeys. `bash scripts/verify.sh` runs the production-build checks, frontend logic checks, backend suite, and code formatting checks.

## Framework references

The shared tokens follow [Tailwind theme variables](https://tailwindcss.com/docs/theme). Semantic landmarks, labelled fields, skip links, and focus feedback follow [Vue accessibility guidance](https://vuejs.org/guide/best-practices/accessibility.html).

## Local automation flow

`AutomationConsole.vue` uses the command-deck tokens and shared buttons. Its engine controls, read-only investigation, candidate cards, saved draft, expiring preview and outcome report remain dark under both system themes. Server validation focuses the error and marks invalid fields. Approval and contract-acceptance acknowledgement are separate controls.

Run `node scripts/verify-automation-browser.mjs` for both complete workflows with an owned temporary launchd service. It retains full-page and focused panel screenshots at 1440px, 390px and 320px, checks keyboard focus, validation, reload, stale prices, Stop, End session and restart. All upstream responses are synthetic. The script removes its service, server and browser before exiting.
