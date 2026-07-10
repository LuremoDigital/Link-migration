<?php

namespace luremo\linkmigrator\services;

use Craft;
use craft\base\Component;
use craft\fields\Link;
use craft\fieldlayoutelements\CustomField;
use luremo\linkmigrator\LinkMigrator;
use luremo\linkmigrator\models\AuditResult;
use luremo\linkmigrator\models\FieldMigrationResult;
use luremo\linkmigrator\models\MappingDecision;

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

                $existingMapping = LinkMigrator::$plugin->getState()->getFieldMapping($fieldAudit->uid);
                if ($existingMapping?->targetHandle) {
                    $mappedField = $fieldsService->getFieldByHandle($existingMapping->targetHandle);
                    if ($mappedField instanceof Link) {
                        $dryRun = !empty($options['dryRun']);
                        if (!$dryRun) {
                            // Re-attach idempotently so layouts that started using the
                            // source field after the original prepare (or lost the target
                            // element to a manual edit) are repaired by re-running prepare.
                            $this->attachPreparedFieldToLayouts($existing, $mappedField);
                        }

                        $result->skipped[] = [
                            'field' => $fieldAudit->handle,
                            'target' => $existingMapping->targetHandle,
                            'reason' => $dryRun
                                ? 'Native field already prepared.'
                                : 'Native field already prepared. Layout placement re-checked.',
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
                $mapping = LinkMigrator::$plugin->getState()->savePreparedFieldMapping([
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

    public function adoptPrepared(AuditResult $audit, array $options): FieldMigrationResult
    {
        $result = new FieldMigrationResult();
        $explicitTarget = isset($options['target']) && $options['target'] !== '' ? (string)$options['target'] : null;
        $dryRun = !empty($options['dryRun']);

        if ($explicitTarget !== null && count($audit->fields) > 1) {
            throw new \InvalidArgumentException('An explicit target handle can only be adopted for a single source field.');
        }

        foreach ($audit->fields as $fieldAudit) {
            if ($fieldAudit->mapping->status === MappingDecision::STATUS_UNSUPPORTED) {
                $result->skipped[] = [
                    'field' => $fieldAudit->handle,
                    'reason' => $fieldAudit->mapping->unsupportedReasons,
                ];
                continue;
            }

            try {
                $existingMapping = $this->state()->getFieldMapping($fieldAudit->uid);
                $existingTarget = $existingMapping?->targetHandle
                    ? $this->findAdoptableTarget($existingMapping->targetHandle)
                    : null;
                if ($existingTarget !== null) {
                    $this->warnOnUnsupportedTypes($result, $fieldAudit, $existingTarget);
                    $result->skipped[] = [
                        'field' => $fieldAudit->handle,
                        'target' => $existingMapping->targetHandle,
                        'reason' => 'Mapping already recorded in this environment.',
                    ];
                    $result->mappings[] = $existingMapping->toArray();
                    continue;
                }

                if ($explicitTarget !== null) {
                    $target = $this->findAdoptableTarget($explicitTarget);
                    if ($target === null) {
                        throw new \RuntimeException(sprintf(
                            'Target `%s` is not an existing native Link field in this environment.',
                            $explicitTarget
                        ));
                    }
                } else {
                    $candidates = $this->findConventionCandidates($fieldAudit->handle . 'Native');
                    if (count($candidates) > 1) {
                        throw new \RuntimeException(sprintf(
                            'Multiple candidate native Link fields found (%s). Re-run with --field=%s and --target.',
                            implode(', ', array_column($candidates, 'handle')),
                            $fieldAudit->handle
                        ));
                    }

                    $target = $candidates[0] ?? null;
                    if ($target === null) {
                        $result->skipped[] = [
                            'field' => $fieldAudit->handle,
                            'reason' => sprintf(
                                'No native Link field found at `%sNative`. Run prepare-fields and deploy its project config first, or pass --target.',
                                $fieldAudit->handle
                            ),
                        ];
                        continue;
                    }
                }

                $this->warnOnUnsupportedTypes($result, $fieldAudit, $target);

                if ($dryRun) {
                    $result->migrated[] = [
                        'field' => $fieldAudit->handle,
                        'target' => $target['handle'],
                        'uid' => $fieldAudit->uid,
                        'mode' => 'dry-run',
                    ];
                    continue;
                }

                $mapping = $this->state()->savePreparedFieldMapping([
                    'sourceFieldId' => $fieldAudit->fieldId,
                    'sourceFieldUid' => $fieldAudit->uid,
                    'sourceHandle' => $fieldAudit->handle,
                    'targetFieldId' => $target['id'],
                    'targetFieldUid' => $target['uid'],
                    'targetHandle' => $target['handle'],
                ]);

                $result->migrated[] = [
                    'field' => $fieldAudit->handle,
                    'target' => $target['handle'],
                    'uid' => $fieldAudit->uid,
                    'mode' => 'adopt',
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

    /**
     * @param array{id: int, uid: string, handle: string, types: string[]} $target
     */
    private function warnOnUnsupportedTypes(FieldMigrationResult $result, object $fieldAudit, array $target): void
    {
        $unsupportedTypes = array_diff($fieldAudit->mapping->craftLinkTypes, $target['types']);
        if ($unsupportedTypes === []) {
            return;
        }

        $result->warnings[] = [
            'field' => $fieldAudit->handle,
            'target' => $target['handle'],
            'warnings' => [sprintf(
                'Target `%s` does not allow link type(s) the mapping needs: %s. Verify this is the prepared field.',
                $target['handle'],
                implode(', ', $unsupportedTypes)
            )],
        ];
    }

    protected function state(): StateService
    {
        return LinkMigrator::$plugin->getState();
    }

    /**
     * Collects adoptable Link fields along the same numbered-handle sequence that
     * nextAvailableHandle() walks during prepare, so a `ctaLinkNative2` created
     * because `ctaLinkNative` was already taken is found — and the ambiguity is
     * surfaced instead of silently adopting the wrong field.
     *
     * @return array<array{id: int, uid: string, handle: string, types: string[]}>
     */
    private function findConventionCandidates(string $baseHandle): array
    {
        $candidates = [];
        $candidate = $baseHandle;
        $suffix = 2;

        while ($this->fieldHandleExists($candidate)) {
            $target = $this->findAdoptableTarget($candidate);
            if ($target !== null) {
                $candidates[] = $target;
            }

            $candidate = $baseHandle . $suffix;
            $suffix++;
        }

        return $candidates;
    }

    protected function fieldHandleExists(string $handle): bool
    {
        return Craft::$app->getFields()->getFieldByHandle($handle) !== null;
    }

    /**
     * @return array{id: int, uid: string, handle: string, types: string[]}|null
     */
    protected function findAdoptableTarget(string $handle): ?array
    {
        $field = Craft::$app->getFields()->getFieldByHandle($handle);
        if (!$field instanceof Link || empty($field->id)) {
            return null;
        }

        return [
            'id' => (int)$field->id,
            'uid' => (string)$field->uid,
            'handle' => (string)$field->handle,
            'types' => (array)$field->types,
        ];
    }

    private function buildLinkFieldConfig(object $existingField, MappingDecision $mapping, string $targetHandle): Link
    {
        if ($mapping->craftLinkTypes === []) {
            throw new \RuntimeException('Cannot create a native Link field without allowed link types.');
        }

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
