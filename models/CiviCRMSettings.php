<?php

namespace k7zz\humhub\civicrm\models;
use k7zz\humhub\civicrm\lib\FieldMappingCollection;
use Yii;
use yii\base\Model;

class CiviCRMSettings extends Model
{
    protected $humhubSettings;

    public string $url = '';
    public string $secret = '';
    public string $siteKey = '';
    public string $contactIdField = 'civicrm_contact_id';
    public string $activityIdField = 'civicrm_activity_id';
    public int $activityTypeId = 0;
    public string $humhubUserIdCiviCRMField = '';
    public string $checksumField = 'civicrm_checksum';
    public bool $enableBaseSync = true;
    public bool $enableOnChangeSync = true;
    public bool $enableOnLoginSync = true;
    public bool $autoFullSync = false;
    public bool $dryRun = false;
    public $limit = 0;
    public $offset = 0;
    public bool $strictDisable = false;
    public string $restrictToContactIds = "";
    public string $retryOnMissingField = '';
    public string $fieldMapping = '{}';
    public string $contactCustomFieldGroups = '';
    public string $activityCustomFieldGroups = '';

    public string $includeGroupsString = '';
    public $includeGroups = '';
    public string $excludeGroupsString = '';
    public $excludeGroups = '';
    public ?FieldMappingCollection $fieldMappings = null;

    public function __construct($humhubSettings = null)
    {
        if ($humhubSettings !== null) {
            $this->setHumhubSettings($humhubSettings);
        }
        parent::__construct();
    }

    public function init()
    {
        if ($this->humhubSettings === null) {
            $this->setHumhubSettings(Yii::$app->getModule('civicrm')->settings);
        }
        parent::init();
        $this->url = $this->getHumhubSetting('url') ?? '';
        $this->siteKey = $this->getHumhubSetting('siteKey') ?? '';
        $this->secret = $this->getHumhubSetting('secret') ?? '';
        $this->contactIdField = $this->getHumhubSetting('contactIdField') ?? 'civicrm_id';
        $this->checksumField = $this->getHumhubSetting('checksumField') ?? 'civicrm_checksum';
        $this->enableBaseSync = $this->getHumhubSetting('enableBaseSync') ?? true;
        $this->enableOnChangeSync = $this->getHumhubSetting('enableOnChangeSync') ?? false;
        $this->enableOnLoginSync = $this->getHumhubSetting('enableOnLoginSync') ?? false;
        $this->autoFullSync = $this->getHumhubSetting('autoFullSync') ?? false;
        $this->dryRun = $this->getHumhubSetting('dryRun') ?? false;
        $this->limit = $this->getHumhubSetting('limit') ?? 0;
        $this->offset = $this->getHumhubSetting('offset') ?? 0;
        $this->strictDisable = $this->getHumhubSetting('strictDisable') ?? false;
        $this->restrictToContactIds = $this->getHumhubSetting('restrictToContactIds') ?? '';
        $this->includeGroupsString = $this->getHumhubSetting('includeGroupsString') ?? '';
        $this->includeGroups = array_filter(array_map('trim', explode(',', $this->includeGroupsString)));
        $this->excludeGroupsString = $this->getHumhubSetting('excludeGroupsString') ?? '';
        $this->excludeGroups = array_filter(array_map('trim', explode(',', $this->excludeGroupsString)));
        $this->activityIdField = $this->getHumhubSetting('activityIdField') ?? 'civicrm_network_id';
        $this->activityTypeId = $this->getHumhubSetting('activityTypeId') ?? 0;
        $this->humhubUserIdCiviCRMField = $this->getHumhubSetting('humhubUserIdCiviCRMField') ?? '';
        $this->retryOnMissingField = $this->getHumhubSetting('retryOnMissingField') ?? '';
        $this->fieldMapping = $this->getHumhubSetting('fieldMapping') ?? '{}';
        $this->fieldMappings = new FieldMappingCollection($this->fieldMapping);
        $this->contactCustomFieldGroups = $this->getHumhubSetting('contactCustomFieldGroups') ?? '';
        $this->activityCustomFieldGroups = $this->getHumhubSetting('activityCustomFieldGroups') ?? '';
    }

    protected function setHumhubSetting($property, $value)
    {
        $this->humhubSettings->set($this->getHumhubSettingsName($property), $value);
    }

    protected function getHumhubSetting($property)
    {
        return $this->humhubSettings->get($this->getHumhubSettingsName($property));
    }

    protected function getHumhubSettingsName($property)
    {
        return "civicrm" . ucfirst($property);
    }

    public function getHumhubSettings()
    {
        return $this->humhubSettings;
    }

    public function setHumhubSettings($settings)
    {
        $this->humhubSettings = $settings;
    }


}