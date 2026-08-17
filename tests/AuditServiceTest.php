<?php

declare(strict_types=1);

namespace luremo\linkmigrator\tests;

use luremo\linkmigrator\services\AuditService;
use PHPUnit\Framework\TestCase;

final class AuditServiceTest extends TestCase
{
    public function testMismatchScannerIncludesTypedLinkApis(): void
    {
        $patterns = array_column(
            (new \ReflectionClass(AuditService::class))->getConstant('MISMATCH_PATTERNS'),
            'pattern',
        );

        foreach ([
            'customText',
            'getLinkAttributes(',
            'getLink(',
            'hasElement(',
            'getElement(',
            'linkedUrl',
            'lenz\\linkfield',
            'lenz\\\\linkfield',
            'typedlinkfield',
        ] as $pattern) {
            self::assertContains($pattern, $patterns);
        }
    }

    public function testTypedLinkTypeExtractionHonorsDisabledRowsAndSafeDefault(): void
    {
        $method = new \ReflectionMethod(AuditService::class, 'extractLinkTypes');
        $service = new AuditService();

        self::assertSame(['url'], $method->invoke($service, [
            'typeSettings' => [
                'url' => ['enabled' => true],
                'custom' => ['enabled' => false],
                'entry' => [],
            ],
        ], 'typed-link'));
        self::assertSame(['url'], $method->invoke($service, [
            'enableAllLinkTypes' => true,
            'typeSettings' => [
                'url' => [],
                'custom' => ['enabled' => false],
            ],
        ], 'typed-link'));
    }

    public function testMissingAdvancedFieldsCapabilityIsSafe(): void
    {
        $method = new \ReflectionMethod(AuditService::class, 'nativeAdvancedFields');

        self::assertSame([], $method->invoke(new AuditService(), new \stdClass()));
    }

    public function testRawTypedLinkSettingsPreserveExplicitEmptySources(): void
    {
        $method = new \ReflectionMethod(AuditService::class, 'mergeFieldSettings');
        $settings = $method->invoke(new AuditService(), [
            'enableAllLinkTypes' => false,
            'typeSettings' => ['entry' => ['enabled' => true, 'sources' => null]],
        ], [
            'enableAllLinkTypes' => false,
            'typeSettings' => ['entry' => ['enabled' => true, 'sources' => '*']],
        ]);

        self::assertNull($settings['typeSettings']['entry']['sources']);
    }
}
