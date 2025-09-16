<?php
namespace k7zz\humhub\civicrm\services;

use humhub\modules\user\models\User;
use humhub\modules\user\models\Profile;
use k7zz\humhub\civicrm\components\SyncLog;
use k7zz\humhub\civicrm\lib\FieldMapping;
use k7zz\humhub\civicrm\models\CiviCRMSettings;
use humhub\libs\HttpClient;
use Yii;
use yii\helpers\Url;

class CiviCRMService
{
    public const SRC_HUMHUB = 'humhub';
    public const SRC_CIVICRM = 'civicrm';
    public const SRC_BOTH = 'both';
    public const HUMHUB_DATA_SRC_PROFILE = 'profile';
    public const HUMHUB_DATA_SRC_USER = 'user';
    public const HUMHUB_DATA_SRC_ACCOUNT = 'account';


    private const API_VERSION = 'v4';
    private const API_PATH = '/civicrm/ajax/api4/';
    private array $allowedActivityStati = [
        'Active' => 9
    ];
    private HttpClient $httpClient;
    private CiviCRMSettings $settings;
    public function __construct()
    {
        $this->settings = Yii::createObject(type: CiviCRMSettings::class);

        if ($this->settings->url && $this->settings->secret && $this->settings->siteKey) {
            $this->prepareAPI();
        }

    }

