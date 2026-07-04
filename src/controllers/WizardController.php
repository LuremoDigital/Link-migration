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
        // The wizard creates fields, writes content, and mutates field layouts,
        // so it is admin-only. Prepare and finalize also change project config,
        // which requireAdmin() additionally guards via allowAdminChanges.
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
        $plugin = LinkMigrator::$plugin;

        try {
            $audit = $plugin->getAudit()->buildAudit();
            $result = $plugin->getFieldMigration()->migrate($audit, ['dryRun' => false, 'force' => true]);

            if ($result->hasErrors()) {
                Craft::$app->getSession()->setFlash('error', 'Prepare completed with errors. Check the CLI reports for details.');
            } else {
                Craft::$app->getSession()->setFlash('notice', 'Native Link fields prepared successfully.');
            }
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setFlash('error', $e->getMessage());
        }

        return $this->redirect(LinkMigrator::HANDLE);
    }

    public function actionMigrateContent(): Response
    {
        $this->requirePostRequest();
        $plugin = LinkMigrator::$plugin;

        try {
            $audit = $plugin->getAudit()->buildAudit();
            $result = $plugin->getContentMigration()->migrate($audit, [
                'dryRun' => false,
                'force' => true,
                'createBackup' => true,
                'batchSize' => 100,
            ]);

            if ($result->hasErrors()) {
                Craft::$app->getSession()->setFlash('error', 'Content migration completed with errors. Check the CLI reports for details.');
            } else {
                Craft::$app->getSession()->setFlash('notice', 'Content migration completed successfully.');
            }
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setFlash('error', $e->getMessage());
        }

        return $this->redirect(LinkMigrator::HANDLE);
    }

    public function actionFinalize(): Response
    {
        $this->requirePostRequest();
        $plugin = LinkMigrator::$plugin;

        try {
            $audit = $plugin->getAudit()->buildAudit();

            // Mirror the CLI mismatch gate (finding 9): cutover breaks templates that still use
            // Hyper-only APIs, so require an explicit acknowledgement when mismatches exist.
            $mismatchCount = count($audit->mismatchReferences);
            $acknowledged = (bool)Craft::$app->getRequest()->getBodyParam('acknowledgeMismatches');
            if ($mismatchCount > 0 && !$acknowledged) {
                Craft::$app->getSession()->setFlash('error', sprintf(
                    '%d unreviewed template mismatch(es) found. Review them and confirm before finalizing.',
                    $mismatchCount
                ));
                return $this->redirect(LinkMigrator::HANDLE);
            }

            $result = $plugin->getCutover()->finalize($audit, ['dryRun' => false, 'force' => true]);

            if ($result->hasErrors()) {
                Craft::$app->getSession()->setFlash('error', 'Finalize completed with errors. Check the CLI reports for details.');
            } else {
                Craft::$app->getSession()->setFlash('notice', 'Field layout cutover completed successfully.');
            }
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setFlash('error', $e->getMessage());
        }

        return $this->redirect(LinkMigrator::HANDLE);
    }
}
