# Implement the approved native ship web experience

Plan audit task **UI01**. Status: unimplemented feature. Audited local source `221fbae7a1ecbdf5159211316039f016355c900e` and remote base `98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb` on 2026-11-01.

## Source requirements

- [docs/native-ship-ui-implementation-prompt.md:1](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/native-ship-ui-implementation-prompt.md#L1)
- [docs/design-brief.md:1](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/design-brief.md#L1)
- [docs/app-styling.md:3](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/app-styling.md#L3)
- [docs/single-ship-mobile-research.md:85](https://github.com/giantsoup/spacetraders/blob/98b8792ffc0ce36a50ba5b9149b3049f5bd1e6fb/docs/single-ship-mobile-research.md#L85)

## Verified gap

- Dashboard.vue remains the terminal panel dashboard. app-styling.md explicitly identifies Horizon/Logbook/Voyage as prototypes.
- The design HTML and PNG references have no connected gameplay handlers. No native-ship-ui-implementation.md acceptance report exists.

## Acceptance

- [ ] Implement all workflows in prompt sections 1-5 with a capability-to-destination map, Horizon home/report, focused commands, activity/reconciliation, active-run Stop, Mining/Contracts staging and distinct engine controls.
- [ ] Preserve guided flight, ownership, approval bounds, reset handling, uncertain writes, ship selection, UTC source values and local display.
- [ ] Exercise the full state/action matrix and 320x667, 375x548, 390x844, landscape, wider and enlarged-text layouts; inspect real application screenshots, focus and reduced motion.
- [ ] Keep production assets synchronized and deliver the requested implementation report with actual browser evidence.

## Dependencies

None.

## Execution boundary

Offline implementation and owned fixtures only. Live gameplay, paid calls, external messages, credential changes and deployment need explicit execution authorization.

Keep Laravel gameplay rules, owner scoping, CSRF or the agreed mobile authentication boundary, durable intent/reconciliation, integer amounts and UTC deadlines. Use the locked stack and focused regression checks. Saved reports and UI remain dark.

## Draft handoff

This branch starts with an implementation brief. The planned feature or acceptance work remains open until the requirements above are implemented and verified. This document is not evidence of deployment, live gameplay, paid API access or external delivery.
