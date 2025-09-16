<?php

namespace k7zz\humhub\civicrm\controllers;

use k7zz\humhub\civicrm\components\SyncLog;
use k7zz\humhub\civicrm\jobs\SyncJob;
use Yii;

use humhub\modules\admin\components\Controller;
use k7zz\humhub\civicrm\models\forms\SettingsForm;
use k7zz\humhub\civicrm\services\CiviCRMService;

class ConfigController extends Controller
{
    /**
     * Initializes the controller and the session service.
     */
    public function init()
    {
        parent::init();
    }

    /**
     * @return string
     * @throws \Exception
     */
    public function actionIndex()
    {
        $model = new SettingsForm();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            $this->view->success(Yii::t('CivicrmModule.config', 'CiviCRM settings saved successfully.'));
        }

        return $this->render('index', [
            'model' => $model,
        ]);
    }

    public function actionSync($from = CiviCRMService::SRC_CIVICRM)
    {
        SyncLog::info("Manually queuing CiviCRM sync from $from by " . (Yii::$app->user->isGuest ? 'guest' : 'user ' . Yii::$app->user->identity->displayName));

        $jobId = Yii::$app->queue->push(new SyncJob([
            'from' => $from,
            'manual' => true,
            'settings' => Yii::$app->getModule('civicrm')->settings
        ]));
        $this->view->success(Yii::t('CivicrmModule.config', 'CiviCRM sync initiated successfully. QueueId: {jobId}', ['jobId' => $jobId]));
        $i = 0;
        while (Yii::$app->queue->isWaiting($jobId)) {
            sleep(1);
            $i++;
            SyncLog::info("Waiting for job $jobId to complete... {$i}s elapsed");
            if ($i >= 30) {
                SyncLog::info("Job $jobId is still running after {$i}s, breaking wait loop.");
                break;
            }
        }
        SyncLog::info("Job $jobId is done?" . (Yii::$app->queue->isDone($jobId) ? ' Yes' : ' No'));

        return $this->redirect(['index']);
    }
}
