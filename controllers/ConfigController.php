<?php

namespace k7zz\humhub\civicrm\controllers;

use GuzzleHttp\Psr7\Uri;
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

    public function actionIndex()
    {
        $model = new SettingsForm();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            $this->view->success(Yii::t('CivicrmModule.config', 'CiviCRM settings saved successfully.'));
        }

        if (Yii::$app->request->post('sync-from-civi')) {
            return $this->redirect(['sync?from=' . CiviCRMService::SRC_CIVICRM]);

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

        return $this->redirect(['index']);
    }
}
