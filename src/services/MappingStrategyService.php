<?php

namespace luremo\linkmigrator\services;

use craft\base\Component;
use craft\fields\Link;
use luremo\linkmigrator\models\MappingDecision;

class MappingStrategyService extends Component
{
    private const TYPE_MAP = [
        'asset' => 'asset',
        'category' => 'category',
        'email' => 'email',
        'entry' => 'entry',
        'phone' => 'tel',
        'tel' => 'tel',
        'url' => 'url',
    ];

    public static function supportsLegacyTargetField(string $linkClass = Link::class): bool
    {
        return property_exists($linkClass, 'showTargetField');
    }

    public function decide(
        array $settings,
        array $linkTypes,
        bool $multi,
        array $fieldLayouts,
        string $sourceKind = 'hyper',
        array $availableSources = [],
        array $capabilities = []
    ): MappingDecision
    {
        $decision = new MappingDecision();

        $sourceLabel = $sourceKind === 'typed-link' ? 'Typed Link' : 'Hyper';

        if ($multi) {
            $decision->status = MappingDecision::STATUS_UNSUPPORTED;
            $decision->unsupportedReasons[] = "$sourceLabel field allows multiple links; Craft native Link is single-value.";
            return $decision;
        }

        if ($linkTypes === []) {
            if ($sourceKind === 'typed-link') {
                $decision->status = MappingDecision::STATUS_UNSUPPORTED;
                $decision->unsupportedReasons[] = 'Typed Link enabled link types could not be determined; refusing to prepare a broader native field.';
                return $decision;
            }

            $typesConfigured = array_key_exists('linkTypes', $settings) || array_key_exists('types', $settings);
            if ($typesConfigured && empty($settings['enableAllLinkTypes'])) {
                $decision->status = MappingDecision::STATUS_UNSUPPORTED;
                $decision->unsupportedReasons[] = "$sourceLabel field has no enabled link types.";
                return $decision;
            }

            $linkTypes = array_keys(self::TYPE_MAP);
            $decision->warnings[] = "$sourceLabel field does not list enabled link types; using Craft Link defaults.";
        }

        foreach ($linkTypes as $type) {
            $normalized = strtolower((string)$type);

            if ($sourceKind === 'typed-link' && $normalized === 'custom') {
                $decision->craftLinkTypes[] = 'url';
                continue;
            }

            if (isset(self::TYPE_MAP[$normalized])) {
                $decision->craftLinkTypes[] = self::TYPE_MAP[$normalized];
                continue;
            }

            $decision->unsupportedReasons[] = sprintf(
                'Custom or unsupported %s link type "%s" requires manual review.',
                $sourceLabel,
                $type
            );
            $decision->lossyAttributes[] = 'customTypeFallback';
        }

        $decision->craftLinkTypes = array_values(array_unique($decision->craftLinkTypes));

        if (!empty($fieldLayouts)) {
            $decision->warnings[] = 'Custom field layouts on Hyper link types are not migrated to native Link.';
            $decision->lossyAttributes[] = 'customFields';
            $decision->legacyBackupKeys[] = 'fields';
        }

        if (($settings['enableAllLinkTypes'] ?? false) === true) {
            $decision->warnings[] = 'Hyper field uses broad link-type allowances; verify the generated Link field settings.';
        }

        if ($sourceKind === 'typed-link') {
            $this->mapTypedLinkSettings($decision, $settings, $availableSources, $capabilities);
        } else {
            $decision->showLabelField = true;
            $decision->advancedFields = ['label', 'target', 'urlSuffix', 'title', 'class', 'id', 'rel'];
        }

        if ($decision->unsupportedReasons !== []) {
            $decision->status = $sourceKind === 'typed-link' || $decision->craftLinkTypes === []
                ? MappingDecision::STATUS_UNSUPPORTED
                : MappingDecision::STATUS_PARTIAL;
        } elseif ($decision->warnings !== []) {
            $decision->status = MappingDecision::STATUS_PARTIAL;
        } else {
            $decision->status = MappingDecision::STATUS_SUPPORTED;
        }

        return $decision;
    }

