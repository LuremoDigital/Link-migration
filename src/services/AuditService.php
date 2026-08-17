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
            'replacement' => '.label',
            'reason' => 'Hyper commonly exposes link text via `.text`; Craft LinkData uses `.label`.',
        ],
        [
            'pattern' => '.linkText',
            'replacement' => '.label',
            'reason' => 'Hyper `linkText` should usually become Craft LinkData `.label`.',
        ],
        [
            'pattern' => 'linkValue',
            'replacement' => 'value or url',
            'reason' => 'Hyper `linkValue` maps to `value` for the raw stored value or `url` for rendered href output.',
        ],
        [
            'pattern' => 'customText',
            'replacement' => 'label',
            'reason' => 'Typed Link `customText` maps to Craft LinkData `label`.',
        ],
        [
            'pattern' => 'linkedUrl',
            'replacement' => 'url',
            'reason' => 'Typed Link `linkedUrl` maps to Craft LinkData `url`.',
        ],
        [
            'pattern' => 'getLinkAttributes(',
            'replacement' => 'manual LinkData attribute mapping',
            'reason' => 'Typed Link `getLinkAttributes()` is not portable to every supported Craft LinkData version.',
        ],
        [
            'pattern' => 'getLink(',
            'replacement' => 'link.url plus manual <a> rendering',
            'reason' => 'Hyper `getLink()` returns rendered markup; Craft LinkData should be rendered explicitly in Twig.',
        ],
        [
            'pattern' => 'getHtml(',
            'replacement' => 'manual rendering',
            'reason' => 'Hyper embed/html helpers do not exist on Craft LinkData.',
        ],
        [
            'pattern' => 'getData(',
            'replacement' => 'manual mapping or backup-only data',
            'reason' => 'Hyper embed/provider payload helpers do not exist on Craft LinkData.',
        ],
        [
            'pattern' => 'getElement(',
            'replacement' => '.element',
            'reason' => 'Craft LinkData exposes relational targets through `.element` instead of `getElement()`.',
        ],
        [
            'pattern' => 'hasElement(',
            'replacement' => 'if link.element',
            'reason' => 'Craft LinkData does not provide `hasElement()`; check `.element` directly.',
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
        $result->mismatchReferences = $this->findMismatchReferences();
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

    public function findMismatchReferences(): array
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

                foreach ($contents as $lineNumber => $line) {
                    foreach (self::MISMATCH_PATTERNS as $mismatch) {
                        if (!str_contains($line, $mismatch['pattern'])) {
                            continue;
                        }

                        $matches[] = [
                            'file' => $fileInfo->getPathname(),
                            'line' => $lineNumber + 1,
                            'pattern' => $mismatch['pattern'],
                            'replacement' => $mismatch['replacement'],
                            'reason' => $mismatch['reason'],
                            'snippet' => trim($line),
                        ];
                    }
                }
            }
        }

        return $matches;
    }
}
