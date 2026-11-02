Review is complete. The branch has since moved to evidence-only commits (`0f12782`, `5ed08e4`) on top of the code HEAD. Nothing under `app/`, `routes/`, `config/`, `resources/`, `database/` or `tests/` changed after `308ccb9`; only `scripts/generate-foundation-ledger.py` and docs did. Everything below is against code HEAD `308ccb9`. I wrote nothing to the repo. The one repro I built lived in `/tmp` and I deleted it.

# Final security review: PR #87, code HEAD `308ccb9`

## Verdict
There is one **Medium** finding that needs a fix before you call the manual write path settled. I found no injection, IDOR, CSRF, credential-mixing or secret-leak defect. This is not a clean full-app review and not a live acceptance. The official-API gate stays at 0/59.

## What I actually ran
- **Targeted backend tests:** `FoundationBoundaryTest`, `FoundationCommandTest`, `FoundationReadTest` and `AgentTest`, run through `scripts/herd-php`. 103 tests, 697 assertions, all passed.
  - These use in-memory SQLite, `Http::fake`, `preventStrayRequests` and a temp lock directory. The worktree has no `.env`.
- **Repro of finding 1:** a throwaway PHPUnit test in `/tmp` against the same fixture world.
- **Coverage verifier:** the default check passes (59 source-mapped, 0 accepted), and `--require-complete` fails 0/59. I did not use `--refresh`.
- **Not re-run:** the 469-test full suite, frontend build, Pint, browser journeys, real-worker race harnesses and `route:list`. I took their results from the stated parent evidence. For routes I read `routes/web.php` directly.
- **Source inspected:** all 20 changed app and config files, the Vue components and Dashboard diff, the pinned 2.3.0 schema, the plan, the research doc and the prior QA review.

## Findings, by severity

### 1. Medium (confirmed by repro): a budget deferral after a received write response reverts the intent to `pending`
- **Where:** `app/Gameplay/ShipCommandExecutor.php:200-209`, with the post-receipt GETs at `:283` and `:364`. The scheduler requeues pending intents at `app/Console/Commands/DispatchDueSpaceTraders.php:64`.
- **Defect:** the `RequestDeferred` catch never checks `$received`. A genuine write response is stored before `confirm()` runs. `confirm()` then issues follow-up `GET /my/ships/{symbol}` calls for the four equipment commands and for any response with `events`. If the request budget defers one of those GETs (cooldown, or "storage is busy" under worker/browser contention), the action goes back to `pending` with `sent_at=null`. The scheduler then redispatches it.
- **Repro result:**
  - A successful `install_mount` write was recorded.
  - The first execute ended with state `pending`, message "simulated budget cooldown", and the receipt present.
  - The second execute ended `rejected` with "Installation requires an unprotected matching component in cargo."
  - The fixture shows exactly one write.
- **Impact:**
  - **No replay today.** The write was not repeated, but only because `unchangedApproval` and `inspect` happen to fail afterward. That is an accidental barrier, not the designed one.
  - **False rejection.** A real, paid, irreversible write is recorded as `rejected`. `confirm()` never ran, so no credit entry, no `review_required` flag and no validated effect were saved.
  - This is the exact outcome acceptance criterion 3 forbids ("not converted into a false rejection because a downstream bookkeeping write failed").
  - Any later command whose preflight still passes after its own write would be replayed.
- **Cheap regression:** keep my repro as a permanent test. Force `RequestDeferred` after the receipt is saved and assert the state is `uncertain` and the fixture write count is 1. Fix: in the catch, only revert to `pending` when `! $received`; otherwise mark `uncertain`.

### 2. Low-Medium: any 401 marks the saved agent credential `invalid_credential`, including for the unresolved inherited-auth operations
- **Where:** `SpaceTradersApi.php:536-538`, applying to `get-faction` and the module reads/writes.
- **Impact:** the pinned schema's root `security` is `AgentToken AND AccountToken`, and your design deliberately sends only the agent bearer. If the live server answers 401 to that, one manual read downgrades a valid token to "Token needs attention". That state also feeds the `connected` check in `AutomationRunner.php:199`. The unresolved auth gate then damages credential state.
- **Fix and regression:** skip the credential downgrade for those four operations (record "auth unresolved" instead), and add a test with a faked 401 on `get-faction`.

### 3. Low: a non-string `operation` or `command` returns a 500
- **Where:** `AgentReadController.php:15` and `GameplayRequest.php:~32`.
- **Defect:** `(string) $request->input('operation')` throws an array-to-string `ErrorException` when the input is an array (`operation[]=x`).
- **Regression:** one assertion that `operation[]=x` returns 422 rather than 500.

### 4. Low (reasoned, not reproduced): Stop after reservation rewrites `outcome` from a snapshot
- **Where:** `stopManual`, `ShipCommandExecutor.php:132-137`.
- **Defect:** state is protected by the conditional update, as the previous QA required. `outcome` is still merged from a snapshot read earlier in the transaction. `lockForUpdate` does nothing on SQLite, so a worker receipt written in between could in theory be overwritten.
- **Impact:** on SQLite I expect a busy/snapshot error (a Stop 500 that `result()` does not catch) rather than a silent overwrite, but I did not test it. An atomic JSON merge or a re-read would close the gap.

