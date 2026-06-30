# Migration Demo Findings

Generated from a disposable Craft 5.10 + Hyper 2.3.10 test run on 2026-06-30.

## Demo Scope

- Installed a fresh Craft 5 instance against throwaway MySQL.
- Linked this plugin from the working tree with Composer path repository.
- Installed Verbb Hyper 2.3.10.
- Seeded a Hyper URL field, one entry with a non-empty Hyper link, and template usages of `.text` and `getElement()`.
- Ran the documented workflow: audit, mismatches, prepare-fields, content, status, finalize.
- Ran a stale-state cutover test with a second Hyper field.

## What Worked

- Plugin install succeeded on Craft 5.10.
- Hyper install succeeded.
- Empty audit succeeded.
- Seeded Hyper URL field was detected by audit.
- Mismatch scan found `.text` and `getElement()` and exited non-zero.
- After forcing the legacy paid edition in project config during the earlier test, the happy path completed:
  - `prepare-fields --force=1`
  - `content --force=1 --create-backup=1`
  - `finalize --force=1`
- Native URL content was written with `value`, `type`, `label`, `target`, and `class`.
- Finalize replaced the source field layout element with the native Link field on the happy path.
- `composer validate --strict` passed.
- `php -l` passed for all PHP files under `src/`.

## Critical Findings

### 1. Product Pricing Was Inconsistent — SUPERSEDED 2026-06-30

The initial paid design had Lite/Pro traces: migration writes were blocked behind Pro, while read-only workflows remained available in Lite. That later went through a superseded permissive-license detour, but the current product decision is a single paid Craft plugin: `$5` for the complete workflow, with no Lite/Pro split and no feature-level license gates.

Original evidence:

- `composer.json` still declares `"license": "proprietary"`.
- `src/HyperToLink.php` defines `EDITION_LITE`, `EDITION_PRO`, `PRO_PRICE_USD`, and `editions()`.
- `src/services/LicenseService.php` blocks non-dry-run writes unless Pro has no license issues.
- `README.md` advertises a `$5 Pro` write tier.
- `src/templates/index.twig` displays the Pro price and disables write buttons when `canWrite` is false.

Original user impact:

A fresh install could not run the documented migration writes unless the operator manually forced Pro edition in project config. That contradicted the current single-paid-product plan.

Current resolution:

Feature gating remains removed. The plugin now ships as one commercial `$5` Craft plugin with every migration feature included.

- `composer.json` declares `"license": "proprietary"`.
- `LICENSE.md` uses the Craft-style commercial license.
- `src/HyperToLink.php` no longer defines Lite/Pro edition constants, the `license` component, or `getLicense()`.
- `src/services/LicenseService.php` deleted.
- `src/console/controllers/MigrateController.php` no longer calls `requireWriteAccess()`; write commands still require explicit `--force=1`.
- `src/controllers/WizardController.php` no longer passes license/edition template vars or calls `requireWriteAccess()`.
- `src/templates/index.twig` no longer shows edition/price/license state or disables write buttons.
- `README.md` documents the single `$5` plugin license.

### 2. Audit, Status, And CP Index Are Not Read-Only — RESOLVED 2026-06-30

`audit --dry-run=1` mutated the demo database. Before audit, `linkmigrator_fieldmappings` had `0` rows. After audit, it had `1` row.

Evidence:

- `MigrateController::runAuditStage()` calls `syncAuditedFields()`.
- `MigrateController::actionStatus()` calls `syncAuditedFields()`.
- `WizardController::actionIndex()` calls `syncAuditedFields()`.
- `StateService::syncAuditedFields()` creates/updates `FieldMappingRecord` rows.

User impact:

Operators cannot safely run audit/status/CP refresh as observation-only steps. This breaks the stated staged workflow and makes dry-run claims untrustworthy.

Resolution:

Audit data is now purely derived from a fresh audit and never persisted. The `syncAuditedFields()` method was removed entirely, so no entry point writes `linkmigrator_fieldmappings` outside of an actual workflow write.

- `StateService::syncAuditedFields()` deleted.
- All nine call sites removed: `runAuditStage()`, `runFieldStage()`, `runContentStage()`, `actionStatus()`, and `actionFinalize()` in `MigrateController`, plus `actionIndex()`, `actionPrepareFields()`, `actionMigrateContent()`, and `actionFinalize()` in `WizardController`.
- A `linkmigrator_fieldmappings` row is now only created by `savePreparedFieldMapping()` during prepare, so a row's existence means the field has entered the workflow (been prepared), not merely observed.
- `workflowStatuses()` already defaults fields with no row to the `audited` phase, so audit/status/CP index render correctly without persisting anything.

