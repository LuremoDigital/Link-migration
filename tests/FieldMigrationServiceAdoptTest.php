<?php

declare(strict_types=1);

namespace luremo\linkmigrator\tests;

use luremo\linkmigrator\models\AuditResult;
use luremo\linkmigrator\models\FieldAudit;
use luremo\linkmigrator\models\FieldMapping;
use luremo\linkmigrator\models\MappingDecision;
use luremo\linkmigrator\services\FieldMigrationService;
use luremo\linkmigrator\services\StateService;
use PHPUnit\Framework\TestCase;

final class FieldMigrationServiceAdoptTest extends TestCase
{
    public function testAdoptsConventionTargetAndRecordsMapping(): void
    {
        $state = $this->stateService();
        $service = $this->service($state, [
            'ctaLinkNative' => ['id' => 42, 'uid' => 'target-uid', 'handle' => 'ctaLinkNative', 'types' => ['url', 'entry']],
        ]);

        $result = $service->adoptPrepared($this->audit(['ctaLink']), []);

        self::assertSame([], $result->errors);
        self::assertSame([], $result->skipped);
        self::assertSame([], $result->warnings);
        self::assertSame('adopt', $result->migrated[0]['mode']);
        self::assertSame('ctaLinkNative', $result->migrated[0]['target']);
        self::assertSame([
            'sourceFieldId' => 1,
            'sourceFieldUid' => 'ctaLink-uid',
            'sourceHandle' => 'ctaLink',
            'targetFieldId' => 42,
            'targetFieldUid' => 'target-uid',
            'targetHandle' => 'ctaLinkNative',
        ], $state->savedMappings[0]);
    }

    public function testSkipsFieldWhoseMappingAlreadyExistsWithoutResettingPhase(): void
    {
        $existing = new FieldMapping([
            'sourceFieldId' => 1,
            'sourceFieldUid' => 'ctaLink-uid',
            'sourceHandle' => 'ctaLink',
            'targetFieldId' => 42,
            'targetFieldUid' => 'target-uid',
            'targetHandle' => 'ctaLinkNative',
            'phase' => FieldMapping::PHASE_READY_TO_FINALIZE,
        ]);
        $state = $this->stateService(['ctaLink-uid' => $existing]);
        $service = $this->service($state, [
            'ctaLinkNative' => ['id' => 42, 'uid' => 'target-uid', 'handle' => 'ctaLinkNative', 'types' => ['url']],
        ]);

        $result = $service->adoptPrepared($this->audit(['ctaLink']), []);

        self::assertSame([], $result->migrated);
        self::assertSame('Mapping already recorded in this environment.', $result->skipped[0]['reason']);
        self::assertSame([], $state->savedMappings);
    }

    public function testReadoptsWhenRecordedTargetFieldIsMissing(): void
    {
        $existing = new FieldMapping([
            'sourceFieldId' => 1,
            'sourceFieldUid' => 'ctaLink-uid',
            'sourceHandle' => 'ctaLink',
            'targetFieldId' => 99,
            'targetFieldUid' => 'stale-uid',
            'targetHandle' => 'ctaLinkOld',
            'phase' => FieldMapping::PHASE_PREPARED,
        ]);
        $state = $this->stateService(['ctaLink-uid' => $existing]);
        $service = $this->service($state, [
            'ctaLinkNative' => ['id' => 42, 'uid' => 'target-uid', 'handle' => 'ctaLinkNative', 'types' => ['url']],
        ]);

        $result = $service->adoptPrepared($this->audit(['ctaLink']), []);

        self::assertSame('adopt', $result->migrated[0]['mode']);
        self::assertSame('ctaLinkNative', $state->savedMappings[0]['targetHandle']);
    }

    public function testSkipsWhenNoConventionTargetExists(): void
    {
        $state = $this->stateService();
        $service = $this->service($state, []);

        $result = $service->adoptPrepared($this->audit(['ctaLink']), []);

        self::assertSame([], $result->errors);
        self::assertSame([], $result->migrated);
        self::assertStringContainsString('No native Link field found at `ctaLinkNative`', $result->skipped[0]['reason']);
        self::assertSame([], $state->savedMappings);
    }

