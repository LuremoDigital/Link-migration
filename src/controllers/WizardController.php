<?php

namespace luremo\linkmigrator\controllers;

use Craft;
use craft\web\Controller;
use luremo\linkmigrator\LinkMigrator;
use yii\web\Response;

class WizardController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

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
        Craft::$app->getSession()->setFlash('warning', 'Run prepare-fields from the CLI with --force=1.');

        return $this->redirect(LinkMigrator::HANDLE);
    }

    public function actionMigrateContent(): Response
    {
        $this->requirePostRequest();
        Craft::$app->getSession()->setFlash('warning', 'Run content migration from the CLI with --force=1.');

        return $this->redirect(LinkMigrator::HANDLE);
    }

    public function actionFinalize(): Response
    {
        $this->requirePostRequest();
        Craft::$app->getSession()->setFlash('warning', 'Run finalize from the CLI with --force=1.');

        return $this->redirect(LinkMigrator::HANDLE);
    }
}
