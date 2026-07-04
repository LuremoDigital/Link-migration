<?php

namespace lm2k\hypertolink\services;

use Craft;
use craft\base\Component;
use craft\fields\Link;
use craft\fieldlayoutelements\CustomField;
use lm2k\hypertolink\HyperToLink;
use lm2k\hypertolink\models\AuditResult;
use lm2k\hypertolink\models\FieldMigrationResult;
use lm2k\hypertolink\models\MappingDecision;

class FieldMigrationService extends Component
{
    public function migrate(AuditResult $audit, array $options): FieldMigrationResult
    {
        $result = new FieldMigrationResult();
        $fieldsService = Craft::$app->getFields();

        foreach ($audit->fields as $fieldAudit) {
            if ($fieldAudit->mapping->status === MappingDecision::STATUS_UNSUPPORTED) {
                $result->skipped[] = [
                    'field' => $fieldAudit->handle,
                    'reason' => $fieldAudit->mapping->unsupportedReasons,
                ];
                continue;
            }

            try {
                $existing = $fieldsService->getFieldById($fieldAudit->fieldId);
                if (!$existing) {
                    $result->errors[] = [
                        'field' => $fieldAudit->handle,
                        'reason' => 'Field no longer exists.',
                    ];
                    continue;
                }

                $existingMapping = HyperToLink::$plugin->getState()->getFieldMapping($fieldAudit->uid);
                if ($existingMapping?->targetHandle) {
                    $mappedField = $fieldsService->getFieldByHandle($existingMapping->targetHandle);
                    if ($mappedField instanceof Link) {
                        $result->skipped[] = [
                            'field' => $fieldAudit->handle,
                            'target' => $existingMapping->targetHandle,
                            'reason' => 'Native field already prepared.',
                        ];
                        $result->mappings[] = $existingMapping->toArray();
                        continue;
                    }
                }

                $targetHandle = $this->nextAvailableHandle($existing->handle . 'Native');
                $linkField = $this->buildLinkFieldConfig($existing, $fieldAudit->mapping, $targetHandle);

                if (!empty($options['dryRun'])) {
                    $result->migrated[] = [
                        'field' => $fieldAudit->handle,
                        'target' => $targetHandle,
                        'uid' => $fieldAudit->uid,
                        'mode' => 'dry-run',
                        'config' => $linkField->toArray(),
                    ];
                    continue;
                }

                $saved = $fieldsService->saveField($linkField, false);
                if (!$saved) {
                    throw new \RuntimeException('saveField() returned false.');
                }

                $persistedTargetField = $fieldsService->getFieldByHandle($targetHandle);
                if (!$persistedTargetField instanceof Link || empty($persistedTargetField->id)) {
                    throw new \RuntimeException(sprintf(
                        'Prepared field `%s` was not persisted correctly.',
                        $targetHandle
                    ));
                }

                $this->attachPreparedFieldToLayouts($existing, $persistedTargetField);
                $mapping = HyperToLink::$plugin->getState()->savePreparedFieldMapping([
                    'sourceFieldId' => (int)$existing->id,
                    'sourceFieldUid' => (string)$existing->uid,
                    'sourceHandle' => (string)$existing->handle,
                    'targetFieldId' => (int)$persistedTargetField->id,
                    'targetFieldUid' => (string)$persistedTargetField->uid,
                    'targetHandle' => (string)$persistedTargetField->handle,
                ]);

                $result->migrated[] = [
                    'field' => $fieldAudit->handle,
                    'target' => $persistedTargetField->handle,
                    'uid' => $fieldAudit->uid,
                    'mode' => 'write',
                    'status' => $fieldAudit->mapping->status,
                ];
                $result->mappings[] = $mapping->toArray();
            } catch (\Throwable $e) {
                $result->errors[] = [
                    'field' => $fieldAudit->handle,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    private function buildLinkFieldConfig(object $existingField, MappingDecision $mapping, string $targetHandle): Link
    {
        $config = [
            'name' => $existingField->name,
            'handle' => $targetHandle,
            'types' => $mapping->craftLinkTypes,
            'showLabelField' => true,
        ];

        foreach (['instructions', 'translationMethod', 'translationKeyFormat', 'searchable', 'required', 'tip', 'warning', 'groupId'] as $property) {
            try {
                $config[$property] = $existingField->{$property};
            } catch (\Throwable) {
                // Hyper/Craft field models do not expose all historical field properties on Craft 5.
            }
        }

        if ($this->supportsAdvancedFields()) {
            $config['advancedFields'] = array_values(array_filter(
                $mapping->advancedFields,
                static fn(string $field) => $field !== 'label'
            ));
        }

        /** @var Link $field */
        $field = Craft::createObject(array_merge(['class' => Link::class], $config));
        return $field;
    }

    private function supportsAdvancedFields(): bool
    {
        return property_exists(Link::class, 'advancedFields');
    }

    private function nextAvailableHandle(string $baseHandle): string
    {
        $candidate = $baseHandle;
        $suffix = 2;

        while (Craft::$app->getFields()->getFieldByHandle($candidate) !== null) {
            $candidate = $baseHandle . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function attachPreparedFieldToLayouts(object $sourceField, Link $targetField): void
    {
        $fieldsService = Craft::$app->getFields();

        foreach ($fieldsService->findFieldUsages($sourceField) as $layout) {
            if (!method_exists($layout, 'getTabs') || !method_exists($layout, 'getCustomFieldElements')) {
                continue;
            }

            $hasTarget = false;
            foreach ($layout->getCustomFieldElements() as $layoutElement) {
                if ($layoutElement instanceof CustomField && $layoutElement->getFieldUid() === $targetField->uid) {
                    $hasTarget = true;
                    break;
                }
            }

            if ($hasTarget) {
                continue;
            }

            foreach ($layout->getTabs() as $tab) {
                $elements = $tab->getElements();
                $updated = [];
                $changed = false;

                foreach ($elements as $element) {
                    $updated[] = $element;

                    if (!$element instanceof CustomField || $element->getFieldUid() !== $sourceField->uid) {
                        continue;
                    }

                    $updated[] = new CustomField($targetField, [
                        'label' => $element->label,
                        'instructions' => $element->instructions,
                        'required' => $element->required,
                    ]);
                    $changed = true;
                }

                if ($changed) {
                    $tab->setElements($updated);
                }
            }

            $fieldsService->saveLayout($layout);
        }
    }
}
