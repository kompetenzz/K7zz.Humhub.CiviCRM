<?php

namespace k7zz\humhub\civicrm;

use humhub\modules\user\models\Profile;
use humhub\modules\user\models\User;
use k7zz\humhub\civicrm\services\CiviCRMService;
use Yii;

class Events
{
    private static bool $initialized = false;
    private static CiviCRMService $civiCRMService;

    public function __construct()
    {
    }

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
        self::$civiCRMService->onChange(
            "profile",
            $profile,
            $event->changedAttributes // This are the old values!!
        );

    }


    public static function onCronDailyRun($event)
    {
        /** @var Module $module */
        $module = Yii::$app->getModule('civicrm');
        $settings = $module->settings;

        if (!$settings->get('enableBaseSync', true)) {
            return;
        }
        self::init(); // Ensure the service is initialized
        self::$civiCRMService->daily();
    }

}