<?php

namespace luremo\linkmigrator\services;

use Craft;
use craft\base\Component;
use craft\fieldlayoutelements\CustomField;
use luremo\linkmigrator\LinkMigrator;
use luremo\linkmigrator\models\AuditResult;
use luremo\linkmigrator\models\CutoverResult;
use luremo\linkmigrator\models\FieldMapping;
use luremo\linkmigrator\models\MappingDecision;

class CutoverService extends Component
{
    public function finalize(AuditResult $audit, array $options): CutoverResult
    {
        $result = new CutoverResult();
        $fieldsService = Craft::$app->getFields();

        foreach ($audit->fields as $fieldAudit) {
            if ($fieldAudit->mapping->status === MappingDecision::STATUS_UNSUPPORTED) {
                $result->skipped[] = [
                    'field' => $fieldAudit->handle,
                    'reason' => 'Unsupported fields cannot be finalized.',
                ];
                continue;
            }

            try {
                $mapping = LinkMigrator::$plugin->getState()->getFieldMapping($fieldAudit->uid);
                if (!$mapping instanceof FieldMapping || !$mapping->targetHandle) {
                    throw new \RuntimeException('Field has not been prepared.');
                }

                if ($mapping->phase === FieldMapping::PHASE_FINALIZED) {
                    $result->finalized[] = [
                        'field' => $fieldAudit->handle,
                        'target' => $mapping->targetHandle,
                        'mode' => 'already-finalized',
                    ];
                    continue;
                }

                if (!$mapping->isContentReady()) {
                    throw new \RuntimeException('Content migration has not completed for this field.');
                }

                $targetField = $this->findFieldByHandle($mapping->targetHandle);
                $sourceField = $fieldsService->getFieldById($fieldAudit->fieldId);
                if (!$sourceField || !$targetField) {
                    throw new \RuntimeException('Source or target field could not be loaded.');
                }

                $reconciliation = LinkMigrator::$plugin->getContentMigration()->reconcileField($fieldAudit, $mapping);
                if ($reconciliation['unverified'] !== []) {
                    if ($reconciliation['checked'] === 0) {
                        throw new \RuntimeException(
                            $reconciliation['unverified'][0]['warnings'][0]
                                ?? 'No source values were checked; field is not ready to finalize.'
                        );
                    }

                    throw new \RuntimeException(sprintf(
                        '%d of %d source value(s) are not verified.',
                        count($reconciliation['unverified']),
                        $reconciliation['checked']
                    ));
                }

                if (!empty($options['dryRun'])) {
                    $result->finalized[] = [
                        'field' => $fieldAudit->handle,
                        'target' => $mapping->targetHandle,
                        'mode' => 'dry-run',
                    ];
                    continue;
                }

                $this->replaceFieldInLayouts($sourceField, $targetField);
                LinkMigrator::$plugin->getState()->markFinalized($fieldAudit->uid);

                $result->finalized[] = [
                    'field' => $fieldAudit->handle,
                    'target' => $mapping->targetHandle,
                    'mode' => 'write',
                ];
            } catch (\Throwable $e) {
                $result->errors[] = [
                    'field' => $fieldAudit->handle,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    private function replaceFieldInLayouts(object $sourceField, object $targetField): void
    {
        $fieldsService = Craft::$app->getFields();

        foreach ($fieldsService->findFieldUsages($sourceField) as $layout) {
            if (!method_exists($layout, 'getTabs')) {
                continue;
            }

            foreach ($layout->getTabs() as $tab) {
                $elements = $tab->getElements();
                $updated = [];
                $changed = false;
                $targetPresent = false;

                foreach ($elements as $element) {
                    if ($element instanceof CustomField && $element->getFieldUid() === $targetField->uid) {
                        $targetPresent = true;
                    }
                }

                foreach ($elements as $element) {
                    if (!$element instanceof CustomField || $element->getFieldUid() !== $sourceField->uid) {
                        $updated[] = $element;
                        continue;
                    }

                    if (!$targetPresent) {
                        $updated[] = new CustomField($targetField, [
                            'label' => $element->label,
                            'instructions' => $element->instructions,
                            'required' => $element->required,
                        ]);
                        $targetPresent = true;
                    }

                    $changed = true;
                }

                if ($changed) {
                    $tab->setElements($updated);
                }
            }

            $fieldsService->saveLayout($layout);
        }
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
}
