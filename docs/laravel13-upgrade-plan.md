# Upgrade SpaceTraders to Laravel 13 on Herd PHP 8.4

Prepared October 31, 2026 against commit `a3ea1d40982530ea428b298b25192835e01fd8ec` on `chore/spacetraders-api-v2`.

## Outcome and scope

Run the existing application on Laravel 13 and Herd's PHP 8.4, with compatible current Composer and frontend dependencies. Preserve the SpaceTraders v2.3 integration, authentication, profile management, saved agents, API forwarding, and existing appearance.

This document is a plan. No application dependencies, runtime settings, or data have been changed. Implement on a new local branch, proposed as `chore/laravel-13-php-84`, based on the API-update commit above or a later revision containing it.

Keep the existing Laravel kernels, providers, and application bootstrap. Apply the relevant changes from the [Laravel 11](https://laravel.com/framework/docs/11.x/upgrade), [Laravel 12](https://laravel.com/framework/docs/12.x/upgrade), and [Laravel 13](https://laravel.com/framework/docs/13.x/upgrade) upgrade guides to the final target together. Intermediate Laravel 11 and 12 installations are unnecessary. Laravel specifically recommends retaining the existing application structure when upgrading a Laravel 10 app.

Keep the current authentication screens. Do not run a starter-kit installer or regenerate Breeze scaffolding. SSR, TypeScript conversion, new gameplay, token-encryption migrations, and a UI redesign are outside this upgrade.

## Verified starting point

- Herd MCP reports `http://spacetraders.test`, PHP `8.4`, no site-specific PHP isolation, and global PHP `8.4`.
- CLI PHP is Herd `8.4.27`. Node is `26.13.0`; npm is `12.2.1`.
- The project has no `.env` file. Herd reports no configured database for it. Select an owned local database before application verification; never assume a shared database is disposable.
- The current locks contain Laravel `10.10.1`, Inertia Laravel `0.6.9`, Vue `3.3.2`, and Vite `4.3.5`. Some locked PHP packages reject PHP 8.4.
- The preceding API-update verification passed 66 tests and 318 assertions using isolated PHP 8.2. That is a baseline to preserve, not proof of PHP 8.4 compatibility.
- `public/build` is tracked. Source and built assets must be delivered together.

## Dependency targets

These are dated candidates, not a promise that future `latest` releases remain compatible. Refresh metadata at implementation time and resolve the complete graph. Keep stable releases and commit both lockfiles.

### PHP and Composer packages

Set the application PHP requirement to `~8.4.1`, covering current PHP 8.4 patch releases while excluding PHP 8.5. Set Composer's platform PHP to `8.4.1`, the minimum for the chosen PHPUnit 13 release. Run verification on the actual Herd PHP 8.4 executable as well; platform emulation does not verify extensions or web PHP.

The latest stable package records below were checked through each package's Packagist metadata.

| Package | Current lock | Proposed constraint | Action |
| --- | --- | --- | --- |
| [laravel/framework](https://repo.packagist.org/p2/laravel/framework.json) | 10.10.1 | `^13.39` | Resolve 13.39.0 or a later compatible patch. |
| [guzzlehttp/guzzle](https://repo.packagist.org/p2/guzzlehttp/guzzle.json) | 7.5.1 | `^8.3` | Target 8.3.1; recheck HTTP-client behavior. |
| [inertiajs/inertia-laravel](https://repo.packagist.org/p2/inertiajs/inertia-laravel.json) | 0.6.9 | `^3.6` | Target 3.6.0 with the Vue 3.x adapter. |
| [laravel/sanctum](https://repo.packagist.org/p2/laravel/sanctum.json) | 3.2.5 | `^4.3` | Target 4.3.3; update configuration. |
| [laravel/tinker](https://repo.packagist.org/p2/laravel/tinker.json) | 2.8.1 | `^3.0` | Target 3.0.2. |
| [tightenco/ziggy](https://repo.packagist.org/p2/tightenco/ziggy.json) | 1.6.0 | `^2.6` | Target 2.6.5; replace the old deep import. |
| [barryvdh/laravel-ide-helper](https://repo.packagist.org/p2/barryvdh/laravel-ide-helper.json) | 2.13.0 | `^3.7` | Target 3.7.0. |
| [fakerphp/faker](https://repo.packagist.org/p2/fakerphp/faker.json) | 1.21.0 | `^1.24` | Target 1.24.1. |
| [laravel/breeze](https://repo.packagist.org/p2/laravel/breeze.json) | 1.21.0 | `^2.4` | Target 2.4.2; retain generated application code. |
| [laravel/pint](https://repo.packagist.org/p2/laravel/pint.json) | 1.10.0 | `^1.32` | Target 1.32.1; scope formatting to touched files. |
| [laravel/sail](https://repo.packagist.org/p2/laravel/sail.json) | 1.22.0 | `^1.69` | Target 1.69.0; Herd remains the local server. |
| [mockery/mockery](https://repo.packagist.org/p2/mockery/mockery.json) | 1.5.1 | `^1.6` | Target 1.6.15. |
| [nunomaduro/collision](https://repo.packagist.org/p2/nunomaduro/collision.json) | 7.5.2 | `^8.9` | Target 8.9.5. |
| [phpunit/phpunit](https://repo.packagist.org/p2/phpunit/phpunit.json) | 10.1.3 | `^13.4` | Target 13.4.5; migrate configuration and removed test APIs. |
| [spatie/laravel-ignition](https://repo.packagist.org/p2/spatie/laravel-ignition.json) | 2.1.2 | `^2.12` | Target 2.12.1. |

Laravel's initial 13 upgrade guide recommends PHPUnit 12. The current [framework package](https://raw.githubusercontent.com/laravel/framework/13.x/composer.json) also supports PHPUnit 13, and current Collision permits it. The complete candidate graph resolves with PHPUnit 13 on platform PHP 8.4.1. This plan therefore targets PHPUnit 13. Runtime test compatibility still needs verification.

Refresh transitive packages through Composer, including Symfony, Carbon, Guzzle's PSR packages, and development tools. Do not pin old vulnerable transitive versions to make a conflict disappear.

### Frontend and build tools

Keep Node `26.13.0`, which is already installed and satisfies the current [Vite requirements](https://vite.dev/guide/). Add matching `.nvmrc` and `.node-version` files and an appropriate Node 26 engine requirement. Pin `packageManager` to `pnpm@12.16.1` and migrate the existing pnpm lockfile. Do not add an npm lockfile or change the machine's global Node version.

| Package | Current lock | Target candidate | Action |
| --- | --- | --- | --- |
| [@inertiajs/vue3](https://registry.npmjs.org/@inertiajs/vue3/latest) | 1.0.6 | 3.10.0 | Upgrade alongside the PHP adapter. |
| [vue](https://registry.npmjs.org/vue/latest) | 3.3.2 | 3.5.44 | Stay with Vue 3 and JavaScript. |
| [pinia](https://registry.npmjs.org/pinia/latest) | 2.0.36 | 4.1.0 | Review both v3 and v4 migration notes. |
| [pinia-plugin-persistedstate](https://registry.npmjs.org/pinia-plugin-persistedstate/latest) | 3.1.0 | 4.7.1 | Preserve the existing storage key and saved-state format. |
| [@vue/devtools-api](https://registry.npmjs.org/@vue/devtools-api/latest) | Not direct | 8.2.1 | Required peer for Pinia 4. |
| [vite](https://registry.npmjs.org/vite/latest) | 4.3.5 | 8.3.5 | Review accumulated Vite migration changes. |
| [laravel-vite-plugin](https://registry.npmjs.org/laravel-vite-plugin/latest) | 0.7.6 | 3.2.0 | Use its supported Vite 8 integration. |
| [@vitejs/plugin-vue](https://registry.npmjs.org/@vitejs/plugin-vue/latest) | 4.2.3 | 6.0.10 | Compatible with Vite 8 and Vue 3. |
| [tailwindcss](https://registry.npmjs.org/tailwindcss/latest) | 3.3.2 | 4.3.3 | Migrate CSS and preserve the rendered design. |
| [@tailwindcss/vite](https://registry.npmjs.org/@tailwindcss/vite/latest) | Not direct | 4.3.3 | Replace the current Tailwind PostCSS integration. |
| [@tailwindcss/forms](https://registry.npmjs.org/@tailwindcss/forms/latest) | 0.5.3 | 0.5.11 | Keep existing form styling. |
| [axios](https://registry.npmjs.org/axios/latest) | 1.4.0 | 1.21.0 | Preserve the existing `window.axios` interface. |
| [autoprefixer](https://registry.npmjs.org/autoprefixer/latest) | 10.4.14 | Remove direct dependency | No longer needed after the Tailwind Vite migration. |
| [postcss](https://registry.npmjs.org/postcss/latest) | 8.4.23 | Remove direct dependency | Remove `postcss.config.js` after checking for other consumers. A transitive PostCSS dependency may remain. |

Pinia 4 is ESM-only and requires an explicit devtools peer. TypeScript and Nuxt peers are optional here; do not introduce those stacks. See the [Pinia changelog](https://github.com/vuejs/pinia/blob/v4.1.0/packages/pinia/CHANGELOG.md).

The [Tailwind 4 guide](https://tailwindcss.com/docs/upgrade-guide) changes browser support and several visual defaults. Use modern browsers for acceptance and record any loss of older-browser support. Preserve shadows, borders, focus indicators, spacing, and responsive behavior through class or CSS adjustments where required.

## Execution sequence

### 1. Prepare an isolated baseline

Create the upgrade branch from the recorded baseline and confirm ownership and a clean worktree. Preserve the API-update commit.

Use an isolated Herd workspace for write/browser verification, with its own empty database and synthetic users. Configure it through Herd tools; do not start a second PHP server for an existing Herd site. Verify PHP 8.4 through Herd's site metadata, CLI, and an actual web request. Keep runtime diagnostics local and remove them afterward.

Restore a safe `.env.example`, which Composer's existing setup hook references but the repository lacks. Add a `.gitignore` exception for that template because `.env.*` is currently ignored. Use placeholder credentials, local service settings, and no real SpaceTraders tokens. Configure mail, queues, cache, sessions, and storage for the owned fixture. Never regenerate an existing environment's `APP_KEY`.

Retain the existing baseline test and screenshot evidence. Inventory routes, middleware, migrations, auth behavior, token handling, and configuration before changes.

### 2. Resolve and apply the target package set

Update both manifests to the selected compatible versions, including PHP and Node constraints. Treat the Inertia PHP adapter and JavaScript client as one coordinated change.

Resolve Composer with dependencies enabled and platform requirements intact. Regenerate the pnpm lockfile with the pinned package-manager version and strict peer checking. Review package scripts before allowing necessary dependency builds. Resolve conflicts explicitly rather than using `--ignore-platform-reqs`, disabling audit blocking, or forcing incompatible peers.

Install from the resulting locks and run `composer check-platform-reqs` on Herd PHP 8.4. Include all development packages. Review both runtime and development audit results before declaring the dependency work complete.

### 3. Adapt Laravel and authentication configuration

Apply the combined upgrade-guide changes to the current application structure. Concentrate inspection on these files:

- `bootstrap/app.php`, `app/Http/Kernel.php`, providers, and `app/Exceptions/Handler.php`. Preserve route loading, middleware ordering, guest/authenticated redirects, and the exclusion of tokens and authorization headers from flashed input.
- `app/Http/Middleware/VerifyCsrfToken.php` and references in the HTTP kernel and Sanctum config. Adopt Laravel 13's `PreventRequestForgery` middleware while retaining intended cookie behavior and token checks. Verify request-origin handling through Herd.
- `config/sanctum.php`. Replace the legacy middleware keys with the Sanctum 4 keys and supported classes. Keep `/api/user` authentication working. Compare published migrations with the existing `personal_access_tokens` migration, which already includes `expires_at`; do not add a duplicate table-creation migration.
- `config/cache.php`, `config/session.php`, and related environment keys. Set cache serialization policy explicitly. Preserve current session serialization and cookie/prefix names during this compatibility upgrade; switching session serialization to JSON invalidates active sessions and should be a separate documented decision.
- Existing model casts, filesystem configuration, and date handling. Review Carbon 3 and filesystem changes where they affect actual callers. Keep storage roots and public/private visibility intentional.

The [Laravel 13 upgrade guide](https://laravel.com/framework/docs/13.x/upgrade) documents the forgery middleware, cache, and session changes. Add tests for any application behavior that requires adaptation.

### 4. Adapt Inertia, routing, and the frontend build

Update `resources/js/app.js`, `resources/views/app.blade.php`, `app/Http/Middleware/HandleInertiaRequests.php`, and the Inertia configuration together. Change the root title attribute to `data-inertia`, clear compiled views, and preserve shared auth/error props and page resolution. Review skipped Inertia v1-to-v2 changes as well as v2-to-v3 changes. Existing `AssertableInertia` usage should remain covered.

Check `useForm` processing, `onFinish` token clearing, redirects, validation, back navigation, and reloads. Inertia 3 uses its own HTTP client; existing Axios defaults no longer configure Inertia requests. Retain Axios separately for the existing global interface and verify XSRF behavior in both paths. The optional Inertia Vite plugin and SSR are unnecessary for this migration. See the [Inertia 3 upgrade guide](https://inertiajs.com/docs/v3/getting-started/upgrade-guide).

Replace Ziggy's `../../vendor/tightenco/ziggy/dist/vue.m` import with its supported package entry or a Vite alias to the Composer package. Keep a single source of route metadata and preserve `@routes`. Follow [Ziggy's Vue integration](https://github.com/tighten/ziggy#vue).

Update `vite.config.js`, `resources/css/app.css`, and `tailwind.config.js` for Tailwind's Vite plugin. Move or explicitly load the existing theme and forms configuration. Preserve scanning of Vue, Blade, and Laravel pagination templates. Remove obsolete PostCSS wiring only after the new build works.

Upgrade Pinia and its persistence plugin without adding a consumer for the currently unused store. Verify initialization and saved-state compatibility with a focused fixture. Regenerate all tracked `public/build` assets and inspect the manifest for every Inertia page.

### 5. Verify the complete application on PHP 8.4

Use fresh dependencies installed from the new locks. Update `phpunit.xml` for PHPUnit 13 and migrate any removed APIs. Keep tests on disposable SQLite with an isolated application key and fake outbound HTTP. Resolve application deprecations and PHPUnit warnings instead of suppressing them globally.

Update `scripts/verify.sh` to use the selected PHP and package-manager binaries consistently. Build before Inertia rendering tests. Expand formatter coverage to the PHP files touched by this upgrade without reformatting unrelated code.

Required evidence:

| Check | Acceptance |
| --- | --- |
| Runtime | Herd web requests, Composer, Artisan, and PHPUnit use PHP 8.4. No ignored platform requirements or PHP 8.2 verification container is needed for the final result. |
| Dependencies | Fresh locked installs succeed; strict frontend peers resolve; runtime and dev audits have no unresolved applicable known vulnerabilities. Document any exception explicitly. |
| Backend | Preserve all 66 baseline scenarios and add meaningful upgrade regressions. Authentication, profiles, token handling, ownership, and SpaceTraders forwarding still pass. Do not retain old assertion counts as an artificial target. |
| Browser | Exercise registration/login/logout, password reset, password confirmation/update, email verification, profile update/delete, agent registration/import/listing, API errors, and reloads through real local handlers and persistence. Fake external APIs and mail only at their boundaries. |
| Web protection | With normal web middleware active, verify intended CSRF/origin behavior, session cookies, unauthenticated redirects, Sanctum access, and protected POST/PATCH/DELETE routes. |
| UI/build | Inspect desktop and narrow-screen screenshots, keyboard/focus behavior, field feedback, and navigation. Verify production assets and Herd-managed Vite HMR. Recheck after the final source change. |
| Operations | Artisan boot, route/config/view caches and clearing, migration status, and a fresh disposable-database migration succeed. Preserve the existing database schema unless a reviewed compatibility migration is necessary. |
| Reproducibility | Reinstall from both locks in a fresh environment, rebuild tracked assets, and rerun the final verification commands without unexplained generated changes. |

Run scoped Laravel QA, then an independent security review on the resulting revision. Fix confirmed in-scope findings and rerun affected checks. Save a dark local review report with the final commit, commands, screenshots, audit output, and remaining limitations. No live SpaceTraders account creation is required for local acceptance.

### 6. Deliver and retain a rollback point

Use local commits for coherent changes, with manifests, locks, source adaptations, and built assets kept consistent. Do not present intermediate incompatible server/client versions as accepted checkpoints.

Update README runtime/setup instructions, the verification command, and the package-manager version. Record the exact installed dependency versions, PHP binary, Herd URL, database ownership, audit status, and final test/browser results.

Keep the API-update baseline available for comparison and rollback. If no schema/data migration is needed, code and lockfiles provide the rollback boundary. If a migration becomes necessary, verify a backup and restoration procedure before any persistent-data change. Rolling back to the old lockfile also restores its PHP 8.2 limitation, so it is not a PHP 8.4 fallback.

The implementation stops at a verified local branch and report. Remote publication or deployment requires its own instruction.

## Planning evidence and limits

The candidate Composer manifest was resolved with `composer update --dry-run --no-scripts --no-plugins` under Herd PHP 8.4.27 with platform PHP 8.4.1. It resolved 123 packages, including Laravel 13.39.0, Inertia Laravel 3.6.0, Guzzle 8.3.1, and PHPUnit 13.4.5. Nothing was installed into the application, and the dry run skipped its package audit.

The frontend candidate resolved with pnpm 12.16.1 using `--lockfile-only --ignore-scripts --strict-peer-dependencies` in a separate planning directory. This checks dependency metadata, not native package builds, application compilation, or browser behavior. pnpm added release-age exceptions for newly published Inertia 3.10 packages in that candidate directory. Do not copy those exceptions into the application automatically; select policy-compliant releases or review the exact exception during implementation.

Planning inputs and resolver output are retained at `/Users/Taylor/.codex/artifacts/spacetraders-laravel13-plan-20261031/`. Both candidate directories are outside the repository. The application manifests and lockfiles remain unchanged.
