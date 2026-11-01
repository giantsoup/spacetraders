# Live rate-header verification

The user authorized a bounded read-only header check on 2026-11-01. It completed with six successful GET requests: one public `/` request and five authenticated `/my/agent` requests. All returned HTTP 200. The saved reset matched the public response and all authenticated identities matched the configured agent. No server mutation endpoint was called and automation remained disabled.

The initial application revision was `0259710a8f1bd73931ec8a85eb54ca81753b8706`, with application code accepted at `f56513b`. The existing live environment supplied its encrypted credential in memory. Its database was opened read-only, and the check did not synchronize or update saved agents. No credential, account identifier or response body was saved. Existing workers were left running; there were no active runs, unresolved intents or queued jobs at preflight.

## Evidence

The [sanitized six-response record](evidence/live-rate-headers-2026-11-01.json) contains UTC timestamps, HTTP status, latency and an allowlist of rate headers. The credential is absent. The captured public response also supplies a portable test fixture at `tests/Fixtures/rate-limit-success.json`.

| Header | All six observations |
| --- | --- |
| `X-RateLimit-Type` | `IP Address` |
| `X-RateLimit-Limit-Per-Second` | `2` |
| `X-RateLimit-Limit-Burst` | `30` |
| `X-RateLimit-Remaining` | `1` |
| `Retry-After` | `1`, including successful responses |
| `X-RateLimit-Reset` | ISO timestamps near the response time |

These advertised limits match the public [SpaceTraders rate-limit guide](https://docs.spacetraders.io/api-guide/rate-limits), retrieved on 2026-11-01. The guide also documents account limits and rate-limit error headers. Those error paths were tested locally rather than deliberately triggered live.

The initial spacing was 1.25 seconds after the public response. The old limiter deferred the next authenticated request before transport. The remaining five reads were collected with three-second spacing. There were exactly six completed live requests and no retries after a failed HTTP response, no 429s, no write requests and no deliberate exhaustion or burst test.

## Resulting correction

A successful response's positive `Remaining: 1` cannot safely be treated as the capacity of the client's entire sustained/burst allowance. The old code subtracted another reserve, clamped local tokens to zero, then demanded several reserved tokens before permitting a read. The captured headers reproduce that false deferral for writes, critical reads and discovery reads.

Positive Remaining values are now advisory. The client's existing host and account buckets still enforce 1.9 requests per second and burst capacity ten; header-reported rate/burst limits can only lower those allowances. Explicit zero/exhaustion, 429 and 5xx responses still enforce Retry-After/Reset pauses. Successful `Retry-After: 1` alone does not pause transport. Saved account pauses, protected write capacity, shared host coordination and uncertain-write reconciliation are retained.

Legacy `server_remaining`/`server_reset_at` fields are removed when a bucket is next used. Existing tokens, usage counters and genuine `blocked_until` pauses are preserved. No valid live budget was deleted, reset or manually edited for this verification.

This resolves D3 without making automation depend on an inferred interpretation of positive Remaining/Reset windows. The six samples establish the observed successful-response behavior; they do not reverse-engineer the server's full limiter or establish sustained/burst throughput. Account-type throttling, exhaustion, infrastructure errors, latency and unsaved-pause recovery retain deterministic fixture coverage. No further live calls are needed for the correction's local verification.

## Authorization boundary

The authorization covered these read-only checks. The user later separately authorized committing, merging to main and pushing main after completion. Neither instruction authorizes live mining, scouting, trading, ship purchases, engine activation, deployment or PR creation. Fuel estimates and fleet-measurement prerequisites are unchanged.

## Local correction verification

Standard verification passes 289 tests / 1,504 assertions, build determinism, application and environment checks, formatting and whitespace checks. The 60-second simulation now supplies positive Remaining=1 headers after every response and still verifies the existing sustained/burst bounds. Tests also retain real exhaustion, account isolation, restart, contention and uncertain-write coverage. Both real-worker scripts pass. Evidence is saved under `storage/app/live-rate-check/`; Claude Opus 5.5 independently accepted the correction at `f9913c2b4137628241c7384af12533a1f5cf2a9c`, with nine additional boundary probes and no QA/security blockers. D3 is resolved within this captured-header/local-fixture scope.
