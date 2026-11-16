# Ship information QA plan

## Scope and evidence

Make existing ship information accurate and complete across the campaign, flight deck, fleet list, ship report, and map-flight summary. Campaign is phone-first: essentials remain visible and native expandable sections expose equipment and all approved observed fields. No deployment, new gameplay actions, upstream reads, polling changes, or relaxed command gates.

The current official SpaceTraders ShipType model was verified through gh on November 15, 2026: 13 types, matching the pinned schema (ShipType blob c188b1017ea2bac6f72ca0171331fba943153e2b). Owned Ship objects do not identify their catalog ShipType. Do not infer it from frame or registration role. Fixtures labeled with catalog types are illustrative coverage scenarios, not official shipyard statistics.

CampaignStatus currently drops equipment, core component details, crew, inventory, cooldown and per-ship observation timestamps. Remaining surfaces still render 0/0. ShipReport destructively merges equipment with equal symbols even when individual observed statistics differ. The current campaign fix and verifier must be retained and generalized.

## Presentation contract

- Derive resource labels from numeric observed capacity: exactly zero means No fuel tank / No cargo hold / No crew capacity; positive capacity retains current / capacity including empty resources; missing/nonfinite values remain Unknown, never zero. Hide meters and free-space arithmetic when not applicable or unknown.
- Campaign overview distinguishes Campaign role from Registration role; shows actual frame identity, resource summaries, equipment counts, readiness and the ship observation time. Freshness/readiness use observed_at, not page updatedAt.
- Expandable sections expose frame/reactor/engine, mounts, modules, crew, cargo inventory, navigation/route, fuel consumption and cooldown. Each equipment instance retains its own fields, order and requirements; duplicate symbols must not discard distinct data. Empty arrays mean None installed; missing arrays mean Not observed.
- All pinned-schema ship fields have an accessible structured-data backstop plus an escaped, wrapped Exact observed values JSON disclosure, so precision and identifiers are not lost to humanization. Reuse DataDetails with a ship-scoped path-aware formatting mode: nav.waypointSymbol means Current waypoint, route.destination means Destination, identifiers/custom registration names stay verbatim. Existing command/preview formatting stays unchanged. Test campaign and ShipReport labels separately. Unknown/future equipment symbols are displayed without assuming abilities. Lists and long text wrap at phone widths. No installed-equipment label asserts action eligibility.
- Condition and integrity are percentages. Component quality is a raw numeric value, NOT a percentage. Crew wages retain their per-crew-member per-hour context.
- A known no-hold ship must not be described as an empty hold. Fuel-free statements are not inferred for arbitrary zero-tank ships. Existing mining capability reasons are explicitly labeled Mining, not general ship capability.

## Backend contract

Each campaign ship gains observed_at and details. details explicitly projects all approved Ship fields and their documented nested fields: symbol; registration; full nav/route; crew; frame/reactor/engine with quality, stats and requirements; cooldown (including recognized local readyAt if present); every mount and module; cargo inventory; fuel consumed. Preserve absent observations rather than fabricate defaults. Keep existing flat campaign properties compatible.

Use a recursive explicit allowlist, not arbitrary snapshot passthrough. Unknown fields at EVERY depth are listed by path only in unlisted_fields (for example mounts[2].newField or frame.newStat), with no unknown values or credentials exposed. State clearly that these fields are not included. Test token-shaped fields both top-level and nested; their paths may appear, their values must never appear. A schema-walking PHP test enumerates every pinned-schema leaf and proves it is projected or explicitly excluded. Existing operator-only access remains unchanged. No remote requests from projection.

The auditable leaf contract is symbol; registration.{name,factionSymbol,role}; nav.{systemSymbol,waypointSymbol,status,flightMode,route.{departureTime,arrival,origin.{symbol,type,systemSymbol,x,y},destination.{symbol,type,systemSymbol,x,y}}}; crew.{current,required,capacity,rotation,morale,wages}; each core component's {symbol,name,description,condition,integrity,quality,requirements.{power,crew,slots}} plus frame.{moduleSlots,mountingPoints,fuelCapacity}, reactor.powerOutput and engine.speed; cooldown.{shipSymbol,totalSeconds,remainingSeconds,expiration,readyAt}; mounts[].{symbol,name,description,strength,deposits[],requirements.{power,crew,slots}}; modules[].{symbol,name,description,capacity,range,requirements.{power,crew,slots}}; cargo.{capacity,units,inventory[].{symbol,name,description,units}}; fuel.{current,capacity,consumed.{amount,timestamp}}. The schema walk resolves refs and arrays and catches any overlooked pinned leaf; it is authoritative over this prose list. Local cooldown.readyAt is an explicit recognized extension. No implicit exclusion is allowed.

