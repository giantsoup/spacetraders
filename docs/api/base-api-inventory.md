# Base API operation inventory

Source: [official OpenAPI](https://spacetraders.io/SpaceTraders.json), version 2.3.0. Snapshot SHA-256 `c07d04f575df43e4d05eea4ba539f2d33ef8c5d9a3c863d49520193ef5431ad8`. Audited source `99be3c474dddd7bf9fd1c1aec5bf2147cf9b5868`. See the [foundation plan](../base-api-foundation-plan.md).

This is source coverage, not completed acceptance. No operation is marked accepted by this audit. `source_mapped` means a mapping exists; `partial` means known capability restrictions; `aggregate_only` means data is exposed through a broader response without accepted operation equivalence; `local_only` means unpublished implementation; `missing` means no first-class support was identified.

| Operation | Method and path | Source coverage | Task | Gap or acceptance work |
| --- | --- | --- | --- | --- |
| `get-status` | `GET /` | source_mapped | FND-02 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `register` | `POST /register` | partial | FND-02 | Real registration exists; full schema constraints and ambiguous-registration recovery need foundation acceptance. |
| `get-systems` | `GET /systems` | source_mapped | FND-02 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-system` | `GET /systems/{systemSymbol}` | source_mapped | FND-02 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-system-waypoints` | `GET /systems/{systemSymbol}/waypoints` | source_mapped | FND-02 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-waypoint` | `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}` | source_mapped | FND-02 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-market` | `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}/market` | source_mapped | FND-02 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-shipyard` | `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}/shipyard` | source_mapped | FND-02 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-jump-gate` | `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}/jump-gate` | source_mapped | FND-10 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-construction` | `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}/construction` | source_mapped | FND-11 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `supply-construction` | `POST /systems/{systemSymbol}/waypoints/{waypointSymbol}/construction/supply` | missing | FND-11 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-factions` | `GET /factions` | source_mapped | FND-02 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-faction` | `GET /factions/{factionSymbol}` | source_mapped | FND-02 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-my-agent` | `GET /my/agent` | source_mapped | FND-02 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-agents` | `GET /agents` | source_mapped | FND-02 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-agent` | `GET /agents/{agentSymbol}` | source_mapped | FND-02 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-contracts` | `GET /my/contracts` | source_mapped | FND-04 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-contract` | `GET /my/contracts/{contractId}` | source_mapped | FND-04 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `accept-contract` | `POST /my/contracts/{contractId}/accept` | partial | FND-04 | Executor supports it only inside an exact approved contract run; add manual approval. |
| `deliver-contract` | `POST /my/contracts/{contractId}/deliver` | partial | FND-04 | Executor supports it only inside an exact approved contract run; add manual delivery. |
| `fulfill-contract` | `POST /my/contracts/{contractId}/fulfill` | partial | FND-04 | Executor supports it only inside an exact approved contract run; add manual fulfillment. |
| `get-my-ships` | `GET /my/ships` | source_mapped | FND-03 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `purchase-ship` | `POST /my/ships` | partial | FND-03 | Current purchase requires a verified complete miner and mining site/buyer; not general ship purchase. |
| `get-my-ship` | `GET /my/ships/{shipSymbol}` | source_mapped | FND-03 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-my-ship-cargo` | `GET /my/ships/{shipSymbol}/cargo` | source_mapped | FND-03 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `orbit-ship` | `POST /my/ships/{shipSymbol}/orbit` | source_mapped | FND-03 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `ship-refine` | `POST /my/ships/{shipSymbol}/refine` | missing | FND-07 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `create-chart` | `POST /my/ships/{shipSymbol}/chart` | partial | FND-09 | Purpose/reserve policy restricts the manual command to marketplace/shipyard questions. |
| `get-ship-cooldown` | `GET /my/ships/{shipSymbol}/cooldown` | source_mapped | FND-03 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `dock-ship` | `POST /my/ships/{shipSymbol}/dock` | source_mapped | FND-03 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `create-survey` | `POST /my/ships/{shipSymbol}/survey` | source_mapped | FND-07 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `extract-resources` | `POST /my/ships/{shipSymbol}/extract` | partial | FND-07 | Plain extraction exists; the optional survey body on this endpoint is not sent. |
| `siphon-resources` | `POST /my/ships/{shipSymbol}/siphon` | missing | FND-07 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `extract-resources-with-survey` | `POST /my/ships/{shipSymbol}/extract/survey` | source_mapped | FND-07 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `jettison` | `POST /my/ships/{shipSymbol}/jettison` | missing | FND-03 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `jump-ship` | `POST /my/ships/{shipSymbol}/jump` | missing | FND-10 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `navigate-ship` | `POST /my/ships/{shipSymbol}/navigate` | partial | FND-08 | Same-system CRUISE only; other modes and zero-capacity configurations are blocked. |
| `patch-ship-nav` | `PATCH /my/ships/{shipSymbol}/nav` | partial | FND-08 | Explicit PATCH dispatch, exact flight-mode preview and manual Stop implemented; mode travel variants and live calibration pending. |
| `get-ship-nav` | `GET /my/ships/{shipSymbol}/nav` | source_mapped | FND-08 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `warp-ship` | `POST /my/ships/{shipSymbol}/warp` | missing | FND-10 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `sell-cargo` | `POST /my/ships/{shipSymbol}/sell` | source_mapped | FND-03 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `create-ship-system-scan` | `POST /my/ships/{shipSymbol}/scan/systems` | missing | FND-09 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `create-ship-waypoint-scan` | `POST /my/ships/{shipSymbol}/scan/waypoints` | partial | FND-09 | Purpose-bounded target checking exists; general scan results/workflow need coverage. |
| `create-ship-ship-scan` | `POST /my/ships/{shipSymbol}/scan/ships` | missing | FND-09 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `refuel-ship` | `POST /my/ships/{shipSymbol}/refuel` | partial | FND-08 | Only explicit market units are sent; fromCargo and omitted-units variant are not covered. |
| `purchase-cargo` | `POST /my/ships/{shipSymbol}/purchase` | source_mapped | FND-03 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `transfer-cargo` | `POST /my/ships/{shipSymbol}/transfer` | source_mapped | FND-03 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `negotiateContract` | `POST /my/ships/{shipSymbol}/negotiate/contract` | missing | FND-04 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-mounts` | `GET /my/ships/{shipSymbol}/mounts` | source_mapped | FND-06 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `install-mount` | `POST /my/ships/{shipSymbol}/mounts/install` | missing | FND-06 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `remove-mount` | `POST /my/ships/{shipSymbol}/mounts/remove` | missing | FND-06 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-scrap-ship` | `GET /my/ships/{shipSymbol}/scrap` | source_mapped | FND-05 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `scrap-ship` | `POST /my/ships/{shipSymbol}/scrap` | missing | FND-12 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-repair-ship` | `GET /my/ships/{shipSymbol}/repair` | source_mapped | FND-05 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `repair-ship` | `POST /my/ships/{shipSymbol}/repair` | missing | FND-05 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-supply-chain` | `GET /market/supply-chain` | source_mapped | FND-02 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `get-ship-modules` | `GET /my/ships/{shipSymbol}/modules` | source_mapped | FND-06 | Named API library read, complete response presentation and bounded manual queries implemented. Synthetic request/browser checks exist; official-API and final acceptance pending. |
| `install-ship-module` | `POST /my/ships/{shipSymbol}/modules/install` | missing | FND-06 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `remove-ship-module` | `POST /my/ships/{shipSymbol}/modules/remove` | missing | FND-06 | No owned first-class operation or manual UI was identified in the audited baseline. |

The JSON manifest also records every request-body field and query field from the pinned schema, so endpoint presence cannot hide missing parameter variants. See [base-api-coverage.json](base-api-coverage.json).

Run `python3 scripts/verify-base-api-coverage.py` for inventory/source integrity, `--refresh` for read-only official-spec drift detection, and `--require-complete` for the intentionally failing foundation-acceptance gate.
