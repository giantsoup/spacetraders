# Verify phone-to-engine recovery and distribute through TestFlight

Plan audit task **MOB04**. Status: unverified mobile release. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/ios-app-plan.md:70](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/ios-app-plan.md#L70)
- [docs/ios-app-plan.md:100](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/ios-app-plan.md#L100)

## Verified gap

- No physical-phone acceptance, signing setup, TestFlight build or repeatable release evidence exists in the repository.

## Acceptance

- [ ] Verify duplicates, interrupted connections, expired access, stale approvals, uncertain outcomes, engine restart and concurrent web/mobile controls.
- [ ] Prove approved bounded work continues while the phone is locked/disconnected and that reconnect recovers Stop and blocked states.
- [ ] Check current Apple requirements, configure signing securely, and establish reproducible private replacement builds before beta expiry.
- [ ] With explicit distribution authorization, install through TestFlight and record build/version, physical-device workflow and remaining limits.

## Dependencies

MOB03, OPS11

## Execution boundary

Signing credentials, Apple setup, distribution and any live commands need explicit execution authorization. Use owned offline fixtures first.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
