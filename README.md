# Link Migrator

Link Migrator is a staged Craft CMS migration plugin for moving Verbb Hyper fields to Craft's native Link field without replacing the original Hyper fields in place.

This plugin is independent and unaffiliated. Verbb Hyper is a plugin by Verbb.

## What This Plugin Does

- Audits Hyper fields before anything is changed
- Audits Hyper fields before anything is changed
- Prepares parallel native Craft Link fields for supported Hyper fields
- Migrates existing element content into those prepared native fields in a separate step
- Finalizes the cutover by updating field layouts only when you are ready
- Writes JSON and log reports for every run
- Optionally writes per-element backup payloads before content changes
- Tracks migration state so content migration can resume safely
- Scans your templates and modules for common Hyper-to-Link API mismatches

## Requirements

- PHP 8.2+
- Craft CMS 5.3+
- Verbb Hyper must remain installed until prepare, content migration, and finalize are complete
- Recommended: Craft 5.6+ if you want the fuller native Link advanced field set

## Pricing and Licensing

Link Migrator is a single paid Craft plugin: `$5` for the complete migration workflow. There is no Lite/Pro split and no feature-limited edition; the purchase includes audit, prepare, content migration, backups, template impact review, status reporting, and finalize.

All migration features are included in the one license. Write actions still require explicit operator confirmation via `--force=1`; that confirmation flag is a safety guard, not a pricing gate.

## Installation

Once published, you will be able to install Link Migrator from Craft's in-app Plugin Store or via Composer.

Install from Composer:

```bash
composer require lm2k/craft-link-migrator
php craft plugin/install link-migrator
```

## Recommended Workflow

Run the migration as explicit stages:

```bash
php craft link-migrator/migrate/audit --dry-run=1
php craft link-migrator/migrate/prepare-fields --dry-run=1
php craft link-migrator/migrate/prepare-fields --force=1
php craft link-migrator/migrate/content --dry-run=1 --create-backup=1
php craft link-migrator/migrate/content --force=1 --create-backup=1 --batch-size=100
php craft link-migrator/migrate/status
php craft link-migrator/migrate/finalize --dry-run=1
php craft link-migrator/migrate/finalize --force=1
```

Notes:

- In dry-run mode, no changes are written.
- In write mode, the command refuses to run unless `--force=1` is provided.
- `prepare-fields` creates new native Link fields and records source-to-target mappings.
- `content` writes only into prepared native target fields and leaves Hyper values untouched.
- `finalize` updates field layouts; it does not delete Hyper fields in v1.
- If the mismatch scan found template/module references, `finalize --force=1` refuses to run until you review them and pass `--acknowledge-mismatches=1`.

## Manual Workflow

If you want to inspect every stage yourself, run:

```bash
php craft link-migrator/migrate/audit --dry-run=1
php craft link-migrator/migrate/prepare-fields --dry-run=1
php craft link-migrator/migrate/prepare-fields --force=1
php craft project-config/apply
php craft link-migrator/migrate/content --dry-run=1 --create-backup=1
php craft link-migrator/migrate/content --force=1 --create-backup=1 --batch-size=100
php craft link-migrator/migrate/status
php craft link-migrator/migrate/finalize --dry-run=1
php craft link-migrator/migrate/finalize --force=1
php craft link-migrator/migrate/rollback-info
```

A single-field run is also supported:

```bash
php craft link-migrator/migrate/prepare-fields --field=ctaLink --dry-run=1
php craft link-migrator/migrate/content --field=ctaLink --force=1 --create-backup=1
php craft link-migrator/migrate/finalize --field=ctaLink --force=1
```

## Commands

### `link-migrator/migrate/audit`

Builds an audit of Hyper fields, supported mappings, unsupported cases, code references, and mismatch candidates.

Useful when:

- you want to know which Hyper fields are migratable
- you want to see unsupported link types before changing anything
- you want a machine-readable report of the current state

### `link-migrator/migrate/prepare-fields`

Prepares supported Hyper field definitions by creating new native Craft Link fields and persisting source-to-target mappings.

Important:

- non-dry runs require `--force=1`
- unsupported fields are skipped
- this changes field configuration **and field layouts**: the new native Link field is inserted next to its source Hyper field in every layout that uses the source field, so editors and content migration can populate it before cutover
- the layout insertion is idempotent — re-running prepare will not add duplicate native fields
- no content is changed
- source Hyper fields remain intact (in both fields and layouts) until `finalize`

### `link-migrator/migrate/content`

Migrates existing content values into the prepared native target fields.

Important:

- non-dry runs require `--force=1`
- requires `prepare-fields` to have completed first
- content writes are resumable
- already migrated element/site pairs are skipped on later runs
- optional backups are written before content is changed
- if you want to run `php craft project-config/apply`, do it as a separate command after the migration run

### `link-migrator/migrate/status`

