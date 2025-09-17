<?php
namespace k7zz\humhub\civicrm\models\forms;

use k7zz\humhub\civicrm\models\CiviCRMSettings;
use Yii;

class SettingsForm extends CiviCRMSettings
{
    public function rules(): array
    {
        return array_merge([
            [['url', 'secret', 'siteKey'], 'required'],
            ['url', 'url', 'defaultScheme' => 'https'],
            ['siteKey', 'string', 'max' => 255],
            ['secret', 'string', 'max' => 255],
            ['checksumField', 'string', 'max' => 255],
            ['contactIdField', 'string', 'max' => 255],
            ['activityIdField', 'string', 'max' => 255],
            [['enableBaseSync', 'autoFullSync', 'dryRun'], 'boolean'],
            ['activityTypeId', 'integer'],
            [['restrictToContactIds'], 'string'],
            [['restrictToContactIds'], 'validateStringList', 'params' => ['type' => 'numeric']],
            [['fieldMapping'], 'string'],
            [['fieldMapping'], 'validateJson'],
            [['fieldMapping'], 'default', 'value' => '{}'],
        ], parent::rules());
    }

    public function attributeLabels(): array
    {
        return array_merge([
            'url' => Yii::t('CivicrmModule.config', 'CiviCRM URL'),
            'siteKey' => Yii::t('CivicrmModule.config', 'CiviCRM Site Key'),
            'secret' => Yii::t('CivicrmModule.config', 'CiviCRM API-Key Secret'),
            'contactIdField' => Yii::t('CivicrmModule.config', 'Profile filed holding user\'s civicrm contact ID'),
            'activityIdField' => Yii::t('CivicrmModule.config', 'Profile field holding user\'s civicrm activity ID'),
            'checksumField' => Yii::t('CivicrmModule.config', 'Profile field holding user\'s civicrm checksum'),
            'activityTypeId' => Yii::t('CivicrmModule.config', 'CiviCRM ActivityTypeID'),
            'enableBaseSync' => Yii::t('CivicrmModule.config', 'Enable Base Sync'),
            'autoFullSync' => Yii::t('CivicrmModule.config', 'Auto Full Sync'),
            'dryRun' => Yii::t('CivicrmModule.config', 'Dry Run (no data will be changed)'),
            'restrictToContactIds' => Yii::t('CivicrmModule.config', 'Restrict running to specified contacts. Use any non numeric as delimiter'),
            'fieldMapping' => Yii::t('CivicrmModule.config', 'Field Mapping HumHub2CiviCRM (JSON)'),
        ], parent::attributeLabels());
    }

    /** speichert bei erfolgreicher Validierung */
    public function save(): bool
    {
        if (!$this->validate()) {
            return false;
        }
        $this->setHumhubSetting('url', $this->url);
        $this->setHumhubSetting('siteKey', $this->siteKey);
        $this->setHumhubSetting('secret', $this->secret);
        $this->setHumhubSetting('contactIdField', $this->contactIdField);
        $this->setHumhubSetting('activityIdField', $this->activityIdField);
        $this->setHumhubSetting('activityTypeId', $this->activityTypeId);
        $this->setHumhubSetting('checksumField', $this->checksumField);
        $this->setHumhubSetting('enableBaseSync', $this->enableBaseSync);
        $this->setHumhubSetting('autoFullSync', $this->autoFullSync);
        $this->setHumhubSetting('dryRun', $this->dryRun);
        $this->setHumhubSetting('restrictToContactIds', $this->restrictToContactIds);
        $this->setHumhubSetting('fieldMapping', $this->fieldMapping);

        return true;
    }

    public function validateJson($attribute, $params)
    {
        json_decode($this->$attribute);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addError($attribute, Yii::t('CivicrmModule.config', 'Invalid JSON.'));
        }
    }

    public function validateStringList($attribute, $params)
    {
        switch ($params['type']) {
            case 'numeric':
                $values = preg_split('/\D+/', $this->$attribute);
                foreach ($values as $value) {
                    if (!is_numeric($value)) {
                        $this->addError($attribute, Yii::t('CivicrmModule.config', 'Only numeric values are allowed.'));
                        return;
                    }
                }
                break;
            default:
                // Unknown type
                return;
        }
    }

}
