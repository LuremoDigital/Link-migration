<?php

namespace lm2k\hypertolink\services;

use craft\base\Component;
use lm2k\hypertolink\models\MappingDecision;

class MappingStrategyService extends Component
{
    private const TYPE_MAP = [
        'asset' => 'asset',
        'category' => 'category',
        'email' => 'email',
        'entry' => 'entry',
        'phone' => 'tel',
        'sms' => 'sms',
        'url' => 'url',
    ];

    public function decide(array $settings, array $linkTypes, bool $multi, array $fieldLayouts): MappingDecision
    {
        $decision = new MappingDecision();

        if ($multi) {
            $decision->status = MappingDecision::STATUS_UNSUPPORTED;
            $decision->unsupportedReasons[] = 'Hyper field allows multiple links; Craft native Link is single-value.';
            return $decision;
        }

        foreach ($linkTypes as $type) {
            $normalized = strtolower((string)$type);

            if (isset(self::TYPE_MAP[$normalized])) {
                $decision->craftLinkTypes[] = self::TYPE_MAP[$normalized];
                continue;
            }

            $decision->craftLinkTypes[] = 'url';
            $decision->warnings[] = sprintf(
                'Custom or unsupported Hyper link type "%s" will be migrated as a native URL link.',
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

        $advancedFields = [
            'target',
            'urlSuffix',
            'title',
            'class',
            'id',
            'rel',
        ];

        $decision->advancedFields[] = 'label';

        $decision->advancedFields = array_merge($decision->advancedFields, $advancedFields);

        if ($decision->unsupportedReasons !== []) {
            $decision->status = $decision->craftLinkTypes === []
                ? MappingDecision::STATUS_UNSUPPORTED
                : MappingDecision::STATUS_PARTIAL;
        } elseif ($decision->warnings !== []) {
            $decision->status = MappingDecision::STATUS_PARTIAL;
        } else {
            $decision->status = MappingDecision::STATUS_SUPPORTED;
        }

        return $decision;
    }
}
