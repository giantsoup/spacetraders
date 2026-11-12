# Travel and discovery review findings

Claude Sonnet 5.5 completed a mobile UI design pass and a separate independent general QA pass. General QA inspected source and rendered fixture screenshots. It reported no hard blockers and identified the usability defects below. The final sol-loop review approved the current behavior in one round with GPT-6.1 Sol on high, with no blocker or should-fix findings. Usage figures were not returned.

| Finding | Resolution and proof |
| --- | --- |
| Entered reserve was overwritten by the deck default | Caller arguments now retain the reserve. The browser approval shows 4,321 CR, and the input survives orbit approval. |
| Discovery needed the guarded exploration path | Scan and chart pass the target, question, reserve and cooldown threshold to the existing ExplorationPolicy. The browser verifies approval evidence, queue execution, revealed traits, a missed target and capability gates. |
| Actions and disabled reasons consumed short cards | Actions sit in a fixed footer. Disabled buttons name the reason. Portrait and landscape cards leave at least 130px of scrollable detail space. Native keyboard scrolling passed. |
| A retained map context took over unrelated commands | Preview return context is tracked by origin. Ordinary command cancellation and browser Back retain the normal deck flow. |
| An empty reserve silently became zero | Blank map reserves use the configured default. The browser preview shows the default 2,000 CR. Explicit zero remains a deliberate manual value. |
| A block from another action had no recovery control | The blocked banner links to Activity history regardless of the latest selected-ship action. Existing Stop and reconciliation remain available there. |
| Preview copy implied approval or queueing | Preview status now states that no command has been approved or queued. Cancel clears it. |
| Return navigation lost map selection | Selecting or closing a location updates Inertia client history without a network request. Browser Back restores the selected card and ship. |
| Narrow Done button wrapped | FocusSheet keeps its Done control on one line. Phone screenshots verify the result. |
| Uncalibrated ships lacked an upfront explanation | The focused task explains when explicit fuel/time limits and acknowledgement are needed. The existing backend error retains its API argument names. |

Optional changes to restore camera position, collapse all safety inputs, hide disabled chart options, or add catalogue fetching were omitted. The selected card and ship are restored; disabled options explain their requirements. Catalogue collection and new automation remain outside this change.

The final browser runs passed all 16 action journeys and all 26 map scenarios. Earlier diagnostic runs hit a local throttle and one transient empty Herd GET response; the final paced runs passed without changing app limits. Exact current source and asset hashes are in source-sha256.json. The earlier base SHA in raw results identifies the starting commit, not a claim that the uncommitted work was absent.

No new live API travel or discovery was performed. Existing PHP tests cover the command boundary; these browser journeys use real local handlers and the owned fake provider. Physical devices, Safari, Firefox, screen readers and deployment remain unverified.
