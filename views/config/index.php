<?php

use humhub\libs\Html;
use k7zz\humhub\civicrm\Module;
use yii\bootstrap\ActiveForm;

?>

<div class="panel panel-default">
    <div class="panel-heading">

        <h1><?= Yii::t('CivicrmModule.config', '<strong>CiviCRM</strong> Integration'); ?></h1>

        <p><?= Yii::t('CivicrmModule.config', 'Please configure all settings carefully and double check with your profile fields and CiviCRM settings.') ?>
            <?= Yii::t('CivicrmModule.config', 'You may use the dry-run feature alongside with contact ID restriction and manual triggering.') ?>
        </p>

        <p class="text-small"><?= Yii::t(
            'CivicrmModule.config',
            'This module writes a dedicated log file in <strong>{path}</strong>',
            ['path' => Module::getLogFilePath()]
        ) ?></p>
    </div>

    <div class="panel-body">
        <?php $form = ActiveForm::begin() ?>
        <div class="panel-body">
            <div class="row">
                <div class="col-md-7">
                    <h3><?= Yii::t('CivicrmModule.config', 'API Settings') ?></h3>
                    <?= $form->field($model, 'url')
                        ->textInput(options: ['placeholder' => 'https://civicrm.example.org/']); ?>

                    <?= $form->field($model, 'siteKey')
                        ->textInput(options: ['placeholder' => 'your-site-key', 'autocomplete' => 'new-password']); ?>

                    <?= $form->field($model, 'secret')
                        ->passwordInput(['autocomplete' => 'new-password']); ?>

                    <h3><?= Yii::t('CivicrmModule.config', 'References Settings') ?></h3>

                    <?= $form->field($model, 'contactIdField')
                        ->textInput(options: ['placeholder' => 'civicrm_id']); ?>

                    <?= $form->field($model, 'checksumField')
                        ->textInput(options: ['placeholder' => '342422154135145431543543_34324_342443242F']); ?>

                    <?= $form->field($model, 'activityIdField')
                        ->textInput(options: ['placeholder' => '123456']); ?>

                    <?= $form->field($model, 'activityTypeId')
                        ->textInput(options: ['placeholder' => '123456']); ?>

                    <h3><?= Yii::t('CivicrmModule.config', 'Sync Settings') ?></h3>
                    <?= $form->field($model, 'enableBaseSync')
                        ->checkbox()
                        ->hint(Yii::t('CivicrmModule.config', 'Enable synchronization of activity id and checksum.')); ?>
                    <?= $form->field($model, 'enableOnChangeSync')
                        ->checkbox()
                        ->hint(Yii::t('CivicrmModule.config', 'Enable direct synchronization of profile changes to CiviCRM.')); ?>
                    <?= $form->field($model, 'autoFullSync')
                        ->checkbox()
                        ->hint(Yii::t('CivicrmModule.config', 'Enable automatic scheduled daily synchronization of profile data.')); ?>
                    <?= $form->field($model, 'strictDisable')
                        ->checkbox()
                        ->hint(Yii::t('CivicrmModule.config', 'Disable users without CiviCRM activity (Network profile).')); ?>

                    <?= $form->field($model, 'retryOnMissingField')
                        ->textInput(options: ['placeholder' => 'field_name, e.g. profile.street'])
                        ->hint(Yii::t('CivicrmModule.config', 'If this field has not value, contact will be synced. Use to partially rerun.')); ?>

                    <?= $form->field($model, 'fieldMapping')
                        ->textarea(['rows' => 20, 'placeholder' => '{"profile.firstname": "contact.first_name", "profile.beratung": "activity.beratung"}'])
                        ->hint(Yii::t('CivicrmModule.config', 'JSON string for field mapping. Example: {"profile.firstname": "contact.first_name", "profile.beratung": "activity.beratung"}')); ?>
                </div>
                <div class="col-md-5">
                    <div class="row text-warning mt-3">
                        <h3>
                            <?= Yii::t('CivicrmModule.config', 'Testing/debugging.') ?>
                        </h3>

                        <?= $form->field($model, 'dryRun')->checkbox(); ?>
                        <?= $form->field($model, 'restrictToContactIds')
                            ->textarea(['rows' => 3, 'placeholder' => '10000,12000,1234'])
                            ->hint(Yii::t('CivicrmModule.config', 'Comma-separated list of contact IDs to restrict actions to.')); ?>
                        <div class="row">
                            <div class="col-md-6">
                                <?= $form->field($model, 'limit')
                                    ->textInput(['type' => 'number', 'min' => 0, 'placeholder' => '0'])
                                    ->hint(Yii::t('CivicrmModule.config', 'Limit number of contacts to process per run (0 = no limit).')); ?>
                            </div>
                            <div class="col-md-6">
                                <?= $form->field($model, 'offset')
                                    ->textInput(['type' => 'number', 'min' => 0, 'placeholder' => '0'])
                                    ->hint(Yii::t('CivicrmModule.config', 'Offset for contact processing (0 = start from beginning).')); ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3><?= Yii::t('CivicrmModule.config', 'Actions') ?></h3>
                        <p>
                            <?= Yii::t('CivicrmModule.config', 'Clicking this actions will <b>save</b> settings!'); ?>
                        </p>
                        <div class="row">
                            <div class="well col-md-6">
                                <p>
                                    <?= Yii::t('CivicrmModule.config', 'You can manually trigger a sync from CiviCRM to HumHub.'); ?>
                                </p>
                                <button class="btn btn-primary pull-right" type="submit" name="force-sync"
                                    value="civicrm"><?= Yii::t('CivicrmModule.config', 'Sync from CiviCRM') ?></button>

                            </div>
                            <div class="well col-md-6">
                                <p>
                                    <?= Yii::t('CivicrmModule.config', 'You can manually trigger a full sync as if it would run daily.'); ?>
                                </p>
                                <button class="btn btn-primary pull-right" type="submit" name="force-sync"
                                    value="daily"><?= Yii::t('CivicrmModule.config', 'Run daily sync') ?></button>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <button class="btn btn-primary" data-ui-loader name="sync-from-civi" value="0">
                <?= Yii::t('base', 'Save') ?></button>
            <?php ActiveForm::end() ?>
        </div>
    </div>
</div>


<?php
$enableId = Html::getInputId($model, 'enableBaseSync');
$strictId = Html::getInputId($model, 'strictDisable');

$js = <<<JS
function toggleStrictDisable() {
    var baseSync = $('#$enableId');
    var strict   = $('#$strictId').closest('.form-group');

    if (baseSync.is(':checked')) {
        strict.show();
    } else {
        strict.hide();
        // Optional: auch abhaken deaktivieren
        $('#$strictId').prop('checked', false);
    }
}
toggleStrictDisable();
$('#$enableId').on('change', toggleStrictDisable);
JS;

$this->registerJs($js, yii\web\View::POS_READY);
?>