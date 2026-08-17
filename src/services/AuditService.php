<?php

namespace luremo\linkmigrator\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\fields\Link;
use craft\fields\linktypes\Url;
use craft\services\ElementSources;
use luremo\linkmigrator\LinkMigrator;
use luremo\linkmigrator\models\AuditResult;
use luremo\linkmigrator\models\FieldAudit;
use luremo\linkmigrator\models\MappingDecision;

class AuditService extends Component
{
    private const MISMATCH_PATTERNS = [
        [
            'pattern' => '.text',
            'replacement' => '.label plus source-specific fallback/precedence review',
            'reason' => 'Source link text can include fallback behavior that LinkData `.label` does not reproduce.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => '.linkText',
            'replacement' => '.label',
            'reason' => 'Hyper `linkText` should usually become Craft LinkData `.label`.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'linkValue',
            'replacement' => 'value or url',
            'reason' => 'Hyper `linkValue` maps to `value` for the raw stored value or `url` for rendered href output.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'customText',
            'replacement' => 'label',
            'reason' => 'Typed Link `customText` maps to Craft LinkData `label`.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'linkedUrl',
            'replacement' => 'url',
            'reason' => 'Typed Link `linkedUrl` maps to Craft LinkData `url`.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getLinkAttributes(',
            'replacement' => '.attributes (Craft 5.9+) or manual mapping; merge passed overrides explicitly',
            'reason' => 'Typed Link renders an attribute string and accepts overrides; LinkData exposes only an attribute array on newer Craft versions.',
            'aliases' => ['.linkAttributes'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getRawLinkAttributes(',
            'replacement' => '.attributes (Craft 5.9+) or manual mapping; merge passed overrides explicitly',
            'reason' => 'Typed Link accepts attribute overrides; LinkData exposes only its base attributes on newer Craft versions.',
            'aliases' => ['.rawLinkAttributes'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getLink(',
            'replacement' => '.link; rewrite calls that pass text or attributes',
            'reason' => 'Craft LinkData can render a link, but it does not accept Typed Link or Hyper text/attribute arguments.',
            'argumentsOnly' => true,
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getHtml(',
            'replacement' => 'manual rendering',
            'reason' => 'Hyper embed/html helpers do not exist on Craft LinkData.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getData(',
            'replacement' => 'manual mapping or backup-only data',
            'reason' => 'Hyper embed/provider payload helpers do not exist on Craft LinkData.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getElement(',
            'replacement' => '.element; remove Typed Link ignoreStatus arguments',
            'reason' => 'Craft LinkData exposes `.element` and `getElement()`, but not Typed Link’s ignore-status argument.',
            'argumentsOnly' => true,
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'hasElement(',
            'replacement' => 'if link.element',
            'reason' => 'Craft LinkData does not provide `hasElement()`; check `.element` directly.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getAllowCustomText(',
            'replacement' => 'remove the runtime setting check and use .label',
            'reason' => 'Craft LinkData does not expose the source field’s allow-custom-text setting.',
            'aliases' => ['.allowCustomText'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getAllowTarget(',
            'replacement' => 'remove the runtime setting check and use .target',
            'reason' => 'Craft LinkData does not expose the source field’s allow-target setting.',
            'aliases' => ['.allowTarget'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getAriaLabel(',
            'replacement' => '.ariaLabel',
            'reason' => 'Typed Link’s aria-label getter becomes the native LinkData property.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getCustomText(',
            'replacement' => 'explicit custom/default/fallback logic; .label is not equivalent',
            'reason' => 'Typed Link custom-text fallback order is not preserved by the native LinkData label.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getDefaultText(',
            'replacement' => 'preserve the field default explicitly; .label may be custom or intrinsic',
            'reason' => 'Typed Link exposes the field default independently, while LinkData resolves one effective label.',
            'aliases' => ['.defaultText'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getEnableAriaLabel(',
            'replacement' => 'remove the runtime setting check and use .ariaLabel',
            'reason' => 'Craft LinkData does not expose the source field’s enable-aria-label setting.',
            'aliases' => ['.enableAriaLabel'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getEnableTitle(',
            'replacement' => 'remove the runtime setting check and use .title',
            'reason' => 'Craft LinkData does not expose the source field’s enable-title setting.',
            'aliases' => ['.enableTitle'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getIntrinsicText(',
            'replacement' => 'type-specific intrinsic label, such as .element.title',
            'reason' => 'LinkData `.label` can contain a migrated custom/default label, so it is not always intrinsic text.',
            'aliases' => ['.intrinsicText'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getIntrinsicUrl(',
            'replacement' => '.url',
            'reason' => 'Craft LinkData exposes the resolved URL through `.url`.',
            'aliases' => ['.intrinsicUrl'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getLinkType(',
            'replacement' => '.type',
            'reason' => 'Craft LinkData exposes a short type handle rather than Typed Link’s link-type model.',
            'aliases' => ['.linkType'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getOwnerSite(',
            'replacement' => 'use the owner element site',
            'reason' => 'Craft LinkData does not expose Typed Link’s owner-site helper.',
            'aliases' => ['.ownerSite'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getTarget(',
            'replacement' => '.target',
            'reason' => 'Typed Link’s target getter becomes the native LinkData property.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getText(',
            'replacement' => '.label plus explicit fallback/precedence handling',
            'reason' => 'Typed Link checks custom, intrinsic, default, then fallback text; LinkData exposes one effective label.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getTitle(',
            'replacement' => '.title',
            'reason' => 'Typed Link’s title getter becomes the native LinkData property.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getUrl(',
            'replacement' => '.url; rewrite Typed Link URL option arrays explicitly',
            'reason' => 'A no-argument URL read remains portable, but Craft LinkData does not accept Typed Link’s URL modification options.',
            'argumentsOnly' => true,
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getSiteId(',
            'replacement' => 'use owner-site .element.siteId; the cross-site target site is not preserved',
            'reason' => 'Craft native Link cannot preserve a linkedSiteId that differs from the owner site.',
            'aliases' => ['.siteId'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'isCrossSiteLink(',
            'replacement' => 'compare link.element.siteId with the owner site',
            'reason' => 'Craft native Link cannot preserve the separate target site used by Typed Link’s cross-site helper.',
            'aliases' => ['.crossSiteLink'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'isEmpty(',
            'replacement' => 'if not link.url',
            'reason' => 'Craft LinkData does not expose Typed Link’s `isEmpty()` helper.',
            'aliases' => ['.empty'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'isEditorEmpty(',
            'replacement' => 'validate the native value directly',
            'reason' => 'Craft LinkData does not expose Typed Link’s editor-empty helper.',
            'aliases' => ['.editorEmpty'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'getSite(',
            'replacement' => 'resolve the site explicitly',
            'reason' => 'Craft LinkData does not expose Typed Link’s site-link helper.',
            'aliases' => ['.site'],
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'customQuery',
            'replacement' => 'urlSuffix',
            'reason' => 'Typed Link custom queries migrate to the native LinkData URL suffix.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'linkedId',
            'replacement' => 'element.id or value',
            'reason' => 'Typed Link linked IDs are represented by the native LinkData element or raw value.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'linkedSiteId',
            'replacement' => 'owner-site element.siteId; the cross-site target site is not preserved',
            'reason' => 'Craft native Link cannot preserve a linkedSiteId that differs from the owner site.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'linkedTitle',
            'replacement' => 'label or element.title',
            'reason' => 'Typed Link cached titles are represented by the native label or resolved element.',
            'sourceScoped' => true,
        ],
        [
            'pattern' => 'verbb\\hyper\\links\\',
            'replacement' => 'entry, asset, category, email, phone, url',
            'reason' => 'Hyper type checks often use class names; Craft LinkData `.type` uses short handles.',
        ],
        [
            'pattern' => 'verbb\\\\hyper\\\\links\\\\',
            'replacement' => 'entry, asset, category, email, phone, url',
            'reason' => 'Escaped Hyper class-name checks in PHP strings must be rewritten to Craft LinkData short handles.',
        ],
        [
            'pattern' => 'lenz\\linkfield',
            'replacement' => 'craft\\fields\\data\\LinkData',
            'reason' => 'Typed Link class references must be rewritten for Craft LinkData.',
        ],
        [
            'pattern' => 'lenz\\\\linkfield',
            'replacement' => 'craft\\fields\\data\\LinkData',
            'reason' => 'Escaped Typed Link class references must be rewritten for Craft LinkData.',
        ],
        [
            'pattern' => 'typedlinkfield',
            'replacement' => 'Craft LinkData APIs',
            'reason' => 'Legacy Typed Link plugin references must be rewritten for Craft LinkData.',
        ],
    ];

    public function buildAudit(?string $fieldHandle = null): AuditResult
    {
        $result = new AuditResult();
        $allFields = Craft::$app->getFields()->getAllFields(false);
        $storedFields = (new Query())
            ->select(['id', 'type', 'settings'])
            ->from('{{%fields}}')
            ->indexBy('id')
            ->all();
        $fieldsByHandle = [];

        foreach ($allFields as $field) {
            if ($fieldHandle && $field->handle !== $fieldHandle) {
                continue;
            }

            $stored = $storedFields[$field->id] ?? [];
            $sourceKind = $this->sourceKind($field, (string)($stored['type'] ?? ''));
            if ($sourceKind === null) {
                continue;
            }

            $settings = $this->mergeFieldSettings(
                $this->decodeSettings($stored['settings'] ?? null),
                array_merge(
                    $field->getAttributes(),
                    method_exists($field, 'getSettings') ? $field->getSettings() : []
                ),
            );
            $linkTypes = $this->extractLinkTypes($settings, $sourceKind);
            $fieldLayouts = $this->extractCustomFieldLayouts($settings);
            $multi = (bool)($settings['multipleLinks'] ?? $settings['allowMultiple'] ?? false);
            $sourcePluginAvailable = $sourceKind !== 'typed-link' || $this->typedLinkPluginAvailable($field);

            $audit = new FieldAudit([
                'fieldId' => (int)$field->id,
                'uid' => (string)$field->uid,
                'handle' => (string)$field->handle,
                'name' => (string)$field->name,
                'sourceKind' => $sourceKind,
                'sourcePluginAvailable' => $sourcePluginAvailable,
                'multi' => $multi,
                'allowedHyperTypes' => $linkTypes,
                'customFieldLayouts' => $fieldLayouts,
                'containers' => $this->discoverContainers($field),
                'rawSettings' => $settings,
            ]);

            $audit->mapping = LinkMigrator::$plugin
                ->getMappingStrategy()
                ->decide(
                    $settings,
                    $linkTypes,
                    $multi,
                    $fieldLayouts,
                    $sourceKind,
                    $sourceKind === 'typed-link' ? $this->availableTypedLinkSources() : [],
                    $this->nativeLinkCapabilities(),
                );
            if (!$sourcePluginAvailable) {
                $audit->mapping->status = MappingDecision::STATUS_UNSUPPORTED;
                $audit->mapping->unsupportedReasons[] = 'Typed Link Field is not installed and enabled, so its values cannot be hydrated for migration.';
            }
            $audit->warnings = $audit->mapping->warnings;

            $fieldsByHandle[$audit->handle] = $audit;
        }

        foreach ($this->recoverMigratedLinkFields($fieldHandle, array_keys($fieldsByHandle)) as $audit) {
            $fieldsByHandle[$audit->handle] = $audit;
        }

        $result->fields = array_values($fieldsByHandle);

        $result->codeReferences = $this->findCodeReferences();
        $result->mismatchReferences = $this->findMismatchReferences(array_keys($fieldsByHandle));
        $result->notes = [
            'Source plugins must remain installed and enabled during content migration so existing field values can still be hydrated.',
            'Content migration should be rerun in each environment because content is environment-specific.',
            'Craft Link field exists only in Craft 5.3.0+; advanced fields like URL suffix/class/id/rel require newer 5.x versions.',
        ];

        return $result;
    }

    private function sourceKind(object $field, string $storedType): ?string
    {
        if ($this->isTypedLinkType($storedType) || $this->isTypedLinkType($field::class)) {
            return 'typed-link';
        }

        return (
            is_a($field, 'verbb\\hyper\\fields\\HyperField', true)
            || is_a($field, 'verbb\\hyper\\fields\\Hyper', true)
            || str_contains($field::class, '\\hyper\\fields\\')
        ) ? 'hyper' : null;
    }

    private function isTypedLinkType(string $type): bool
    {
        return in_array($type, [
            'lenz\\linkfield\\fields\\LinkField',
            'typedlinkfield\\fields\\LinkField',
        ], true);
    }

    private function typedLinkPluginAvailable(object $field): bool
    {
        if (!is_a($field, 'lenz\\linkfield\\fields\\LinkField') && !is_a($field, 'typedlinkfield\\fields\\LinkField')) {
            return false;
        }

        foreach (Craft::$app->getPlugins()->getAllPlugins() as $plugin) {
            if (
                (str_starts_with($plugin::class, 'lenz\\linkfield\\') || str_starts_with($plugin::class, 'typedlinkfield\\')) &&
                Craft::$app->getPlugins()->isPluginEnabled($plugin->handle)
            ) {
                return true;
            }
        }

        return false;
    }

    private function extractLinkTypes(array $settings, string $sourceKind = 'hyper'): array
    {
        if ($sourceKind === 'typed-link') {
            $types = [];
            $enableAll = ($settings['enableAllLinkTypes'] ?? false) === true;
            foreach ((array)($settings['typeSettings'] ?? []) as $type => $typeSettings) {
                $typeSettings = (array)$typeSettings;
                $enabled = array_key_exists('enabled', $typeSettings)
                    ? $typeSettings['enabled'] === true
                    : $enableAll;
                if ($enabled) {
                    $types[] = $this->normalizeHyperType((string)$type);
                }
            }

            return array_values(array_unique(array_filter($types)));
        }

        $types = $settings['linkTypes'] ?? $settings['types'] ?? [];
        $handles = [];

        foreach ((array)$types as $key => $type) {
            if (is_string($type)) {
                $handles[] = $this->normalizeHyperType($type);
            } elseif (is_array($type) && isset($type['type'])) {
                if (($type['enabled'] ?? true) === false) {
                    continue;
                }

                $handles[] = $this->normalizeHyperType((string)$type['type']);
            } elseif (is_array($type)) {
                if (($type['enabled'] ?? true) === false) {
                    continue;
                }

                foreach (['handle', 'class'] as $typeKey) {
                    if (isset($type[$typeKey]) && is_string($type[$typeKey])) {
                        $handles[] = $this->normalizeHyperType($type[$typeKey]);
                        break;
                    }
                }
            } elseif ($type !== false && is_string($key)) {
                $handles[] = $this->normalizeHyperType($key);
            }
        }

        return array_values(array_unique(array_filter($handles)));
    }

    private function normalizeHyperType(string $type): string
    {
        $type = strtolower($type);
        $type = preg_replace('/^.*\\\\/', '', $type);

        return match ($type) {
            'asset' => 'asset',
            'category' => 'category',
            'email' => 'email',
            'entry' => 'entry',
            'phone' => 'phone',
            'tel' => 'tel',
            'url' => 'url',
            default => $type,
        };
    }

    private function decodeSettings(mixed $settings): array
    {
        if (is_array($settings)) {
            return $settings;
        }

        if (!is_string($settings) || $settings === '') {
            return [];
        }

        $decoded = json_decode($settings, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function mergeFieldSettings(array $stored, array $hydrated): array
    {
        return array_merge($hydrated, $stored);
    }

    private function nativeLinkCapabilities(): array
    {
        return [
            'showLabelField' => property_exists(Link::class, 'showLabelField'),
            'advancedFields' => property_exists(Link::class, 'advancedFields'),
            'targetField' => MappingStrategyService::supportsLegacyTargetField(),
            'allowRootRelativeUrls' => property_exists(Url::class, 'allowRootRelativeUrls'),
            'allowAnchors' => property_exists(Url::class, 'allowAnchors'),
            'allowCustomSchemes' => property_exists(Url::class, 'allowCustomSchemes'),
        ];
    }

    private function availableTypedLinkSources(): array
    {
        $types = [
            'asset' => \craft\elements\Asset::class,
            'category' => \craft\elements\Category::class,
            'entry' => \craft\elements\Entry::class,
        ];
        $available = [];

        foreach ($types as $type => $elementType) {
            try {
                $available[$type] = array_values(array_filter(array_map(
                    static fn(array $source) => $source['key'] ?? null,
                    Craft::$app->getElementSources()->getSources($elementType, ElementSources::CONTEXT_FIELD),
                )));
            } catch (\Throwable) {
                $available[$type] = [];
            }
        }

        return $available;
    }

    private function extractCustomFieldLayouts(array $settings): array
    {
        $layouts = $settings['fieldLayouts'] ?? $settings['fields'] ?? [];
        return is_array($layouts) ? $layouts : [];
    }

    private function discoverContainers(object $field): array
    {
        $containers = [];

        foreach (Craft::$app->getFields()->findFieldUsages($field) as $usage) {
            $containers[] = $usage;
        }

        return $containers;
    }

    /**
     * @return FieldAudit[]
     */
    private function recoverMigratedLinkFields(?string $fieldHandle, array $existingHandles): array
    {
        $baseDir = Craft::getAlias('@storage/runtime/link-migrator');
        if (!$baseDir || !is_dir($baseDir)) {
            return [];
        }

        $reports = glob($baseDir . DIRECTORY_SEPARATOR . '*-fields.json') ?: [];
        rsort($reports, SORT_STRING);

        $recovered = [];
        foreach ($reports as $reportPath) {
            $payload = json_decode((string)file_get_contents($reportPath), true);
            if (!is_array($payload)) {
                continue;
            }

            foreach (($payload['migrated'] ?? []) as $item) {
                $handle = $item['field'] ?? null;
                if (!is_string($handle) || $handle === '') {
                    continue;
                }

                if ($fieldHandle && $handle !== $fieldHandle) {
                    continue;
                }

                if (in_array($handle, $existingHandles, true)) {
                    continue;
                }

                if (isset($recovered[$handle])) {
                    continue;
                }

                $field = $this->findFieldByHandle($handle);
                if (!$field || !$field instanceof Link) {
                    continue;
                }

                $mapping = new MappingDecision([
                    'status' => (string)($item['status'] ?? MappingDecision::STATUS_PARTIAL),
                    'craftLinkTypes' => $field->types,
                    'advancedFields' => $this->nativeAdvancedFields($field),
                ]);

                $audit = new FieldAudit([
                    'fieldId' => (int)$field->id,
                    'uid' => (string)$field->uid,
                    'handle' => (string)$field->handle,
                    'name' => (string)$field->name,
                    'multi' => false,
                    'allowedHyperTypes' => $field->types,
                    'containers' => $this->discoverContainers($field),
                    'rawSettings' => method_exists($field, 'getSettings') ? $field->getSettings() : [],
                ]);
                $audit->mapping = $mapping;
                $audit->warnings = $mapping->warnings;

                $recovered[$handle] = $audit;
            }
        }

        return array_values($recovered);
    }

    private function nativeAdvancedFields(object $field): array
    {
        if (property_exists($field, 'advancedFields')) {
            return (array)$field->advancedFields;
        }

        return property_exists($field, 'showTargetField') && $field->showTargetField ? ['target'] : [];
    }

    private function findFieldByHandle(string $handle): ?object
    {
        foreach (Craft::$app->getFields()->getAllFields(false) as $field) {
            if ((string)$field->handle === $handle) {
                return $field;
            }
        }

        return null;
    }

    private function findCodeReferences(): array
    {
        $patterns = [
            '.url',
            '.text',
            '.linkText',
            '.target',
            'getLink(',
            'getHtml(',
            'getData(',
            'linkValue',
            '.type',
            'getElement(',
            'hasElement(',
            'Hyper',
        ];

        $roots = [
            Craft::getAlias('@root/templates'),
            Craft::getAlias('@root/modules'),
            Craft::getAlias('@root/src'),
            Craft::getAlias('@root/config'),
        ];

        $references = [];

        foreach ($roots as $root) {
            if (!$root || !is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $contents = @file($fileInfo->getPathname());
                if ($contents === false) {
                    continue;
                }

                foreach ($contents as $lineNumber => $line) {
                    foreach ($patterns as $pattern) {
                        if (str_contains($line, $pattern)) {
                            $references[] = [
                                'file' => $fileInfo->getPathname(),
                                'line' => $lineNumber + 1,
                                'pattern' => $pattern,
                                'snippet' => trim($line),
                            ];
                        }
                    }
                }
            }
        }

        return $references;
    }

    public function findMismatchReferences(array $sourceFieldHandles = []): array
    {
        $roots = [
            Craft::getAlias('@root/templates'),
            Craft::getAlias('@root/modules'),
            Craft::getAlias('@root/src'),
            Craft::getAlias('@root/config'),
        ];

        $matches = [];

        foreach ($roots as $root) {
            if (!$root || !is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $contents = @file($fileInfo->getPathname());
                if ($contents === false) {
                    continue;
                }
                $scanContents = $this->withoutCommentLines(
                    $contents,
                    strtolower($fileInfo->getExtension()) === 'php',
                );
                $sourceReferences = $this->sourceReferencesByLine($scanContents, $sourceFieldHandles);

                foreach ($scanContents as $lineNumber => $line) {
                    foreach (self::MISMATCH_PATTERNS as $mismatch) {
                        foreach ([$mismatch['pattern'], ...($mismatch['aliases'] ?? [])] as $pattern) {
                            $candidate = [...$mismatch, 'pattern' => $pattern];
                            if (!$this->lineMatchesMismatch($scanContents, $lineNumber, $candidate, $sourceReferences[$lineNumber])) {
                                continue;
                            }

                            $matches[] = [
                                'file' => $fileInfo->getPathname(),
                                'line' => $lineNumber + 1,
                                'pattern' => $pattern,
                                'replacement' => $mismatch['replacement'],
                                'reason' => $mismatch['reason'],
                                'snippet' => trim($contents[$lineNumber]),
                            ];
                        }
                    }
                }
            }
        }

        return $matches;
    }

    private function lineMatchesMismatch(array $lines, int $lineNumber, array $mismatch, array $sourceReferences): bool
    {
        $line = $lines[$lineNumber];
        $pattern = $mismatch['pattern'];
        $containsPattern = str_starts_with($pattern, '.')
            ? preg_match('/' . preg_quote($pattern, '/') . '(?![A-Za-z0-9_])/', $line) === 1
            : str_contains($line, $pattern);
        if (!$containsPattern) {
            return false;
        }

        $positions = [];
        if (!empty($mismatch['sourceScoped'])) {
            $positions = $this->sourcePatternPositions($this->sourceContext($lines, $lineNumber), $line, $pattern, $sourceReferences);
            if ($positions === []) {
                return false;
            }
        }

        if (!empty($mismatch['argumentsOnly'])) {
            if ($positions === []) {
                $offset = 0;
                while (($position = strpos($line, $pattern, $offset)) !== false) {
                    $positions[] = $position;
                    $offset = $position + strlen($pattern);
                }
            }

            foreach ($positions as $position) {
                $tail = substr($line, $position + strlen($pattern));
                $nextLine = $lineNumber + 1;
                while (true) {
                    $stripped = preg_replace('/^\s*(?:(?:\{#.*?#\}|\/\*.*?\*\/|\/\/[^\r\n]*|#[^\r\n]*)\s*)+/s', '', $tail) ?? $tail;
                    $trimmed = ltrim($stripped);
                    $unfinishedComment = (str_starts_with($trimmed, '{#') && !str_contains($trimmed, '#}'))
                        || (str_starts_with($trimmed, '/*') && !str_contains($trimmed, '*/'));
                    if (($trimmed !== '' && !$unfinishedComment) || !isset($lines[$nextLine])) {
                        $tail = $stripped;
                        break;
                    }
                    $tail .= $lines[$nextLine++];
                }

                if (preg_match('/^\s*\)/', $tail) !== 1) {
                    return true;
                }

            }

            return false;
        }

        return true;
    }

    private function sourcePatternPositions(string $context, string $line, string $pattern, array $sourceReferences): array
    {
        $positions = [];
        $lineOffset = strlen($context) - strlen($line);
        $member = ltrim($pattern, '.');
        foreach ($sourceReferences as $reference) {
            if (!is_string($reference) || $reference === '') {
                continue;
            }

            $receiver = (str_starts_with($reference, '$') ? '(?<![A-Za-z0-9_])' : '(?<![A-Za-z0-9_$])')
                . preg_quote($reference, '/') . '(?![A-Za-z0-9_])';
            $regex = '/' . $receiver . '(?:[\'\"]\s*[\]\)]\s*)?\s*(?:\?\.|\.|\?->|->)\s*\K' . preg_quote($member, '/') . '/s';
            preg_match_all($regex, $context, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [, $offset]) {
                if ($offset >= $lineOffset) {
                    $positions[] = $offset - $lineOffset;
                }
            }
        }

        return array_values(array_unique($positions));
    }

    private function sourceReferencesByLine(array $lines, array $sourceFieldHandles): array
    {
        $contents = implode('', $lines);
        $assignmentsByLine = [];
        foreach (['/\bset\s+([A-Za-z_][A-Za-z0-9_]*)\s*=(?!=|>)\s*(.*?)(?:%}|$)/s', '/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=(?!=|>)\s*(.*?);/s'] as $pattern) {
            preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $endOffset = $match[0][1] + strlen($match[0][0]);
                $assignmentLine = substr_count(substr($contents, 0, $endOffset), "\n");
                $assignmentsByLine[$assignmentLine][] = [$match[1][0], $match[2][0]];
            }
        }

        $references = $sourceFieldHandles;
        $referencesByLine = [];
        foreach (array_keys($lines) as $lineNumber) {
            $lineReferences = $references;
            foreach ($assignmentsByLine[$lineNumber] ?? [] as [$alias, $expression]) {
                $sourceExpression = $this->lineContainsSourceReference($expression, $references);
                if (!in_array($alias, $sourceFieldHandles, true)) {
                    $references = array_values(array_diff($references, [$alias]));
                }
                if ($sourceExpression) {
                    $references[] = $alias;
                }
                $lineReferences = array_values(array_unique([...$lineReferences, ...$references]));
            }
            $referencesByLine[$lineNumber] = $lineReferences;
        }

        return $referencesByLine;
    }

    private function sourceContext(array $lines, int $lineNumber): string
    {
        $context = $lines[$lineNumber];
        for ($index = $lineNumber - 1, $minimum = max(0, $lineNumber - 20); $index >= $minimum; $index--) {
            $previous = $lines[$index];
            if (str_contains($previous, ';') || str_contains($previous, '}}') || str_contains($previous, '%}')) {
                break;
            }

            $context = $previous . $context;
            if (str_contains($previous, '{{') || str_contains($previous, '{%') || str_contains($previous, '<?php')) {
                break;
            }
        }

        return $context;
    }

    private function withoutCommentLines(array $lines, bool $php): array
    {
        $blockEnd = null;
        foreach ($lines as &$line) {
            while (true) {
                if ($blockEnd !== null) {
                    $end = strpos($line, $blockEnd);
                    if ($end === false) {
                        $line = "\n";
                        break;
                    }
                    $line = substr($line, $end + strlen($blockEnd));
                    $blockEnd = null;
                    continue;
                }

                $trimmed = ltrim($line);
                foreach ($php ? ['/*' => '*/'] : ['{#' => '#}'] as $start => $endMarker) {
                    if (!str_starts_with($trimmed, $start)) {
                        continue;
                    }

                    $startOffset = strlen($line) - strlen($trimmed);
                    $end = strpos($line, $endMarker, $startOffset + strlen($start));
                    if ($end === false) {
                        $blockEnd = $endMarker;
                        $line = "\n";
                        continue 3;
                    }
                    $line = substr($line, 0, $startOffset) . substr($line, $end + strlen($endMarker));
                    continue 2;
                }

                if ($php && (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#'))) {
                    $line = "\n";
                }
                break;
            }
        }
        unset($line);

        return $lines;
    }

    private function lineContainsSourceReference(string $line, array $sourceReferences): bool
    {
        foreach ($sourceReferences as $reference) {
            $boundary = str_starts_with((string)$reference, '$') ? '(?<![A-Za-z0-9_])' : '(?<![A-Za-z0-9_$])';
            if (is_string($reference) && $reference !== '' && preg_match('/' . $boundary . preg_quote($reference, '/') . '(?![A-Za-z0-9_])/', $line)) {
                return true;
            }
        }

        return false;
    }
}
