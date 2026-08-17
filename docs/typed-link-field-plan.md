# Typed Link Field migration plan

## Goal

Extend the existing staged migration workflow so it can migrate legacy Typed
Link Field values (`lenz\\linkfield` and `typedlinkfield`) directly to Craft's
native Link field. It must retain the existing safety contract: parallel target
fields, `--force=1` for writes, optional backups, fresh reconciliation before
finalize, and read-only audit/status commands.

## Compatibility and safety contract

- **Audit can identify a source without its PHP class.** Match the current and
  legacy Typed Link field-type strings in stored field configuration. Audit must
  report an unavailable source plugin rather than writing state or pretending a
  value can be migrated.
- **Content migration requires Typed Link to be installed and enabled.** Use its
  hydrated values instead of reading version-specific tables directly. Before a
  content run, fail that field clearly if the source plugin cannot hydrate it;
  do not guess from raw database rows.
- **Scope matches the existing migration workflow.** Enumerate every source
  container, site, draft, revision, and provisional draft already covered by
  `ContentMigrationService`. Native element links keep the target element ID.
  A Typed Link `linkedSiteId` that differs from the owner site cannot be
  represented separately by the native field, so retain the existing warning
  and backup behaviour.
- **Never broaden editor access.** `sources: '*'` maps to an unrestricted
  native source setting. Map only source restrictions that still exist in the
  target Craft installation. If none of a configured type's restrictions are
  valid, refuse prepare for that field; do not omit the setting and turn it
  into an unrestricted selector. A partial set is narrowed to valid sources
  and reported at audit time.
- **No automatic source-plugin or custom-type support.** Typed Link must remain
  installed through verification. Arbitrary registered types keep their source
  data in the optional backup and are skipped unless they produce a supported,
  explicitly configured native value.

## Native-setting capability matrix

The implementation must calculate this matrix during audit and expose every
loss before `prepare-fields`; content migration must not be the first time an
operator learns a field setting will be dropped.

| Typed Link setting or value | Native representation | Fallback when unavailable |
| --- | --- | --- |
| `allowCustomText` / `customText` | `showLabelField` / `label` (Craft 5.5+) | Preserve the value in backup and warn during audit. |
| `defaultText` | `showLabelField` plus the hydrated value's `label` | Preserve the value in backup and warn during audit. |
| `customTextRequired`, `customTextMaxLength` | No native field-setting equivalent | Report the constraint as lossy before prepare. |
| `allowTarget` / `target` | target input / `target` payload (legacy target setting before 5.6; `advancedFields` from 5.6) | Preserve in backup and warn during audit. |
| `autoNoReferrer` | `rel: noopener noreferrer` for `_blank` links (Craft 5.6+) | Preserve in backup and warn during audit. |
| `enableTitle` / `title`, `enableAriaLabel` / `ariaLabel` | matching `advancedFields` (Craft 5.6+) | Preserve in backup and warn during audit. |
| `customQuery` | `urlSuffix` when the native advanced field is available; a missing prefix normalizes to `?` | Strip only the suffix, preserve it in backup, and warn when it cannot be represented. |
| Typed `custom` URL values | native `url` plus native root-relative, anchor, or custom-scheme settings where supported (5.4+/5.7+) | Per-value skip with warning; never write a value the target Link field rejects. |

The existing package floor remains Craft 5.3. The Typed Link feature must use
capability checks rather than raising that floor just to preserve optional
editor settings.

## Implementation order

1. **Discover Typed Link fields.**
   - Update `AuditService` to recognise the current and legacy Typed Link field
     classes as migration sources, without requiring either class at install
     time.
   - Record the source kind on `FieldAudit` and read its enabled link types,
     element-source settings, and capability/loss warnings.
   - Confirm field discovery inside Matrix and nested entry-type layouts.

2. **Map field settings.**
   - Extend `MappingStrategyService` for `url`, `email`, `tel`, `asset`,
     `category`, and `entry`.
   - Transfer Typed Link element-source restrictions into native Link
     `typeSettings`; transfer custom text, target, title, and ARIA-label where
     the installed Craft version supports the equivalent field setting.
   - Treat `custom`, `site`, `user`, Commerce/event, and third-party types as
     partial or unsupported. Never make a non-URL value look successfully
     migrated.
   - Validate source restrictions against the target Craft installation before
     prepare. Preserve `*`; narrow a partially stale list; refuse an entirely
     stale list rather than broadening it. Surface each outcome in audit.