    public function prepareAPI()
    {
        if (!$this->settings->url || !$this->settings->secret || !$this->settings->siteKey) {
            throw new \Exception("CiviCRM settings are not properly configured. Can't prepare API" . Url::to(['/civicrm/config/']));
        }
        $this->httpClient = new HttpClient([
            'baseUrl' => rtrim($this->settings->url, '/') . self::API_PATH,
            'requestConfig' => [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    "X-Civi-Auth" => "Bearer {$this->settings->secret}",
                    "X-Civi-Key" => $this->settings->siteKey
                ]
            ]
        ]);
    }

    private function isPrepared(): bool
    {
        return isset($this->httpClient);
    }

    /**
     * Construct the URL for the CiviCRM API4 endpoint like Entity/action
     * 
     * @param string $entity
     * @param string $action
     * @return string
     */
    private function getEndpoint(string $entity, string $action): string
    {
        return ucFirst(strtolower("{$entity}/{$action}"));
    }

    /**
     * 
     * Calling api4 using yii2 http client
     * 
     * (always by using post method)
     * 
     * @param string $entity
     * @param string $action
     * @param array $params
     * @return array
     */
    private function call(string $entity, string $action, array $params = []): array
    {
        if (!$this->isPrepared()) {
            $this->prepareAPI();
        }
        $request = $this->httpClient
            ->createRequest()
            ->setMethod('POST')
            ->setUrl($this->getEndpoint($entity, $action))
            ->setData([
                'params' => json_encode($params)
            ]);
        $response = $request
            ->send();
        if ($response->isOk) {
            if (!empty($response->data['error_message'])) {
                Yii::error("CiviCRM API call failed: {$response->data['error_message']}. \n
                                | {$entity}.{$action} with params: " . json_encode($params));
                return []; // Return an empty array as a placeholder
            }
            return $response->data['values'] ?? []; // Return the 'values' key if it exists
        }
        Yii::error("CiviCRM API call failed with status {$response->statusCode}: {$response->content}. \n
            | {$entity}.{$action} with params: " . json_encode($params));

        return []; // Return an empty array as a placeholder
    }

    private function get(string $entity, array $where = [], int $limit = 10, int $offset = 0): array
    {
        if (count($where) && !is_array($where[0])) {
            $where = [$where]; // Ensure where is an array of conditions
        }
        $params['where'] = $where;
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        return $this->call($entity, 'get', $params);
    }

    private function genChecksum(int $contactId): ?string
    {
        $params = [
            'contactId' => $contactId,
            'checkPermissions' => true
        ];
        $result = $this->call('Contact', 'getChecksum', $params);
        return $result[0]['checksum'] ?? null; // Return the checksum or null if not found
    }

    private function validateChecksum(int $contactId, string $checksum): bool
    {
        $params = [
            'contactId' => $contactId,
            'checksum' => $checksum,
            'checkPermissions' => true
        ];
        $result = $this->call('Contact', 'validateChecksum', $params);
        return (bool) $result[0]['valid']; // Return the checksum or null if not found
    }

    private function parseMethodName(string $name): array|null
    {
        $result = preg_split('/(?=[A-Z])/', $name, 2);
        if (count($result) < 2) {
            return null; // Not a valid method name
        }
        return $result;
    }

    public function update(string $entity, int $id, array $values = []): array
    {
        $params['values'] = $values;
        $params['where'] = [
            ['id', '=', $id]
        ];
        return $this->call($entity, 'update', $params);
    }

    public function create(string $entity, array $values = []): array
    {
        $params['values'] = $values;
        return $this->call($entity, 'create', $params);
    }

    private function getSubEntity(string $entity, int $contactId, ?array $where): array|null
    {
        if (!$contactId) {
            throw new \InvalidArgumentException("Contact ID is required for fetching sub-entity.");
        }
        $params = [
            'where' => [
                ['contact_id', '=', $contactId]
            ]
        ];
        if ($where) {
            foreach ($where as $field => $value) {
                $params['where'][] = [$field, '=', $value];
            }
        }
        $results = $this->call($entity, 'get', $params);
        if (count($results) > 1) {
            Yii::error("Multiple sub-entities found for contact ID {$contactId} in entity {$entity}. Returning first result.");
        }
        return $results[0] ?? null; // Return the first result or an empty array if
    }

    private function updateSubEntity(
        string $entity,
        int $contactId,
        string $civicrmField,
        mixed $value,
        ?array $additionalParams = null
    ): array {
        if (!$contactId || !$civicrmField) {
            throw new \InvalidArgumentException("Contact ID and CiviCRM field are required for update.");
        }
        // If exists, update sub-entity
        $subEntity = $this->getSubEntity($entity, $contactId, $additionalParams);
        if ($subEntity) {
            return $this->update($entity, $subEntity['id'], [
                $civicrmField => $value
            ]);
        }

        // If no sub-entity exists, create it
        return $this->create($entity, array_merge([
            'contact_id' => $contactId,
            $civicrmField => $value
        ], $additionalParams ?? []));
    }

    /**
     * Magic method to handle dynamic method calls for CiviCRM actions.
     * 
     * This allows calling methods like getContact, updateContact, etc.
     * 
     * @param string $name
     * @param array $parameters
     * @return array|null
     * @throws \BadMethodCallException
     */
    function __call($name, $parameters): array|null
    {
        $callParts = $this->parseMethodName($name);
        if ($callParts === null) {
            throw new \BadMethodCallException("Method name '{$name}' is not valid.");
        }
        $action = $callParts[0];
        $entity = $callParts[1];
        switch ($action) {
            case 'get':
                return $this->get(
                    $entity,
                    $parameters[0] ?? [],
                    $parameters[1] ?? 10,
                    $parameters[2] ?? 0
                );
            case 'single':
                $candidates = $this->get(
                    $entity,
                    [
                        'id',
                        '=',
                        $parameters[0] ?? 0
                    ],
                    1,
                    0
                );
                return $candidates[0] ?? null;
            case 'update':
                $id = $parameters[0] ?? 0;
                $values = $parameters[1] ?? [];
                if (!$id || empty($values)) {
                    throw new \InvalidArgumentException("ID and values needed  for update action.");
                }
                return $this->update(
                    $entity,
                    $id,
                    $values
                );
            default:
                throw new \BadMethodCallException("Action '{$action}' is not supported.");
        }
    }

    private function ensureCiviActivity(array $params): void
    {
    }

    /**
     * Get the latest activity for a contact with the configured activity type and allowed stati.
     * if (!available) return freshest with other status to disable account
     * @return array|null
     */
    private function getActivityFromContact(?int $contactId): ?array
    {
        if (!$contactId) {
            return null; // Contact ID is null, cannot fetch activity
        }
        // Get latest activity of $contactid and $this->activitytypeid with status active
        $params = [
            'where' => [
                ['source_record_id', '=', $contactId],
                ['activity_type_id', '=', $this->settings->activityTypeId]
            ],
            'orderBy' => [
                'created_date' => 'DESC',
            ]
        ];
        $activities = $this->call('activity', 'get', $params);
        if (!count($activities) == 1) {
            return null;
        }
        if (count($activities) > 1) {
            foreach ($activities as $activity) {
                if (in_array($activity['status_id'], $this->allowedActivityStati)) {
                    return $activity;
                }
            }
        }
        return $activities[0];
    }

    private function isProfileEnabled($activity): bool
    {
        if (!$activity) {
            return false;
        }
        return in_array($activity['status_id'], $this->allowedActivityStati);
    }

    public function daily(): void
    {
        // Logic to be executed daily, e.g., syncing users or cleaning up data
        if ($this->settings->enableBaseSync) {
            $this->syncBases();
        }
        if ($this->settings->autoFullSync) {
            $this->syncUsers();
        }
    }

    public function sync(string $from = self::SRC_CIVICRM, bool $manual = false): bool
    {
        $okay = true;
        if ($this->settings->enableBaseSync) {
            $okay = $this->syncBases();
        }

        if ($this->settings->autoFullSync || $manual) {
            $okay = $okay && $this->syncUsers($from);
        }
        return $okay;
    }

    /**
     * Summary of syncBase
     * Sync civicrm base data like checksum, activity id and enabled state.
     * @param \humhub\modules\user\models\User $user
     * @return bool
     */
    public function syncBase(User $user): bool
    {
        SyncLog::info("Base syncing user {$user->id}");
        $profile = $user->profile;
        $contactId = $profile->{$this->settings->contactIdField} ?? null;
        if (!$contactId) {
            return true; // No contact ID, nothing to sync
        }

        $civicrmContact = $this->singleContact($contactId);
        if ($civicrmContact) {
            $save = false;
            // Renew checksum
            if (!$this->validateChecksum($contactId, $profile->{$this->settings->checksumField})) {
                $checksum = $this->genChecksum($contactId);
                if ($checksum && $profile->{$this->settings->checksumField} !== $checksum) {
                    $profile->{$this->settings->checksumField} = $checksum;
                    $save = true;
                    SyncLog::info("Updated checksum (CiviCRM Contact ID {$contactId}).");
                }
            }

            // Sync activity ID and account status
            $activity = $this->getActivityFromContact($contactId);
            $activityId = $activity['id'] ?? null;
            if ($activityId) {
                if ($profile->{$this->settings->activityIdField} !== $activityId) {
                    $profile->{$this->settings->activityIdField} = $activityId;
                    SyncLog::info("Updated activity to ID {$activityId}.");
                    $save = true;
                }
                $profileEnabled = $this->isProfileEnabled($activity);
                $userEnabled = $user->status === User::STATUS_ENABLED;
                if ($userEnabled !== $profileEnabled) {
                    $user->status = $this->isProfileEnabled($activity) ? User::STATUS_ENABLED : User::STATUS_DISABLED;
                    SyncLog::info("Adjusted account status to {$user->status}.");
                    $save = true;
                }
            } else {
                $user->status = User::STATUS_DISABLED;
                SyncLog::info("Disabling account because no activity in CiviCRM.");
                $save = true;
            }
            if ($save) {
                $profile->save();
                $user->save();
            }
        }
        return true;
    }

    private function getConnectedUsers(): array
    {
        return User::find()
            ->joinWith('profile')
            ->where(['<>', $this->settings->contactIdField, 0])
            ->all();
    }
    public function syncBases(): bool
    {
        SyncLog::info("Syncing base data from CiviCRM to HumHub for all connected users.");
        $users = $this->getConnectedUsers();
        foreach ($users as $user) {
            if (!$this->syncBase($user))
                return false;
        }
        return true;
    }

    public function onChange(string $eventSrc, Profile $profile, array $valuesBeforeChange): void
    {
        if (!$eventSrc || !count($valuesBeforeChange)) {
            return;
        }

        $contactId = $profile->{$this->settings->contactIdField} ?? null;
        if (!$contactId) {
            return;
        }
        $user = $profile->getUser()->one();

        $activityId = $profile->{$this->settings->activityIdField} ?? null;

        // get activity if null
        if (!$activityId) {
            $activity = $this->getActivityFromContact($contactId);
            if ($activity) {
                $activityId = $activity['id'];
                if ($this->isProfileEnabled($activity)) {
                    $user->status = User::STATUS_ENABLED;
                    Yii::error("Enabled user {$profile->user->id} due to activity status {$activity['status_id']}.", 'civicrm');
                } else {
                    // Disable account if activity is not in allowed stati
                    $user->status = User::STATUS_DISABLED;
                    Yii::error("Disabled user {$profile->user->id} due to activity status {$activity['status_id']}.", 'civicrm');
                }

            }
            // FIXME: Else create
        }

        $currentValues = [];
        foreach ($valuesBeforeChange as $field => $value) {
            $currentValues[$field] = $profile->$field;
        }
        $params = [];
        // Field config pattern:
        //   "profile.firstname": "contact.first_name",
        //   "profile.organisation_art": "activity.custom_641",
        foreach ($this->settings->fieldMappings->mappings as $mapping) {
            if (!$mapping->isSrc($eventSrc)) {
                continue; // Skip if the source does not match
            }
            if (!array_key_exists($mapping->humhubField, $currentValues) || !$currentValues[$mapping->humhubField]) {
                continue; // Skip if the field was not changed
            }

            // Do not gather fields for sub entities
            // This is used for fields like phones, emails, etc. where the entity is not the main entity
            // but a sub-entity like phone, email, etc.
            if ($mapping->isSubEntity()) {
                if (!$mapping->civiEntity || !$mapping->civiField) {
                    Yii::error("Invalid CiviCRM field definition for {$mapping->humhubField}, missing entity and/or field keys: " . json_encode($mapping));
                    continue; // Skip invalid definitions
                }
                $this->updateSubEntity($mapping->civiEntity, $contactId, $mapping->civiField, $currentValues[$mapping->humhubField], $mapping->params);
                continue;
            }

            if (!array_key_exists($mapping->civiEntity, $params)) {
                $params[$mapping->civiEntity] = [];
            }
            $params[$mapping->civiEntity][$mapping->civiField] = $currentValues[$mapping->humhubField];
        }

        if (empty($params)) {
            return; // No changes to sync
        }

        // Update main entities with multiple fields
        foreach ($params as $entity => $targetParams) {
            switch ($entity) {
                case 'contact':
                    $this->updateContact($contactId, $targetParams);
                    break;
                case 'activity':
                    $this->updateActivity($activityId, $targetParams);
                    break;
                default:
                    break; // Unknown entity, skip
            }
        }

    }

    /**
     * Summary of syncUser
     * @param \humhub\modules\user\models\User $user
     * @return void
     * 
     * handle json field mapping like
     * {
  "profile.firstname": "contact.first_name",
  "profile.lastname": "contact.last_name",
  "profile.title": "contact.formal_title",
  "profile.organisation_art": "activity.custom_641",
  "profile.organisation_art_sonstige": "activity.custom_642",
  "profile.expertise": "activity.custom_645",
  "profile.expertise_other_selection": "activity.custom_648",
  "profile.bundesland": "activity.custom_646",
  "profile.ueber2": "activity.custom_647",
  "profile.url_linkedin": {
       "entity": "website",
      "params": {
          "website_type_id": 16
       },
      "field": "url"
   },
  "profile.phone_work": {
       "entity": "phone",
      "field": "phone",
      "params": {
          "location_type_id": 20
       }
   },
  "profile.phone_private": {
       "entity": "phone",
      "field": "phone",
      "params": {
          "location_type_id": 1
       }
   },
  "profile.phone_mobile": {
       "entity": "phone",
      "field": "phone",
      "params": {
          "location_type_id": 21
       }
   },
  "profile.email": {
       "entity": "email",
      "field": "email",
      "params": {
          "location_type_id": 20
       }
   }
}
     */
    public function syncUser(User $user, string $from = self::SRC_CIVICRM): bool
    {
        SyncLog::info("Syncing user {$user->id} from source {$from}");
        $profile = $user->profile;
        $contactId = $profile->{$this->settings->contactIdField} ?? null;
        if (!$contactId) {
            return true; // No contact ID, nothing to sync
        }
        $civicrmContact = $this->singleContact($contactId);
        if (!$civicrmContact) {
            SyncLog::error("CiviCRM contact with ID {$contactId} not found for user {$user->id} ({$user->email}).");
            return false;
        }
        $activityId = $profile->{$this->settings->activityIdField} ?? null;
        $activity = $activityId
            ? $this->singleActivity($activityId)
            : $this->getActivityFromContact($contactId);

        // Sync fields based on mapping
        $params = [];
        // Field config pattern:
        //   "profile.firstname": "contact.first_name",
        //   "profile.organisation_art": "activity.custom_641",
        $saveHumhub = false;
        foreach ($this->settings->fieldMappings->mappings as $mapping) {
            switch ($from) {
                case self::SRC_HUMHUB:
                    break;
                case self::SRC_CIVICRM:
                    // Update humhub from civicrm
                    $humhubValue = $this->getHumhubValue($user, $mapping->humhubField);
                    $civiValue = $this->getCiviCRMValue($civicrmContact, $mapping);
                    SyncLog::info("Comparing HumHub value '{$humhubValue}' with CiviCRM value '{$civiValue}' for field '{$mapping->humhubField}'");
                    if ($humhubValue != $civiValue) {
                        // Update HumHub field if different
                        if ($this->setHumhubValue($user, $mapping->humhubField, $civiValue)) {
                            SyncLog::info("Updated HumHub field '{$mapping->humhubField}' for user {$user->id}");
                            $saveHumhub = true;
                        } else {
                            SyncLog::error("Failed to update HumHub field '{$mapping->humhubField}' for user {$user->id}");
                        }
                    }
                    break;
                case self::SRC_BOTH:
                default:
                    // No filtering
                    break;
            }
        }
        if ($saveHumhub) {
            $profile->save();
            $user->save();
        }
        return true;
    }

    public function syncUsers(string $from = self::SRC_CIVICRM)
    {
        // Logic to sync all users with CiviCRM
        // This could involve fetching all users from the database and updating their CiviCRM records
        SyncLog::info("Syncing all users from source {$from}");
        $users = $this->getConnectedUsers();
        foreach ($users as $user) {
            if (!$this->syncUser($user, $from))
                return false;
        }
        return true;
    }

    private function getHumhubValue($user, $field)
    {
        [$dataSrc, $fieldName] = explode('.', $field);
        return match ($dataSrc) {
            self::HUMHUB_DATA_SRC_USER => $user->$fieldName ?? null,
            self::HUMHUB_DATA_SRC_PROFILE => $user->profile->$fieldName ?? null,
            self::HUMHUB_DATA_SRC_ACCOUNT => $user->account->$fieldName ?? null,
            default => null,
        };
    }

    private function setHumhubValue($user, $field, $value): bool
    {
        [$dataSrc, $fieldName] = explode('.', $field);
        switch ($dataSrc) {
            case self::HUMHUB_DATA_SRC_USER:
                $user->$fieldName = $value;
                return true;
            case self::HUMHUB_DATA_SRC_PROFILE:
                $user->profile->$fieldName = $value;
                return true;
            case self::HUMHUB_DATA_SRC_ACCOUNT:
                $user->account->$fieldName = $value;
                return true;
            default:
                return false;
        }
    }

    public function getCiviCRMValue(array $civicrmContact, FieldMapping $mapping)
    {
        if (!$civicrmContact || empty($mapping->civiField)) {
            return null;
        }
        // Handle sub-entities
        if ($mapping->isSubEntity()) {
            if (empty($mapping->civiEntity)) {
                Yii::error("Invalid CiviCRM field definition for {$mapping->humhubField}, missing entity key: " . json_encode($mapping));
                return null; // Invalid definition
            }
            $subEntity = $this->getSubEntity($mapping->civiEntity, $civicrmContact['id'], $mapping->params);
            return $subEntity[$mapping->civiField] ?? null;
        }

        // Main entity field
        return $civicrmContact[$mapping->civiField] ?? null;
    }

    private function notifyAdmins($severity, string $message): void
    {
        // Notify admins about the CiviCRM sync issues
        Yii::error("CiviCRM sync issue: {$message}", 'civicrm');
        // You can implement a more sophisticated notification system here
    }
}