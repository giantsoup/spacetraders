# Local early-game automation loops

Researched 2026-11-01 against official documentation and the live bundled OpenAPI v2.3.0. This is a planning note. No credentials, authenticated game reads, gameplay actions, queue starts, or profitability tests were used.

## Recommended starting point

Start with one ship and one extraction-and-sale trip, provided discovery confirms an equipped miner, an accessible asteroid, a buyer for its yields, and enough fuel for the route. If a market at the extraction site buys the output, use it to avoid a hauling leg. If these prerequisites are missing, show what is missing before spending. This recommendation is an engineering judgment, not a claim about Taylor's current fleet or market.

## Loops we can automate

### Extract and sell

Orbit at the extraction site, extract, wait for the returned cooldown as required, travel to a verified buyer, dock, and sell. Extraction yields vary with equipment and location. Selling requires a market trading the cargo. Gas siphoning uses a different setup: a gas giant, siphon mount, and gas processor. Surveys can target yields but expire or exhaust, so leave them for a later iteration. [Extraction guide](https://docs.spacetraders.io/game-concepts/extracting-resources), [OpenAPI](https://api.spacetraders.io/v2/documentation/json).

Mined cargo has no purchase bill, but fuel, equipment, repair costs, and time still count. A short route is a better first experiment than a large new ship purchase. Keep the same-site selling option conditional on discovered market data.

### Contract delivery

Read the actual offered contract, compare its payment, required goods, destination, and deadline, then decide whether to accept. Deliver the required cargo and explicitly fulfill after every delivery term is met. Contracts specify an upfront payment and a completion payment; delivery removes cargo. [OpenAPI](https://api.spacetraders.io/v2/documentation/json).

Reserve contract cargo before selling other output. Compare the total payment with acquisition and travel costs. For mined goods, also compare selling those goods directly. An acceptance payment alone does not prove that completing the contract is profitable.

### Buy, haul, sell

Buy a small batch at a cheap market, fly to a buyer, and sell. Export-to-import routes are candidates. Prices respond to supply and demand, and detailed prices require a ship at the market. A probe can observe prices. [Market guide](https://docs.spacetraders.io/game-concepts/markets).

Both buying and selling are limited by the good's per-transaction `tradeVolume`. Purchases require docking and the good to be offered for sale. [OpenAPI](https://api.spacetraders.io/v2/documentation/json).

This loop needs working capital and fresh observations at both ends. Require a margin after round-trip fuel and a buffer for price changes, then recheck between batches. Do not buy an entire hold based on one old quote.

## Suggested first run and scorekeeping

These are proposed controls, not existing UI promises:

- Preview a single trip with the ship, site, buyer, fuel estimate, spend ceiling, credit reserve, and stop condition visible.
- Start with one cycle and require another explicit start after reviewing the result.
- Stop launching new actions when Stop is pressed. An API request already sent may finish, and a ship already travelling still arrives on the remote game server.
- After stopping or a Mac restart, refresh actual ship, cargo, fuel, credits, reset identity, and market state before offering resume. Do not replay uncertain trades automatically.
- Show starting credits, current credits, sales, fuel and other spending, and net cash change. List unsold cargo separately.

For the first mining trip, measure sales receipts minus fuel and other attributable operating costs. Also show total cash change from the original starting balance, including any ship or equipment purchases. A profitable operating trip can still leave cash below the original balance while recovering setup costs. Track fuel inventory changes when comparing cycles so a prefilled tank does not make the first run appear artificially cheap.

After one confirmed positive trip, increase the cycle limit modestly. Then improve cargo utilization or add contract-aware inventory handling before adding unattended trading. Actual profitability remains unknown until the live route and confirmed transactions are measured.

## Local session controls

Current source already has mining preview/start/stop, bounded runs, durable intents, and worker/scheduler activity timestamps. Each mining trip currently makes one extraction before selling and refueling. It does not fill the hold through repeated extractions. Operation currently requires a database queue worker and `schedule:work` in two terminals. These are source observations, not a live process or browser check. See [local operation](../README.md#local-operation), [MiningRunner](../app/Gameplay/MiningRunner.php), and [MiningPlanner](../app/Gameplay/MiningPlanner.php).

Proposed session behavior:

- **Start local engine** starts the app's worker and scheduler once, reports their process state, and leaves mining awaiting an explicit run approval.
- **Stop automation** prevents further run actions and leaves the engine available for approved manual commands. An already reserved action must settle before takeover.
- **End session** records Stop for active runs, settles or explicitly holds pending work, and shuts down the owned worker and scheduler gracefully. Show Stopping until shutdown completes; unresolved work remains visible for reconciliation.
- After engine restart, require a state refresh and explicit review before resuming saved automation or executing leftover manual approvals. Avoid automatic launch at login in the first version.

Show engine state separately from run state and API connectivity. An idle worker may be healthy without a recent job heartbeat. Closing the browser does not stop local processes. Mac sleep stops issuing new commands, while travel already accepted by the game server continues.
