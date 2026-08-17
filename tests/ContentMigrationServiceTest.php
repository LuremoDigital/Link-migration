<?php

declare(strict_types=1);

namespace luremo\linkmigrator\tests;

use craft\fields\Link;
use luremo\linkmigrator\models\FieldAudit;
use luremo\linkmigrator\services\ContentMigrationService;
use PHPUnit\Framework\TestCase;

final class LegacyTargetLinkFieldFixture extends Link
{
    public bool $showTargetField = true;
}

final class ContentMigrationServiceTest extends TestCase
{
    public function testHyperUrlArrayIsNotEmpty(): void
    {
        $method = new \ReflectionMethod(ContentMigrationService::class, 'isEmptyHyperValue');

        self::assertFalse($method->invoke(new ContentMigrationService(), [
            'type' => 'url',
            'linkValue' => 'https://example.test',
        ]));
    }

    public function testEmptyTypedLinkArrayIsEmpty(): void
    {
        $method = new \ReflectionMethod(ContentMigrationService::class, 'isEmptyHyperValue');

        self::assertTrue($method->invoke(new ContentMigrationService(), [
            'type' => 'url',
            'linkedUrl' => '',
            'linkedId' => null,
        ]));
    }

    public function testConvertsHyperUrlArray(): void
    {
        $method = new \ReflectionMethod(ContentMigrationService::class, 'convertHyperValue');

        $conversion = $method->invoke(new ContentMigrationService(), [
            'type' => 'url',
            'linkValue' => 'https://example.test',
        ]);

        self::assertSame('ok', $conversion['status']);
        self::assertSame([
            'type' => 'url',
            'value' => 'https://example.test',
        ], $conversion['payload']);
    }

    public function testValidatesUrlValuesAgainstPreparedFieldSettings(): void
    {
        $method = new \ReflectionMethod(ContentMigrationService::class, 'validateTargetPayload');
        $service = new ContentMigrationService();
        $conversion = static fn(string $value): array => [
            'status' => 'ok',
            'payload' => ['type' => 'url', 'value' => $value],
            'warnings' => [],
            'backup' => ['linkedUrl' => $value],
        ];

        $strictField = new Link(['types' => ['url']]);
        foreach (['/pricing', '#pricing', 'webcal:pricing'] as $value) {
            self::assertSame(
                'unsupported',
                $method->invoke($service, $conversion($value), $strictField, 'typed-link')['status'],
                $value,
            );
        }

        $relaxedField = new Link([
            'types' => ['url'],
            'typeSettings' => ['url' => [
                'allowRootRelativeUrls' => true,
                'allowAnchors' => true,
                'allowCustomSchemes' => true,
            ]],
        ]);
        foreach (['/pricing', '#pricing', 'webcal:pricing'] as $value) {
            self::assertSame(
                'ok',
                $method->invoke($service, $conversion($value), $relaxedField, 'typed-link')['status'],
                $value,
            );
        }
    }

    public function testWarnsWhenPreparedFieldDropsTypedLinkAttributes(): void
    {
        $method = new \ReflectionMethod(ContentMigrationService::class, 'validateTargetPayload');
        $conversion = $method->invoke(new ContentMigrationService(), [
            'status' => 'ok',
            'payload' => [
                'type' => 'url',
                'value' => 'https://example.test',
                'label' => 'Example',
                'target' => '_blank',
                'urlSuffix' => '?from=typed',
                'title' => 'Example title',
                'ariaLabel' => 'Example aria label',
            ],
            'warnings' => [],
            'backup' => ['source' => 'complete'],
        ], new Link(['types' => ['url']]), 'typed-link');

        self::assertSame('ok', $conversion['status']);
        self::assertSame([
            'type' => 'url',
            'value' => 'https://example.test',
        ], $conversion['payload']);
        self::assertCount(5, $conversion['warnings']);
        self::assertSame(['source' => 'complete'], $conversion['backup']);
    }

    public function testNormalizesTypedLinkQuerySuffixWithoutDiscardingLink(): void
    {
        $method = new \ReflectionMethod(ContentMigrationService::class, 'validateTargetPayload');
        $conversion = $method->invoke(new ContentMigrationService(), [
            'status' => 'ok',
            'payload' => [
                'type' => 'url',
                'value' => 'https://example.test',
                'urlSuffix' => 'from=typed',
            ],
            'warnings' => [],
            'backup' => ['customQuery' => 'from=typed'],
        ], new Link([
            'types' => ['url'],
            'advancedFields' => ['urlSuffix'],
        ]), 'typed-link');

        self::assertSame('ok', $conversion['status']);
        self::assertSame('?from=typed', $conversion['payload']['urlSuffix']);
        self::assertSame([], $conversion['warnings']);
    }

    public function testNormalizesCraftLinkStorageToHyperValues(): void
    {
        $method = new \ReflectionMethod(ContentMigrationService::class, 'normalizeNativePayload');
        $service = new ContentMigrationService();

        self::assertSame(['type' => 'email', 'value' => 'hello@example.test'], $method->invoke($service, [
            'type' => 'email',
            'value' => ' mailto:hello@example.test ',
        ]));
        self::assertSame(['type' => 'tel', 'value' => '+31 20 123 4567'], $method->invoke($service, [
            'type' => 'tel',
            'value' => ' tel:+31 20 123 4567 ',
        ]));
        self::assertSame(['type' => 'entry', 'value' => 15], $method->invoke($service, [
            'type' => 'entry',
            'value' => '{entry:15@1:url}',
        ]));
    }