3. **Prepare and adopt target fields.**
   - Reuse `FieldMigrationService` and the existing `<source>Native` naming,
     layout placement, state mapping, and `adopt-prepared` workflow.
   - Pass the mapped native Link `typeSettings` into the target-field config.
   - Do not add commands, tables, or an alternative write guard.

4. **Convert and verify content.**
   - Generalise `ContentMigrationService` to read Typed Link `linkedUrl`,
     `linkedId`, `customText`, `ariaLabel`, `customQuery`, and linked
     element/site values.
   - Write native Link payloads with the matching type and element ID; preserve
     unrepresentable data in the existing warning and optional-backup path.
   - Validate each source value against the prepared target's allowed types and
     settings. Empty, orphaned, relative/anchor/custom-scheme, or otherwise
     invalid values are skipped with a per-element warning and backup; they do
     not abort or silently blank an entire batch.
   - Reuse the current resumable state and reconciliation gates rather than
     introducing source-specific migration state.

5. **Prove the behaviour.**
   - Add unit tests for field/type-settings mapping and Typed Link scalar and
     relational payload conversion.
   - Add cases for custom/site/unknown types that assert a warning or skip,
     stale source restrictions, missing source-plugin preflight, multi-site
     links, and fields in Matrix/nested layouts.
   - Prove interruption/resume idempotency and assert that optional backups
     retain the complete source payload. The plugin does not restore backups;
     do not imply a restore command exists.
   - Run PHPUnit and a disposable Craft 5 migration with Typed Link installed.

6. **Document the operator impact.**
   - Update `README.md`, `docs/TEMPLATE-IMPACT.md`, and `CHANGELOG.md` with
     supported mappings, template API changes, and the requirement to keep
     Typed Link installed until verification and finalization are complete.

## Explicit non-goals

- No automatic conversion of Typed Link custom fields or arbitrary registered
  link types.
- No source-field deletion; finalization continues to remove source fields only
  from field layouts.
- No changes to the read-only or `--force=1` contracts.

## Implementation status — 2026-08-17

Steps 1–4 and 6 are complete. Audit identifies `lenz\\linkfield\\fields\\LinkField` and legacy `typedlinkfield\\fields\\LinkField` configuration without loading the source class, records Typed Link settings/losses, and refuses unavailable source hydration, unknown enabled types, and stale or explicit-empty source restrictions. Prepare reuses the existing staged mapping/layout workflow. Content converts URL, email, tel, asset, category, entry, and valid custom URL values, then normalizes and validates scalar payloads against the prepared Craft Link type configuration. Custom/default text, legacy target settings, and automatic noreferrer values are retained where the native field supports them; warning records retain their optional-backup paths. Custom URL capabilities are enabled only when the custom source type is active and disables validation. Site, user, Commerce/event, and third-party types remain unsupported. Lossy attributes and unsupported values use the warning/optional-backup path and prevent readiness. Step 5 has unit and disposable-Craft coverage; focused Matrix/nested-layout and interrupted-run tests remain follow-ups.

Verified on 2026-08-17 in a fresh disposable Craft 5.10.13.2 + MySQL 8.0 environment with Typed Link Field 3.0.0-beta. The staged workflow ran `audit`, guarded/dry/write `prepare-fields`, dry/write `content --create-backup=1`, `status`, guarded/dry/write `finalize`, and the mismatch scanner. A supported field migrated 10 URL/email/tel/entry/category/asset/custom values, including `?from=typed`, root-relative, anchor, and custom-scheme URLs; it retained exact element sources, label, target, title, and ARIA label, reached `readyToFinalize`, and finalized without deleting the source field. A strict URL target rejected `/must-not-broaden`, wrote a backup, stayed `contentMigrated`, and could not finalize. Clearing one migrated native value made finalize refuse `1 of 10` unverified values; re-running content restored it. An explicit-null entry source stayed unsupported through dry and forced prepare with zero mapping rows. Disabling Typed Link made audit unsupported while mapping/migration row counts stayed `2`/`24`. Scanner coverage found Typed-only APIs and both direct and escaped namespace references. Keep Typed Link Field installed and enabled through the staged sequence.
