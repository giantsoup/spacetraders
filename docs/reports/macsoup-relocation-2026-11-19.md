# MACSOUP relocation fallback: recommendation, 2026-11-19

Status: prepared for implementation council review. This report covers relocation only. The
approved core fuel plan is not reopened here. The investigation used read-only local state and
public documentation. It made no game API call or gameplay, engine, or production change.

## Question and short answer

Could moving MACSOUP to a new system help with low yield in X1-VK6?

Possibly, but the evidence can't show it, and MACSOUP-1 cannot leave the system under the
current code. Warp is impossible with the installed equipment. The local jump guard requires a
jump-drive module that the ship doesn't have. No gate connection, destination system,
antimatter price or destination market has ever been observed. The only costable part is the
in-system approach to the gate, and that buys nothing until a jump is possible.

Recommendation: don't relocate now. Complete the fuel correction in development and leave
the existing campaign under its unchanged authorization. Deployment and activation of the
correction remain separate choices. Option B below is the proposed next evidence step for
relocation. Decide on any relocation feature after that evidence is available. Nothing in this
report predicts income in any system.

## Known facts

From the coordinator's read-only check of the live SQLite copy at 2026-11-19 03:57:16Z, unless
noted.

| Item | Value |
| --- | --- |
| MACSOUP-1 | Docked at X1-VK6-A4, CRUISE, speed 36, cargo 0/40, fuel 400/400 |
| MACSOUP-1 modules | CARGO_HOLD_II, CREW_QUARTERS_I ×2, MINERAL_PROCESSOR_I, GAS_PROCESSOR_I. No warp or jump drive |
| MACSOUP-2 | Probe, no modules, fuel capacity 0 (fuel-free), in X1-VK6 |
| Known data | 82 waypoints and 25 markets, all in X1-VK6. Zero waypoints or markets in other systems |
| Gate | X1-VK6-I53, observed completed (`isUnderConstruction: false`) at 03:06:33Z in the baseline |
| Gate connections | Never observed. No jump-gate or construction reads recorded |
| History | Zero jump or warp actions |
| Fuel quotes | A4 ask 72 (03:53:20Z), I54 ask 80 (01:58:04Z), I53 ask 104 (01:46:16Z) |

The I54 and I53 quotes are about two hours old, beyond the 180-second execution freshness
window (`gameplay.snapshot_seconds`). They support provisional fuel planning within the
six-hour bound, but refueling needs fresh quotes before execution.

## What the code allows today

- Jump guard ([CommandRules.php:177-211](../../app/Gameplay/CommandRules.php)): requires a
  `MODULE_JUMP_DRIVE_*` module, a ready cooldown, completed gates at both ends, a live
  connection read, and an explicit `maxTotal` because the antimatter fee is unknown. MACSOUP-1
  fails the first check. This fix must not remove that guard.
- Warp branch (same lines): requires `MODULE_WARP_DRIVE_*`. MACSOUP-1 has none.
- Navigation is same-system only
  ([CommandRules.php:335](../../app/Gameplay/CommandRules.php),
  [ShipCommandExecutor.php:390](../../app/Gameplay/ShipCommandExecutor.php)).
- [FuelPlanner](../../app/Gameplay/FuelPlanner.php) plans same-system legs only (line 26).
- Campaign strategies are `scout`, `trade`, `mining` and `sell_aboard`
  ([CampaignPlaybook.php:49](../../app/Gameplay/CampaignPlaybook.php)). None relocates.
- [CampaignDiscovery](../../app/Gameplay/CampaignDiscovery.php) seeds its cursor from the
  ships' current systems (up to three, line 15) and keeps it until the cycle finishes and clears
  it (line 53). The next cycle re-seeds from wherever the ships are, but a move during a cycle
  goes unnoticed until then. Whether the rest of the campaign copes with a ship in a new system is
  untested. For example, the evaluator skips ships outside its selected system
  ([OpportunityEvaluator.php:64](../../app/Gameplay/OpportunityEvaluator.php)).

There is no autonomous relocation support. A manual jump is blocked by local policy.

### Official API versus local policy

