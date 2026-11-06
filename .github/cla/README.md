# Signing the Contributor Licence Agreement

Contributions require explicit acceptance of [CLA version 1.0](../../CLA.md).
You retain copyright. The agreement permits future AGPL versions and changes
to additional permissions or exceptions, not separate proprietary licensing.
Read the complete agreement before accepting; seek legal advice if needed.

## Contributors

1. Read the agreement on the repository's default branch. Obtain the full
   commit SHA for that revision, for example with `git rev-parse origin/master`
   after fetching the upstream repository. Make sure `origin` is this repository,
   not your fork; otherwise use your upstream remote.
2. Copy [acceptance-template.md](acceptance-template.md) to
   `.github/cla/signatures/YOUR_GITHUB_USERNAME.md`. Replace every placeholder.
   The agreement URL must use the same full commit SHA and show version 1.0
   of the active agreement, not an earlier draft.
3. Use your own GitHub account to open a **separate signing-only PR** adding
   that one record. Title it `CLA: YOUR_GITHUB_USERNAME`. Do not modify the
   agreement, bot configuration, another person's record, or package code.
4. A maintainer will review and merge your acceptance, then add your username
   to the bot's approved list. A failing CLA check on the signing PR itself is
   expected; it does not mean you should modify the approved list.
5. Submit your contribution PR, or comment `@cla-bot check` on an existing one
   after approval. Each contributor identified by the bot needs approval.

Acceptance records and PR history are public. Do not include postal addresses,
private email addresses, identity documents, or other unnecessary personal
information. For an entity, identify its legal name and your signing capacity.
Contact the maintainer if you need a different acceptance arrangement.

## Maintainer verification

- Confirm the PR author matches the GitHub username in the record and that
  the PR changes only that author's acceptance record. Resolve identity or
  entity-authority questions before approval; a username is not identity proof.
- Check every placeholder is replaced, the explicit acceptance is intact, and
  the full commit reference resolves in this repository to the active version
  1.0 text. Verify the linked licence and exception at that revision as well.
- Review the record and merge the signing-only PR. If a required CLA check
  blocks it, use a narrowly authorised, manually reviewed maintainer bypass
  for that PR only. Do not disable checks globally, create a filename/label
  based automatic bypass, or bypass a PR containing code contributions.
- After merging, add only the verified username to `contributors` in the
  root [.clabot](../../.clabot), through a maintainer-reviewed change. The
  approval list is a manual index of verified records and the explicit Project
  Steward exemption below, not the agreement or acceptance evidence. Never
  approve a username solely because it was added to that list in a PR.
- Request a bot recheck on the contribution PR. Approval is not automatic
  when a signing PR merges. Do not accept bot approval in place of reviewing
  any PR that changes the agreement, signatures, or approval configuration.

### Updating the agreement

Do not silently edit an agreement version after collecting acceptances.
For each amendment:

1. Publish a new version identifier, announce it publicly in the repository,
   and identify its effective date for future contributions. Retain the earlier
   text and its commit reference.
2. Update this guide, the acceptance template, and the bot message to name the
   current version for new signers.
3. Keep existing signers approved unless they explicitly withdraw. Do not
   delete their historical acceptance records or require routine re-signing.
   This applies only to signers whose accepted terms include this amendment
   mechanism; do not impose it retroactively on other agreements.
4. Preserve section 4's licensing limits. Expanding them requires explicit
   acceptance, not this opt-out process.

There is no fixed withdrawal window. Earlier contribution grants remain valid.
cla-bot does not track amendments, notice, submission dates, or withdrawal:
maintainers handle these manually. The allowlist is not evidence that future
amendments are legally enforceable. Obtain legal review of this mechanism and
comply with applicable notice and consent requirements.

### Withdrawing approval

From your GitHub account, open a withdrawal-only PR adding
`.github/cla/withdrawals/YOUR_GITHUB_USERNAME.md`, or open an issue, stating:

> I withdraw my CLA approval for future contributions as GitHub user USERNAME.
> I understand that grants for earlier contributions remain in effect.

This notice must not contain code contributions. Withdrawal takes effect on
submission, not merge. It does not erase the historical acceptance or revoke
earlier grants.

Maintainers must verify the account, retain the notice and its submission
timestamp, remove the username from `.clabot`, and recheck open contribution
PRs. Do not merge contributions submitted after withdrawal on the strength
of a stale passing check. Treat a withdrawal-only PR as an administrative
notice, not a contribution requiring CLA approval. A reviewed maintainer
bypass may be necessary if checks block recording it.

To resume contributing, submit a fresh acceptance of the current agreement in
a new record, preserving earlier acceptance and withdrawal history. Maintainers
restore approval only after verification.

### Project Steward exemption

`bumbummen99` is Patrick Henninger's GitHub account and is allowlisted as the
Project Steward for his own contributions. This is an explicit maintainer
exemption, not a CLA signature or acceptance record. It does not cover
third-party contributions submitted through that account or automatically
exempt other maintainers.

## Repository owner setup

These settings are not enabled merely by committing these files:

1. Merge/push the active agreement, this guide, and `.clabot` to `master`.
2. Install the [cla-bot GitHub App](https://github.com/apps/cla-bot) and grant
   access to `bumbummen99/laravel-versioned-storage`.
3. Open a test PR and confirm the App publishes `verification/cla-signed`
   and the signing instructions. The approved list initially contains only
   the exempt Project Steward account; no CLA acceptance is recorded for it.
4. In the existing ruleset or branch-protection settings for `master`, add
   `verification/cla-signed` as a required status check. Select cla-bot as the
   expected source where supported. Preserve all existing protection settings.
5. Retain a narrowly authorised maintainer merge path for signing-only PRs
   and initial configuration. Without one, requiring CLA approval also blocks
   unsigned contributors' signing PRs. Never grant that bypass to contributors
   or automatically exempt PRs by path or label.

Until the App and required check are configured, maintainers must enforce the
acceptance requirement manually. App approval only checks the approved
username list; it does not validate the acceptance wording, legal authority,
or enforceability. Legal review of the agreement and process is recommended.
