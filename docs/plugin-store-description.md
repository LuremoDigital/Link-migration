# Link Migrator

Link Migrator provides a safe, staged workflow for migrating **Verbb Hyper** fields and content to Craft CMS’s native **Link** field.

Instead of replacing fields or modifying existing content in place, Link Migrator creates parallel native Link fields, copies supported values into them, verifies the results, and only removes the original Hyper fields from field layouts once the migration is ready to complete. Your original Hyper fields and source values remain intact throughout the workflow.

The migration can be managed through a guided Control Panel wizard or automated using Craft’s CLI commands.

## Guided Control Panel workflow

The Control Panel wizard walks you through each stage:

1. **Audit** your site to discover Hyper fields, supported link types, unsupported or lossy values, and likely template API mismatches.
2. **Prepare native fields** by creating Craft Link fields beside the original Hyper fields in your field layouts.
3. **Migrate content** by copying supported Hyper values into the prepared native fields.
4. **Review template impact** to identify common Hyper-specific Twig and PHP APIs that may need updating.
5. **Finalize the cutover** by removing the original Hyper fields from field layouts after all content has been verified.

After each stage, the wizard refreshes its audit and workflow status so you can review the results before continuing.

## Safe, reversible migration process

Link Migrator is designed to make production migrations easier to inspect and control:

- Original Hyper fields are not deleted automatically.
- Native fields are prepared alongside the source fields.
- Content migration can be run in batches.
- Interrupted migrations can be resumed.
- Migrated values are re-read and verified before finalization.
- Optional per-element backups preserve the original Hyper payloads.
- Finalization is blocked while non-empty source values remain unverified.
- CLI write operations require explicit `--force=1` confirmation.
- Dry runs allow you to preview changes without writing fields, content, mappings, or migration state.
- CLI stages produce human-readable logs and JSON reports in Craft’s runtime storage.

## Supported link types

Link Migrator supports the common Hyper link types and maps them to their native Craft equivalents:

- URL
- Entry
- Asset
- Category
- Email
- Phone

Where supported by the installed Craft version, the migration also carries over link labels, target or new-tab behavior, URL suffixes, titles, CSS classes, IDs, and `rel` attributes.

Custom or plugin-specific link types may be migrated partially when a scalar URL is available, but these cases are reported for manual review.

## Template compatibility checks

Hyper and Craft’s native Link field do not expose identical Twig or PHP APIs. Before finalization, Link Migrator scans project templates and PHP files for common Hyper-specific properties and methods, including:

- `.text`
- `.linkText`
- `linkValue`
- `getElement()`
- `hasElement()`
- `getLink()`
- `getHtml()`
- `getData()`
- Hyper-specific type and class checks

The scanner reports likely mismatches and suggests the corresponding native Link APIs, helping you identify template changes before removing Hyper fields from your layouts.

The scanner is intended as a migration aid and review checklist. It does not replace testing your templates, integrations, GraphQL queries, or frontend output.

## Control Panel and CLI support

Use the Control Panel wizard for a guided, visual migration experience, or use the CLI for scripted deployments, CI checks, dry runs, individual field migrations, and repeatable environment workflows. Before any non-dry run, back up your database and project config. Preview each write stage first, then run the corresponding command with explicit confirmation.

```bash
php craft link-migrator/migrate/audit --dry-run=1
php craft link-migrator/migrate/prepare-fields --dry-run=1
php craft link-migrator/migrate/prepare-fields --force=1
php craft link-migrator/migrate/content --dry-run=1 --create-backup=1
php craft link-migrator/migrate/content --force=1 --create-backup=1
php craft link-migrator/migrate/status
php craft link-migrator/migrate/mismatches
php craft link-migrator/migrate/finalize --dry-run=1
php craft link-migrator/migrate/finalize --force=1 --acknowledge-mismatches=1
```

Individual fields can also be migrated by specifying the source Hyper field handle.

## Important limitations

Link Migrator reports unsupported and potentially lossy cases instead of silently converting them. These include:

- Hyper fields that allow multiple links
- Embed-only data
- SMS links
- User, site, or plugin-specific link types without a native equivalent
- Custom fields attached to Hyper link types

Unsupported values are skipped and included in reports. Their original payloads can be preserved in backups for manual handling.

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later
- Verbb Hyper installed until the staged migration is complete
- Craft CMS 5.6 or later recommended for the full native Link advanced-field set

Link Migrator is free, includes the complete workflow, and is released under the MIT license.

> Link Migrator is an independent project and is not affiliated with Verbb. Hyper is a plugin by Verbb.
