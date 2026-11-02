## Independent QA review of b1b0b89 against base 98ff5d7

HEAD is exactly `b1b0b8922c2174343c876f7a1d0f45fdeb09879e`. I made no edits and no live calls, and I did not read `.env`, credentials or databases.

I did not re-run the backend, frontend, worker or browser checks. The only thing I ran was the read-only coverage verifier: it passes integrity and `--require-complete` fails at 0/59, as intended. I also opened the three committed screenshots.

Nothing below is a blocker for a fixture-only claim. Findings 1–3 should be fixed before calling the manual UI usable.

### Findings

**1. Medium: agent-scoped contract actions are treated as ship actions when launched from the UI.**
- Files: `resources/js/Components/ManualCommandForm.vue:16`, `app/Gameplay/CommandRules.php:17-26`, `ShipCommandExecutor.php:~64-110`.
- The form always sends `ship_symbol: props.selected`, even for `contract_accept` and `contract_fulfill`. The backend only skips the ship when the symbol is `''`.
- With a selected ship, preview refreshes that ship and rejects it with "Ship is in transit" when it is in transit. `unchangedApproval` then also invalidates the approval if that unrelated ship's fuel, cargo or equipment changes.
- The tests (`FoundationCommandTest.php:124`) pass `''`. The 31 browser journeys pass a docked, quiescent `LOCAL-1`, so neither covers this path.
- Fix: send `''` for those two commands, and make `CommandRules` and `unchangedApproval` ignore any ship symbol for `agentCommand`. Add a test with a selected in-transit ship.

**2. Medium: the approval panel never shows contract terms, rewards or deadlines.**
- Files: `resources/js/Pages/Agent/Dashboard.vue:~349-362`, `CommandRules.php:257-272`.
- Nothing in `resources/js` reads `before_state.contract`.
- The accept, deliver, fulfill and negotiate cases set no `estimate.effects`, so the panel shows only the raw arguments, such as `contractId`.
- That falls short of FND-04: acceptance obligations and rewards are real side effects, and the plan requires the preview to show exact terms. The form text says to look in the API library instead.
- Fix: render contract terms, deadlines, payment and remaining units from `before_state.contract` in the panel, and add `effects` text for each contract command.

**3. Medium: the protected-credit reserve is optional server-side and defaults to 0 in the form.**
- Files: `ManualCommands.php:69` (`$field !== 'reserve'`), `CommandRules.php:63`, `ManualCommandForm.vue:7`.
- A spending preview that omits `reserve` gets a floor of 0. Buy, ship purchase, repair, equipment install/remove, jump and refuel can all drain credits to the exact quote or `maxTotal`.
- `maxTotal` also defaults to 0, so the form fails safe there. It does not fail safe for reserve.
- Fix: require `reserve` for every command that can spend, or default it to a configured non-zero floor. Show the effective reserve in the panel, and test an omitted reserve.

**4. Medium (hardening): manual stop uses an unconditional state write.**
- File: `ShipCommandExecutor.php:115-132` (`stopManual`).
- It runs `refresh()` then `update()` by id, without checking `state`. The reservation uses `where state='pending'`.
- On SQLite the transaction probably fails safely, and the file-backed race harness passes. On any engine that allows the read-then-write interleaving, a Stop could overwrite `sending` with `rejected` after the request went out. The record would then read "No manual request was sent" while a write was in flight, and the uncertainty would be lost.
- Fix: use `whereKey()->whereIn('state', [preview, pending])->update(...)` and branch on the affected-row count. Add a test that forces the reservation between refresh and update, or document the SQLite-only assumption.

**5. Medium-low: manual reads write unvalidated raw upstream data into the planning observations.**
- File: `app/Gameplay/SpaceTradersApi.php:59-76` and `:99-103`.
- For `get-market`, `get-shipyard` and `get-waypoint`, manual-read validation only checks `symbol`.
- The result is then stored as the canonical `market`, `shipyard` or `waypoint` observation, bypassing the field checks in `market()`, `shipyard()` and `waypointRows()`. Those checks cover `tradeGoods.*.purchasePrice`/`sellPrice`/`tradeVolume`, `ships.*.purchasePrice` and the waypoint field whitelist.
- Strategy and fuel code reads those observations later. Malformed upstream data could reach it.
- Fix: run the existing normalizers before `observe()`, or store only the `manual_read` record for these three operations.

**6. Low-medium: some manual commands still enforce strategy-era policy that the preview does not label.**
- Mining: `mine`, `mine_survey` and `survey` require a mining laser, a mineral processor, a 50% condition floor and a non-hazard asteroid (`CommandRules.php:303-309`, `:336`; `MiningPlanner.php:11-36`, `:196-204`). `siphon` and `refine` have their own checks, including a 100-unit input floor for refine.
- Navigation: default CRUISE navigation without `maxFuel` requires `FuelPlanner::leg`, which needs known fuel sellers, recent market quotes and a rescue continuation (`CommandRules.php:289-295`, `FuelPlanner.php:15-55`).
- Navigation also needs a waypoint observation under an hour old (`CommandRules.php:470-478`). A library `get-system-waypoints` list read does not create one, so the user gets "Synchronize first".
- The plan says to separate game rules from optional restrictions. Each of these is safety-motivated, but none states in the preview that it is a local policy with an override, and the CRUISE failure messages do not point to the explicit variant.
- Fix: label each as a client policy in the preview and error text, and say how to use the override variants.

