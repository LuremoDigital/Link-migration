# Changelog

## 1.1.0 - 2026-07-10

### Added

- `link-migrator/migrate/adopt-prepared` CLI command: records source-to-target mappings for native Link fields that arrived through deployed project config, enabling the local prepare → deploy YAML → migrate content per environment workflow. Supports `--dry-run=1`, requires `--force=1` to write, and accepts `--field` with `--target` for non-convention handles. Refuses to guess between multiple candidate handles, warns when the matched field does not allow the mapped link types, and exits non-zero when nothing was adopted or previously recorded.
- README section on multi-environment deployment covering the two-deploy workflow and its ordering requirements.

## 1.0.0 - 2026-07-09

### Added

- Initial release of Link Migrator for Craft CMS 5.
- Plugin Store description covering the staged workflow, safety controls, supported link types, template checks, and CLI usage.
- Staged migration workflow from Verbb Hyper to Craft's native Link field: audit, prepare-fields, content, and finalize.
- Control Panel wizard with per-field workflow status (admin-only).
- CLI commands with dry-run support; write commands require `--force=1`.
- Fresh content reconciliation gating finalize — cutover is refused while any non-empty source value is unverified.
- Template mismatch scanner (`migrate/mismatches`) with a finalize acknowledgement gate.
- Resumable migration state keyed by source field UID, with JSON/log reports and optional per-element backups.
