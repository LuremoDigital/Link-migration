<?php

declare(strict_types=1);

namespace luremo\linkmigrator\tests;

use luremo\linkmigrator\models\AuditResult;
use luremo\linkmigrator\models\ContentMigrationResult;
use luremo\linkmigrator\models\FieldAudit;
use luremo\linkmigrator\models\FieldMapping;
use luremo\linkmigrator\models\MappingDecision;
use PHPUnit\Framework\TestCase;

final class ModelStateTest extends TestCase
{
    public function testFieldMappingPhasesExposeWorkflowReadiness(): void
    {
        $mapping = new FieldMapping(['phase' => FieldMapping::PHASE_AUDITED]);
        self::assertFalse($mapping->isPrepared());
        self::assertFalse($mapping->isContentReady());

        $mapping->phase = FieldMapping::PHASE_PREPARED;
        self::assertTrue($mapping->isPrepared());
        self::assertFalse($mapping->isContentReady());

        $mapping->phase = FieldMapping::PHASE_READY_TO_FINALIZE;
        self::assertTrue($mapping->isPrepared());
        self::assertTrue($mapping->isContentReady());
    }

    public function testAuditResultOnlyBlocksUnsupportedMappings(): void
    {
        $audit = new AuditResult([
            'fields' => [
                $this->field('ctaLink', MappingDecision::STATUS_PARTIAL),
                $this->field('heroLink', MappingDecision::STATUS_SUPPORTED),
            ],
        ]);

        self::assertFalse($audit->hasBlockingIssues());

        $audit->fields[] = $this->field('brokenLink', MappingDecision::STATUS_UNSUPPORTED);

        self::assertTrue($audit->hasBlockingIssues());
    }

    public function testContentMigrationResultKeepsCountsBeyondSampleLimit(): void
    {
        $result = new ContentMigrationResult();

        for ($i = 0; $i < $result->detailLimit() + 1; $i++) {
            $result->addMigrated(['id' => $i]);
        }

        self::assertSame(251, $result->migratedCount);
        self::assertCount($result->detailLimit(), $result->migrated);
    }

    private function field(string $handle, string $status): FieldAudit
    {
        return new FieldAudit([
            'fieldId' => 1,
            'uid' => $handle . '-uid',
            'handle' => $handle,
            'name' => $handle,
            'mapping' => new MappingDecision(['status' => $status]),
        ]);
    }
}
