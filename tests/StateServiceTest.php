<?php

declare(strict_types=1);

namespace luremo\linkmigrator\tests;

use luremo\linkmigrator\models\AuditResult;
use luremo\linkmigrator\models\FieldAudit;
use luremo\linkmigrator\models\FieldMapping;
use luremo\linkmigrator\models\MappingDecision;
use luremo\linkmigrator\services\StateService;
use PHPUnit\Framework\TestCase;

final class StateServiceTest extends TestCase
{
    public function testWorkflowStatusesDefaultRowlessAuditFieldsToAudited(): void
    {
        $service = $this->stateService();
        $audit = new AuditResult([
            'fields' => [
                $this->field('ctaLink', 'CTA Link'),
            ],
        ]);

        self::assertSame([
            [
                'sourceHandle' => 'ctaLink',
                'sourceName' => 'CTA Link',
                'targetHandle' => null,
                'phase' => FieldMapping::PHASE_AUDITED,
                'mappingStatus' => MappingDecision::STATUS_SUPPORTED,
                'preparedAt' => null,
                'contentMigratedAt' => null,
                'finalizedAt' => null,
                'contentSummary' => null,
            ],
        ], $service->workflowStatuses($audit));
    }

    public function testWorkflowStatusesUsePreparedMappingAndContentSummaryWhenPresent(): void
    {
        $summary = [
            'action' => 'content',
            'fieldHandle' => 'ctaLink',
            'migratedCount' => '2',
        ];
        $mapping = new FieldMapping([
            'sourceFieldId' => 1,
            'sourceFieldUid' => 'source-uid',
            'sourceHandle' => 'ctaLink',
            'targetFieldId' => 2,
            'targetFieldUid' => 'target-uid',
            'targetHandle' => 'ctaLinkLink',
            'phase' => FieldMapping::PHASE_READY_TO_FINALIZE,
            'preparedAt' => '2026-06-30 12:00:00',
            'contentMigratedAt' => '2026-06-30 12:05:00',
        ]);
        $service = $this->stateService([$mapping], [$summary]);

        $status = $service->workflowStatuses(new AuditResult([
            'fields' => [$this->field('ctaLink', 'CTA Link')],
        ]))[0];

        self::assertSame('ctaLinkLink', $status['targetHandle']);
        self::assertSame(FieldMapping::PHASE_READY_TO_FINALIZE, $status['phase']);
        self::assertSame($summary, $status['contentSummary']);
    }

    /**
     * @param FieldMapping[] $mappings
     */
    private function stateService(array $mappings = [], array $summaries = []): StateService
    {
        return new class($mappings, $summaries) extends StateService {
            public function __construct(
                private readonly array $mappings,
                private readonly array $storedSummaries,
            ) {
                parent::__construct();
            }

            public function getFieldMappings(?string $fieldHandle = null): array
            {
                if ($fieldHandle === null) {
                    return $this->mappings;
                }

                return array_values(array_filter(
                    $this->mappings,
                    fn(FieldMapping $mapping): bool => $mapping->sourceHandle === $fieldHandle
                ));
            }

            public function summaries(?string $fieldHandle = null): array
            {
                if ($fieldHandle === null) {
                    return $this->storedSummaries;
                }

                return array_values(array_filter(
                    $this->storedSummaries,
                    fn(array $summary): bool => ($summary['fieldHandle'] ?? null) === $fieldHandle
                ));
            }
        };
    }

    private function field(string $handle, string $name): FieldAudit
    {
        return new FieldAudit([
            'fieldId' => 1,
            'uid' => $handle . '-uid',
            'handle' => $handle,
            'name' => $name,
            'mapping' => new MappingDecision(['status' => MappingDecision::STATUS_SUPPORTED]),
        ]);
    }
}