**7. Low: numeric argument normalization is incomplete.**
- File: `app/Http/Controllers/AgentCommandController.php:17-27`.
- `maxFuel`, `maxSeconds`, `protectedFuel` and `maxCargoUnits` are not cast to int. The `integer` rule accepts numeric strings.
- A non-UI client could persist `"5"` and store a different `arguments` type from the UI.
- Fix: cast these four to int in the controller, or normalize them in `ManualCommands::rules`.

**8. Low: usability gaps in the new forms.**
- `ManualReadBrowser.vue:70`: Next-page enablement uses the live `form.limit`, not the displayed `result.meta.limit`. Changing the limit after loading page N makes Next skip or repeat rows.
- `ManualReadBrowser.vue:20`: system and waypoint fields are only auto-filled when empty, so they go stale when the ship selection changes.
- `ManualCommandForm.vue:7`: `symbol` is a free-text input defaulting to `IRON_ORE`, used for equipment as well. `foundationEnums.TradeSymbol` is available but unused.
- `Dashboard.vue:~363`: every manual command's approval panel shows "Scouting movement also requires this explicit approval." That line is wrong for jettison, scrap, contracts and others.
- `Dashboard.vue:~364`: scrap is approved with the same primary "Approve command" button as `orbit`, with no destructive styling in the panel.

### Test and evidence gaps

- **Stop in the browser:** every one of the 31 browser journeys records `stopped:false`. Stop is only covered by PHPUnit and the file-backed race worker, which exercises orbit and PATCH only.
- **Variant coverage:** each command had one browser journey, on one variant. That means refuel by cargo only, unknown-variant navigate only, nested-survey `mine` only, and `flight_mode` BURN only.
  - Not exercised in the browser: plain extraction, market refuel, standard CRUISE navigate, and omitted `flightMode`.
  - The inventory's per-row text says "synthetic browser plus file-backed worker confirmed this action" identically for all POST rows. That overstates variant coverage.
- **Uncertain write recovery:** the file-backed worker covers only repair and PATCH lost-response behavior, plus the earlier mining/purchase kill case. Navigate, jump, warp, supply, siphon, refine, install/remove and contract writes have no real-worker lost-response check. Scrap is covered only at unit level (`FoundationCommandTest.php:386`).
- **Screenshots:** the 390px image shows only the Manual API dialog with the default "Enter orbit" action. The scrap image shows history after the fact. There is no screenshot of a long form (refuel or navigate), or of the contract, equipment or scrap approval panels.
- **Missing regressions:**
  - Contract commands with a selected ship (finding 1).
  - An omitted reserve (finding 3).
  - A Stop and reservation interleaving that is not SQLite-specific (finding 4).
  - Malformed market or shipyard data from a manual read (finding 5).

### Checked and found consistent

- **Request bodies and PATCH:** the request bodies and verbs for all 32 gameplay writes match the pinned schema. PATCH goes only through the explicit `flight_mode` mapping.
- **Auth:** inherited-auth operations use only the agent bearer, with no account-token fallback.
- **Read shape:** the 204 cooldown and the derived-versus-raw deadline separation behave as the plan describes. Repeated `traits` query parameters are sent per OpenAPI.
- **Response fields:** every write's `responseFields` set matches the schema. The `events` and `modifiers` fields are kept where the schema has them.
- **Receipt and replay:** the received-status and body-hash receipt is saved before bookkeeping. A post-receipt failure becomes `uncertain`, and `execute` returns early unless the state is `pending`, so there is no replay.
- **Scrap:** it requires the exact ship symbol, an acknowledgement, no active run, and no protected cargo. A confirmed scrap deletes only the exact ship and retains the history row and credit entry.

### Limits

- **Not run:** I did not re-run the backend suite, Vue build, workers, browser journeys or Pint.
- **Not reviewed:** untracked files that appeared after HEAD, such as the capability map, operation ledger, final-verification text and route-access CSV, are outside this review.
- **Fixture only:** the fixture world is not evidence of server mechanics.
- **Live acceptance:** nothing here is official-API acceptance. The coverage gate stays at 0/59.
- **Still open:** inherited faction and module authentication, live calibration and numeric budgets, refinery and rounding rules, warp and non-CRUISE formulas, and construction behavior.
- **Not blessed:** I do not bless any unrun live acceptance.

PR https://github.com/giantsoup/spacetraders/pull/87, head b1b0b8922c2174343c876f7a1d0f45fdeb09879e, checks and reviews not checked by this review (no live or remote state read)