### 3. Finalize Trusts Stored Phase Instead Of Fresh Reconciliation — RESOLVED 2026-06-30

The stale-state demo prepared a second field, manually set `phase='readyToFinalize'`, did not run content migration, then ran `finalize --field=unsafeHyper --force=1`. Finalize succeeded and removed the source Hyper field from the layout while the native field had no migrated value.

Evidence:

- `CutoverService::finalize()` only checks `FieldMapping::isContentReady()`.
- `FieldMapping::isContentReady()` trusts `contentMigrated`, `readyToFinalize`, or `finalized` phase values.
- No fresh inventory of source element/site values is compared against native target values before layout cutover.

User impact:

Incomplete migration state can authorize cutover. A layout can be finalized while non-empty source content remains unmigrated.

Resolution:

Finalize now recomputes reconciliation from live content and ignores the stored phase entirely.

- `ContentMigrationService::reconcileField()` added. It re-inventories every source unit (across all containers, sites, drafts/revisions) and, for each non-empty source value, freshly re-reads the saved native target value and confirms it is populated. It returns `total`, `verified`, `empty`, and an `unverified` list (with element/site and reason).
- `CutoverService::finalize()` calls `reconcileField()` for both dry-run and write. If any non-empty source unit is unverified — including the target field missing from a layout — it throws and refuses cutover with a count-based message. The reconciliation summary is attached to each finalized result entry.
- `FieldMapping::isContentReady()` (the phase-trusting gate) was removed so it cannot be reused as a finalize authority.

This closes the stale-state path: a field whose phase is `readyToFinalize` but whose native value was never written now fails reconciliation and is refused.

### 4. Content Migration Can Mark A Field Ready With Zero Migrated Values — RESOLVED 2026-06-30

The stale-state problem is reachable without direct database tampering. `ContentMigrationService` marks a field content-migrated whenever no exception was thrown, even if no units migrated and only skips or warnings occurred.

Evidence:

- `ContentMigrationService::migrate()` calls `markContentMigrated()` when `!$fieldHadErrors`.
- `StateService::markContentMigrated()` defaults `$readyToFinalize = true`.
- Unsupported conversions are recorded as warnings, not field-level blockers.

User impact:

A command can report success and move the field to `readyToFinalize` without proving every non-empty source value was copied and verified.

Resolution:

Readiness is now derived from the same fresh reconciliation that gates finalize, not from the absence of an exception.

- After the per-field migration loop, `ContentMigrationService::migrate()` calls `reconcileField()` and only passes `$readyToFinalize = true` to `markContentMigrated()` when there were no errors **and** `reconciliation['unverified'] === []`.
- Warning-only, unsupported, and otherwise unmigrated units now leave the field at `contentMigrated` (not `readyToFinalize`), so the status display is honest and finalize — which recomputes the identical reconciliation — refuses cutover.

Drift detection (added after independent review):

`reconcileField()` now also re-derives the native link *type* each source unit would convert to right now and confirms the stored target type still matches. A non-empty source that no longer converts to a supported type, or whose link type changed since migration, is reported `unverified` and blocks finalize. To keep this actionable, the content-migration loop no longer blindly skips already-`migrated` units: `migratedTargetIsCurrent()` re-checks the type and re-migrates a drifted unit instead of trusting the stale record. Type is an enum, not subject to value-formatting normalization, so an unchanged unit always re-verifies cleanly (no false blocks, and re-runs converge to "Already migrated.").

Remaining limitation (tracked for a follow-up, recommended-fix-order item 4):

Verification is type-level plus presence, not full value equality. A source value that was edited to a **different value of the same type** after migration (e.g. one URL swapped for another) is not detected, because catching it needs normalized source-vs-target *value* comparison, which risks false "unverified" blocks on benign formatting differences and needs live Craft integration coverage before shipping. This is an unusual operator action (editing Hyper values mid-migration) and the safe direction is preserved: when drift is detected at the type level, finalize refuses rather than cutting over stale data.

### 5. Post-Finalize Recovery Path Is Broken — RESOLVED 2026-06-30

