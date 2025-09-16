<?php

use yii\bootstrap\ActiveForm;

?>

<div class="panel panel-default">
    <div class="panel-heading"><?= Yii::t('CivicrmModule.config', '<strong>CiviCRM</strong> Integration'); ?>
        <div class="pull-right">
            <a class="btn btn-warning ml-5"
                href="<?= \yii\helpers\Url::to(['/civicrm/config/sync?src=civicrm']) ?>"><?= Yii::t('base', 'Sync from CiviCRM') ?></a>
        </div>
    </div>

    <div class="panel-body">
        <div class="clearfix">
            <h4><?= Yii::t('CivicrmModule.config', 'Settings') ?></h4>
            <div class="help-block">
                <?= Yii::t('CivicrmModule.config', 'On this page you can configure general settings of your CiviCRM integration.') ?>
            </div>
        </div>

        <hr>

        <?php $form = ActiveForm::begin() ?>
        <div class="panel-body">
            <?= $form->field($model, 'url')
                ->textInput(options: ['placeholder' => 'https://civicrm.example.org/']); ?>

            <?= $form->field($model, 'siteKey')
                ->textInput(options: ['placeholder' => 'your-site-key', 'autocomplete' => 'new-password']); ?>

            <?= $form->field($model, 'secret')
                ->passwordInput(['autocomplete' => 'new-password']); ?>

            <div class="text-warning">
                <?= $form->field($model, 'dryRun')->checkbox(); ?>
            </div>

            <?= $form->field($model, 'contactIdField')
                ->textInput(options: ['placeholder' => 'civicrm_id']); ?>

            <?= $form->field($model, 'checksumField')
                ->textInput(options: ['placeholder' => '342422154135145431543543_34324_342443242F']); ?>

            <?= $form->field($model, 'activityIdField')
                ->textInput(options: ['placeholder' => '123456']); ?>

            <?= $form->field($model, 'activityTypeId')
                ->textInput(options: ['placeholder' => '123456']); ?>

            <?= $form->field($model, 'enableBaseSync')->checkbox(); ?>
            <?= $form->field($model, 'autoFullSync')->checkbox(); ?>

            <?= $form->field($model, 'fieldMapping')
                ->textarea(['rows' => 20, 'placeholder' => '{"profile.firstname": "contact.first_name", "profile.beratung": "activity.beratung"}'])
                ->hint(Yii::t('CivicrmModule.config', 'JSON string for field mapping. Example: {"profile.firstname": "contact.first_name", "profile.beratung": "activity.beratung"}')); ?>
        </div>

        <button class="btn btn-primary" data-ui-loader><?= Yii::t('base', 'Save') ?></button>

        <?php ActiveForm::end() ?>
    </div>

</div>