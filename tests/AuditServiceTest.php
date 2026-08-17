<?php

declare(strict_types=1);

namespace luremo\linkmigrator\tests;

use Craft;
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

    public function testMismatchScannerFindsDocumentedTypedLinkValueApis(): void
    {
        $patterns = array_column($this->scan([
            'templates/typed-link.twig' => <<<'TWIG'
{{ entry.cta.getRawLinkAttributes() }}
{{ entry.cta.getAllowCustomText() }}
{{ entry.cta.getAllowTarget() }}
{{ entry.cta.getAriaLabel() }}
{{ entry.cta.getCustomText() }}
{{ entry.cta.getDefaultText() }}
{{ entry.cta.getEnableAriaLabel() }}
{{ entry.cta.getEnableTitle() }}
{{ entry.cta.getIntrinsicText() }}
{{ entry.cta.getIntrinsicUrl() }}
{{ entry.cta.getLinkType() }}
{{ entry.cta.getOwnerSite() }}
{{ entry.cta.getTarget() }}
{{ entry.cta.getText() }}
{{ entry.cta.getTitle() }}
{{ entry.cta.getUrl({ scheme: 'https' }) }}
{{ entry.cta.getSiteId() }}
{{ entry.cta.isCrossSiteLink() }}
{{ entry.cta.isEmpty() }}
{{ entry.cta.customQuery }}
{{ entry.cta.linkedId }}
{{ entry.cta.linkedSiteId }}
{{ entry.cta.linkedTitle }}
TWIG,
        ]), 'pattern');

        foreach ([
            'getRawLinkAttributes(',
            'getAllowCustomText(',
            'getAllowTarget(',
            'getAriaLabel(',
            'getCustomText(',
            'getDefaultText(',
            'getEnableAriaLabel(',
            'getEnableTitle(',
            'getIntrinsicText(',
            'getIntrinsicUrl(',
            'getLinkType(',
            'getOwnerSite(',
            'getTarget(',
            'getText(',
            'getTitle(',
            'getUrl(',
            'getSiteId(',
            'isCrossSiteLink(',
            'isEmpty(',
            'customQuery',
            'linkedId',
            'linkedSiteId',
            'linkedTitle',
        ] as $pattern) {
            self::assertContains($pattern, $patterns);
        }
    }

    public function testMismatchScannerIgnoresPortableAndUnrelatedCalls(): void
    {
        self::assertSame([], $this->scan([
            'templates/typed-link.twig' => <<<'TWIG'
{{ entry.cta.getLink() }}
{{ entry.cta.getElement() }}
{{ entry.cta.getUrl() }}
TWIG,
            'src/Unrelated.php' => <<<'PHP'
<?php
$page->getText();
$page->getTitle();
$page->getUrl();
$collection->isEmpty();
PHP,
        ]));
    }

    public function testMismatchScannerFindsOnlySourceCallsWithSourceSpecificArguments(): void
    {
        $matches = $this->scan([
            'templates/typed-link.twig' => <<<'TWIG'
{{ entry.cta.getLink('Read more') }}
{{ entry.cta.getElement(true) }}
{{ entry.cta.getUrl({ scheme: 'https' }) }}
TWIG,
            'src/Unrelated.php' => "<?php\n\$page->getUrl(['scheme' => 'https']);\n",
        ]);

        self::assertSame(['getLink(', 'getElement(', 'getUrl('], array_column($matches, 'pattern'));
    }

    public function testMismatchScannerHandlesMultilineAndRepeatedArgumentCalls(): void
    {
        $matches = $this->scan([
            'templates/typed-link.twig' => <<<'TWIG'
{{ entry.cta.getLink(
    {# no arguments #}
) }}
{{ entry.cta.getElement(
) }}
{{ entry.cta.getUrl(
) ?? entry.cta.getUrl({ scheme: 'https' }) }}
{{ entry.cta.getUrl(
    { scheme: 'https' }
) }}
TWIG,
        ]);

        self::assertSame(['getUrl(', 'getUrl('], array_column($matches, 'pattern'));
    }

    public function testMismatchScannerFindsTypedLinkNamespacesWithoutFieldHandles(): void
    {
        $patterns = array_column($this->scan([
            'src/TypedLinks.php' => <<<'PHP'
<?php
use lenz\linkfield\models\Link;
$class = 'lenz\\linkfield\\models\\Link';
$legacy = 'typedlinkfield\\models\\Link';
PHP,
        ], []), 'pattern');

        self::assertSame(['lenz\\linkfield', 'lenz\\\\linkfield', 'typedlinkfield'], $patterns);
    }

    public function testMismatchScannerPreservesTypedLinkTextHelperSemanticsInGuidance(): void
    {
        $matches = $this->scan([
            'templates/typed-link.twig' => <<<'TWIG'
{{ entry.cta.getCustomText('Fallback') }}
{{ entry.cta.getDefaultText() }}
{{ entry.cta.getText('Fallback') }}
{{ entry.cta.getIntrinsicText() }}
TWIG,
        ]);
        $replacements = array_column($matches, 'replacement', 'pattern');

        self::assertStringContainsString('fallback', $replacements['getCustomText(']);
        self::assertStringContainsString('field default', $replacements['getDefaultText(']);
        self::assertStringContainsString('fallback', $replacements['getText(']);
        self::assertStringContainsString('intrinsic', $replacements['getIntrinsicText(']);
    }

    public function testMismatchScannerFindsTypedLinkGetterProperties(): void
    {
        $patterns = array_column($this->scan([
            'templates/typed-link.twig' => <<<'TWIG'
{{ entry.cta.allowCustomText }}
{{ entry.cta.allowTarget }}
{{ entry.cta.defaultText }}
{{ entry.cta.enableAriaLabel }}
{{ entry.cta.enableTitle }}
{{ entry.cta.intrinsicText }}
{{ entry.cta.intrinsicUrl }}
{{ entry.cta.linkAttributes }}
{{ entry.cta.rawLinkAttributes }}
{{ entry.cta.linkType }}
{{ entry.cta.ownerSite }}
{{ entry.cta.siteId }}
{{ entry.cta.crossSiteLink }}
{{ entry.cta.empty }}
{{ entry.cta.editorEmpty }}
{{ entry.cta.site }}
TWIG,
        ]), 'pattern');

        foreach ([
            '.allowCustomText',
            '.allowTarget',
            '.defaultText',
            '.enableAriaLabel',
            '.enableTitle',
            '.intrinsicText',
            '.intrinsicUrl',
            '.linkAttributes',
            '.rawLinkAttributes',
            '.linkType',
            '.ownerSite',
            '.siteId',
            '.crossSiteLink',
            '.empty',
            '.editorEmpty',
            '.site',
        ] as $pattern) {
            self::assertContains($pattern, $patterns);
        }
        self::assertSame(1, array_count_values($patterns)['.site']);
    }

    public function testMismatchScannerWarnsThatCrossSiteTargetsAreLossy(): void
    {
        $matches = $this->scan([
            'templates/typed-link.twig' => <<<'TWIG'
{{ entry.cta.getSiteId() }}
{{ entry.cta.linkedSiteId }}
TWIG,
        ]);

        foreach ($matches as $match) {
            self::assertStringContainsString('cannot preserve', $match['reason']);
        }
    }

    public function testMismatchScannerPreservesAttributeHelperOverridesInGuidance(): void
    {
        $matches = $this->scan([
            'templates/typed-link.twig' => <<<'TWIG'
{{ entry.cta.getLinkAttributes({ class: 'button' }) }}
{{ entry.cta.getRawLinkAttributes({ rel: 'external' }) }}
TWIG,
        ]);

        foreach ($matches as $match) {
            self::assertStringContainsString('merge passed overrides', $match['replacement']);
        }
    }

    public function testMismatchScannerFollowsSimpleSourceFieldAliases(): void
    {
        $patterns = array_column($this->scan([
            'templates/typed-link.twig' => <<<'TWIG'
{% set link = entry.cta %}
{{ link.getText() }}
TWIG,
            'src/Template.php' => <<<'PHP'
<?php
$link = $entry->cta;
$link->getTitle();
PHP,
        ]), 'pattern');

        self::assertSame(['getText(', 'getTitle('], $patterns);
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

    private function scan(array $files, array $sourceFieldHandles = ['cta']): array
    {
        $root = sys_get_temp_dir() . '/link-migrator-' . bin2hex(random_bytes(4));
        mkdir($root . '/templates', 0777, true);
        mkdir($root . '/src', 0777, true);
        foreach ($files as $path => $contents) {
            file_put_contents($root . '/' . $path, $contents);
        }

        $previousRoot = Craft::getAlias('@root', false);
        Craft::setAlias('@root', $root);

        try {
            return (new AuditService())->findMismatchReferences($sourceFieldHandles);
        } finally {
            Craft::setAlias('@root', $previousRoot === false ? null : $previousRoot);
            foreach (array_keys($files) as $path) {
                unlink($root . '/' . $path);
            }
            rmdir($root . '/templates');
            rmdir($root . '/src');
            rmdir($root);
        }
    }
}