After the unsafe finalize removed the source Hyper field from the layout, running `content --field=unsafeHyper --force=1 --create-backup=1` returned exit `0` with `migrated: 0`, `errors: 0`, and no native value.

Evidence:

- `ContentMigrationService::resolveFieldContext()` depends on the source field being present in the current element field layout.
- `CutoverService::replaceFieldInLayouts()` removes the source layout element.
- If the source field is absent from the layout, content migration silently continues.

User impact:

Once bad finalize happens, the normal content migration command can no longer recover the missing value and still exits green.

Resolution:

Content migration now refuses to run silently against a finalized field instead of exiting green having done nothing.

- `ContentMigrationService::migrate()` checks the stored mapping phase up front. It records a field-level error only when the field is `finalized` **and** the source field is no longer in any layout (`$fieldAudit->containers === []`), explaining that the source Hyper field must be re-added before content migration can recover, and continues to the next field.
- Gating on `containers` (not phase alone) keeps the recovery instruction actionable: once the operator re-adds the source field, `containers` is non-empty so the guard no longer fires and migration proceeds normally. (An earlier version blocked every `finalized` field, which made the "re-add and re-run" message a dead end.)
- Because the error is recorded on the result, the `content` command now exits non-zero and surfaces the broken state rather than reporting `migrated: 0, errors: 0`.
- Combined with finding 3, an unsafe finalize can no longer happen in the first place, so this path is now only reachable by deliberate layout edits — and is reported clearly when it is.

### 6. Migration Evidence Is Handle-Based In Critical Places — RESOLVED 2026-06-30

Migration records are keyed by `fieldHandle`, `ownerId`, and `siteId`. This is not enough for a portable and layout-instance-aware migration.

Evidence:

- `linkmigrator_migrations` unique index uses `action`, `fieldHandle`, `ownerId`, `siteId`.
- `StateService::saveRecord()` finds records by `fieldHandle`, not source field UID or layout element UID.
- `StateService::getFieldMapping()` looks up by `sourceHandle`.

User impact:

Deleted/recreated fields, handle reuse, repeated field layout instances, and cross-environment project config can contaminate migration status and readiness decisions.

Resolution:

Per-element migration evidence is now keyed by the stable source field UID, not the reusable handle. The handle is retained only as a human-readable label.

- New column `linkmigrator_migrations.sourceFieldUid` added by `m260630_000000_migration_source_field_uid` (and by `Install` for fresh installs). The unique index is now `action`, `sourceFieldUid`, `ownerId`, `siteId`; the old handle-based unique index is dropped, and existing rows are backfilled from `linkmigrator_fieldmappings`.
- `StateService::saveRecord()`, `markMigrated/markSkipped/markWarning/markError`, `migratedMap()`, and `isMigrated()` all take and key on `sourceFieldUid`. `ContentMigrationService` threads `$fieldAudit->uid` through every call.
- This removes the handle-reuse and delete/recreate contamination: a record's identity is now the field it actually belongs to. Layout-instance granularity is no longer load-bearing for correctness because finalize and content readiness are gated by `reconcileField()` (finding 3), which re-reads live source and target content per element regardless of stored evidence. The handle-based `summaries()` grouping is kept for reporting only.
- The decision-driving workflow/phase lookups were also re-keyed by source field UID: `StateService::getFieldMapping()`, `markContentMigrated()`, and `markFinalized()` now resolve by `sourceFieldUid`, and `ContentMigrationService`, `CutoverService`, and `FieldMigrationService` pass `$fieldAudit->uid`. Previously these resolved by handle, so a recreated field with a reused handle could inherit the old field's mapping and phase (and `prepare` would wrongly skip it). `savePreparedFieldMapping()` still resolves by UID first and only falls back to handle to reset a pre-existing row to the current field's UID + `prepared` phase. `getFieldMappings()` (plural) keeps a handle join for the status display only.

### 7. Old Success Can Mask Later Failure — RESOLVED 2026-06-30

`StateService::shouldReplaceStatus()` gives `migrated` the highest priority. A later warning or error for the same action/field/owner/site cannot replace an old migrated row.

Evidence:

- Status priority is `skipped < warning < error < migrated`.

User impact:

Status can say a value is migrated even after a corrected rerun discovers a new failure for that same unit.

Resolution:

The priority ladder was replaced with a "latest outcome wins" rule, with a single guard.

