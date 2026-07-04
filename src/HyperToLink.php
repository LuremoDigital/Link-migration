<?php

namespace lm2k\hypertolink;

use Craft;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;
use lm2k\hypertolink\services\AuditService;
use lm2k\hypertolink\services\ContentMigrationService;
use lm2k\hypertolink\services\CutoverService;
use lm2k\hypertolink\services\FieldMigrationService;
use lm2k\hypertolink\services\MappingStrategyService;
use lm2k\hypertolink\services\ReportService;
use lm2k\hypertolink\services\StateService;

class HyperToLink extends Plugin
{
    public const HANDLE = 'link-migrator';

    public bool $hasCpSettings = false;
    public bool $hasCpSection = true;
    public string $schemaVersion = '1.1.0';

    public static self $plugin;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'audit' => AuditService::class,
            'mappingStrategy' => MappingStrategyService::class,
            'fieldMigration' => FieldMigrationService::class,
            'contentMigration' => ContentMigrationService::class,
            'cutover' => CutoverService::class,
            'report' => ReportService::class,
            'state' => StateService::class,
        ]);

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'lm2k\\hypertolink\\console\\controllers';
        } else {
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_CP_URL_RULES,
                static function(RegisterUrlRulesEvent $event) {
                    $event->rules[static::HANDLE] = static::HANDLE . '/wizard/index';
                    $event->rules[static::HANDLE . '/prepare'] = static::HANDLE . '/wizard/prepare-fields';
                    $event->rules[static::HANDLE . '/content'] = static::HANDLE . '/wizard/migrate-content';
                    $event->rules[static::HANDLE . '/finalize'] = static::HANDLE . '/wizard/finalize';
                }
            );
        }
    }

    public function getAudit(): AuditService
    {
        /** @var AuditService */
        return $this->get('audit');
    }

    public function getMappingStrategy(): MappingStrategyService
    {
        /** @var MappingStrategyService */
        return $this->get('mappingStrategy');
    }

    public function getFieldMigration(): FieldMigrationService
    {
        /** @var FieldMigrationService */
        return $this->get('fieldMigration');
    }

    public function getContentMigration(): ContentMigrationService
    {
        /** @var ContentMigrationService */
        return $this->get('contentMigration');
    }

    public function getCutover(): CutoverService
    {
        /** @var CutoverService */
        return $this->get('cutover');
    }

    public function getReport(): ReportService
    {
        /** @var ReportService */
        return $this->get('report');
    }

    public function getState(): StateService
    {
        /** @var StateService */
        return $this->get('state');
    }
}
