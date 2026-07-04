# Link Migrator Risk Audit Handoff

Read-only audit for the follow-up fix pass.

## 2026-07-04 Fix Pass Results

Fixed:

- Removed the Lite/Pro split and `LicenseService`; write commands are gated by `--force=1` only.
- Removed `syncAuditedFields()` and all audit/status/CP index calls that wrote `linkmigrator_fieldmappings`.
- Disabled Hyper link types are ignored during audit.
- Empty/unknown native Link type configs are blocked or defaulted safely; native Link fields cannot be created with `types => []`.
- Unsupported `site`, `user`, `embed`, and unknown scalar values no longer become native URL payloads unless the scalar is URL/path-like.
- Same-type source drift and blank native targets are rechecked against the expected native type/value and remigrated when needed.
- Warning-only content runs exit non-zero and leave fields at `contentMigrated`, not `readyToFinalize`.
- No-layout Hyper fields warn and stay out of finalize readiness.
- Backup/report directory and write failures throw; backup filenames are unique.
- Element links with differing `linkSiteId` emit a warning because native Link stores the element ID only.
- CP writes are no longer performed synchronously; the CP section is read-only and directs write stages to CLI.
- Recovered finalized fields are merged across field reports instead of stopping at the newest report.

Static checks:

- `composer validate --strict`: pass.
- `find src -name '*.php' -print0 | xargs -0 -n1 php -l`: pass.

Disposable Craft 5 + MySQL + Hyper run:

- Environment: Craft 5.10.9, Verbb Hyper 2.3.10, MySQL 8.0, local plugin path install.
- Seeded fixtures: URL, Entry, Asset, Category, Email, Phone, raw SMS payload, empty value, unsupported/custom scalar, site/user-like scalar, disabled Embed link type, same-type drift, blanked native target, and no-layout field.
- `audit --dry-run=1`: exit 0, 3 fields, disabled Embed ignored, `linkmigrator_fieldmappings` stayed at 0 before prepare.
- `mismatches`: exit 0.
- `prepare-fields --dry-run=1`: exit 0, 3 would migrate.
- `prepare-fields --force=1`: exit 0, 3 native fields created.
- second `prepare-fields --force=1`: exit 0, 3 skipped, proving idempotency.
- `content --dry-run=1 --create-backup=1`: exit 1 because warnings are now non-clean.
- `content --force=1 --create-backup=1`: exit 1 with supported values migrated, risk/no-layout values warned, and backups written before saves.
- `content --field=safeLink --force=1 --create-backup=1` rerun: exit 0, converged with no extra backups.
- same-type URL and Entry drift: exit 0, 2 values remigrated.
- blank native target with source present: exit 0, 1 value remigrated.
- `status`: `safeLink=readyToFinalize`, `riskLink=contentMigrated`, `unusedLink=prepared`.
- `finalize --field=safeLink --dry-run=1`: exit 0.
- `finalize --field=safeLink --force=1`: exit 0; source Hyper field removed from the layout only after verification.
- `finalize --field=riskLink --force=1`: exit 1.
- `finalize --field=unusedLink --force=1`: exit 1.
- post-finalize `audit --dry-run=1` and `status`: exit 0; no audit/status workflow-state writes were introduced.
- backup failure simulation: exit 1, `migrated=0`, `backups=0`.
- report directory failure simulation: exit 1 with `Could not create report directory`.

Remaining notes:

- Revision migration remains a documented policy question; this pass did not add revision migration.

## 2026-07-04 Follow-Up Review

Follow-up fixes from review:

- Added `MigrationReport::$verbose` so `ReportService::beginRun()` can safely configure reports.
- Downgraded SMS to unsupported because Craft native Link has no SMS type; SMS is no longer included in generated native field types or content payloads.
- Made finalize idempotent for already-finalized mappings by reporting `mode=already-finalized` before live source-field reconciliation.

## Sources Checked

- Hyper docs: links, link types, Link object, programmatic links, migration guidance.
- Craft docs: native Link field supported types and value shape.
- Repo files under `src/`, `README.md`, `docs/`, and `TODOS.md`.

## Highest Priority Fixes

### 1. Unsupported Hyper Types Can Become Invalid Native URLs

Risk: `site`, `user`, `embed`, product/variant/custom, or unknown Hyper types with scalar `linkValue` can be converted to native `url`. For site/user links that scalar may be an ID/UID, so finalize can pass with a broken href.

Locations:

- `src/services/MappingStrategyService.php:38`
- `src/services/ContentMigrationService.php:753`

Fix direction:

- Only fallback to native `url` when the value is actually URL/path-like or resolvable to a URL.
- Treat `site`, `user`, `embed` data-only values, unknown element IDs, and unresolved plugin element types as unsupported warnings/errors.
- Make unsupported units block readiness and clearly report the affected element/site.

### 2. Same-Type Source Drift Is Not Detected

Risk: after content migration, if an editor changes a Hyper URL to another URL, or one entry link to another entry link, reconciliation only compares type/presence and can finalize stale target content.

Locations:

- `src/services/ContentMigrationService.php:253`
- `src/services/ContentMigrationService.php:330`
- `README.md:36`
- `TODOS.md:21`

Fix direction:

