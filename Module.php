<?php

namespace k7zz\humhub\civicrm;

use k7zz\humhub\civicrm\components\SyncLog;
use k7zz\humhub\civicrm\services\CiviCRMService;
use Yii;
use humhub\components\Module as BaseModule;
use yii\helpers\Url;
use yii\log\FileTarget;

class Module extends BaseModule
{
    public $guid = 'civicrm';                     // ganz wichtig
    public $controllerNamespace = __NAMESPACE__ . '\controllers';

    public function init()
    {
        parent::init();
        Yii::$container->set(CiviCRMService::class);


        self::configureLogging();
    }

    public function getConfigUrl()
    {
        return Url::to(['/civicrm/config/']);
    }

    public static function configureLogging()
    {

        $uid = Yii::$app->has('user') ? Yii::$app->user->id ?? 'system/guest' : 'console';
        $prefix = $uid;

        $target = new FileTarget([
            'logFile' => self::getLogFilePath(),
            'categories' => [SyncLog::LOG_CATEGORY_SYNC],
            'levels' => ['error', 'warning', 'info'], // bei Bedarf 'trace' ergänzen
            'logVars' => [],                          // $_SERVER etc. weglassen
            'maxFileSize' => 10240,                   // 10 MB
            'maxLogFiles' => 10,                      // Rotation
            'exportInterval' => 1,                    // sofort schreiben (nützlich bei CLI)
            'prefix' => function ($message) use ($prefix) {
                return "$prefix ";
            },
        ]);
        Yii::$app->log->targets[SyncLog::LOG_CATEGORY_SYNC] = $target;
    }

    public static function getLogFilePath(): string
    {
        return Yii::getAlias('@runtime/logs/' . SyncLog::LOG_CATEGORY_SYNC . '.log');
    }
}
