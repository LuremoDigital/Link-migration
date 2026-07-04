# Changelog

## 1.0.0 - 2026-07-04

### Added

- Initial release of Link Migrator for Craft CMS 5.
- Staged migration workflow from Verbb Hyper to Craft's native Link field: audit, prepare-fields, content, and finalize.
- Control Panel wizard with per-field workflow status (admin-only).
- CLI commands with dry-run support; write commands require `--force=1`.
- Fresh content reconciliation gating finalize — cutover is refused while any non-empty source value is unverified.
- Template mismatch scanner (`migrate/mismatches`) with a finalize acknowledgement gate.
- Resumable migration state keyed by source field UID, with JSON/log reports and optional per-element backups.