### 5. Informational: local policy wording and UI
- **Refine:** the 100-unit check counts total cargo units (`CommandRules.php:175`). The official text says "100 basic goods will be converted into 10 processed goods".
- **Transfer:** requires both cooldowns ready (`CommandRules.php:367`). The official text does not require that, and the message does not call it a client policy.
- **Destructive styling:** `contract_deliver`, `install_*` and `warp` do not get the destructive approval style. Only scrap, jettison and supply do. Contract acceptance creates real obligations and has no Danger styling.
- **Scrap:** there is no last-ship or command-ship guard. The exact-symbol confirmation and acknowledgement are the only friction.
- **Observation history:** `observe('manual_read', …)` appends a full response to `observation_history` on every manual read. That is bounded only by the request budget, and nothing prunes it.

## Corrections from the previous QA, as verified
| Previous finding | Status |
| --- | --- |
| Agent-only contract commands used the selected ship | Fixed. `preview` forces `''`, `inspect` ignores the ship, `unchangedApproval` skips it, and a regression test covers an in-transit ship. |
| Contract terms, rewards and deadlines not shown | Fixed. `ContractApproval.vue` renders them from `before_state.contract`, and each contract command has `effects` text. |
| Reserve optional server-side | Fixed. The 2000 CR floor is applied in `CommandRules.php:63-64` for every credit-spending command. I checked each one: buy, buy_ship, repair, the four equipment commands, jump and market refuel. |
| Stop overwrote `sending` | State is fixed (lock plus conditional update, tested). The `outcome` residual is finding 4. |
| Manual reads wrote unvalidated data | Fixed. The canonical normalizers run before `observe()`, and a test confirms malformed market, shipyard and waypoint reads change no observation. |
| Client policies unlabeled | Mostly fixed, apart from the transfer cooldown and the refine unit semantics in finding 5. |
| Numeric canonicalization | Fixed. Every integer-rule field is cast in the controller. |
| Pagination | Fixed. Next/Previous use the frozen `result.parameters` and `meta`. |
| Destructive approval styling | Partly fixed (see finding 5). |

## Areas with no defect found
- **Ownership and IDOR:**
  - Reads and previews are owner-scoped.
  - Approve, stop, reconcile and acknowledge are scoped by `agent_id`.
  - Ship, transfer-target and contract identifiers are validated, and an unowned ship makes no private upstream call.
  - Tests cover foreign users getting 404.
- **CSRF:** `PreventRequestForgery` is in the web group with an empty exception list. A test exercises real 419 behavior with valid controls.
- **Credentials and auth:**
  - All gameplay requests use a single agent bearer.
  - Registration alone uses the account bearer, which is never persisted.
  - Public reads send no `Authorization` header.
  - The token is hidden on the model, `dontFlash` covers `token` and `account_token`, and the budget keys are SHA-256 hashed.
  - Per the pinned schema, market and shipyard accept the agent token, and faction and module routes inherit the root requirement; the code matches this.
- **Path and body mapping:** every path segment is regex-limited. `Arr::only` builds each body, PATCH goes through an explicit mapping, and there is no upstream-URL, method or header input.
- **No SSRF or XSS:** the forwarder is whitelisted to GET `/` and `/factions`, and there is no `v-html` anywhere.
- **Response handling:**
  - The receipt (status plus body hash) is saved before validation and bookkeeping, apart from finding 1.
  - Scrap deletes only the exact ship row and keeps history, since `ship_actions.ship_symbol` is a plain string with no foreign key.
  - A missing ship alone never confirms an uncertain scrap.
  - Reset and engine-drain checks run before reservation, and `sending` rows are never invalidated.

## Local policy versus official mechanics
- **Official, matching the schema:**
  - Scrap needs a docked shipyard.
  - Transfer needs the same waypoint and the same docked/orbit state.
  - Warp needs orbit and a warp drive.
  - Jump needs orbit and a connected waypoint.
  - Construction supply needs the good in cargo and an under-construction site.
  - Refuel from the market works in 100-unit steps.
- **Local client policy:**
  - The 2000 CR floor.
  - The mining 50% condition floor.
  - The CRUISE fuel-seller and continuation requirement.
  - The orbit and cooldown readiness requirements for scans.
  - The 100-cargo-unit refine floor and the transfer cooldowns.
  - Jump gate-type and drive-module requirements.
  - Cargo refuel requiring dock plus a zero cash ceiling.

## Gates still open (none verified live or by me)
- Inherited faction and module auth, which is unresolved at the schema level.
- Equipment fees, refine recipe and rounding, warp and non-CRUISE formulas, construction location rules, and the cargo-refuel rounding rule.
- World-dependent cases that need equipment, credits or targets: siphon, jump, warp and supply.
- Every numerical budget.
- The one-representative-variant browser evidence the parent documents.

**Bottom line:** fix finding 1, probably fix finding 2, and treat the rest as hardening. Taylor's authorization for the remaining stages is outside this review.

PR https://github.com/giantsoup/spacetraders/pull/87, head 308ccb9c86d9180b05fd5d05934d0bc5c069131c, checks and reviews not checked by this review (no remote CI or review state read; branch has since advanced to evidence-only `5ed08e4`)
