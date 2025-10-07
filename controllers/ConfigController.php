<?php

namespace k7zz\humhub\civicrm\controllers;

use GuzzleHttp\Psr7\Uri;
use k7zz\humhub\civicrm\components\SyncLog;
use k7zz\humhub\civicrm\Events;
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

    public function actionIndex()
    {
        $model = new SettingsForm();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            $this->view->success(Yii::t('CivicrmModule.config', 'CiviCRM settings saved successfully.'));

            if (Yii::$app->request->post('force-sync')) {
                return $this->redirect(['sync?type=' . Yii::$app->request->post('force-sync')]);

            }
        }

        return $this->render('index', [
            'model' => $model,
        ]);
    }

    public function actionSync($type = CiviCRMService::SRC_CIVICRM)
    {
        SyncLog::info("Manually queuing CiviCRM sync $type by " . (Yii::$app->user->isGuest ? 'guest' : 'user ' . Yii::$app->user->identity->displayName));

        if (in_array($type, [CiviCRMService::SRC_CIVICRM, CiviCRMService::SRC_HUMHUB, CiviCRMService::SRC_BOTH])) {
            $jobId = Yii::$app->queue->push(new SyncJob([
                'from' => $type,
                'manual' => true,
                'settings' => Yii::$app->getModule('civicrm')->settings
            ]));
        } else if ($type === 'daily') {
            $jobId = Events::runDaily(true);
        } else {
            $this->view->error(Yii::t('CivicrmModule.config', 'Unknown sync type {type}', ['type' => $type]));
            return $this->redirect(['index']);
        }
        if ($jobId ?? false) {
            $this->view->success(Yii::t('CivicrmModule.config', 'CiviCRM sync initiated successfully. QueueId: {jobId}', ['jobId' => $jobId]));
        } else {
            $this->view->error(Yii::t('CivicrmModule.config', 'Unknown queue error'));
        }
        return $this->redirect(['index']);
    }
}