- Compare normalized expected native value as well as native type in `reconcileField()` and `migratedTargetIsCurrent()`.
- Treat unreadable/malformed target type (`actualType === null`) as unverified, not current.
- Add live fixtures for URL, email, tel, entry, asset, category normalization, plus unsupported SMS reporting.

### 3. Disabled Hyper Link Types Are Included

Risk: Hyper allows link types to be disabled per field, but audit currently reads every configured type. Native fields may allow types editors deliberately disabled, and disabled unsupported types can create false partial/unsupported status.

Locations:

- `src/services/AuditService.php:132`
- `src/services/MappingStrategyService.php:30`

Fix direction:

- In `extractLinkTypes()`, skip link type configs where `enabled` is explicitly false.
- Preserve custom handles separately from class names if needed for reporting.

### 4. Content Warnings Report As Success

Risk: warning units are often not migrated, but CLI and CP can still say the content run succeeded because only errors fail. Finalize may refuse later, but the operator gets misleading feedback.

Locations:

- `src/models/ContentMigrationResult.php:57`
- `src/console/controllers/MigrateController.php:238`
- `src/controllers/WizardController.php:75`

Fix direction:

- Add a CP state like "completed with warnings; some values were not migrated."
- Consider non-zero CLI exit when warnings exist, or add a `--strict` mode.
- Include reconciliation readiness in the content result.

### 5. No-Layout Fields Can Advance To Ready

Risk: a Hyper field with zero layout usages can process zero units, reconcile zero unverified values, and advance to `readyToFinalize`. Any undiscovered/orphaned content remains unmigrated.

Locations:

- `src/services/ContentMigrationService.php:68`
- `src/services/ContentMigrationService.php:393`
- `src/services/ContentMigrationService.php:190`

Fix direction:

- If `containers === []`, record a warning and keep the field out of `readyToFinalize`.
- Require an explicit operator flag if advancing an unused field is intended.
- If feasible, add raw UID-based recovery/inventory for orphaned content.

### 6. CP Writes Are Synchronous

Risk: CP buttons run full prepare/content/finalize in one web request. Large sites can hit PHP/gateway timeouts after partial writes.

Locations:

- `src/controllers/WizardController.php:36`
- `src/controllers/WizardController.php:59`
- `src/controllers/WizardController.php:87`

Fix direction:

- Prefer queue jobs with progress for CP writes.
- Short-term: disable CP content/finalize for large sites or steer operators to CLI before running.

### 7. Backup And Report Writes Are Unchecked

Risk: failed `mkdir()` / `file_put_contents()` can be ignored. Content may be changed while the promised backup/report does not exist. Backup filenames also overwrite prior backups for the same action/field/element/site.

Locations:

- `src/services/StateService.php:161`
- `src/services/ReportService.php:16`
- `src/services/ReportService.php:167`

Fix direction:

- Throw on failed directory creation or file writes before mutating content.
- Include the run ID in backup filenames.
- Consider writing backups before `saveElement()` and aborting if the backup write fails.

## Additional Risks From Double-Check

### 8. Multi-Site Link Targeting Can Be Lost

Risk: Hyper `linkSiteId` is used to resolve an element, but the native payload stores only `element->id`. Cross-site target intent can be lost silently.

Locations:

- `src/services/ContentMigrationService.php:725`
- `src/services/ContentMigrationService.php:770`

Fix direction:

- Verify native Craft Link can preserve target site for element links. If not, warn whenever `linkSiteId` differs from the owner element site.
- Preserve the original site targeting in backup/report details.

### 9. Empty Native Link Type Config Is Possible

Risk: if Hyper settings expose no explicit link type list, `craftLinkTypes` can be empty while the field is treated as supported. `FieldMigrationService` then creates a native Link field with `types => []`.

Locations:

- `src/services/MappingStrategyService.php:54`
- `src/services/FieldMigrationService.php:125`

Fix direction:

- Define an explicit default allowed native type set when Hyper is configured for all/enabled types.
- If types cannot be determined safely, mark the field partial/unsupported and block prepare.

### 10. Recovered Status Only Uses Newest Field Report

Risk: `recoverMigratedLinkFields()` returns recovered fields from the newest matching report only. Older finalized fields may disappear from audit/status after newer runs.

Location:

- `src/services/AuditService.php:180`

Fix direction:

- Merge recoverable fields across reports instead of returning after the first non-empty report.
- Prefer DB state where available over report reconstruction.

### 11. Revisions Can Retain Hyper Data

Risk: main migration queries include drafts/provisional drafts/trashed, but not revisions. Reverting to an old revision after Hyper uninstall may revive raw Hyper data into a layout where the field is gone.

Locations:

- `src/services/ContentMigrationService.php:400`
- `src/services/ContentMigrationService.php:882`

Fix direction:

- Decide whether revisions are in scope. If yes, migrate or explicitly inspect them.
- If no, document a warning in audit notes and README before Hyper uninstall.

## Checks Already Run

- `composer validate --strict`: passed.
- `find src -name '*.php' -print0 | xargs -0 -n1 php -l`: passed.
- A read-only double-check was run with search tools only; no file edits.

## Suggested Fix Order

1. Unsupported fallback / invalid URL corruption.
2. Warning-as-success and no-layout readiness.
3. Same-type value reconciliation.
4. Disabled link type filtering and empty type defaults.
5. Backup/report write guarantees.
6. Multi-site target warnings.
7. CP queue/progress.
8. Report recovery and revision policy.