Shows the current staged workflow status for each Hyper field, including prepared target handles and content migration counters.

### `link-migrator/migrate/finalize`

Removes Hyper fields from field layouts and leaves the prepared native Link fields in place.

Important:

- non-dry runs require `--force=1`
- requires `prepare-fields` and `content` to have completed first
- if `migrate/mismatches` reported any template/module references, finalize refuses to run until you review them and re-run with `--acknowledge-mismatches=1`
- recomputes reconciliation from live content and refuses cutover unless every non-empty source value is verified on the native field (the stored phase is not trusted)
- does not delete Hyper fields in v1

### `link-migrator/migrate/mismatches`

Scans templates, modules, `src`, and config for common Hyper-only API usage that usually breaks after migration.

Examples it flags:

- `.text`
- `.linkText`
- `linkValue`
- `getLink()`
- `getElement()`
- `hasElement()`
- `getHtml()`
- `getData()`
- Hyper class-name type checks such as `verbb\hyper\links\Entry`

This command exits non-zero if mismatches are found, which makes it useful in CI or migration checklists.

### `link-migrator/migrate/rollback-info`

Shows informational summaries from the plugin's migration state table:

- migrated counts
- skipped counts
- warning counts
- backup counts
- last update time

It does not automatically roll anything back.

## Supported Mappings

Fully supported link types:

- URL -> URL
- Entry -> Entry
- Asset -> Asset
- Category -> Category
- Email -> Email
- Phone -> Phone
- SMS -> SMS

Migrated advanced attributes:

- label/text
- target/new tab
- URL suffix
- title
- class
- id
- rel

Field configuration defaults:

- the native Link field label field is enabled by default
- target field handles default to `<sourceHandle>Native`

Partially supported or lossy cases:

- custom field layouts on Hyper link types are not migrated
- Hyper fields with broad link-type allowances should be checked after migration
- custom link field data is preserved in backups, not converted into native Link data
- custom or unsupported Hyper link types are downgraded to native URL links when a scalar URL-like value is available

Unsupported cases:

- Hyper fields allowing multiple links
- embed-only data
- user/site/plugin-specific link types without a native Link equivalent

Unsupported values are skipped and reported. They are not silently coerced.

## What Gets Persisted

### Reports

Every run writes:

- a JSON report
- a log report

Stored in:

```text
storage/runtime/link-migrator/
```

### Optional backups

When `--create-backup=1` is used during content migration, per-element backup payloads are written to:

```text
storage/runtime/link-migrator/backups/
```

## Control Panel Wizard

The plugin now exposes a CP section with a staged wizard:

1. Scan
2. Prepare Native Fields
3. Migrate Content
4. Template Impact Review
5. Finalize

All steps are included in the single `$5` plugin license. Write actions still require explicit confirmation: each CLI write command refuses to run without `--force=1`.

### Migration state

The plugin stores per-element migration state in:

```text
{{%linkmigrator_migrations}}
```

This is what allows content migration to skip already migrated element/site pairs and resume safely after interruptions.

## Template and API Differences You Must Review

Hyper and Craft's native Link field are not API-identical, even when the content migration succeeds.

Common breakpoints:

- Hyper `.text` or `.linkText` usually becomes LinkData `.label`
- Hyper `linkValue` becomes LinkData `value` or `url`, depending on what your template really needs
- Hyper `getElement()` and `hasElement()` become checks against `.element`
- Hyper class-name type checks become short Craft type handles like `entry`, `asset`, or `url`
- Hyper-only helpers like `getHtml()` and `getData()` do not exist on native Link values
- Hyper GraphQL output shape differs from Craft Link GraphQL output

Read [docs/TEMPLATE-IMPACT.md](docs/TEMPLATE-IMPACT.md) before running the content migration in production.

## Safety Notes

- Back up the database and project config before any non-dry run
- Keep Hyper installed until reports are clean and templates are updated
- Run content migration separately in each environment because content is environment-specific
- Treat `migrate/mismatches` as a guide, not a proof that every template issue has been found
- `rollback-info` is informational only; it is not an automatic restore command

## Typical Example

Dry run everything first:

```bash
php craft link-migrator/migrate/mismatches
php craft link-migrator/migrate/audit --dry-run=1
php craft link-migrator/migrate/prepare-fields --dry-run=1
php craft link-migrator/migrate/content --dry-run=1 --create-backup=1
php craft link-migrator/migrate/finalize --dry-run=1
```

Then perform the real migration:

```bash
php craft link-migrator/migrate/prepare-fields --force=1
php craft link-migrator/migrate/content --force=1 --create-backup=1 --batch-size=100
php craft link-migrator/migrate/status
php craft link-migrator/migrate/finalize --force=1
php craft link-migrator/migrate/rollback-info
```

## Support

Report bugs and migration edge cases here:

- https://github.com/LuukRM2000/hyper-to-native-migration/issues
