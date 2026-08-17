# Changelog

## Unreleased

### Changed

- Removed the Control Panel wizard. Link Migrator now exposes its staged migration workflow through CLI commands only.
- Added staged migration support for Typed Link Field (`lenz\\linkfield` and legacy `typedlinkfield`) sources. Audit detects fields without loading their PHP class; content migration requires the source plugin to remain installed and enabled. URL, email, phone, asset, category, entry, and valid custom URL values migrate to native Link fields, with source restrictions and supported editor attributes retained. Unsupported or unsafe values are reported and preserved by optional backups.
- Hardened Typed Link and Hyper migration integrity: prepared native settings now normalize and validate every scalar value, unknown enabled types and explicit-empty element sources refuse preparation, stale disabled custom settings cannot broaden URL inputs, lossy attributes warn and block readiness, default text and automatic noreferrer settings are retained where supported, warning backups remain discoverable, query suffixes normalize without discarding the link, and expanded source-API scanning gates finalization.

## 1.1.0 - 2026-08-17

### Added

- `link-migrator/migrate/adopt-prepared` CLI command: records source-to-target mappings for native Link fields that arrived through deployed project config, enabling the local prepare → deploy YAML → migrate content per environment workflow. Supports `--dry-run=1`, requires `--force=1` to write, and accepts `--field` with `--target` for non-convention handles. Refuses to guess between multiple candidate handles, warns when the matched field does not allow the mapped link types, and exits non-zero when nothing was adopted or previously recorded.
- README section on multi-environment deployment covering the two-deploy workflow and its ordering requirements.

### Fixed

- Finalization now compares live content directly instead of trusting a persisted readiness phase.
- Reconciliation recognizes Craft's stored entry/category references and `mailto:`/`tel:` values, preventing valid migrations from being blocked at cutover.

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
