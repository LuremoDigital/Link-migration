<p align="center">
  <img src="https://pluginicons.craft-cdn.com/link-migratorzuwfzbrttedlcmkdcazsbclpilqiqkbhpuht.svg?1784221213" width="120" alt="Link Migrator icon">
</p>

<h1 align="center">Link Migrator</h1>

<p align="center">
  Migrate Verbb Hyper fields and content to Craft CMS native Link fields — safely, in stages, from the CLI.
</p>

<p align="center">
  <a href="https://plugins.craftcms.com/link-migrator"><img src="https://img.shields.io/badge/Craft%20Plugin%20Store-link--migrator-E5422B.svg" alt="Craft Plugin Store"></a>
  <img src="https://img.shields.io/badge/Craft%20CMS-5.x-E5422B.svg" alt="Craft CMS 5.x">
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/edition-Free-0EA5E9.svg" alt="Free edition">
  <img src="https://img.shields.io/badge/license-MIT-0F172A.svg" alt="MIT license">
</p>

---

**Link Migrator** gives Craft teams a staged CLI workflow from [Verbb Hyper](https://plugins.craftcms.com/hyper) to Craft's native Link field. Audit, prepare parallel native fields, migrate content with backups, review template impact, and finalize the layout cutover when everything is ready.

The original Hyper fields and values remain intact throughout the migration. CLI write commands require `--force=1`, and each migration stage produces reports you can inspect before continuing.

Link Migrator is an independent product and is not affiliated with Verbb. Hyper is a plugin by Verbb.

## Features

- **CLI only**: run the staged workflow locally, in CI, or from deployment scripts.
- **Audit before writing**: inspect Hyper fields, supported mappings, lossy cases, and template API mismatches.
- **Keep source data intact**: prepare parallel native Link fields instead of replacing Hyper fields in place.
- **Migrate safely**: process content in batches, resume interrupted runs, and optionally back up each source value.
- **Verify before cutover**: re-read migrated content and refuse finalization while non-empty source values remain unverified.
- **Review template impact**: find common Hyper-only properties and methods that need updating.
- **Track every run**: write human-readable logs and JSON reports to Craft's runtime storage.
- **Automate safely**: use dry runs, explicit write confirmation, and reports in deployment scripts.

## Requirements

- PHP 8.2+
- Craft CMS 5.3+
- Verbb Hyper installed until preparation, content migration, and finalization are complete
- Craft CMS 5.6+ recommended for the full native Link advanced-field set

## Installation

Install Link Migrator from the Craft Plugin Store or use Composer:

```bash
composer require luremo/craft-link-migrator
php craft plugin/install link-migrator
```

Link Migrator is free to use, with every feature included and no edition split.

## Migration Walkthrough

Use this sequence for a single environment. Back up the database and project config first, keep Hyper installed, and inspect each generated report before proceeding.

```bash
# 1. Audit is read-only. Fix or explicitly accept anything in its report.
php craft link-migrator/migrate/audit
php craft link-migrator/migrate/mismatches

# 2. Preview, then create native Link fields beside the Hyper fields.
php craft link-migrator/migrate/prepare-fields --dry-run=1
php craft link-migrator/migrate/prepare-fields --force=1

# 3. Preview, then copy and verify content. Backups are written only by
#    the non-dry run.
php craft link-migrator/migrate/content --dry-run=1
php craft link-migrator/migrate/content --force=1 --create-backup=1 --batch-size=100
php craft link-migrator/migrate/status

# 4. Update templates for native Link values, then scan again. A non-zero
#    exit from mismatches means potential changes were found and must be reviewed.
php craft link-migrator/migrate/mismatches

# 5. Preview, then remove Hyper fields from layouts. The fields and their
#    source values are not deleted.
php craft link-migrator/migrate/finalize --dry-run=1
php craft link-migrator/migrate/finalize --force=1 --acknowledge-mismatches=1
```

`audit`, `status`, and `mismatches` do not change fields, content, or migration mappings. `mismatches` intentionally exits non-zero when it finds potential Hyper API usage. Every non-dry-run write command requires `--force=1`; `finalize` additionally requires `--acknowledge-mismatches=1` when the scanner finds mismatches. A successful `content` run can still return non-zero for warnings, so resolve its report before finalizing.

### Migrate one field

Pass the source Hyper field handle to every stage:

```bash
php craft link-migrator/migrate/audit --field=ctaLink
php craft link-migrator/migrate/prepare-fields --field=ctaLink --force=1
php craft link-migrator/migrate/content --field=ctaLink --force=1 --create-backup=1
php craft link-migrator/migrate/status --field=ctaLink
php craft link-migrator/migrate/finalize --field=ctaLink --force=1 --acknowledge-mismatches=1
```

## Multi-Environment Deployment

`prepare-fields` and `finalize` change field definitions and layouts, so run them only in the environment that authors project config. Commit and deploy the resulting project-config changes. Content and the plugin's mapping state are database data, so run `adopt-prepared` and `content` in every deployed environment.

```bash
# 1. In the project-config authoring environment: prepare fields and migrate
#    its local content. Commit the generated project-config changes.
php craft link-migrator/migrate/prepare-fields --force=1
php craft link-migrator/migrate/content --force=1 --create-backup=1
php craft link-migrator/migrate/status

# 2. First deploy: apply the prepared-field project config everywhere, but
#    keep templates rendering Hyper fields. On each downstream environment:
php craft project-config/apply
php craft link-migrator/migrate/adopt-prepared --dry-run=1
php craft link-migrator/migrate/adopt-prepared --force=1
php craft link-migrator/migrate/content --dry-run=1
php craft link-migrator/migrate/content --force=1 --create-backup=1
php craft link-migrator/migrate/status

# 3. Only after every environment's content is verified: update templates in
#    the authoring environment, then finalize and commit its project config.
php craft link-migrator/migrate/mismatches
php craft link-migrator/migrate/finalize --dry-run=1
php craft link-migrator/migrate/finalize --force=1 --acknowledge-mismatches=1

# 4. Second deploy: deploy the finalized project config and native-Link
#    template changes together.
php craft project-config/apply
```

`adopt-prepared` records the mapping for a deployed native field without changing fields or layouts, so it can run where `allowAdminChanges` is disabled. It expects `<sourceHandle>Native`; use `--field` and `--target` for a different handle. Do not deploy the final layout change until content is verified everywhere: project-config application cannot perform that per-environment verification for you.

## How the Migration Works

| Stage | What it does | Writes data? |
| --- | --- | :---: |
| `audit` | Discovers Hyper fields, mapping support, code references, and likely API mismatches. | No |
| `prepare-fields` | Creates native Link fields, places them beside their source fields in layouts, and records the mappings. | Yes |
| `adopt-prepared` | Records mappings for native Link fields that arrived through deployed project config, without creating fields. | Plugin state only |
| `content` | Copies supported values into prepared native fields and verifies saved values. | Yes |
| `status` | Shows each field's phase, target handle, and migration counters. | No |
| `finalize` | Reconciles live content, then removes source Hyper fields from layouts when every value is ready. | Yes |

`prepare-fields`, `adopt-prepared`, `content`, and `finalize` refuse CLI writes unless `--force=1` is present. If template mismatches are found, finalization also requires `--acknowledge-mismatches=1` after you have reviewed and accepted the template impact. Dry runs do not write field mappings, migration state, project config, or content.

Finalization does not delete Hyper fields. It removes them from field layouts and leaves the prepared native Link fields in place.

## Supported Mappings

| Hyper type | Native Link type | Support |
| --- | --- | --- |
| URL | URL | Full |
| Entry | Entry | Full |
| Asset | Asset | Full |
| Category | Category | Full |
| Email | Email | Full |
| Phone | Phone (`tel`) | Full |
| Custom or plugin type | URL when a scalar URL is available | Partial; review required |

The migration also carries over label/text, target/new-tab behavior, URL suffix, title, class, ID, and `rel` where the installed Craft version supports them. Prepared target handles default to `<sourceHandle>Native`.

### Unsupported or lossy cases

- Hyper fields that allow multiple links
- Embed-only data
- SMS links, because Craft's native Link field has no SMS type
- User, site, or plugin-specific link types without a native equivalent
- Custom fields attached to Hyper link types

Unsupported values are skipped and reported. Custom link data is included in optional backups but is not converted into native Link data.

## Template Impact

Hyper and native Link values do not expose the same Twig and PHP APIs. Run the scanner before finalizing:

```bash
php craft link-migrator/migrate/mismatches
```

The command exits non-zero when it finds likely mismatches, making it useful in CI and deployment checklists.

Common changes include:

| Hyper | Native Link |
| --- | --- |
| `.text` or `.linkText` | `.label` |
| `linkValue` | `.value` or `.url` |
| `getElement()` | `.element` |
| `hasElement()` | Check `.element` directly |
| Hyper link classes | Short type handles such as `entry`, `asset`, or `url` |
| `getLink()`, `getHtml()`, `getData()` | Render or map explicitly |

The scanner is a guide, not proof that every integration is compatible. GraphQL output also changes. Read [Template Impact](docs/TEMPLATE-IMPACT.md) before migrating production content.

## Reports, Backups, and State

Audit, mismatch, prepare, content, and finalize runs each write a JSON report and log file to:

```text
storage/runtime/link-migrator/
```

With `--create-backup=1`, content migration writes per-element source payloads to:

```text
storage/runtime/link-migrator/backups/
```

Resumable per-element state is stored in `{{%linkmigrator_migrations}}`. Prepared source-to-target mappings are stored in `{{%linkmigrator_fieldmappings}}` only after `prepare-fields` or `adopt-prepared` writes them. Audit and status remain read-only.

Use the informational summary at any time:

```bash
php craft link-migrator/migrate/rollback-info
```

This reports migrated, skipped, warning, error, and backup counts. It does not restore content automatically.

## Safety Checklist

- Back up the database and project config before every non-dry run.
- Review audit warnings, unsupported fields, and mismatch results before continuing.
- Keep Hyper installed until reports are clean, templates are updated, and finalization has succeeded.
- Run content migration in each environment because Craft content is environment-specific.
- Verify the site and templates before removing Hyper from the project.

## Support

- **Bug reports:** [GitHub Issues](https://github.com/LuremoDigital/Link-migration/issues). Include Craft, PHP, and Hyper versions plus the relevant JSON report.
- **Changelog:** [CHANGELOG.md](CHANGELOG.md)
- **Template migration guide:** [docs/TEMPLATE-IMPACT.md](docs/TEMPLATE-IMPACT.md)
- **Plugin Store description:** [docs/plugin-store-description.md](docs/plugin-store-description.md)

## License

Link Migrator is released under the [MIT License](LICENSE.txt).

<p align="center">Built by <a href="https://github.com/LuremoDigital">Luremo</a> for the Craft CMS community.</p>
