<?php

declare(strict_types=1);

namespace luremo\linkmigrator\tests;

use luremo\linkmigrator\models\MappingDecision;
use luremo\linkmigrator\services\MappingStrategyService;
use PHPUnit\Framework\TestCase;

final class LegacyLinkFieldFixture
{
    public bool $showTargetField = false;
}

final class MappingStrategyServiceTest extends TestCase
{
    public function testDetectsLegacyShowTargetFieldCapability(): void
    {
        self::assertTrue(MappingStrategyService::supportsLegacyTargetField(LegacyLinkFieldFixture::class));
        self::assertFalse(MappingStrategyService::supportsLegacyTargetField(\stdClass::class));
    }

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

    public function testTypedLinkFieldWithUnknownEnabledTypesIsUnsupported(): void
    {
        $decision = $this->service()->decide([], [], false, [], 'typed-link');

        self::assertSame(MappingDecision::STATUS_UNSUPPORTED, $decision->status);
        self::assertSame([], $decision->craftLinkTypes);
        self::assertStringContainsString('could not be determined', $decision->unsupportedReasons[0]);
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

    public function testMapsTypedLinkSettingsAndNarrowedElementSources(): void
    {
        $decision = $this->service()->decide(
            [
                'allowCustomText' => true,
                'allowTarget' => true,
                'enableTitle' => true,
                'enableAriaLabel' => true,
                'typeSettings' => [
                    'entry' => ['enabled' => true, 'sources' => ['section:news', 'section:removed'], 'allowCustomQuery' => true],
                    'url' => ['enabled' => true],
                ],
            ],
            ['entry', 'url'],
            false,
            [],
            'typed-link',
            ['entry' => ['section:news'], 'url' => []],
            ['showLabelField' => true, 'advancedFields' => true],
        );

        self::assertSame(MappingDecision::STATUS_PARTIAL, $decision->status);
        self::assertSame(['entry', 'url'], $decision->craftLinkTypes);
        self::assertTrue($decision->showLabelField);
        self::assertSame(['section:news'], $decision->typeSettings['entry']['sources']);
        self::assertContains('target', $decision->advancedFields);
        self::assertContains('title', $decision->advancedFields);
        self::assertContains('ariaLabel', $decision->advancedFields);
        self::assertContains('urlSuffix', $decision->advancedFields);
        self::assertNotEmpty($decision->warnings);
    }

    public function testRefusesTypedLinkFieldWhenAllConfiguredSourcesAreStale(): void
    {
        $decision = $this->service()->decide(
            ['typeSettings' => ['entry' => ['enabled' => true, 'sources' => ['section:removed']]]],
            ['entry'],
            false,
            [],
            'typed-link',
            ['entry' => ['section:news']],
        );

        self::assertSame(MappingDecision::STATUS_UNSUPPORTED, $decision->status);
        self::assertStringContainsString('no longer exist', $decision->unsupportedReasons[0]);
    }

    public function testRejectsExplicitEmptySourceRestrictionsWithoutBroadeningAccess(): void
    {
        foreach ([null, '', '   ', []] as $sources) {
            $decision = $this->service()->decide(
                ['typeSettings' => ['entry' => ['enabled' => true, 'sources' => $sources]]],
                ['entry'],
                false,
                [],
                'typed-link',
                ['entry' => ['section:news']],
            );

            self::assertSame(MappingDecision::STATUS_UNSUPPORTED, $decision->status, var_export($sources, true));
        }

        $unrestricted = $this->service()->decide(
            ['typeSettings' => ['entry' => ['enabled' => true, 'sources' => '*']]],
            ['entry'],
            false,
            [],
            'typed-link',
            ['entry' => ['section:news']],
        );
        self::assertSame('*', $unrestricted->typeSettings['entry']['sources']);
    }

    public function testUsesLegacyNativeTargetSettingWhenAdvancedFieldsAreUnavailable(): void
    {
        $decision = $this->service()->decide(
            [
                'allowCustomText' => false,
                'allowTarget' => true,
                'enableTitle' => false,
                'enableAriaLabel' => false,
            ],
            ['url'],
            false,
            [],
            'typed-link',
            [],
            ['showLabelField' => false, 'advancedFields' => false, 'targetField' => true],
        );

        self::assertSame(MappingDecision::STATUS_SUPPORTED, $decision->status);
        self::assertTrue($decision->showTargetField);
        self::assertNotContains('target', $decision->advancedFields);
    }

    public function testMapsTypedLinkDefaultTextAndNoReferrerSettings(): void
    {
        $decision = $this->service()->decide(
            [
                'allowCustomText' => false,
                'defaultText' => 'Read more',
                'allowTarget' => true,
                'autoNoReferrer' => true,
            ],
            ['url'],
            false,
            [],
            'typed-link',
            [],
            ['showLabelField' => true, 'advancedFields' => true],
        );

        self::assertTrue($decision->showLabelField);
        self::assertContains('target', $decision->advancedFields);
        self::assertContains('rel', $decision->advancedFields);
    }

    public function testReportsTypedLinkCustomTextConstraintsAsLossy(): void
    {
        $decision = $this->service()->decide(
            ['customTextRequired' => true, 'customTextMaxLength' => 80],
            ['url'],
            false,
            [],
            'typed-link',
        );

        self::assertSame(MappingDecision::STATUS_PARTIAL, $decision->status);
        self::assertContains('customTextRequired', $decision->lossyAttributes);
        self::assertContains('customTextMaxLength', $decision->lossyAttributes);
    }

    public function testMapsTypedLinkCustomValuesToNativeUrlWithSupportedUrlForms(): void
    {
        $decision = $this->service()->decide(
            ['typeSettings' => ['custom' => ['enabled' => true, 'disableValidation' => true]]],
            ['custom'],
            false,
            [],
            'typed-link',
            [],
            [
                'showLabelField' => true,
                'advancedFields' => true,
                'allowRootRelativeUrls' => true,
                'allowAnchors' => true,
                'allowCustomSchemes' => true,
            ],
        );

        self::assertSame(MappingDecision::STATUS_SUPPORTED, $decision->status);
        self::assertSame(['url'], $decision->craftLinkTypes);
        self::assertSame([
            'allowRootRelativeUrls' => true,
            'allowAnchors' => true,
            'allowCustomSchemes' => true,
        ], $decision->typeSettings['url']);
    }

    public function testStaleDisabledCustomSettingsDoNotRelaxNativeUrls(): void
    {
        $decision = $this->service()->decide(
            ['typeSettings' => [
                'url' => ['enabled' => true],
                'custom' => ['enabled' => false, 'disableValidation' => true],
            ]],
            ['url'],
            false,
            [],
            'typed-link',
            [],
            [
                'allowRootRelativeUrls' => true,
                'allowAnchors' => true,
                'allowCustomSchemes' => true,
            ],
        );

        self::assertArrayNotHasKey('url', $decision->typeSettings);
    }

    public function testLeavesTypedLinkSiteAndUserTypesUnsupported(): void
    {
        $decision = $this->service()->decide(
            [],
            ['site', 'user'],
            false,
            [],
            'typed-link',
        );

        self::assertSame(MappingDecision::STATUS_UNSUPPORTED, $decision->status);
        self::assertSame([], $decision->craftLinkTypes);
        self::assertCount(2, $decision->unsupportedReasons);
    }

    private function service(): MappingStrategyService
    {
        return new MappingStrategyService();
    }
}