    public function testConvertsTypedLinkScalarAndRelationalPayloads(): void
    {
        $method = new \ReflectionMethod(ContentMigrationService::class, 'convertTypedLinkValue');
        $service = new ContentMigrationService();

        $url = $method->invoke($service, [
            'type' => 'url',
            'linkedUrl' => 'https://example.test/pricing',
            'payload' => json_encode([
                'customText' => 'Pricing',
                'target' => '_blank',
                'ariaLabel' => 'Open pricing',
                'customQuery' => '?ref=nav',
            ], JSON_THROW_ON_ERROR),
        ], 1);
        self::assertSame('ok', $url['status']);
        self::assertSame([
            'type' => 'url',
            'value' => 'https://example.test/pricing',
            'label' => 'Pricing',
            'target' => '_blank',
            'urlSuffix' => '?ref=nav',
            'ariaLabel' => 'Open pricing',
        ], $url['payload']);

        $entry = $method->invoke($service, [
            'type' => 'entry',
            'linkedId' => 0,
            'linkedSiteId' => 2,
            'payload' => ['customText' => 'Read more'],
        ], 1);
        self::assertSame('unsupported', $entry['status']);
        self::assertStringContainsString('Linked element is missing', $entry['warnings'][0]);
    }

    public function testConvertsTypedLinkCustomUrlValuesWhenNativeUrlCanRepresentThem(): void
    {
        $method = new \ReflectionMethod(ContentMigrationService::class, 'convertTypedLinkValue');

        $conversion = $method->invoke(new ContentMigrationService(), [
            'type' => 'custom',
            'linkedUrl' => 'https://example.test/custom',
        ]);

        self::assertSame('ok', $conversion['status']);
        self::assertSame(['type' => 'url', 'value' => 'https://example.test/custom'], $conversion['payload']);
        self::assertSame([
            'type' => 'custom',
            'linkedUrl' => 'https://example.test/custom',
        ], $conversion['backup']);
    }

    public function testNormalizesTypedLinkCustomUrlsUsingPreparedFieldRules(): void
    {
        $service = new ContentMigrationService();
        $convert = new \ReflectionMethod(ContentMigrationService::class, 'convertTypedLinkValue');
        $validate = new \ReflectionMethod(ContentMigrationService::class, 'validateTargetPayload');

        $conversion = $convert->invoke($service, [
            'type' => 'custom',
            'linkedUrl' => 'example.com',
        ]);
        self::assertSame('ok', $conversion['status']);

        $conversion = $validate->invoke($service, $conversion, new Link(['types' => ['url']]), 'typed-link');
        self::assertSame('ok', $conversion['status']);
        self::assertSame('https://example.com', $conversion['payload']['value']);
        self::assertSame('https://example.com', $conversion['summary']['value']);
    }

    public function testAppliesTypedLinkDefaultTextInsteadOfDisabledCustomText(): void
    {
        $method = new \ReflectionMethod(ContentMigrationService::class, 'convertSourceValue');
        $field = new FieldAudit([
            'sourceKind' => 'typed-link',
            'rawSettings' => [
                'allowCustomText' => false,
                'defaultText' => 'Read more',
            ],
        ]);

        $conversion = $method->invoke(new ContentMigrationService(), $field, [
            'type' => 'url',
            'linkedUrl' => 'https://example.test',
            'payload' => ['customText' => 'Stale custom text'],
        ], 1);

        self::assertSame('Read more', $conversion['payload']['label']);
        self::assertSame('Read more', $conversion['summary']['label']);
    }

    public function testKeepsTargetOnLegacyCraftLinkFields(): void
    {
        $method = new \ReflectionMethod(ContentMigrationService::class, 'validateTargetPayload');
        $conversion = $method->invoke(new ContentMigrationService(), [
            'status' => 'ok',
            'payload' => [
                'type' => 'url',
                'value' => 'https://example.test',
                'target' => '_blank',
            ],
            'warnings' => [],
            'backup' => [],
        ], new LegacyTargetLinkFieldFixture(['types' => ['url']]), 'typed-link');

        self::assertSame('_blank', $conversion['payload']['target']);
        self::assertSame([], $conversion['warnings']);
    }

    public function testAddsNoReferrerRelForBlankTypedLinks(): void
    {
        $method = new \ReflectionMethod(ContentMigrationService::class, 'convertSourceValue');
        $field = new FieldAudit([
            'sourceKind' => 'typed-link',
            'rawSettings' => ['autoNoReferrer' => true],
        ]);

        $conversion = $method->invoke(new ContentMigrationService(), $field, [
            'type' => 'url',
            'linkedUrl' => 'https://example.test',
            'payload' => ['target' => '_blank'],
        ], 1);

        self::assertSame('noopener noreferrer', $conversion['payload']['rel']);
    }

    public function testTreatsHydratedTypedLinkEmptyValueAsEmpty(): void
    {
        $method = new \ReflectionMethod(ContentMigrationService::class, 'isEmptyHyperValue');
        $empty = new class {
            public function isEditorEmpty(): bool
            {
                return true;
            }
        };

        self::assertTrue($method->invoke(new ContentMigrationService(), $empty));
    }
}
