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

        $uid = Yii::$app->user->id ?? '-';
        $rid = Yii::$app->request->headers->get('X-Request-Id') ?? substr(uniqid('', true), -6);
        self::configureLogging("[uid:$uid][rid:$rid]");
    }
    public function getConfigUrl()
    {
        return Url::to(['/civicrm/config/']);
    }

    public static function configureLogging($prefix)
    {

        $target = new FileTarget([
            'logFile' => Yii::getAlias('@runtime/logs/' . SyncLog::LOG_CATEGORY_SYNC . '.log'),
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
}