The official OpenAPI spec (v2.3.0,
[SpaceTraders.json at 45fbb04](https://github.com/SpaceTradersAPI/api-docs/blob/45fbb04130aca3fa0bd9a634ab77b35fa6c468ab/reference/SpaceTraders.json))
describes jump as needing only orbit and a connected destination, and says one unit of
antimatter is bought automatically at a market price. It lists no module requirement. Warp
requires the Warp Drive module. The coordinator reports that the official navigation guide
(<https://spacetraders.io/game-concepts/ship-navigation>) also requires a warp drive for warp
and describes antimatter purchases at connected gates.

So the server may allow MACSOUP-1 to jump, but the local guard is stricter. Relaxing it is a
separate feature with its own plan review, not part of this fix.

## Cost of the in-system approach to the gate

This is an approach within X1-VK6 only, not an interstellar route.

The direct leg A4 (26, 9) → I53 (209, −400) is 448.07 units. FuelPlanner's conservative
allowance is ceil(distance) + 2 = 451 fuel, plus the 2-unit reserve. That exceeds the 400-unit
tank.

Among the enumerated approaches with one intermediate recorded FUEL seller, A4 → I54 → I53
has the smallest conservative burn, with legs of 228 and 226 fuel. Each leg plus the 2-unit
reserve fits in the tank if the ship tops up at I54.

| | Outbound A4→I54→I53 | Return I53→I54→A4 | Round trip |
| --- | ---: | ---: | ---: |
| Fuel burned (allowance) | 454 | 454 | 908 |
| Illustrative refill cost¹ | 552 CR | 456 CR | 1,008 CR |
| Safety ceilings, fresh² | 1,472 CR | 1,216 CR | 2,688 CR |
| FuelPlanner travel time³ | 342 s | 342 s | 684 s |

1. Whole 100-fuel market units bought at each stop to replace that leg: 3 × 80 (I54) +
   3 × 104 (I53) outbound; 3 × 80 (I54) + 3 × 72 (A4) return. Uses the stale asks above.
2. FuelPlanner ceiling per stop = ceil(400/100) × ask × 2: 640 (I54) + 832 (I53) + 640 (I54) +
   576 (A4). A stale quote doubles the ceiling again, up to 5,376 CR.
3. `FuelPlanner::seconds`: 172 + 170 s each way. Excludes API waits, docking, quote refreshes
   and displaced trading time.

Other candidates in the protected route-facts file (via K82, B6 or B7) burn more fuel (470–715)
and have higher illustrative costs.

The departure itself can't be costed. The antimatter price, jump cooldown, return jump cost and
any destination fuel supply are unknown.

## Options

| Option | What it is | Cost / risk | Can it start now? |
| --- | --- | --- | --- |
| A. Stay | Finish the fuel correction in development; leave the existing campaign unchanged | No relocation cost | Implementation is authorized. Deployment and activation still need separate decisions |
| B. Evidence step | Read the I53 gate's connections and fresh I53/I54 fuel quotes. Optionally send fuel-free MACSOUP-2 to I53 to observe its market | A few API reads; probe travel time; MACSOUP-1 stays put | No. Needs approval for network reads and probe navigation |
| C. Manual jump by MACSOUP-1 | Approach I53, then jump | Blocked by the local guard. Needs B's evidence plus a reviewed guard change | No |
| D. Warp | Fit a warp drive, then warp | Needs a module purchase and a shipyard. Fit, price and stock unknown | No. Out of scope |
| E. Relocation feature | Cross-system discovery, planning and campaign support | Large change across planner, campaign and executor | No. Needs its own plan and review |

Option B is the proposed first step to learn whether relocation is worth pursuing. Option C makes
sense only if B finds a connected system with markets that look better than X1-VK6 on recorded
evidence. Even then, an arrival is a one-way bet until a return path and its cost are known.

## Evidence gaps

A relocation decision needs the following unresolved evidence.

1. **Gate connections.** Which systems X1-VK6-I53 connects to (`get-jump-gate`).
2. **Destination quality.** Waypoints, market listings and fresh prices in a connected system.
   Fresh prices generally need a ship present.
3. **Jump cost and timing.** Antimatter price at I53, the jump cooldown, and the cost of a
   return jump.
4. **Jump eligibility.** Whether the server lets a ship without a jump drive jump. The OpenAPI
   lists no jump-drive requirement. Resolve the difference from local policy through
   documentation or a reviewed test plan before any attempted jump.
5. **Fuel along the way.** Fresh fuel asks at I54 and I53 (stale since 01:58:04Z and 01:46:16Z),
   and fuel availability at the destination for working and returning.
6. **Code behavior after a move.** How discovery, the opportunity evaluator, same-system checks
   and FuelPlanner behave once a ship sits in a new system. Needs code review and tests.
7. **Baseline yield.** A measured yield for X1-VK6 under the core fuel plan. Without it there is
   nothing to compare a destination against.

## Approval boundary

Current authorization covers implementing the approved core fuel plan and this investigation.
It does not cover any of the following. Each needs Taylor's separate approval:

- Game API reads or writes: gate, system or market reads, sync, registration.
- Ship actions: navigation (MACSOUP-2 included), refuel, orbit, jump, warp, purchases or module
  fitting.
- Campaign controls: approve, pause or resume.
- Engine, schedule or production changes, merges or deploys.
- Code changes to the jump guard, FuelPlanner, campaign discovery or strategies. These go
  through a plan and council review as a separate feature.

## Sources

- Live SQLite facts: coordinator's read-only check at 2026-11-19 03:57:16Z. The protected
  snapshot contains credentials and was not read for this report.
- Route candidates: protected file `relocation-route-facts.json` in the coordinator's private
  investigation folder. Fuel arithmetic checked against
  [FuelPlanner.php](../../app/Gameplay/FuelPlanner.php) and `config/gameplay.php`
  (`fuel_reserve_units` 2, `snapshot_seconds` 180).
- Official API: OpenAPI v2.3.0 at commit `45fbb04130aca3fa0bd9a634ab77b35fa6c468ab`, fetched by
  the coordinator with `gh`. Official navigation guide as reported by the coordinator. Not
  re-fetched for this report.
