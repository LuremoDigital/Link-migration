# Link Migrator

Link Migrator is a staged Craft CMS migration plugin for moving Verbb Hyper fields to Craft's native Link field without replacing the original Hyper fields in place.

This plugin is independent and unaffiliated. Verbb Hyper is a plugin by Verbb.

## What This Plugin Does

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

## Installation

Once approved, you will be able to install Link Migrator from Craft's in-app Plugin Store or via Composer.

Install from Composer:

```bash
composer require luremo/craft-link-migrator
php craft plugin/install link-migrator
```

Link Migrator is a commercial Craft CMS plugin. You can install and evaluate it in local, development, and staging environments. A paid license is required before using it on a public production site. Once approved, purchase and license management will be handled through the Craft Plugin Store.

## Pricing and License

One paid version, everything included. No editions and no feature gates. Migration safety confirmations such as `--force=1` are safety checks, not paywalls.

## Recommended Workflow

Run the migration as explicit stages:

```bash
php craft link-migrator/migrate/audit --dry-run=1
php craft link-migrator/migrate/prepare-fields --dry-run=1
php craft link-migrator/migrate/prepare-fields --force=1
php craft link-migrator/migrate/content --dry-run=1 --create-backup=1
php craft link-migrator/migrate/content --force=1 --create-backup=1 --batch-size=100
php craft link-migrator/migrate/status
php craft link-migrator/migrate/mismatches
php craft link-migrator/migrate/finalize --dry-run=1
php craft link-migrator/migrate/finalize --force=1 --acknowledge-mismatches=1
```

Notes:

- In dry-run mode, no changes are written.
- In write mode, the command refuses to run unless `--force=1` is provided.
- `prepare-fields` creates new native Link fields and records source-to-target mappings.
- `content` writes only into prepared native target fields and leaves Hyper values untouched.
- `finalize` updates field layouts; it does not delete Hyper fields in v1.
- Do not use `--acknowledge-mismatches=1` until you have reviewed and accepted the template impact.

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
php craft link-migrator/migrate/mismatches
php craft link-migrator/migrate/finalize --dry-run=1
php craft link-migrator/migrate/finalize --force=1 --acknowledge-mismatches=1
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
- this changes field configuration, not content
- source Hyper fields remain intact

### `link-migrator/migrate/content`

Migrates existing content values into the prepared native target fields.

Important:

- non-dry runs require `--force=1`
- requires `prepare-fields` to have completed first
- content writes are resumable
- already migrated element/site pairs are rechecked and skipped only when the native value still matches
- optional backups are written before content is changed
- if you want to run `php craft project-config/apply`, do it as a separate command after the migration run

### `link-migrator/migrate/status`

Shows the current staged workflow status for each Hyper field, including prepared target handles and content migration counters.

### `link-migrator/migrate/finalize`

Removes Hyper fields from field layouts and leaves the prepared native Link fields in place.

Important:

- non-dry runs require `--force=1`
- if template mismatches are found, non-dry runs also require `--acknowledge-mismatches=1`
- requires `prepare-fields` and `content` to have completed first
- does not delete Hyper fields in v1
- do not acknowledge mismatches until you have reviewed and accepted the template impact

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
- SMS links, because Craft native Link has no SMS type
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

The plugin exposes an admin-only CP wizard that shows:

- audit results
- workflow and field status
- template impact

The wizard can run prepare, content migration, and finalize with explicit confirmation checkboxes. These actions run synchronously in the request, so the CLI remains the safest and recommended production workflow for large migrations.

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
php craft link-migrator/migrate/mismatches
php craft link-migrator/migrate/finalize --force=1 --acknowledge-mismatches=1
php craft link-migrator/migrate/rollback-info
```

## Support

Report bugs and migration edge cases here:

- https://github.com/LuremoDigital/Link-migration/issues
