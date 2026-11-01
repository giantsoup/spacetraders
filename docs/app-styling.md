# App styling

The [design brief](design-brief.md) defines the approved direction for new UI work, including the Horizon, Logbook, and Voyage references. This page describes the shared application styling and the implemented native ship home. The HTML redesign report remains the visual reference.

## Current implementation

Every Inertia page uses `resources/js/Layouts/AppShell.vue`, either directly or through `AuthenticatedLayout` or `GuestLayout`. The shell owns the dark background, navigation, and keyboard skip link. Account pages keep wrapping navigation. The ship home uses a compact header and an explicit Menu.

`resources/css/command.css` defines the shared dark semantic palette as Tailwind theme variables. It also owns panels, form controls, buttons, focus outlines, and the account dialog. The guide keeps its reading layout and shares the application header.

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

## Native ship home

The flight deck now implements Horizon's continuous single-ship home. The shared canvas, text, mint action, lavender cargo and amber attention colors follow the design brief. System text replaces the terminal font in the shell and shared controls. The ship scene reuses the approved scout and station pixel SVG paths. It is decorative and grants no capability.

The compact shell keeps account navigation in Menu. Home shows owned-ship selection, Report, navigation status, absolute ETA, grouped Fuel/Cargo and a contextual action. Short viewports reduce art and move Systems into Report. Long names, enlarged text and landscape scroll naturally. No simulated phone frame or prototype controls ship in Vue.

`FocusSheet.vue` provides the shared native dialog, explicit Done, Escape, focus containment and restoration. Ship and Report open `ShipReport.vue`; Fuel and Cargo select their own sections. Commands, approvals, automation, intelligence, activity and guidance use focused destinations on the same dashboard. Browser Back and Forward restore destinations. Legacy guide anchors still resolve to their destinations. Guidance opens its target's destination and can reveal it again after dismissal.

`shipPresentation.js` derives time and freshness from saved observation timestamps. Expired travel stays Checking; a fresh observation must confirm navigation. Cooldown is separate and a fresh zero confirms readiness. Pause stops only decoration. Reduced motion, hidden pages and focused sheets also stop the decorative animation. UTC values stay unchanged; `dateTime.js` formats local presentation.

Activity retains confirmed transactions with review flags. Read-only pagination exposes older commands, and saved previews reopen the existing approval flow. The history endpoint is owner scoped and never contacts SpaceTraders. Incomplete ship observations stay available in Report; intelligence explains missing planning prerequisites rather than inventing values.

Run `node scripts/verify-native-ship-browser.mjs` for the implemented home, reports, navigation, command execution/recovery, active Stop and state/layout matrix. Run `node scripts/verify-ship-https-browser.mjs` for an owned temporary TLS proxy with forwarded HTTPS and actual asset/navigation/CSRF checks. Both create isolated fixture databases and finite loopback servers. The established guided, tutorial, agent, presentation, opportunities, automation and page scripts follow the focused destinations and retain their workflow assertions. Use the Playwright module and Chrome executable described above. See [implementation evidence](native-ship-ui-implementation.md) for the executed results and limits.
