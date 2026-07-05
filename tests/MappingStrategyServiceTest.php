<?php

declare(strict_types=1);

namespace luremo\linkmigrator\tests;

use luremo\linkmigrator\models\MappingDecision;
use luremo\linkmigrator\services\MappingStrategyService;
use PHPUnit\Framework\TestCase;

final class MappingStrategyServiceTest extends TestCase
{
    public function testMapsSupportedHyperTypesToNativeLinkTypes(): void
    {
        $decision = $this->service()->decide([], ['url', 'email', 'phone', 'entry'], false, []);

        self::assertSame(MappingDecision::STATUS_SUPPORTED, $decision->status);
        self::assertSame(['url', 'email', 'tel', 'entry'], $decision->craftLinkTypes);
        self::assertContains('label', $decision->advancedFields);
        self::assertContains('target', $decision->advancedFields);
    }

    public function testMultipleHyperLinksAreUnsupported(): void
    {
        $decision = $this->service()->decide([], ['url'], true, []);

        self::assertSame(MappingDecision::STATUS_UNSUPPORTED, $decision->status);
        self::assertSame([], $decision->craftLinkTypes);
        self::assertNotEmpty($decision->unsupportedReasons);
    }

    public function testConfiguredFieldWithNoEnabledTypesIsUnsupported(): void
    {
        $decision = $this->service()->decide(['linkTypes' => []], [], false, []);

        self::assertSame(MappingDecision::STATUS_UNSUPPORTED, $decision->status);
        self::assertSame([], $decision->craftLinkTypes);
        self::assertStringContainsString('no enabled link types', $decision->unsupportedReasons[0]);
    }

    public function testUnknownTypeBecomesPartialWhenSomeTypesAreSupported(): void
    {
        $decision = $this->service()->decide([], ['url', 'bespoke'], false, []);

        self::assertSame(MappingDecision::STATUS_PARTIAL, $decision->status);
        self::assertSame(['url'], $decision->craftLinkTypes);
        self::assertSame(['customTypeFallback'], $decision->lossyAttributes);
    }

    public function testCustomFieldLayoutsRequireBackupAndPartialReview(): void
    {
        $decision = $this->service()->decide([], ['asset'], false, ['asset' => ['foo']]);

        self::assertSame(MappingDecision::STATUS_PARTIAL, $decision->status);
        self::assertSame(['customFields'], $decision->lossyAttributes);
        self::assertSame(['fields'], $decision->legacyBackupKeys);
    }

    private function service(): MappingStrategyService
    {
        return new MappingStrategyService();
    }
}
