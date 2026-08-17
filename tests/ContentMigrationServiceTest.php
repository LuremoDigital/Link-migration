<?php

declare(strict_types=1);

namespace luremo\linkmigrator\tests;

use luremo\linkmigrator\services\ContentMigrationService;
use PHPUnit\Framework\TestCase;

final class ContentMigrationServiceTest extends TestCase
{
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
}