    public function testErrorsWhenMultipleConventionCandidatesExist(): void
    {
        $state = $this->stateService();
        $service = $this->service($state, [
            'ctaLinkNative' => ['id' => 41, 'uid' => 'other-uid', 'handle' => 'ctaLinkNative', 'types' => ['url']],
            'ctaLinkNative2' => ['id' => 42, 'uid' => 'target-uid', 'handle' => 'ctaLinkNative2', 'types' => ['url']],
        ]);

        $result = $service->adoptPrepared($this->audit(['ctaLink']), []);

        self::assertSame([], $result->migrated);
        self::assertStringContainsString('Multiple candidate native Link fields found', $result->errors[0]['reason']);
        self::assertStringContainsString('ctaLinkNative, ctaLinkNative2', $result->errors[0]['reason']);
        self::assertSame([], $state->savedMappings);
    }

    public function testAdoptsNumberedTargetWhenConventionHandleIsNotALinkField(): void
    {
        $state = $this->stateService();
        $service = $this->service(
            $state,
            ['ctaLinkNative2' => ['id' => 42, 'uid' => 'target-uid', 'handle' => 'ctaLinkNative2', 'types' => ['url']]],
            ['ctaLinkNative'] // exists, but is not a Link field
        );

        $result = $service->adoptPrepared($this->audit(['ctaLink']), []);

        self::assertSame([], $result->errors);
        self::assertSame('ctaLinkNative2', $result->migrated[0]['target']);
        self::assertSame('ctaLinkNative2', $state->savedMappings[0]['targetHandle']);
    }

    public function testWarnsWhenTargetDoesNotAllowMappedLinkTypes(): void
    {
        $state = $this->stateService();
        $service = $this->service($state, [
            'ctaLinkNative' => ['id' => 42, 'uid' => 'target-uid', 'handle' => 'ctaLinkNative', 'types' => ['url']],
        ]);
        $audit = $this->audit(['ctaLink'], ['url', 'entry']);

        $result = $service->adoptPrepared($audit, []);

        self::assertSame('adopt', $result->migrated[0]['mode']);
        self::assertStringContainsString('does not allow link type(s) the mapping needs: entry', $result->warnings[0]['warnings'][0]);
    }

    public function testWarnsOnRerunWhenRecordedTargetNoLongerAllowsMappedTypes(): void
    {
        $existing = new FieldMapping([
            'sourceFieldId' => 1,
            'sourceFieldUid' => 'ctaLink-uid',
            'sourceHandle' => 'ctaLink',
            'targetFieldId' => 42,
            'targetFieldUid' => 'target-uid',
            'targetHandle' => 'ctaLinkNative',
            'phase' => FieldMapping::PHASE_PREPARED,
        ]);
        $state = $this->stateService(['ctaLink-uid' => $existing]);
        $service = $this->service($state, [
            'ctaLinkNative' => ['id' => 42, 'uid' => 'target-uid', 'handle' => 'ctaLinkNative', 'types' => ['url']],
        ]);

        $result = $service->adoptPrepared($this->audit(['ctaLink'], ['url', 'entry']), []);

        self::assertSame('Mapping already recorded in this environment.', $result->skipped[0]['reason']);
        self::assertStringContainsString('does not allow link type(s) the mapping needs: entry', $result->warnings[0]['warnings'][0]);
        self::assertSame([], $state->savedMappings);
    }

    public function testErrorsWhenExplicitTargetIsMissing(): void
    {
        $state = $this->stateService();
        $service = $this->service($state, []);

        $result = $service->adoptPrepared($this->audit(['ctaLink']), ['target' => 'customLink']);

        self::assertSame([], $result->migrated);
        self::assertStringContainsString('`customLink` is not an existing native Link field', $result->errors[0]['reason']);
    }

