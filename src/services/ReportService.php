<?php

namespace luremo\linkmigrator\services;

use Craft;
use craft\base\Component;
use craft\console\Controller;
use luremo\linkmigrator\models\AuditResult;
use luremo\linkmigrator\models\ContentMigrationResult;
use luremo\linkmigrator\models\CutoverResult;
use luremo\linkmigrator\models\FieldMigrationResult;
use luremo\linkmigrator\models\MigrationReport;

class ReportService extends Component
{
    public function beginRun(string $action, bool $dryRun = false): MigrationReport
    {
        $runId = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $baseDir = Craft::getAlias('@storage/runtime/link-migrator');
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0775, true);
        }

        return new MigrationReport([
            'runId' => $runId,
            'action' => $action,
            'dryRun' => $dryRun,
            'reportPath' => $baseDir . DIRECTORY_SEPARATOR . $runId . '-' . $action . '.log',
            'jsonPath' => $baseDir . DIRECTORY_SEPARATOR . $runId . '-' . $action . '.json',
        ]);
    }

    public function writeAudit(MigrationReport $report, AuditResult $audit, ?Controller $controller = null): void
    {
        $payload = [
            'summary' => $this->statusCounts($audit) + [
                'references' => count($audit->codeReferences),
                'mismatches' => count($audit->mismatchReferences),
            ],
            'fields' => array_map(function ($field) {
                return [
                    'fieldId' => $field->fieldId,
                    'uid' => $field->uid,
                    'handle' => $field->handle,
                    'name' => $field->name,
                    'multi' => $field->multi,
                    'allowedHyperTypes' => $field->allowedHyperTypes,
                    'customFieldLayouts' => $field->customFieldLayouts,
                    'warnings' => $field->warnings,
                    'mapping' => $field->mapping->toArray(),
                ];
            }, $audit->fields),
            'references' => $audit->codeReferences,
            'mismatches' => $audit->mismatchReferences,
            'notes' => $audit->notes,
        ];

        $this->persist($report, $payload);
        $controller?->stdout($this->renderSummary($payload['summary']));
    }

    public function writePreflight(MigrationReport $report, AuditResult $audit, ?Controller $controller, string $label): void
    {
        if ($controller === null) {
            return;
        }

        $summary = $this->statusCounts($audit);

        $controller->stdout(strtoupper($label) . "\n");
        $controller->stdout($this->renderSummary($summary));
        $controller->stdout("Warnings:\n");
        $controller->stdout("- Back up the database and project config before non-dry runs.\n");
        $controller->stdout("- Hyper remains installed through the staged workflow; do not uninstall it until finalize is complete.\n");
        $controller->stdout("- Prepare creates new native fields instead of overwriting existing Hyper fields.\n");
        $controller->stdout("- Finalize only updates field layouts; v1 does not delete Hyper fields automatically.\n\n");
    }

    public function writeFieldResult(MigrationReport $report, FieldMigrationResult $result, ?Controller $controller = null): void
    {
        $payload = [
            'summary' => [
                'migrated' => count($result->migrated),
                'skipped' => count($result->skipped),
                'warnings' => count($result->warnings),
                'errors' => count($result->errors),
            ],
            'migrated' => $result->migrated,
            'skipped' => $result->skipped,
            'warnings' => $result->warnings,
            'errors' => $result->errors,
            'mappings' => $result->mappings,
        ];

        $this->persist($report, $payload);
        $controller?->stdout($this->renderSummary($payload['summary']));
    }

    public function writeContentResult(MigrationReport $report, ContentMigrationResult $result, ?Controller $controller = null): void
    {
        $payload = [
            'summary' => [
                'migrated' => $result->migratedCount,
                'skipped' => $result->skippedCount,
                'warnings' => $result->warningCount,
                'errors' => $result->errorCount,
                'backups' => $result->backupCount,
            ],
            'detailLimit' => $result->detailLimit(),
            'truncatedDetails' => [
                'migrated' => max(0, $result->migratedCount - count($result->migrated)),
                'skipped' => max(0, $result->skippedCount - count($result->skipped)),
                'warnings' => max(0, $result->warningCount - count($result->warnings)),
                'errors' => max(0, $result->errorCount - count($result->errors)),
                'backups' => max(0, $result->backupCount - count($result->backups)),
            ],
            'migrated' => $result->migrated,
            'skipped' => $result->skipped,
            'warnings' => $result->warnings,
            'errors' => $result->errors,
            'backups' => $result->backups,
        ];

        $this->persist($report, $payload);
        $controller?->stdout($this->renderSummary($payload['summary']));
    }

    public function writeMismatches(MigrationReport $report, AuditResult $audit): void
    {
        $payload = [
            'summary' => [
                'mismatches' => count($audit->mismatchReferences),
            ],
            'mismatches' => $audit->mismatchReferences,
        ];

        $this->persist($report, $payload);
    }

    public function writeCutoverResult(MigrationReport $report, CutoverResult $result, ?Controller $controller = null): void
    {
        $payload = [
            'summary' => [
                'finalized' => count($result->finalized),
                'skipped' => count($result->skipped),
                'errors' => count($result->errors),
            ],
            'finalized' => $result->finalized,
            'skipped' => $result->skipped,
            'errors' => $result->errors,
        ];

        $this->persist($report, $payload);
        $controller?->stdout($this->renderSummary($payload['summary']));
    }

    private function statusCounts(AuditResult $audit): array
    {
        return [
            'fields' => count($audit->fields),
            'supported' => count(array_filter($audit->fields, fn($field) => $field->mapping->status === 'supported')),
            'partial' => count(array_filter($audit->fields, fn($field) => $field->mapping->status === 'partial')),
            'unsupported' => count(array_filter($audit->fields, fn($field) => $field->mapping->status === 'unsupported')),
        ];
    }

    private function persist(MigrationReport $report, array $payload): void
    {
        $payload = [
            'run' => [
                'runId' => $report->runId,
                'action' => $report->action,
                'dryRun' => $report->dryRun,
            ],
        ] + $payload;

        file_put_contents($report->jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        file_put_contents($report->reportPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private function renderSummary(array $summary): string
    {
        $lines = [];
        foreach ($summary as $key => $value) {
            $lines[] = sprintf("%s: %s", $key, $value);
        }

        return implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
    }
}
