<?php

namespace k7zz\humhub\civicrm;

use humhub\modules\user\models\Profile;
use humhub\modules\user\models\User;
use k7zz\humhub\civicrm\components\SyncLog;
use k7zz\humhub\civicrm\jobs\SyncJob;
use k7zz\humhub\civicrm\services\CiviCRMService;
use Yii;

class Events
{
    private static bool $initialized = false;
    private static CiviCRMService $civiCRMService;

    public static function init()
    {
        if (self::$initialized) {
            return;
        }
        self::$civiCRMService = Yii::createObject(CiviCRMService::class);
        self::$initialized = true;
    }

    /**
     * @param \yii\db\AfterSaveEvent $event
     */
    public static function onUserAfterUpdate($event)
    {
        $user = $event->sender;
        if ($user instanceof User === false) {
            return;
        }
        self::init(); // Ensure the service is initialized
/*        self::$civiCRMService->onChange(
            "user",
            $user->profile,
            $event->changedAttributes
        );
        */
    }

    /**
     * @param \yii\db\AfterSaveEvent $event
     */
    public static function onProfileAfterUpdate($event)
    {
        $profile = $event->sender;
        if ($profile instanceof Profile === false) {
            return;
        }
        self::init(); // Ensure the service is initialized
        if (!self::$civiCRMService->settings->enableOnChangeSync) {
            return;
        }
        self::$civiCRMService->onChange(
            "profile",
            $profile,
            $event->changedAttributes // This are the old values!!
        );
    }

    public static function onCronDailyRun($event)
    {
        self::runDaily();
    }

    public static function runDaily(bool $force = false)
    {
        self::init(); // Ensure the service is initialized
        if (
            !$force &&
            (!self::$civiCRMService->settings->enableBaseSync
                || !self::$civiCRMService->settings->autoFullSync)
        ) {
            return null;
        }
        $module = Yii::$app->getModule('civicrm');
        return Yii::$app->queue->push(new SyncJob([
            'settings' => $module->settings,
            'manual' => $force,
        ]));
    }

}