Measure serialized payload for the 13-ship synthetic fleet and record actual bytes. Add a generous deterministic 200 KiB regression ceiling for that fixed fixture only; it is not a production fleet cap. Do not invent a fallback/lazy-loading architecture unless measured payload actually warrants it; stop and report if the fixture exceeds the ceiling rather than silently changing request behavior.

## Implementation units

1. Coordinator creates ONE canonical JSON matrix at tests/Fixtures/ship-information.json, consumed directly by PHP seeding/tests and Node/browser checks; no separately maintained PHP/JS matrix. It contains provenance, scenarios[{id,ship_type,synthetic,ship}], with metadata outside ship. Derive canonical ShipType and mount/module symbol coverage mechanically from their pinned schema enums (not TradeSymbol). Add narrow pure presentation helpers. Cover every pinned ShipType, all canonical mount and module symbols (including future/unknown symbols), null/absent/empty/full resources, partial values such as 12 / Unknown, duplicate symbols with different fields, missing versus empty arrays, custom long names, partial core data, transit/cooldown, and sensitive-field exclusion. Include exact precision 0.991 versus 0.994, non-unit quality without percent, and distinct same-symbol requirements before/after polling.
2. Extend campaign projection and PHP tests against the matrix and schema. Keep all current behavior compatible, use per-ship timestamps, and verify credentials/unknown values never leave the server.
3. Build reusable read-only ship-detail/equipment disclosure components. Reuse them in campaign and ShipReport. Generalize resource labels in Dashboard, fleet list, ShipReport and MapFlightTask, changing no command predicates or disabled states. Keep campaign disclosures keyed by ship symbol and stable under polls.
4. Extend focused Node SSR and unit checks. Run real local Laravel browser fixture at 320, 390 and 820px: all archetypes and equipment accessible, no overflow, touch summaries at least 44px, keyboard disclosure operation, duplicate detail fidelity, and opened disclosure survives an Inertia poll. Save runtime screenshots without touching production or real game state.
5. Run targeted PHP suites, existing presentation/ship scripts, build and diff checks. Restore only build artifacts generated by our checks. Record coverage/limitations in a concise repo QA report. Obtain three-member implementation council approval before handoff.

## File boundaries

Backend writer: app/Gameplay/CampaignStatus.php, tests/Feature/CampaignStatusTest.php, tests/Support/CampaignStatusFixture.php, tests/Browser/campaign-status.php; PHP support only loads the coordinator-owned tests/Fixtures/ship-information.json. Coordinator owns that canonical matrix, pure helpers, UI components/pages (including ship-scoped DataDetails formatting), Node/browser verifiers and QA report. Writers share no editable files. Tests use local synthetic data and prevent upstream requests. Unrelated untracked docs are untouched.

## Plan review disposition

Round 1: Sol APPROVE; Opus CHANGES REQUIRED (ship-specific field labels, nested unknown paths); Muse CHANGES REQUIRED (auditable leaf coverage, single canonical matrix). This revision addresses all four requirements. Independent audit precision finding is addressed by the exact-value disclosure. Optional partial-state, quality and duplicate-requirement tests are included. No new stale alarm or gameplay eligibility logic is introduced; MapFlightTask's capacity-zero manual-limits predicate stays unchanged. Payload reporting includes measured per-ship bytes and clearly labeled arithmetic fleet projections, not claimed production measurements.

## Review gates

Review this completed plan before implementation with fresh independent Opus 5.5 high, Sol 6.1 high and Muse Wonder 1.0 high. Incorporate valid findings and follow the user's maximum-three-round council checkpoint. Repeat a full council for the verified implementation. Optional suggestions do not expand scope automatically.
