<?php

namespace lm2k\hypertolink\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\db\ElementQuery;
use lm2k\hypertolink\HyperToLink;
use lm2k\hypertolink\models\AuditResult;
use lm2k\hypertolink\models\ContentMigrationResult;
use lm2k\hypertolink\models\FieldAudit;
use lm2k\hypertolink\models\FieldMapping;
use lm2k\hypertolink\models\MappingDecision;

class ContentMigrationService extends Component
{
    private const NATIVE_LINK_TYPES = ['asset', 'category', 'email', 'entry', 'phone', 'sms', 'tel', 'url'];
    private array $fieldContextCache = [];
    private array $rawContentCache = [];
    private array $migratedStateCache = [];

    public function migrate(AuditResult $audit, array $options): ContentMigrationResult
    {
        $result = new ContentMigrationResult();
        $elements = Craft::$app->getElements();
        $batchSize = (int)($options['batchSize'] ?? 100);

        foreach ($audit->fields as $fieldAudit) {
            if ($fieldAudit->mapping->status === MappingDecision::STATUS_UNSUPPORTED) {
                $result->addSkipped([
                    'field' => $fieldAudit->handle,
                    'reason' => $fieldAudit->mapping->unsupportedReasons,
                ]);
                continue;
            }

            $fieldMapping = HyperToLink::$plugin->getState()->getFieldMapping($fieldAudit->uid);
            if (!$fieldMapping?->targetHandle) {
                $result->recordError([
                    'field' => $fieldAudit->handle,
                    'reason' => 'Field has not been prepared yet. Run prepare-fields first.',
                ]);
                continue;
            }

            if (!$fieldMapping->targetFieldId || Craft::$app->getFields()->getFieldByHandle($fieldMapping->targetHandle) === null) {
                $result->recordError([
                    'field' => $fieldAudit->handle,
                    'reason' => sprintf(
                        'Prepared target field `%s` is missing. Re-run prepare-fields.',
                        $fieldMapping->targetHandle
                    ),
                ]);
                continue;
            }

            // Once a field is finalized the source field has been cut out of its layouts, so
            // content migration can no longer locate source values and would otherwise report a
            // misleading success with zero migrated units (finding 5). Only block when the source
            // field is genuinely absent from every layout (no audit containers). If the operator
            // has re-added it to recover, `containers` is non-empty and migration proceeds, so the
            // recovery instruction below is actually actionable.
            if ($fieldMapping->phase === FieldMapping::PHASE_FINALIZED && $fieldAudit->containers === []) {
                $result->recordError([
                    'field' => $fieldAudit->handle,
                    'reason' => sprintf(
                        'Field `%s` has been finalized and its source field is no longer in any layout. '
                        . 'Re-add the source Hyper field to the affected layout(s), then re-run content migration to recover.',
                        $fieldAudit->handle
                    ),
                ]);
                continue;
            }

            $layoutIds = $this->extractLayoutIds($fieldAudit->containers);
            $fieldHadErrors = false;
            foreach ($this->buildElementQueries($fieldAudit->containers) as $query) {
                foreach ($query->batch($batchSize) as $batch) {
                    $this->primeBatchCaches($fieldAudit, $batch);

                    foreach ($batch as $element) {
                        $fieldContext = $this->resolveFieldContext($element, $fieldAudit->fieldId);
                        $runtimeFieldHandle = $fieldContext['runtimeHandle'] ?? null;
                        if ($runtimeFieldHandle === null || !$this->elementSupportsField($element, $runtimeFieldHandle, $layoutIds)) {
                            continue;
                        }

                        try {
                            // Trust the "migrated" record only while the stored native value still
                            // reflects the current source link type. If the source link type drifted
                            // since migration (or no longer converts), fall through and re-migrate
                            // instead of skipping, so a stale target cannot survive to finalize
                            // (finding 4). Same-type value edits remain out of scope (documented).
                            if ($this->isMigratedInBatch($element)
                                && $this->migratedTargetIsCurrent($element, $fieldAudit, $fieldContext, $fieldMapping->targetHandle)
                            ) {
                                $this->recordSkipped($result, $options, $fieldAudit, $element, 'Already migrated.');
                                continue;
                            }

                            if (!$this->elementSupportsField($element, $fieldMapping->targetHandle, $layoutIds)) {
                                throw new \RuntimeException(sprintf(
                                    'Prepared target field `%s` is missing from the element field layout.',
                                    $fieldMapping->targetHandle
                                ));
                            }

                            $value = $this->getStoredFieldValue($element, $fieldAudit, $fieldContext);
                            if ($this->isNativeLinkValue($value)) {
                                $this->recordSkipped($result, $options, $fieldAudit, $element, 'Already a native Link value.');
                                continue;
                            }

                            if ($this->isEmptyHyperValue($value)) {
                                $this->recordSkipped($result, $options, $fieldAudit, $element, 'Empty value.');
                                continue;
                            }

                            $conversion = $this->convertHyperValue($value, $element->siteId);
                            if ($conversion['status'] === 'unsupported') {
                                $this->recordWarning($result, $options, $fieldAudit, $element, $conversion['warnings'], $conversion['backup']);
                                continue;
                            }

                            $backupPath = null;
                            if (empty($options['dryRun']) && !empty($options['createBackup'])) {
                                $backupPath = HyperToLink::$plugin->getState()->writeBackup('content', $fieldAudit->handle, $element, $conversion['backup']);
                                $result->addBackup($backupPath);
                            }

                            if (!empty($options['dryRun'])) {
                                $result->addMigrated([
                                    'field' => $fieldAudit->handle,
                                    'elementId' => $element->id,
                                    'siteId' => $element->siteId,
                                    'mode' => 'dry-run',
                                    'payload' => $conversion['summary'],
                                    'backupPath' => $backupPath,
                                ]);
                                continue;
                            }

                            $element->setFieldValue($fieldMapping->targetHandle, $conversion['payload']);
                            if (!$elements->saveElement($element, false, false, false)) {
                                throw new \RuntimeException('saveElement() returned false.');
                            }

                            HyperToLink::$plugin->getState()->markMigrated(
                                'content',
                                $fieldAudit->handle,
                                $fieldAudit->uid,
                                $element,
                                $conversion['warnings'],
                                $conversion['backup'],
                                $backupPath
                            );
                            $result->addMigrated([
                                'field' => $fieldAudit->handle,
                                'elementId' => $element->id,
                                'siteId' => $element->siteId,
                                'warnings' => $conversion['warnings'],
                                'backupPath' => $backupPath,
                            ]);
                        } catch (\Throwable $e) {
                            $fieldHadErrors = true;
                            if (empty($options['dryRun']) && $element instanceof ElementInterface) {
                                HyperToLink::$plugin->getState()->markError('content', $fieldAudit->handle, $fieldAudit->uid, $element, $e->getMessage());
                            }
                            $result->recordError([
                                'field' => $fieldAudit->handle,
                                'elementId' => $element->id ?? null,
                                'reason' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            if (empty($options['dryRun'])) {
                // Do not advance a field to `readyToFinalize` merely because no exception was
                // thrown. Re-inventory the field and only mark it ready when every non-empty
                // source value has a verified native value (finding 4). Warning-only, unsupported,
                // and otherwise unmigrated units leave the field at `contentMigrated`, so finalize
                // (which recomputes the same reconciliation) refuses cutover.
                $reconciliation = $this->reconcileField($fieldAudit, $fieldMapping->targetHandle);
                $readyToFinalize = !$fieldHadErrors && $reconciliation['unverified'] === [];
                HyperToLink::$plugin->getState()->markContentMigrated($fieldAudit->uid, $readyToFinalize);
            }
        }

        return $result;
    }

    /**
     * Re-inventories every source unit for a field and verifies that a populated native
     * Link value exists on the prepared target field for each non-empty source value.
     *
     * This performs a fresh read of stored source and target content and never trusts
     * persisted workflow phase. Used to gate finalize (finding 3).
     *
     * @return array{total:int, verified:int, empty:int, unverified:list<array{elementId:?int, siteId:?int, reason:string}>}
     */
    public function reconcileField(FieldAudit $fieldAudit, string $targetHandle): array
    {
        $layoutIds = $this->extractLayoutIds($fieldAudit->containers);
        $verified = 0;
        $empty = 0;
        $unverified = [];

        foreach ($this->buildElementQueries($fieldAudit->containers) as $query) {
            foreach ($query->batch(100) as $batch) {
                $this->primeBatchCaches($fieldAudit, $batch);

                foreach ($batch as $element) {
                    $fieldContext = $this->resolveFieldContext($element, $fieldAudit->fieldId);
                    $runtimeFieldHandle = $fieldContext['runtimeHandle'] ?? null;
                    if ($runtimeFieldHandle === null || !$this->elementSupportsField($element, $runtimeFieldHandle, $layoutIds)) {
                        continue;
                    }

                    $sourceValue = $this->getStoredFieldValue($element, $fieldAudit, $fieldContext);

                    // Empty, or already a native Link value on the source handle: nothing to verify.
                    if ($this->isEmptyHyperValue($sourceValue) || $this->isNativeLinkValue($sourceValue)) {
                        $empty++;
                        continue;
                    }

                    // Non-empty source unit: the prepared target field must hold a populated native value.
                    if (!$this->elementSupportsField($element, $targetHandle, $layoutIds)) {
                        $unverified[] = [
                            'elementId' => $element->id ?? null,
                            'siteId' => $element->siteId ?? null,
                            'reason' => sprintf('Prepared target field `%s` is missing from the element field layout.', $targetHandle),
                        ];
                        continue;
                    }

                    if (!$this->isPopulatedNativeLink($this->readNativeTargetValue($element, $targetHandle))) {
                        $unverified[] = [
                            'elementId' => $element->id ?? null,
                            'siteId' => $element->siteId ?? null,
                            'reason' => 'Source value is not empty but the native target field has no migrated value.',
                        ];
                        continue;
                    }

                    // Re-derive what the source should convert to now and confirm the stored native
                    // link type still matches. Catches a source that drifted to an unsupported type
                    // or a different link type since it was migrated (finding 4). Type is an enum,
                    // not subject to value-formatting normalization, so this never false-blocks an
                    // unchanged unit. Same-type value edits are not detected (documented limitation).
                    $expectedType = $this->expectedNativeType($sourceValue, $element->siteId);
                    $actualType = $this->readNativeTargetType($element, $targetHandle);
                    if ($expectedType === null) {
                        $unverified[] = [
                            'elementId' => $element->id ?? null,
                            'siteId' => $element->siteId ?? null,
                            'reason' => 'Source value no longer converts to a supported native link type.',
                        ];
                    } elseif ($actualType !== null && $actualType !== $expectedType) {
                        $unverified[] = [
                            'elementId' => $element->id ?? null,
                            'siteId' => $element->siteId ?? null,
                            'reason' => sprintf('Native target type `%s` does not match the current source link type `%s`.', $actualType, $expectedType),
                        ];
                    } else {
                        $verified++;
                    }
                }
            }
        }

        return [
            'total' => $verified + $empty + count($unverified),
            'verified' => $verified,
            'empty' => $empty,
            'unverified' => $unverified,
        ];
    }

    private function readNativeTargetValue(ElementInterface $element, string $targetHandle): mixed
    {
        $values = $element->getSerializedFieldValues([$targetHandle]);
        return $values[$targetHandle] ?? null;
    }

    private function readNativeTargetType(ElementInterface $element, string $targetHandle): ?string
    {
        $value = $this->readNativeTargetValue($element, $targetHandle);
        if (is_string($value)) {
            $decoded = $this->decodeSerializedJson($value);
            if ($decoded !== null) {
                $value = $decoded;
            }
        }

        if (is_array($value) && isset($value['type']) && is_string($value['type']) && $value['type'] !== '') {
            return $value['type'];
        }

        return null;
    }

    /**
     * The native link type a source value would convert to right now, or null if it would
     * no longer convert to a supported type. Used for drift detection (finding 4).
     */
    private function expectedNativeType(mixed $sourceValue, ?int $siteId): ?string
    {
        $conversion = $this->convertHyperValue($sourceValue, $siteId);
        if ($conversion['status'] !== 'ok') {
            return null;
        }

        $type = $conversion['summary']['type'] ?? null;
        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * Whether an already-migrated unit's stored native value still reflects the current source.
     * Empty/native source values are treated as current (nothing to re-migrate). Otherwise the
     * stored native link type must match the type the current source converts to (finding 4).
     */
    private function migratedTargetIsCurrent(
        ElementInterface $element,
        FieldAudit $fieldAudit,
        array $fieldContext,
        string $targetHandle
    ): bool {
        $sourceValue = $this->getStoredFieldValue($element, $fieldAudit, $fieldContext);
        if ($this->isEmptyHyperValue($sourceValue) || $this->isNativeLinkValue($sourceValue)) {
            return true;
        }

        $expectedType = $this->expectedNativeType($sourceValue, $element->siteId);
        if ($expectedType === null) {
            return false;
        }

        // The stored native value must still be populated. If it was cleared or lost after
        // migration, re-migrate instead of trusting the stale "migrated" record, so a blanked
        // target can be recovered by simply re-running content. This mirrors reconcileField()'s
        // presence gate, so the finalize authority and the re-migration skip guard agree: a unit
        // reconcileField marks unverified is also one content will re-migrate, not skip.
        if (!$this->isPopulatedNativeLink($this->readNativeTargetValue($element, $targetHandle))) {
            return false;
        }

        $actualType = $this->readNativeTargetType($element, $targetHandle);
        return $actualType === null || $actualType === $expectedType;
    }

    private function isPopulatedNativeLink(mixed $value): bool
    {
        if (is_string($value)) {
            $decoded = $this->decodeSerializedJson($value);
            if ($decoded !== null) {
                $value = $decoded;
            }
        }

        if (!is_array($value) || !isset($value['value'])) {
            return false;
        }

        return trim((string)$value['value']) !== '';
    }

    /**
     * @return ElementQuery[]
     */
    private function buildElementQueries(array $containers): array
    {
        $classes = [];

        foreach ($containers as $container) {
            if (
                is_object($container) &&
                isset($container->type) &&
                is_string($container->type) &&
                is_a($container->type, ElementInterface::class, true)
            ) {
                $classes[$container->type] = true;
            }
        }

        if (empty($classes)) {
            $classes[Entry::class] = true;
        }

        $queries = [];
        foreach (array_keys($classes) as $class) {
            /** @var ElementQuery $query */
            $query = $class::find()->status(null)->site('*')->drafts(null)->provisionalDrafts(null)->trashed(null);
            $queries[] = $query;
        }

        return $queries;
    }

    private function extractLayoutIds(array $containers): array
    {
        $layoutIds = [];

        foreach ($containers as $container) {
            if (is_object($container) && isset($container->id) && is_numeric($container->id)) {
                $layoutIds[(int)$container->id] = true;
            }
        }

        return array_keys($layoutIds);
    }

    private function primeBatchCaches(FieldAudit $fieldAudit, array $batch): void
    {
        $this->rawContentCache = [];
        $this->migratedStateCache = HyperToLink::$plugin->getState()->migratedMap('content', $fieldAudit->uid, $batch);

        $condition = $this->buildElementSiteCondition($batch);
        if ($condition === null) {
            return;
        }

        $rows = (new Query())
            ->select(['elementId', 'siteId', 'content'])
            ->from(['{{%elements_sites}}'])
            ->where($condition)
            ->all();

        foreach ($rows as $row) {
            $cacheKey = $row['elementId'] . ':' . $row['siteId'];
            $content = $row['content'] ?? null;

            if (!is_string($content) || $content === '') {
                $this->rawContentCache[$cacheKey] = null;
                continue;
            }

            $decoded = json_decode($content, true);
            $this->rawContentCache[$cacheKey] = is_array($decoded) ? $decoded : null;
        }

        foreach ($batch as $element) {
            if ($element instanceof ElementInterface) {
                $this->rawContentCache[$this->elementKey($element)] ??= null;
            }
        }
    }

    private function buildElementSiteCondition(array $elements): ?array
    {
        $pairs = [];

        foreach ($elements as $element) {
            if (!$element instanceof ElementInterface || $element->id === null || $element->siteId === null) {
                continue;
            }

            $pairs[$this->elementKey($element)] = [
                'and',
                ['elementId' => (int)$element->id],
                ['siteId' => (int)$element->siteId],
            ];
        }

        if ($pairs === []) {
            return null;
        }

        return ['or', ...array_values($pairs)];
    }

    private function isMigratedInBatch(ElementInterface $element): bool
    {
        return $this->migratedStateCache[$this->elementKey($element)] ?? false;
    }

    private function elementSupportsField(ElementInterface $element, string $fieldHandle, array $layoutIds): bool
    {
        $fieldLayout = $element->getFieldLayout();
        if ($fieldLayout === null) {
            return false;
        }

        if ($layoutIds !== [] && !in_array((int)$fieldLayout->id, $layoutIds, true)) {
            return false;
        }

        return $fieldLayout->getFieldByHandle($fieldHandle) !== null;
    }

    private function resolveFieldContext(ElementInterface $element, int $fieldId): ?array
    {
        $fieldLayout = $element->getFieldLayout();
        if ($fieldLayout === null) {
            return null;
        }

        $cacheKey = (int)$fieldLayout->id . ':' . $fieldId;
        if (array_key_exists($cacheKey, $this->fieldContextCache)) {
            return $this->fieldContextCache[$cacheKey];
        }

        foreach ($fieldLayout->getTabs() as $tab) {
            foreach ($tab->getElements() as $layoutElement) {
                if (!method_exists($layoutElement, 'getField')) {
                    continue;
                }

                $field = $layoutElement->getField();
                if ($field && (int)$field->id === $fieldId) {
                    return $this->fieldContextCache[$cacheKey] = [
                        'runtimeHandle' => (string)$field->handle,
                        'layoutElementUid' => isset($layoutElement->uid) ? (string)$layoutElement->uid : null,
                    ];
                }
            }
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            if ((int)$field->id === $fieldId) {
                return $this->fieldContextCache[$cacheKey] = [
                    'runtimeHandle' => (string)$field->handle,
                    'layoutElementUid' => null,
                ];
            }
        }

        return $this->fieldContextCache[$cacheKey] = null;
    }

    private function getStoredFieldValue(ElementInterface $element, FieldAudit $fieldAudit, array $fieldContext): mixed
    {
        $fieldHandle = $fieldContext['runtimeHandle'] ?? null;
        if (!is_string($fieldHandle) || $fieldHandle === '') {
            return null;
        }

        $values = $element->getSerializedFieldValues([$fieldHandle]);
        $value = $this->unwrapStoredFieldValue($values[$fieldHandle] ?? null);

        if (!$this->isEmptyHyperValue($value)) {
            return $value;
        }

        $rawKeys = array_values(array_filter([
            $fieldContext['layoutElementUid'] ?? null,
            $fieldAudit->uid,
        ], static fn($key) => is_string($key) && $key !== ''));

        return $this->unwrapStoredFieldValue($this->getRawFieldValue($element, $rawKeys));
    }

    private function getRawFieldValue(ElementInterface $element, array $rawKeys): mixed
    {
        $cacheKey = $this->elementKey($element);
        if (!array_key_exists($cacheKey, $this->rawContentCache)) {
            $content = (new Query())
                ->select(['content'])
                ->from(['{{%elements_sites}}'])
                ->where([
                    'elementId' => $element->id,
                    'siteId' => $element->siteId,
                ])
                ->scalar();

            if (!is_string($content) || $content === '') {
                $this->rawContentCache[$cacheKey] = null;
            } else {
                $decoded = json_decode($content, true);
                $this->rawContentCache[$cacheKey] = is_array($decoded) ? $decoded : null;
            }
        }

        $decoded = $this->rawContentCache[$cacheKey];
        if (!is_array($decoded)) {
            return null;
        }

        foreach ($rawKeys as $key) {
            if (array_key_exists($key, $decoded)) {
                return $decoded[$key];
            }
        }

        return null;
    }

    private function recordSkipped(
        ContentMigrationResult $result,
        array $options,
        FieldAudit $fieldAudit,
        ElementInterface $element,
        string $reason
    ): void {
        if (empty($options['dryRun'])) {
            HyperToLink::$plugin->getState()->markSkipped('content', $fieldAudit->handle, $fieldAudit->uid, $element, $reason);
        }

        $result->addSkipped([
            'field' => $fieldAudit->handle,
            'elementId' => $element->id,
            'reason' => $reason,
        ]);
    }

    private function recordWarning(
        ContentMigrationResult $result,
        array $options,
        FieldAudit $fieldAudit,
        ElementInterface $element,
        array $warnings,
        array $backup
    ): void {
        if (empty($options['dryRun'])) {
            HyperToLink::$plugin->getState()->markWarning('content', $fieldAudit->handle, $fieldAudit->uid, $element, $warnings, $backup);
        }

        $result->addWarning([
            'field' => $fieldAudit->handle,
            'elementId' => $element->id,
            'warnings' => $warnings,
        ]);
    }

    private function elementKey(ElementInterface $element): string
    {
        return $element->id . ':' . $element->siteId;
    }

    private function unwrapStoredFieldValue(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = $this->decodeSerializedJson($value);
            if ($decoded !== null) {
                return $this->unwrapStoredFieldValue($decoded);
            }

            return $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        if ($this->looksLikeNativeLinkPayload($value)) {
            $decoded = $this->decodeSerializedJson((string)$value['value']);
            if ($decoded !== null) {
                return $this->unwrapStoredFieldValue($decoded);
            }

            return $value;
        }

        if (array_is_list($value) && count($value) === 1) {
            return $this->unwrapStoredFieldValue($value[0]);
        }

        return $value;
    }

    private function decodeSerializedJson(string $value): mixed
    {
        $value = trim($value);
        if ($value === '' || (!str_starts_with($value, '[') && !str_starts_with($value, '{'))) {
            return null;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function looksLikeNativeLinkPayload(array $value): bool
    {
        return isset($value['type'], $value['value'])
            && is_string($value['type'])
            && in_array($value['type'], self::NATIVE_LINK_TYPES, true);
    }

    private function isNativeLinkValue(mixed $value): bool
    {
        return is_array($value)
            && $this->looksLikeNativeLinkPayload($value)
            && $this->decodeSerializedJson((string)$value['value']) === null;
    }

    private function isEmptyHyperValue(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (is_array($value) && isset($value['value']) && trim((string)$value['value']) === '') {
            return true;
        }

        if (is_object($value) && method_exists($value, 'isEmpty')) {
            return (bool)$value->isEmpty();
        }

        return false;
    }

    private function convertHyperValue(mixed $value, ?int $elementSiteId = null): array
    {
        $backup = $this->backupPayload($value);
        $warnings = [];

        $type = $this->normalizeType($this->readHyperProperty($value, ['type', 'linkType', 'handle']));
        $text = $this->readHyperProperty($value, ['text', 'label', 'linkText']);
        $target = $this->readHyperProperty($value, ['target', 'newWindow']);
        $urlSuffix = $this->readHyperProperty($value, ['urlSuffix']);
        $title = $this->readHyperProperty($value, ['title']);
        $class = $this->readHyperProperty($value, ['class', 'classes']);
        $id = $this->readHyperProperty($value, ['id']);
        $rel = $this->readHyperProperty($value, ['rel']);
        $customFields = $this->readHyperProperty($value, ['fields']);
        $linkValue = $this->readHyperProperty($value, ['linkValue', 'value', 'url']);
        $linkedSiteId = $this->readHyperProperty($value, ['linkSiteId', 'siteId']) ?? $elementSiteId;
        $element = $this->readElement($value) ?? $this->resolveLinkedElement($type, $linkValue, $linkedSiteId);

        if ($customFields) {
            $warnings[] = 'Custom Hyper link fields were preserved in backup only.';
        }

        $nativeType = match ($type) {
            'asset' => 'asset',
            'category' => 'category',
            'email' => 'email',
            'entry' => 'entry',
            'phone' => 'tel',
            'sms' => 'sms',
            'url' => 'url',
            default => null,
        };

        if ($nativeType === null) {
            if ($type === 'product' && $element instanceof ElementInterface) {
                $resolvedUrl = method_exists($element, 'getUrl') ? $element->getUrl() : ($element->url ?? null);
                if (is_string($resolvedUrl) && $resolvedUrl !== '') {
                    $nativeType = 'url';
                    $linkValue = $resolvedUrl;
                    $warnings[] = 'Hyper product links were migrated as native URL links.';
                }
            }

            if ($nativeType === null && (!$linkValue || !is_scalar($linkValue))) {
                return [
                    'status' => 'unsupported',
                    'warnings' => [sprintf('Unsupported Hyper link type for content migration: %s', $type ?: 'unknown')],
                    'backup' => $backup,
                ];
            }

            if ($nativeType === null) {
                $nativeType = 'url';
                $warnings[] = sprintf(
                    'Custom or unsupported Hyper link type "%s" was migrated as a native URL link.',
                    $type ?: 'unknown'
                );
            }
        }

        if (in_array($type, ['entry', 'asset', 'category'], true)) {
            if (!$element) {
                return [
                    'status' => 'unsupported',
                    'warnings' => ['Linked element is missing or invalid.'],
                    'backup' => $backup,
                ];
            }

            $linkValue = $element->id;
        }

        $payload = array_filter([
            'type' => $nativeType,
            'value' => $linkValue,
            'label' => $text,
            'target' => $target ? '_blank' : null,
            'urlSuffix' => $urlSuffix,
            'title' => $title,
            'class' => $class,
            'id' => $id,
            'rel' => $rel,
        ], static fn($item) => $item !== null && $item !== '');

        return [
            'status' => 'ok',
            'payload' => $payload,
            'summary' => [
                'type' => $nativeType,
                'value' => $linkValue,
                'label' => $text,
                'target' => $target ? '_blank' : null,
            ],
            'warnings' => $warnings,
            'backup' => $backup,
        ];
    }

    private function normalizeType(mixed $type): string
    {
        $value = strtolower((string)$type);
        return preg_replace('/^.*\\\\/', '', $value);
    }

    private function readHyperProperty(mixed $value, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($value) && array_key_exists($key, $value)) {
                return $value[$key];
            }

            if (is_object($value)) {
                if (isset($value->$key)) {
                    return $value->$key;
                }

                $getter = 'get' . ucfirst($key);
                if (method_exists($value, $getter)) {
                    return $value->$getter();
                }
            }
        }

        return null;
    }

    private function readElement(mixed $value): ?ElementInterface
    {
        $element = $this->readHyperProperty($value, ['element']);
        if ($element instanceof ElementInterface) {
            return $element;
        }

        if (is_object($value) && method_exists($value, 'getElement')) {
            $candidate = $value->getElement();
            return $candidate instanceof ElementInterface ? $candidate : null;
        }

        return null;
    }

    private function resolveLinkedElement(string $type, mixed $value, mixed $siteId): ?ElementInterface
    {
        $elementId = match (true) {
            is_numeric($value) => (int)$value,
            is_array($value) && count($value) === 1 && is_numeric(reset($value)) => (int)reset($value),
            default => null,
        };

        if (!$elementId) {
            return null;
        }

        $elementClass = match ($type) {
            'asset' => Asset::class,
            'category' => Category::class,
            'entry' => Entry::class,
            'product' => class_exists('craft\\commerce\\elements\\Product') ? 'craft\\commerce\\elements\\Product' : null,
            default => null,
        };

        if ($elementClass === null) {
            return null;
        }

        /** @var ElementQuery $query */
        $query = $elementClass::find()->id($elementId)->status(null);

        if (method_exists($query, 'siteId') && is_numeric($siteId)) {
            $query->siteId((int)$siteId);
        }

        foreach (['drafts', 'provisionalDrafts', 'trashed', 'revisions'] as $method) {
            if (method_exists($query, $method)) {
                $query->{$method}(null);
            }
        }

        $element = $query->one();
        return $element instanceof ElementInterface ? $element : null;
    }

    private function backupPayload(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        if ($value instanceof \JsonSerializable) {
            return (array)$value->jsonSerialize();
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return ['unserializable' => get_debug_type($value)];
        }

        $decoded = json_decode($encoded, true);
        return is_array($decoded) ? $decoded : ['scalar' => $decoded];
    }
}