- `StateService::shouldReplaceStatus()` now records whatever the current run actually observed for a unit, so a later `warning` or `error` supersedes an earlier `migrated`.
- The only protected case is an incoming `skipped` against an existing `migrated`: a re-run records `skipped` ("Already migrated.") for units it deliberately leaves alone, and that must never downgrade a confirmed migration.

### 8. Phone Link Type Mapping Is Inconsistent — RESOLVED 2026-06-30

Field configuration and content payload disagree for phone links.

Evidence:

- `MappingStrategyService::TYPE_MAP` maps Hyper `phone` to native `phone`.
- `ContentMigrationService::convertHyperValue()` maps Hyper `phone` to native payload type `tel`.

User impact:

A Hyper phone field can prepare a native Link field whose allowed types do not match the payload written during content migration.

Resolution:

`MappingStrategyService::TYPE_MAP` now maps Hyper `phone` to native `tel`, matching the `tel` payload type that `convertHyperValue()` writes (and Craft's native Link `tel` type). Field settings and saved payloads now agree.

### 9. Template Mismatch Review Does Not Gate Finalize — RESOLVED 2026-06-30

The mismatch scanner correctly found seeded `.text` and `getElement()` usages, but finalize does not consider mismatch results.

Evidence:

- `src/templates/index.twig` tells the user to review the CLI mismatch report.
- `CutoverService::finalize()` does not check mismatch state or require an operator acknowledgement.

User impact:

Layouts can be cut over while templates still reference Hyper-only APIs.

Resolution:

Finalize now refuses to cut layouts over while unreviewed template mismatches exist, unless the operator explicitly acknowledges them.

- `MigrateController::actionFinalize()` gained a `--acknowledge-mismatches` option. When the audit reports any mismatch references and the run is not a dry run, finalize aborts with a non-zero exit and a message pointing at `migrate/mismatches`, unless `--acknowledge-mismatches=1` is passed. Dry runs remain exempt so impact can still be previewed.
- `WizardController::actionFinalize()` mirrors this: it blocks finalize with a flash error when mismatches exist unless the posted `acknowledgeMismatches` checkbox is set. `index.twig` shows the mismatch count and renders that acknowledgement checkbox on the Finalize button.

### 10. Prepare Mutates Layouts — RESOLVED 2026-06-30

The documented wording implies `prepare-fields` creates parallel native fields. In practice, it also inserts those native fields into every source layout.

Evidence:

- `FieldMigrationService::migrate()` calls `attachPreparedFieldToLayouts()`.
- `attachPreparedFieldToLayouts()` saves field layouts after inserting the native field next to the source field.

User impact:

Prepare is more invasive than the docs imply. Operators may expect a field-only config change and instead get field layout mutations.

Resolution:

The layout insertion is intentional — editors and content migration need the native field present before cutover — so it is kept, but the docs now describe it accurately and idempotency is confirmed.

- `README.md` (`prepare-fields` section) now states that prepare changes field configuration **and field layouts**, inserting the native Link field next to its source field in every layout that uses the source, that the insertion is idempotent, and that source Hyper fields stay in both fields and layouts until `finalize`.
- `index.twig` intro wording was corrected to say prepare adds the native fields alongside the Hyper fields in their layouts, and that Hyper fields are only removed during the final cutover.
- Idempotency is provided by `attachPreparedFieldToLayouts()`'s existing `hasTarget` guard, which skips layouts that already contain the native field, so re-running prepare does not add duplicates.

### 11. Re-Running Content Could Not Recover A Blanked Native Target — RESOLVED 2026-06-30 (found during live verification)

Surfaced by the live Craft 5.10 + Hyper 2.3.10 run on 2026-06-30, not the original static review.

After a unit was content-migrated, if its native target value was later cleared (e.g. an editor emptied the native field, or a partial save lost it), re-running `content` skipped the unit as "Already migrated." and never restored it. `reconcileField()` correctly flagged the unit as unverified — so finalize refused (finding 3) — but the documented recovery action (re-run content) did nothing, leaving the operator in a dead-end of the same family as finding 5.

Evidence:

- `ContentMigrationService::migratedTargetIsCurrent()` returned `true` whenever the stored native link *type* was absent (`readNativeTargetType()` returns `null` for an empty value), so a blanked target was treated as "current" and skipped.
- This contradicted `reconcileField()`, which requires the native target to be *populated* (`isPopulatedNativeLink()`), so the finalize authority and the re-migration skip guard disagreed.

User impact:

A migrated value that was subsequently cleared could not be recovered by the normal `content` command; finalize refused forever and content reported "Already migrated."

Resolution:

`migratedTargetIsCurrent()` now also requires the stored native value to be populated, mirroring `reconcileField()`'s presence gate. If a non-empty source's native target is empty or missing, the unit is re-migrated instead of skipped, so re-running `content` recovers it and re-advances the field to `readyToFinalize`. The happy path is unaffected: an unchanged, populated, same-type unit still re-verifies cleanly and converges to "Already migrated."

Verified live: blanking a migrated native value and re-running `content` restored it; the same code path also drives type-drift re-migration (finding 4) correctly.

## Live Verification — 2026-06-30

The full staged workflow was exercised on a disposable Craft 5.10 + MySQL 8 + Verbb Hyper 2.3.10 instance with seeded fields and entries (URL, phone, email, entry, category, empty), template mismatches, and a second Hyper field for stale-state tests. Results:

- Finding 1: `prepare-fields`/`content`/`finalize` all wrote with only `--force=1`; no edition/license gating exists.
- Finding 2: `audit` and `status` left `linkmigrator_fieldmappings` and `linkmigrator_migrations` at 0 rows.
- Finding 3: a `readyToFinalize` field with an empty native target was refused at finalize (reconciliation: "1 of 7 source value(s) are not verified"); phase stayed `readyToFinalize`.
- Finding 4: re-inventory gated readiness; type drift (url→email) was detected, finalize refused, `content` re-migrated to email, finalize then succeeded.
- Finding 5: `content` against a finalized field whose source was cut from the layout exited non-zero with the "re-add the source field" recovery message.
- Finding 6: lookup by an unseen source-field UID returned null (no inheritance); records keyed by `sourceFieldUid` with the unique index `(action, sourceFieldUid, ownerId, siteId)`.
- Finding 7: a `migrated` row was superseded by a later `warning`/`error`, a later `skipped` did not downgrade it, and a corrected re-run restored `migrated`.
- Finding 8: a phone link migrated to native type `tel` in both the payload (`tel:+31…`) and the native field's allowed `types`.
- Finding 9: `finalize` with template mismatches present was refused without `--acknowledge-mismatches=1` (exit 1); dry-run was exempt; the dedicated `mismatches` command exited non-zero.
- Finding 10: running `prepare-fields` twice left exactly one `fieldmappings` row per field and one native field, with no duplicate layout insertion.
- Finding 11: see above.

## Recommended Fix Order

1. Keep feature-level license gating removed and align package/license/docs with the single `$5` paid-plugin decision.
2. Split read-only audit data from persisted workflow state. Audit/status/CP index must not write `linkmigrator_fieldmappings`.
3. Add a reconciliation service that inventories source units by source field UID, layout element UID, element ID, site ID, and supported draft/canonical policy.
4. Make content migration record verified units only after re-reading the saved native Link value and comparing normalized semantics.
5. Treat unsupported, lossy, warning, failed backup, failed save, target conflict, and missing target layout cases as readiness blockers.
6. Make finalize recompute reconciliation fresh and refuse cutover unless every non-empty source unit is verified or explicitly empty.
7. Change migration state keys away from handle-only identity. Use source field UID plus layout element UID where applicable.
8. Make post-finalize recovery explicit: either block content commands after finalize with a clear error, or support raw UID-based recovery from stored source data.
9. Fix native type mapping consistency, starting with `phone` vs `tel`.
10. Add integration fixtures for every supported type: URL, Entry, Asset, Category, Email, Phone, SMS, empty, unsupported, warning-only, conflict, stale-state, and post-finalize recovery.

## Minimal Verification Matrix

- Audit dry run leaves content, project config, and plugin state unchanged.
- Status leaves content, project config, and plugin state unchanged.
- CP index leaves content, project config, and plugin state unchanged.
- Prepare is idempotent and documents layout mutation accurately.
- Content migration verifies saved native values after write.
- Warning-only and unsupported values block readiness.
- Finalize fails if any non-empty source unit is unverified.
- Finalize fails if target field is missing from any required layout.
- Finalize fails when template mismatch state is unreviewed or blocking, if that gate is kept in scope.
- A stale `readyToFinalize` phase alone cannot authorize cutover.
- A previously migrated row can be invalidated by a later failed reconciliation.
- Phone links use one native type consistently in field settings and saved payloads.