    private function mapTypedLinkSettings(
        MappingDecision $decision,
        array $settings,
        array $availableSources,
        array $capabilities
    ): void {
        $showLabelField = ($settings['allowCustomText'] ?? true) || trim((string)($settings['defaultText'] ?? '')) !== '';
        if ($showLabelField && ($capabilities['showLabelField'] ?? true)) {
            $decision->showLabelField = true;
        } elseif ($showLabelField) {
            $decision->warnings[] = 'Typed Link custom text is preserved in optional backups; this Craft version cannot show native Link labels.';
            $decision->lossyAttributes[] = 'customText';
        }

        $advancedSupported = $capabilities['advancedFields'] ?? true;
        $allTypeSettings = (array)($settings['typeSettings'] ?? []);
        $customSettings = (array)($allTypeSettings['custom'] ?? []);
        $customEnabled = array_key_exists('enabled', $customSettings)
            ? $customSettings['enabled'] === true
            : ($settings['enableAllLinkTypes'] ?? false) === true;
        if (
            $customEnabled &&
            !empty($customSettings['disableValidation']) &&
            in_array('url', $decision->craftLinkTypes, true)
        ) {
            $urlSettings = [];
            foreach (['allowRootRelativeUrls', 'allowAnchors', 'allowCustomSchemes'] as $setting) {
                if ($capabilities[$setting] ?? false) {
                    $urlSettings[$setting] = true;
                }
            }
            if ($urlSettings !== []) {
                $decision->typeSettings['url'] = $urlSettings;
            }
        }

        if ($settings['allowTarget'] ?? false) {
            if ($advancedSupported) {
                $decision->advancedFields[] = 'target';
            } elseif ($capabilities['targetField'] ?? false) {
                $decision->showTargetField = true;
            } else {
                $decision->warnings[] = 'Typed Link target values are preserved in optional backups; this Craft version cannot store them.';
                $decision->lossyAttributes[] = 'target';
            }
        }

        if (($settings['allowTarget'] ?? false) && !empty($settings['autoNoReferrer'])) {
            if ($advancedSupported) {
                $decision->advancedFields[] = 'rel';
            } else {
                $decision->warnings[] = 'Typed Link automatic noreferrer values are preserved in optional backups; this Craft version cannot store them.';
                $decision->lossyAttributes[] = 'autoNoReferrer';
            }
        }

        foreach (['customTextRequired', 'customTextMaxLength'] as $setting) {
            if (empty($settings[$setting])) {
                continue;
            }

            $decision->warnings[] = sprintf('Typed Link %s has no equivalent native Link field setting.', $setting);
            $decision->lossyAttributes[] = $setting;
        }

        foreach ([
            'enableTitle' => 'title',
            'enableAriaLabel' => 'ariaLabel',
        ] as $setting => $nativeField) {
            if (!($settings[$setting] ?? true)) {
                continue;
            }

            if ($advancedSupported) {
                $decision->advancedFields[] = $nativeField;
            } else {
                $decision->warnings[] = sprintf('Typed Link %s is preserved in optional backups; this Craft version cannot store it.', $nativeField);
                $decision->lossyAttributes[] = $nativeField;
            }
        }

        foreach ($decision->craftLinkTypes as $type) {
            $typedType = $type === 'tel' ? 'tel' : $type;
            $typeSettings = (array)($allTypeSettings[$typedType] ?? []);
            if (!empty($typeSettings['allowCustomQuery'])) {
                if ($advancedSupported) {
                    $decision->advancedFields[] = 'urlSuffix';
                } else {
                    $decision->warnings[] = 'Typed Link custom query suffixes are preserved in optional backups; this Craft version cannot store them.';
                    $decision->lossyAttributes[] = 'customQuery';
                }
            }

            if (!in_array($type, ['asset', 'category', 'entry'], true) || !array_key_exists('sources', $typeSettings)) {
                continue;
            }

            $sources = $this->normalizeSources($typeSettings['sources']);
            if ($sources === '*') {
                $decision->typeSettings[$type] = ['sources' => '*'];
                continue;
            }

            $validSources = array_values(array_intersect($sources, $availableSources[$type] ?? []));
            if ($validSources === []) {
                $decision->unsupportedReasons[] = sprintf(
                    'Typed Link %s source restrictions no longer exist in this Craft installation; refusing to broaden access.',
                    $type
                );
                continue;
            }

            if (count($validSources) !== count($sources)) {
                $decision->warnings[] = sprintf(
                    'Typed Link %s source restrictions were narrowed to sources that still exist in this Craft installation.',
                    $type
                );
            }

            $decision->typeSettings[$type] = ['sources' => $validSources];
        }

        $decision->advancedFields = array_values(array_unique($decision->advancedFields));
        $decision->lossyAttributes = array_values(array_unique($decision->lossyAttributes));

        if ($decision->unsupportedReasons !== []) {
            $decision->status = MappingDecision::STATUS_UNSUPPORTED;
        }
    }

    private function normalizeSources(mixed $sources): array|string
    {
        if ($sources === '*') {
            return '*';
        }

        if (!is_array($sources)) {
            $source = trim((string)$sources);
            return $source === '' ? [] : [$source];
        }

        $sources = array_values(array_unique(array_map(
            'trim',
            array_filter($sources, static fn($source) => is_string($source)),
        )));
        $sources = array_values(array_filter($sources, static fn(string $source) => $source !== ''));
        return in_array('*', $sources, true) ? '*' : $sources;
    }
}
