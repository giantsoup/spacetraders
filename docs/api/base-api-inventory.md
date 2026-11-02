# Base API operation inventory

Source: [official OpenAPI](https://spacetraders.io/SpaceTraders.json), version 2.3.0. Snapshot SHA-256 `c07d04f575df43e4d05eea4ba539f2d33ef8c5d9a3c863d49520193ef5431ad8`. Audited source `99be3c474dddd7bf9fd1c1aec5bf2147cf9b5868`. See the [foundation plan](../base-api-foundation-plan.md).

This is source coverage, not completed acceptance. No operation is marked accepted by this audit. `source_mapped` means a mapping exists; `partial` means known capability restrictions; `aggregate_only` means data is exposed through a broader response without accepted operation equivalence; `local_only` means unpublished implementation; `missing` means no first-class support was identified.

| Operation | Method and path | Source coverage | Task | Gap or acceptance work |
| --- | --- | --- | --- | --- |
| `get-status` | `GET /` | partial | FND-02 | Reset/version are validated; full status payload and manual status view need acceptance. |
| `register` | `POST /register` | partial | FND-02 | Real registration exists; full schema constraints and ambiguous-registration recovery need foundation acceptance. |
| `get-systems` | `GET /systems` | missing | FND-02 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-system` | `GET /systems/{systemSymbol}` | missing | FND-02 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-system-waypoints` | `GET /systems/{systemSymbol}/waypoints` | partial | FND-02 | Bounded catalogue exists; manual pagination and type/traits query variants need coverage. |
| `get-waypoint` | `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}` | partial | FND-02 | Waypoint normalization drops isUnderConstruction; full manual details and construction-state fidelity need acceptance. |
| `get-market` | `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}/market` | source_mapped | FND-02 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `get-shipyard` | `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}/shipyard` | partial | FND-02 | Read/setup support exists; expose the full yard catalogue and general purchase workflow. |
| `get-jump-gate` | `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}/jump-gate` | missing | FND-10 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-construction` | `GET /systems/{systemSymbol}/waypoints/{waypointSymbol}/construction` | missing | FND-11 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `supply-construction` | `POST /systems/{systemSymbol}/waypoints/{waypointSymbol}/construction/supply` | missing | FND-11 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-factions` | `GET /factions` | partial | FND-02 | Whitelisted public forwarding exists; native manual list/detail and pagination need coverage. |
| `get-faction` | `GET /factions/{factionSymbol}` | missing | FND-02 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-my-agent` | `GET /my/agent` | partial | FND-02 | Gameplay wrapper reduces response to symbol/credits; full profile is not exposed by that wrapper. |
| `get-agents` | `GET /agents` | missing | FND-02 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-agent` | `GET /agents/{agentSymbol}` | missing | FND-02 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-contracts` | `GET /my/contracts` | partial | FND-04 | Read exists inside investigation; independent manual contract list/pagination needs acceptance. |
| `get-contract` | `GET /my/contracts/{contractId}` | partial | FND-04 | Read exists; independent manual detail and all supported terms need acceptance. |
| `accept-contract` | `POST /my/contracts/{contractId}/accept` | partial | FND-04 | Executor supports it only inside an exact approved contract run; add manual approval. |
| `deliver-contract` | `POST /my/contracts/{contractId}/deliver` | partial | FND-04 | Executor supports it only inside an exact approved contract run; add manual delivery. |
| `fulfill-contract` | `POST /my/contracts/{contractId}/fulfill` | partial | FND-04 | Executor supports it only inside an exact approved contract run; add manual fulfillment. |
| `get-my-ships` | `GET /my/ships` | partial | FND-03 | Ship mappings exist, but normalization synthesizes absent cooldown.expiration; retain raw absence and label any derived deadline before full-response acceptance. |
| `purchase-ship` | `POST /my/ships` | partial | FND-03 | Current purchase requires a verified complete miner and mining site/buyer; not general ship purchase. |
| `get-my-ship` | `GET /my/ships/{shipSymbol}` | partial | FND-03 | Ship mappings exist, but normalization synthesizes absent cooldown.expiration; retain raw absence and label any derived deadline before full-response acceptance. |
| `get-my-ship-cargo` | `GET /my/ships/{shipSymbol}/cargo` | aggregate_only | FND-03 | Some data arrives in the aggregate ship response; a dedicated read operation or proved equivalent manual refresh is not accepted. |
| `orbit-ship` | `POST /my/ships/{shipSymbol}/orbit` | source_mapped | FND-03 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `ship-refine` | `POST /my/ships/{shipSymbol}/refine` | missing | FND-07 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `create-chart` | `POST /my/ships/{shipSymbol}/chart` | partial | FND-09 | Purpose/reserve policy restricts the manual command to marketplace/shipyard questions. |
| `get-ship-cooldown` | `GET /my/ships/{shipSymbol}/cooldown` | aggregate_only | FND-03 | Some data arrives in the aggregate ship response; a dedicated read operation or proved equivalent manual refresh is not accepted. |
| `dock-ship` | `POST /my/ships/{shipSymbol}/dock` | source_mapped | FND-03 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `create-survey` | `POST /my/ships/{shipSymbol}/survey` | source_mapped | FND-07 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `extract-resources` | `POST /my/ships/{shipSymbol}/extract` | partial | FND-07 | Plain extraction exists; the optional survey body on this endpoint is not sent. |
| `siphon-resources` | `POST /my/ships/{shipSymbol}/siphon` | missing | FND-07 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `extract-resources-with-survey` | `POST /my/ships/{shipSymbol}/extract/survey` | source_mapped | FND-07 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `jettison` | `POST /my/ships/{shipSymbol}/jettison` | missing | FND-03 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `jump-ship` | `POST /my/ships/{shipSymbol}/jump` | missing | FND-10 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `navigate-ship` | `POST /my/ships/{shipSymbol}/navigate` | partial | FND-08 | Same-system CRUISE only; other modes and zero-capacity configurations are blocked. |
| `patch-ship-nav` | `PATCH /my/ships/{shipSymbol}/nav` | local_only | FND-08 | Local EXP02 branch restores CRUISE only; it does not implement unrestricted supported-mode navigation. |
| `get-ship-nav` | `GET /my/ships/{shipSymbol}/nav` | aggregate_only | FND-08 | Some data arrives in the aggregate ship response; a dedicated read operation or proved equivalent manual refresh is not accepted. |
| `warp-ship` | `POST /my/ships/{shipSymbol}/warp` | missing | FND-10 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `sell-cargo` | `POST /my/ships/{shipSymbol}/sell` | source_mapped | FND-03 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `create-ship-system-scan` | `POST /my/ships/{shipSymbol}/scan/systems` | missing | FND-09 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `create-ship-waypoint-scan` | `POST /my/ships/{shipSymbol}/scan/waypoints` | partial | FND-09 | Purpose-bounded target checking exists; general scan results/workflow need coverage. |
| `create-ship-ship-scan` | `POST /my/ships/{shipSymbol}/scan/ships` | missing | FND-09 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `refuel-ship` | `POST /my/ships/{shipSymbol}/refuel` | partial | FND-08 | Only explicit market units are sent; fromCargo and omitted-units variant are not covered. |
| `purchase-cargo` | `POST /my/ships/{shipSymbol}/purchase` | source_mapped | FND-03 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `transfer-cargo` | `POST /my/ships/{shipSymbol}/transfer` | source_mapped | FND-03 | Transport/command mapping exists. Complete API-variant and manual/live acceptance has not been re-audited. |
| `negotiateContract` | `POST /my/ships/{shipSymbol}/negotiate/contract` | missing | FND-04 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-mounts` | `GET /my/ships/{shipSymbol}/mounts` | aggregate_only | FND-06 | Some data arrives in the aggregate ship response; a dedicated read operation or proved equivalent manual refresh is not accepted. |
| `install-mount` | `POST /my/ships/{shipSymbol}/mounts/install` | missing | FND-06 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `remove-mount` | `POST /my/ships/{shipSymbol}/mounts/remove` | missing | FND-06 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-scrap-ship` | `GET /my/ships/{shipSymbol}/scrap` | missing | FND-05 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `scrap-ship` | `POST /my/ships/{shipSymbol}/scrap` | missing | FND-12 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-repair-ship` | `GET /my/ships/{shipSymbol}/repair` | missing | FND-05 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `repair-ship` | `POST /my/ships/{shipSymbol}/repair` | missing | FND-05 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-supply-chain` | `GET /market/supply-chain` | missing | FND-02 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `get-ship-modules` | `GET /my/ships/{shipSymbol}/modules` | aggregate_only | FND-06 | Some data arrives in the aggregate ship response; a dedicated read operation or proved equivalent manual refresh is not accepted. |
| `install-ship-module` | `POST /my/ships/{shipSymbol}/modules/install` | missing | FND-06 | No owned first-class operation or manual UI was identified in the audited baseline. |
| `remove-ship-module` | `POST /my/ships/{shipSymbol}/modules/remove` | missing | FND-06 | No owned first-class operation or manual UI was identified in the audited baseline. |

The JSON manifest also records every request-body field and query field from the pinned schema, so endpoint presence cannot hide missing parameter variants. See [base-api-coverage.json](base-api-coverage.json).

Run `python3 scripts/verify-base-api-coverage.py` for inventory/source integrity, `--refresh` for read-only official-spec drift detection, and `--require-complete` for the intentionally failing foundation-acceptance gate.
