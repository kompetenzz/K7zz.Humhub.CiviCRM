<?php

use humhub\commands\CronController;
use humhub\modules\user\models\Profile;
use humhub\modules\user\models\User;
use k7zz\humhub\civicrm\Events;

return [
    'id' => 'civicrm',
    'class' => 'k7zz\humhub\civicrm\Module',
    'namespace' => 'k7zz\humhub\civicrm',
    'events' => [
        ['class' => Profile::class, 'event' => Profile::EVENT_AFTER_UPDATE, 'callback' => [Events::class, 'onProfileAfterUpdate']],
        ['class' => User::class, 'event' => User::EVENT_AFTER_UPDATE, 'callback' => [Events::class, 'onUserAfterUpdate']],
        ['class' => \yii\web\User::class, 'event' => \yii\web\User::EVENT_AFTER_LOGIN, 'callback' => [Events::class, 'onUserAfterLogin']],
        ['class' => CronController::class, 'event' => CronController::EVENT_ON_DAILY_RUN, 'callback' => [Events::class, 'onCronDailyRun']],
    ],
];