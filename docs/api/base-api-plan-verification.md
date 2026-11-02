# Base API plan verification

Verified November 1, 2026 in the isolated local branch `docs/base-api-foundation-20261101`, based on `99be3c474dddd7bf9fd1c1aec5bf2147cf9b5868`. This record covers the plan, public contract research, inventory and coverage checker. It does not claim gameplay implementation or live acceptance.

## Checks performed

- Fresh download of the complete official 2.3.0 specification matches the pinned SHA-256 `c07d04f575df43e4d05eea4ba539f2d33ef8c5d9a3c863d49520193ef5431ad8`, including schemas and descriptions.
- Every one of the 59 operation IDs appears exactly once in the manifest and readable inventory, with its exact method/path, request/query fields and implementation task. Read and write operations at a shared path remain separate.
- Source markers and file hashes match the audited application. Classifications are eight source-mapped, 20 partial, five aggregate-only, one local-only and 25 missing. No foundation acceptance was assigned. Existing early-game features remain implemented within their documented scope.
- All 13 task sections and manifest dependencies agree. The graph has no unknown dependency or cycle. All new document links and the five priority links resolve. Historical source citations in earlier reports were not globally rewritten or certified by this check.
- Fifteen Python regression checks pass. They cover missing/duplicate/new operations, method/parameter drift, stale source/anchors, dependency failures, incomplete or misbound evidence, aggregate-read substitution, external artifact paths, readable inventory synchronization, research references and document links.
- The default and `--refresh` inventory checks pass. `--require-complete` exits 1 with `Foundation incomplete: 0/59 operations accepted`, as required for this unaccepted baseline. The negative control verified that exit and message.
- `git diff --check` passes. No application PHP/Vue/CSS, dependency locks, migrations, live environment, database or service configuration changed. A full Laravel/gameplay test run is not claimed for this documentation/Python-only change.

Reproduce:

```sh
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests/ApiCoverage -v
python3 scripts/verify-base-api-coverage.py
python3 scripts/verify-base-api-coverage.py --refresh
python3 scripts/verify-base-api-coverage.py --require-complete
git diff --check
```

The fourth command is deliberately nonzero until all foundation acceptance records are complete. A passing integrity check is not a passing feature or live-game result.

## Review and limitations

A scoped research agent independently read the official contracts and current source. The parent inspected its actual report, checked the official operation set, and adopted findings about partial operations, response fidelity, cooldown 204, strategy-dependent manual commands, general Stop, destructive reconciliation, inherited authentication ambiguity and raw-versus-derived timestamps. The [research note](../base-api-contract-research.md) distinguishes explicit contracts, local design and calibration questions. Its proposed ordering was reconciled into the [adopted plan](../base-api-foundation-plan.md).

Final review with the requested Claude Sonnet 5.5 was attempted through the configured provider. It failed before reviewing the artifacts with `Claude API rate limit reached. Try again later.` No substitute final-review model was used. Direct verification and contract research do not replace that independent final review; it remains outstanding.

Two mechanical checks were refined during verification: the first whole-document link scan encountered the pre-existing historical `MiningRunner.php` citation, so the final link test explicitly checks new documents and priority links; the full-acceptance negative control intentionally returns nonzero and is checked as an expected result. No unrelated historical content was rewritten to manufacture a green result.

## Acceptance-record format and trust boundary

Future operation acceptance uses repository-relative JSON artifact records for `contract`, `browser` and `live`. Each manifest reference supplies `artifact` and the exact artifact `sha256`. The referenced JSON must identify `operation_id`, `stage`, `source_commit` equal to the audited source, and `result: "passed"`. It must point reviewers to the actual sanitized test output, user interaction and official-API evidence; no token, secret, environment or database belongs in it. Unavailable or unapproved cases remain `not_accepted`.

The verifier checks structural integrity, paths, hashes and record identity. It cannot decide whether a human-authored evidence record honestly proves behavior. Reviewers must inspect actual artifacts, reproduce the relevant path and confirm the live boundary. A fabricated JSON record is not acceptance, even if it satisfies the file format. Aggregate-only, partial, local-only and missing operations cannot be accepted by the unchanged completion gate.

This work makes no GitHub issue, PR, push, remote merge, production deployment, game mutation or paid call. The primary dirty checkout and separately authorized live-local cutover are preserved.
