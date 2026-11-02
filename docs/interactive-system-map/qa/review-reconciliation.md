# Independent review reconciliation

Claude Sonnet 5.5 reviewed source at merge commit `777db302b6a6ec4307ec01a78d3cd8eaeaea42a9`. This was an independent static review, not browser execution or approval of the later fixes.

Native Chromium reproduced card dismissal/focus loss on zoom, focus loss on nearby selection, interception of Control+F, and overlap between the unplotted card and camera controls. The expanded native run also reproduced short card content areas, page clipping on short viewports, and inaccessible directory rows behind an existing card. All have final browser regressions.

The directory opener now remains visible for focus return. Nearby choices and details share one scroll container. The map follows the viewport height. Camera interaction preserves the card, nearby choices retain their focus, changing locations resets detail scroll, and open directory rows render above the card. Modifier shortcuts pass through, Escape works from map controls, and marker focus draws inside its SVG hit target. Unplotted cards open away from the normal desktop camera controls.

The review's two-finger-tap concern did not reproduce with trusted Chromium touch input. It remains a passing regression, with explicit multi-pointer gesture tracking. Line-based wheel input now normalizes to pixel units, with a unit test and browser event check; a physical Firefox wheel was not tested. The tracker countdown is outside the live announcement node; the final DOM is asserted, but no screen reader was used.

Three test-harness assumptions were corrected and rerun: the original outside-click point was actually inside the card; native keyboard scrolling needed to finish before a wheel assertion; and login after an authentication redirect correctly returns to the intended map rather than always returning to the Agents index. These were not reported as application defects.

Final native QA covers every rendered marker and all 21 fixture locations, control and card combinations, ship/navigation/history, authentication, API tracker interaction and failure recovery, narrow layouts, trusted touch, and read-only gameplay boundaries. This is scoped map QA, not a whole-application security or accessibility certification.
