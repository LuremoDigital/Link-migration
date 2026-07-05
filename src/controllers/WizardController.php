<?php

namespace luremo\linkmigrator\controllers;

use Craft;
use craft\web\Controller;
use luremo\linkmigrator\LinkMigrator;
use yii\web\Response;

class WizardController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        $this->requireAdmin(in_array($action->id, ['prepare-fields', 'finalize'], true));

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $plugin = LinkMigrator::$plugin;
        $audit = $plugin->getAudit()->buildAudit();

        return $this->renderTemplate(LinkMigrator::HANDLE . '/index', [
            'plugin' => $plugin,
            'audit' => $audit,
            'statuses' => $plugin->getState()->workflowStatuses($audit),
        ]);
    }

    public function actionPrepareFields(): Response
    {
        $this->requirePostRequest();
        if (!$this->forceConfirmed()) {
            $this->setFailFlash('Confirm the write before preparing native fields.');
            return $this->redirect(LinkMigrator::HANDLE);
        }

        try {
            $plugin = LinkMigrator::$plugin;
            $audit = $plugin->getAudit()->buildAudit();
            $result = $plugin->getFieldMigration()->migrate($audit, [
                'dryRun' => false,
                'force' => true,
            ]);

            if ($result->hasErrors()) {
                $this->setFailFlash(sprintf('Prepare completed with %d error(s).', count($result->errors)));
            } else {
                $this->setSuccessFlash(sprintf(
                    'Prepared %d native field(s). %d skipped.',
                    count($result->migrated),
                    count($result->skipped)
                ));
            }
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());
        }

        return $this->redirect(LinkMigrator::HANDLE);
    }

    public function actionMigrateContent(): Response
    {
        $this->requirePostRequest();
        if (!$this->forceConfirmed()) {
            $this->setFailFlash('Confirm the write before migrating content.');
            return $this->redirect(LinkMigrator::HANDLE);
        }

        try {
            $plugin = LinkMigrator::$plugin;
            $audit = $plugin->getAudit()->buildAudit();
            $result = $plugin->getContentMigration()->migrate($audit, [
                'dryRun' => false,
                'force' => true,
                'createBackup' => true,
                'batchSize' => 100,
            ]);

            $message = sprintf(
                'Migrated %d value(s), skipped %d, warnings %d, errors %d.',
                $result->migratedCount,
                $result->skippedCount,
                $result->warningCount,
                $result->errorCount
            );
            if ($result->hasErrors() || $result->hasWarnings()) {
                $this->setFailFlash($message);
            } else {
                $this->setSuccessFlash($message);
            }
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());
        }

        return $this->redirect(LinkMigrator::HANDLE);
    }

    public function actionFinalize(): Response
    {
        $this->requirePostRequest();
        if (!$this->forceConfirmed()) {
            $this->setFailFlash('Confirm the write before finalizing.');
            return $this->redirect(LinkMigrator::HANDLE);
        }

        try {
            $plugin = LinkMigrator::$plugin;
            $audit = $plugin->getAudit()->buildAudit();
            $mismatchCount = count($audit->mismatchReferences);
            if ($mismatchCount > 0 && !Craft::$app->getRequest()->getBodyParam('acknowledgeMismatches')) {
                $this->setFailFlash(sprintf(
                    '%d template mismatch(es) found. Review them and confirm before finalizing.',
                    $mismatchCount
                ));
                return $this->redirect(LinkMigrator::HANDLE);
            }

            $result = $plugin->getCutover()->finalize($audit, [
                'dryRun' => false,
                'force' => true,
            ]);

            if ($result->hasErrors()) {
                $this->setFailFlash(sprintf('Finalize completed with %d error(s).', count($result->errors)));
            } else {
                $this->setSuccessFlash(sprintf(
                    'Finalized %d field(s). %d skipped.',
                    count($result->finalized),
                    count($result->skipped)
                ));
            }
        } catch (\Throwable $e) {
            $this->setFailFlash($e->getMessage());
        }

        return $this->redirect(LinkMigrator::HANDLE);
    }

    private function forceConfirmed(): bool
    {
        return Craft::$app->getRequest()->getBodyParam('force') === '1';
    }
}