    public function testRejectsExplicitTargetForMultipleSourceFields(): void
    {
        $service = $this->service($this->stateService(), []);

        $this->expectException(\InvalidArgumentException::class);
        $service->adoptPrepared($this->audit(['ctaLink', 'footerLink']), ['target' => 'customLink']);
    }

    public function testDryRunReportsWithoutSaving(): void
    {
        $state = $this->stateService();
        $service = $this->service($state, [
            'ctaLinkNative' => ['id' => 42, 'uid' => 'target-uid', 'handle' => 'ctaLinkNative', 'types' => ['url']],
        ]);

        $result = $service->adoptPrepared($this->audit(['ctaLink']), ['dryRun' => true]);

        self::assertSame('dry-run', $result->migrated[0]['mode']);
        self::assertSame([], $state->savedMappings);
    }

    public function testSkipsUnsupportedFields(): void
    {
        $state = $this->stateService();
        $service = $this->service($state, []);
        $audit = new AuditResult([
            'fields' => [
                new FieldAudit([
                    'fieldId' => 1,
                    'uid' => 'multi-uid',
                    'handle' => 'multiLink',
                    'name' => 'Multi Link',
                    'mapping' => new MappingDecision([
                        'status' => MappingDecision::STATUS_UNSUPPORTED,
                        'unsupportedReasons' => ['Multiple links are not supported.'],
                    ]),
                ]),
            ],
        ]);

        $result = $service->adoptPrepared($audit, []);

        self::assertSame(['Multiple links are not supported.'], $result->skipped[0]['reason']);
        self::assertSame([], $state->savedMappings);
    }

    /**
     * @param array<string, FieldMapping> $existingByUid
     */
    private function stateService(array $existingByUid = []): StateService
    {
        return new class($existingByUid) extends StateService {
            /** @var array[] */
            public array $savedMappings = [];

            public function __construct(private readonly array $existingByUid)
            {
                parent::__construct();
            }

            public function getFieldMapping(string $sourceFieldUid): ?FieldMapping
            {
                return $this->existingByUid[$sourceFieldUid] ?? null;
            }

            public function savePreparedFieldMapping(array $mapping): FieldMapping
            {
                $this->savedMappings[] = $mapping;

                return new FieldMapping(array_merge($mapping, [
                    'phase' => FieldMapping::PHASE_PREPARED,
                ]));
            }
        };
    }

    /**
     * @param array<string, array{id: int, uid: string, handle: string, types: string[]}> $linkFieldsByHandle
     * @param string[] $nonLinkHandles handles occupied by fields that are not native Link fields
     */
    private function service(StateService $state, array $linkFieldsByHandle, array $nonLinkHandles = []): FieldMigrationService
    {
        return new class($state, $linkFieldsByHandle, $nonLinkHandles) extends FieldMigrationService {
            public function __construct(
                private readonly StateService $stateService,
                private readonly array $linkFieldsByHandle,
                private readonly array $nonLinkHandles,
            ) {
                parent::__construct();
            }

            protected function state(): StateService
            {
                return $this->stateService;
            }

            protected function fieldHandleExists(string $handle): bool
            {
                return isset($this->linkFieldsByHandle[$handle])
                    || in_array($handle, $this->nonLinkHandles, true);
            }

            protected function findAdoptableTarget(string $handle): ?array
            {
                return $this->linkFieldsByHandle[$handle] ?? null;
            }
        };
    }

    /**
     * @param string[] $handles
     * @param string[] $craftLinkTypes
     */
    private function audit(array $handles, array $craftLinkTypes = ['url']): AuditResult
    {
        return new AuditResult([
            'fields' => array_map(static fn(string $handle) => new FieldAudit([
                'fieldId' => 1,
                'uid' => $handle . '-uid',
                'handle' => $handle,
                'name' => ucfirst($handle),
                'mapping' => new MappingDecision([
                    'status' => MappingDecision::STATUS_SUPPORTED,
                    'craftLinkTypes' => $craftLinkTypes,
                ]),
            ]), $handles),
        ]);
    }
}
