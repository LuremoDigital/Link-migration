<?php

namespace luremo\linkmigrator\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use luremo\linkmigrator\LinkMigrator;
use luremo\linkmigrator\models\AuditResult;
use yii\console\ExitCode;

class MigrateController extends Controller
{
    public ?string $field = null;
    public bool $dryRun = false;
    public bool $force = false;
    public bool $verbose = false;
    public bool $createBackup = false;
    public bool $acknowledgeMismatches = false;
    public int $batchSize = 100;
    public bool $applyProjectConfig = true;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'field';
        $options[] = 'dryRun';
        $options[] = 'force';
        $options[] = 'verbose';
        $options[] = 'createBackup';
        $options[] = 'acknowledgeMismatches';
        $options[] = 'batchSize';
        $options[] = 'applyProjectConfig';

        return $options;
    }

    public function optionAliases(): array
    {
        return [
            'f' => 'field',
            'n' => 'dryRun',
            'b' => 'createBackup',
            'v' => 'verbose',
            'p' => 'applyProjectConfig',
        ];
    }

    public function actionAudit(): int
    {
        [, $exitCode] = $this->runAuditStage();
        return $exitCode;
    }

    public function actionFields(): int
    {
        $this->stderr("`fields` is deprecated. Use `prepare-fields`.\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }

    public function actionPrepareFields(): int
    {
        if (!$this->dryRun && !$this->force) {
            $this->stderr("Refusing to prepare native fields without --force.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        [, $exitCode] = $this->runFieldStage();
        return $exitCode;
    }

    public function actionContent(): int
    {
        if (!$this->dryRun && !$this->force) {
            $this->stderr("Refusing to write content without --force.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        [, $exitCode] = $this->runContentStage();
        return $exitCode;
    }

    public function actionAll(): int
    {
        $this->stderr(
            "`all` is no longer part of the recommended production workflow. Run `audit`, `prepare-fields`, `content`, and `finalize` explicitly.\n",
            Console::FG_RED
        );
        return ExitCode::UNSPECIFIED_ERROR;
    }

    public function actionStatus(): int
    {
        $plugin = LinkMigrator::$plugin;
        $audit = $plugin->getAudit()->buildAudit($this->field);

        $statuses = $plugin->getState()->workflowStatuses($audit, $this->field);
        foreach ($statuses as $status) {
            $content = $status['contentSummary'] ?? [];
            $this->stdout(sprintf(
                "%s | phase=%s | target=%s | migrated=%s skipped=%s (empty=%s, already-migrated=%s, already-native=%s, other=%s) warnings=%s errors=%s\n",
                $status['sourceHandle'],
                $status['phase'],
                $status['targetHandle'] ?? 'n/a',
                $content['migratedCount'] ?? 0,
                $content['skippedCount'] ?? 0,
                $content['skippedEmptyCount'] ?? 0,
                $content['skippedAlreadyMigratedCount'] ?? 0,
                $content['skippedAlreadyNativeCount'] ?? 0,
                $content['skippedOtherCount'] ?? 0,
                $content['warningCount'] ?? 0,
                $content['errorCount'] ?? 0,
            ));
        }

        return ExitCode::OK;
    }

    public function actionFinalize(): int
    {
        if (!$this->dryRun && !$this->force) {
            $this->stderr("Refusing to finalize layout cutover without --force.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $plugin = LinkMigrator::$plugin;
        $report = $plugin->getReport()->beginRun('finalize', $this->dryRun, $this->verbose);
        $audit = $plugin->getAudit()->buildAudit($this->field);
        $plugin->getReport()->writePreflight($report, $audit, $this, 'finalize cutover');

        $mismatchCount = count($audit->mismatchReferences);
        if (!$this->dryRun && $mismatchCount > 0 && !$this->acknowledgeMismatches) {
            $this->stderr(sprintf(
                "Refusing to finalize: %d template mismatch(es) found. Review `php craft link-migrator/migrate/mismatches`, then re-run with --acknowledge-mismatches=1.\n",
                $mismatchCount
            ), Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $result = $plugin->getCutover()->finalize($audit, [
            'field' => $this->field,
            'dryRun' => $this->dryRun,
            'force' => $this->force,
        ]);

        $plugin->getReport()->writeCutoverResult($report, $result, $this);
        $this->stdout("Finalize report written to {$report->reportPath}\n", Console::FG_GREEN);

        return $result->hasErrors() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    public function actionRollbackInfo(): int
    {
        $records = LinkMigrator::$plugin->getState()->summaries($this->field);
        $this->stdout("Rollback information is informational only. Hyper is intentionally left installed.\n\n", Console::FG_YELLOW);

        foreach ($records as $record) {
            $this->stdout(sprintf(
                "%s | %s | migrated=%d skipped=%d warnings=%d errors=%d backups=%d last=%s\n",
                $record['action'],
                $record['fieldHandle'],
                $record['migratedCount'],
                $record['skippedCount'],
                $record['warningCount'],
                $record['errorCount'],
                $record['backupCount'],
                $record['dateUpdated'] ?? 'n/a'
            ));
        }

        return ExitCode::OK;
    }

    public function actionMismatches(): int
    {
        $plugin = LinkMigrator::$plugin;
        $report = $plugin->getReport()->beginRun('mismatches', true, $this->verbose);
        $audit = $plugin->getAudit()->buildAudit($this->field);

        $payload = [
            'summary' => [
                'mismatches' => count($audit->mismatchReferences),
            ],
            'mismatches' => $audit->mismatchReferences,
        ];

        $plugin->getReport()->writePayload($report, $payload);

        $this->stdout(sprintf("Potential Hyper API mismatches: %d\n", count($audit->mismatchReferences)), Console::FG_YELLOW);

        foreach ($audit->mismatchReferences as $mismatch) {
            $this->stdout(sprintf(
                "- %s:%d | %s -> %s\n  %s\n  %s\n",
                $mismatch['file'],
                $mismatch['line'],
                $mismatch['pattern'],
                $mismatch['replacement'],
                $mismatch['reason'],
                $mismatch['snippet']
            ));
        }

        $this->stdout("\nMismatch report written to {$report->reportPath}\n", Console::FG_GREEN);

        return count($audit->mismatchReferences) > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function runAuditStage(): array
    {
        $plugin = LinkMigrator::$plugin;
        $report = $plugin->getReport()->beginRun('audit', $this->dryRun, $this->verbose);
        $audit = $plugin->getAudit()->buildAudit($this->field);
        $plugin->getReport()->writeAudit($report, $audit, $this);
        $this->stdout("Audit written to {$report->reportPath}\n", Console::FG_GREEN);

        return [$audit, $audit->hasBlockingIssues() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK];
    }

    private function runFieldStage(?AuditResult $audit = null): array
    {
        $plugin = LinkMigrator::$plugin;
        $audit ??= $plugin->getAudit()->buildAudit($this->field);
        $report = $plugin->getReport()->beginRun('prepare-fields', $this->dryRun, $this->verbose);
        $plugin->getReport()->writePreflight($report, $audit, $this, 'prepare native fields');

        $result = $plugin->getFieldMigration()->migrate($audit, [
            'field' => $this->field,
            'dryRun' => $this->dryRun,
            'force' => $this->force,
            'verbose' => $this->verbose,
        ]);

        $plugin->getReport()->writeFieldResult($report, $result, $this);
        $this->stdout("Field migration report written to {$report->reportPath}\n", Console::FG_GREEN);

        return [$result, $result->hasErrors() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK];
    }

    private function runContentStage(?AuditResult $audit = null): array
    {
        $plugin = LinkMigrator::$plugin;
        $audit ??= $plugin->getAudit()->buildAudit($this->field);
        $report = $plugin->getReport()->beginRun('content', $this->dryRun, $this->verbose);
        $plugin->getReport()->writePreflight($report, $audit, $this, 'content migration');

        $result = $plugin->getContentMigration()->migrate($audit, [
            'field' => $this->field,
            'dryRun' => $this->dryRun,
            'force' => $this->force,
            'verbose' => $this->verbose,
            'createBackup' => $this->createBackup,
            'batchSize' => max(1, $this->batchSize),
        ]);

        $plugin->getReport()->writeContentResult($report, $result, $this);
        $this->stdout("Content migration report written to {$report->reportPath}\n", Console::FG_GREEN);

        return [$result, ($result->hasErrors() || $result->hasWarnings()) ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK];
    }
}
