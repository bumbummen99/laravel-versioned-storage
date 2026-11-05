---
name: package-bugfix
description: "Use for supervised package bug finding and regression-first fixes: find other issues in the current codebase, write tests that verify the case, then fix the issue only after explicit user approval. Supports review, questions, and design discussion between phases."
license: AGPL-3.0-only with the Library Integration Exception; see LICENSE.md and COPYING.EXCEPTION
metadata:
   author: Patrick Henninger
---

# Package Bugfix

## Primary Goal

Run one supervised bug-finding cycle: reproduce a confirmed issue with a failing regression test, stop for review, then implement and validate a fix only when the user explicitly approves it.

## Phase Selection and Supervision

- Start in **Find and Reproduce** for `/package-bugfix`, "find other issues", or "write tests that verify that case".
- Enter **Fix the Issue** only after an explicit instruction such as "fix the issue" or "implement this approach" for the reported finding. If invoked with a fix request, read the existing finding, regression, and discussion first.
- Questions, acknowledgments, and discussion are not approval to edit production code. Answer them and remain at the review gate.
- If approval or the selected issue is ambiguous, ask for clarification before changing production code.
- If the fix requires a new architectural decision or changes the agreed public contract, explain the trade-offs and wait for a decision.
- After either phase, stop. Do not start another bug hunt, commit, publish, or accept your own solution on the user's behalf.

## Integration With Existing Skills

Use the existing project skills when their scope applies rather than duplicating their instructions:

- **package-testing**: load before creating or changing regression tests. In the first phase, stop at the failing-test stage; implementing the fix requires approval.
- **package-compatibility**: load when investigating or changing PHP/Laravel/Testbench compatibility, dependency constraints, driver dependencies, or platform-sensitive behavior.
- **package-scaffold**: load when an approved fix changes provider wiring, commands, migrations, configuration, or other package capabilities.
- **package-generate-skill**: load when an approved fix changes public APIs, commands, configuration, README guidance, or examples; update the bundled consumer-facing Boost skill accordingly.
- **package-release**: load only when release work is requested. This workflow does not authorize releases or changelog/version changes by itself.

This maintainer workflow belongs under `.agents/skills`, not in the consumer-facing skill under `resources/boost/skills`.

## Find and Reproduce

1. Read `AGENTS.md`, the relevant specification tests, documentation, implementation, and existing regressions. Inspect the worktree and preserve unrelated or user-authored changes.
2. Select one concrete candidate within the documented contract. Prefer observable correctness failures over speculative concerns or style changes. Do not claim a bug solely because a driver or configuration is unfamiliar.
3. Check existing helpers and tests before adding coverage. Keep the scope to one confirmed issue per cycle unless the user requests more; parameterized cases for the same root cause are appropriate.
4. Add the smallest permanent regression that asserts the intended behavior through the public package API. Use existing test categories and automatic discovery. Mock only the boundary needed for a deterministic, service-free test, and state what the mock does not verify.
5. Run the focused regression. Confirm it fails because of the suspected bug, not a bad fixture, missing dependency, or unrelated setup failure. Correct flawed tests before reporting a finding.
6. Run directly affected existing tests and the normal package checks as appropriate, including `composer test` before handing off a confirmed regression. Keep the expected failures visible; do not skip tests or change assertions to conceal the issue.
7. Leave production code, runtime configuration, dependency manifests, and public documentation unchanged in this phase. If reproducing the issue requires changing one of these, request approval first. Remove only throwaway probes, never the permanent regression.
8. Report the finding and **stop for review**:
   - Reproduction and user-visible impact.
   - Relevant implementation and regression-test locations.
   - Expected versus actual behavior and confirmed root cause, or clearly labeled uncertainty.
   - Focused/full validation results, distinguishing new expected failures from baseline failures.
   - A concise proposed fix and meaningful costs or trade-offs.
   - Explicit statement that production code is unchanged and the issue is not fixed.

If no candidate can be reproduced, report that no additional issue was confirmed and state the reviewed scope. Do not invent a finding, leave an unverified failing test, or imply the package is bug-free.

## Fix the Issue

1. Re-read the approved finding, regression, and intervening user decisions. Re-run the regression if the worktree or dependencies changed. Do not silently expand the agreed scope.
2. Implement the simplest complete root-cause fix using repository conventions and existing helpers. Preserve intended behavior across writes, reads, cleanup, rollback, and deferred work where relevant.
3. Add directly related edge cases when necessary. Keep regression expectations intact unless user-approved behavior changes require an explicit adjustment.
4. Update directly related documentation and the bundled consumer skill when applicable. Explain meaningful costs, such as added database queries, locks, remote requests, or compatibility constraints.
5. Run focused tests first, then `composer test`. Use the existing formatting/static-analysis/type-coverage tools; preserve the Laravel Package Skeleton scripts and CI workflows.
6. Review the final diff for unrelated edits, accidental weakening of tests, and whitespace errors. Do not install dependencies unless an approved manifest change or a missing-dependency failure requires it.
7. Report the fix, regression coverage, validation results, and remaining limitations. If validation is blocked or fails, say so; do not claim completion.
8. **Stop for the user's final review.** Accept questions and requested revisions without automatically starting the next cycle.

## Package Contract Boundaries

- Treat specification tests and documented user decisions as the contract; ask before resolving a contradiction by changing behavior.
- Version containers are exclusive to one resource. File versions use `<path>/<uuid>`; directory snapshots use `<path>/<uuid>/<child>`. Do not mix file and directory resources in one container.
- Nested disks and independent resources on different disks are valid. Physical-container exclusivity across independently configured disks is the developer's responsibility; adapter-object equality is not general physical-location detection.
- Do not turn unsupported external writes or conflicting cross-disk configurations into a new requirement for canonical storage identities or automatic overlap detection.
- Preserve transaction guarantees, retryable cleanup, and the requirement to finish snapshot writes before publication. Temporary upload URLs are unsupported.

## Examples

- `/package-bugfix`: find one concrete issue, add and verify the regression, explain the proposed fix, then wait.
- "Why does that fail?": explain the finding without entering the implementation phase.
- "Fix the issue": implement the reviewed fix, validate it, and wait for acceptance.
- "Looks good": acknowledge without starting another cycle. Start again only when requested.
