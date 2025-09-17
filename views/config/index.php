<?php

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
                    <?= $form->field($model, 'autoFullSync')
                        ->checkbox()
                        ->hint(Yii::t('CivicrmModule.config', 'Enable automatic scheduled daily synchronization of profile data.')); ?>

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
                    </div>
                    <div class="row mt-3">
                        <h3><?= Yii::t('CivicrmModule.config', 'Actions') ?></h3>
                        <p>
                            <?= Yii::t('CivicrmModule.config', 'Clicking this actions will <b>save</b> settings!'); ?>
                        </p>
                        <div class="well">
                            <p>
                                <?= Yii::t('CivicrmModule.config', 'You can manually trigger a sync from CiviCRM to HumHub.'); ?>
                            </p>
                            <button class="btn btn-primary pull-right" type="submit" name="sync-from-civi" value="1"
                                data-ui-loader><?= Yii::t('CivicrmModule.config', 'Sync from CiviCRM') ?></button>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<button class="btn btn-primary" data-ui-loader><?= Yii::t('base', 'Save') ?></button>

<?php ActiveForm::end() ?>
</div>

</div>