<?php

namespace k7zz\humhub\civicrm\controllers;

use k7zz\humhub\civicrm\components\SyncLog;
use Yii;

use humhub\modules\admin\components\Controller;
use k7zz\humhub\civicrm\models\forms\SettingsForm;
use k7zz\humhub\civicrm\services\CiviCRMService;

class ConfigController extends Controller
{
    protected ?CiviCRMService $civiCRMService;

    /**
     * Initializes the controller and the session service.
     */
    public function init()
    {
        parent::init();
        $this->civiCRMService = Yii::createObject(CiviCRMService::class);
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
        SyncLog::info("Manually starting CiviCRM sync from $from by " . (Yii::$app->user->isGuest ? 'guest' : 'user ' . Yii::$app->user->identity->displayName));
        if ($this->civiCRMService->sync($from, true)) {
            $this->view->success(Yii::t('CivicrmModule.config', 'CiviCRM sync initiated successfully.'));
        } else {
            $this->view->error(Yii::t('CivicrmModule.config', 'CiviCRM sync could not be started. Please check the CiviCRM settings.'));
        }
        return $this->redirect(['index']);
    }
}
