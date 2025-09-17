<?php
namespace k7zz\humhub\civicrm\jobs;

use k7zz\humhub\civicrm\components\SyncLog;
use k7zz\humhub\civicrm\Module;
use k7zz\humhub\civicrm\services\CiviCRMService;
use humhub\modules\queue\interfaces\ExclusiveJobInterface;
use humhub\modules\queue\LongRunningActiveJob;
use Yii;

class SyncJob extends LongRunningActiveJob implements ExclusiveJobInterface
{
    public static string $id = 'civicrm-sync';
    public $from = CiviCRMService::SRC_CIVICRM;
    public bool $manual = false;
    public $settings = null;

    /**
     * @inhertidoc
     */
    public function getExclusiveJobId()
    {
        return self::$id;
    }

    /**
     * @inhertidoc
     */
    public function run()
    {
        Module::configureLogging();

        SyncLog::info("Starting CiviCRM Sync from {$this->from} (" . ($this->manual ? 'manual' : 'scheduled') . ")");

        $civiCRMService = \Yii::createObject(CiviCRMService::class, [
            $this->settings
        ]);
        if ($civiCRMService->sync($this->from, $this->manual)) {
            SyncLog::info("Sync successfully finished");
        } else {
            SyncLog::error("Sync error. Please check the CiviCRM settings.");
        }
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error)
    {
        return false;
    }
}

