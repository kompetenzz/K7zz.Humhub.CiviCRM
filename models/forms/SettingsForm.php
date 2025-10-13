<?php
namespace k7zz\humhub\civicrm\models\forms;

use humhub\modules\queue\driver\Sync;
use k7zz\humhub\civicrm\components\SyncLog;
use k7zz\humhub\civicrm\models\CiviCRMSettings;
use Yii;

class SettingsForm extends CiviCRMSettings
{
    public function rules(): array
    {
        return array_merge([
            [['url', 'secret', 'siteKey', 'contactIdField', 'activityIdField', 'activityTypeId', 'checksumField', 'fieldMapping'], 'required'],
            ['url', 'url', 'defaultScheme' => 'https'],
            ['siteKey', 'string', 'max' => 255],
            ['secret', 'string', 'max' => 255],
            ['checksumField', 'string', 'max' => 255],
            ['retryOnMissingField', 'string', 'max' => 255],
            ['contactIdField', 'string', 'max' => 255],
            ['activityIdField', 'string', 'max' => 255],
            [['enableBaseSync', 'autoFullSync', 'enableOnChangeSync', 'dryRun', 'strictDisable'], 'boolean'],
            [['activityTypeId', 'limit', 'offset'], 'integer'],
            [['restrictToContactIds'], 'string'],
            [['restrictToContactIds'], 'validateStringList', 'params' => ['type' => 'numeric']],
            [['includeGroups', 'excludeGroups'], 'each', 'rule' => ['integer']],
            [['includeGroups', 'excludeGroups'], 'safe'],
            [['fieldMapping'], 'string'],
            [['fieldMapping'], 'validateJson'],
            [['fieldMapping'], 'default', 'value' => '{}'],
            [['contactCustomFieldGroups', 'activityCustomFieldGroups'], 'string'],
        ], parent::rules());
    }

    public function attributeLabels(): array
    {
        return array_merge([
            'url' => Yii::t('CivicrmModule.config', 'CiviCRM URL'),
            'siteKey' => Yii::t('CivicrmModule.config', 'CiviCRM Site Key'),
            'secret' => Yii::t('CivicrmModule.config', 'CiviCRM API-Key Secret'),
            'contactIdField' => Yii::t('CivicrmModule.config', 'Profile filed holding user\'s civicrm contact Id'),
            'activityIdField' => Yii::t('CivicrmModule.config', 'Profile field holding user\'s civicrm activity Id'),
            'checksumField' => Yii::t('CivicrmModule.config', 'Profile field holding user\'s civicrm checksum'),
            'activityTypeId' => Yii::t('CivicrmModule.config', 'CiviCRM ActivityTypeId'),
            'strictDisable' => Yii::t('CivicrmModule.config', 'Disable users without CiviCRM activity.'),
            'enableBaseSync' => Yii::t('CivicrmModule.config', 'Enable Base Sync'),
            'enableOnChangeSync' => Yii::t('CivicrmModule.config', 'Enable On-Change profile synchronization.'),
            'autoFullSync' => Yii::t('CivicrmModule.config', 'Auto Full Sync'),
            'dryRun' => Yii::t('CivicrmModule.config', 'Dry Run (no data will be changed)'),
            'limit' => Yii::t('CivicrmModule.config', 'Limit'),
            'offset' => Yii::t('CivicrmModule.config', 'Offset'),
            'restrictToContactIds' => Yii::t('CivicrmModule.config', 'Restrict running to specified contacts. Use any non numeric as delimiter'),
            'includeGroups' => Yii::t('CivicrmModule.config', 'Include only contacts from these groups'),
            'excludeGroups' => Yii::t('CivicrmModule.config', 'Exclude contacts from these groups'),
            'retryOnMissingField' => Yii::t('CivicrmModule.config', 'Field which has to be empty to include in run.'),
            'fieldMapping' => Yii::t('CivicrmModule.config', 'Field Mapping HumHub2CiviCRM (JSON)'),
            'contactCustomFieldGroups' => Yii::t('CivicrmModule.config', 'Contact Custom Fields Group names (comma separated)'),
            'activityCustomFieldGroups' => Yii::t('CivicrmModule.config', 'Activity Custom Fields Group names (comma separated)'),
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
        $this->setHumhubSetting('enableOnChangeSync', $this->enableOnChangeSync);
        $this->setHumhubSetting('strictDisable', $this->strictDisable);
        $this->setHumhubSetting('autoFullSync', $this->autoFullSync);
        $this->setHumhubSetting('dryRun', $this->dryRun);
        $this->setHumhubSetting('limit', $this->limit);
        $this->setHumhubSetting('offset', $this->offset);
        $this->setHumhubSetting('restrictToContactIds', $this->restrictToContactIds);
        $this->includeGroupsString = implode(',', $this->includeGroups);
        $this->setHumhubSetting('includeGroupsString', $this->includeGroupsString);
        $this->excludeGroupsString = implode(',', $this->excludeGroups);
        $this->setHumhubSetting('excludeGroupsString', $this->excludeGroupsString);
        $this->setHumhubSetting('retryOnMissingField', $this->retryOnMissingField);
        $this->setHumhubSetting('fieldMapping', $this->fieldMapping);
        $this->setHumhubSetting('contactCustomFieldGroups', $this->contactCustomFieldGroups);
        $this->setHumhubSetting('activityCustomFieldGroups', $this->activityCustomFieldGroups);
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
