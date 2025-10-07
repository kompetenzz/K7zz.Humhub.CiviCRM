<?php
namespace k7zz\humhub\civicrm\services;

use humhub\modules\user\models\ProfileField;
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

    public const ALLOWED_CIVICRM_ENTITIES = [
        'contact',
        'email',
        'phone',
        'address',
        'activity',
        'website',
    ];


    private const API_VERSION = 'v4';
    private const API_PATH = '/civicrm/ajax/api4/';
    private array $allowedActivityStati = [
        'Active' => 9
    ];
    private array $writingCiviActions = ['create', 'update', 'delete'];
    private HttpClient $httpClient;
    public CiviCRMSettings $settings;
    public function __construct($humhubSettings = null)
    {
        $this->settings = Yii::createObject(type: CiviCRMSettings::class, params: [$humhubSettings]);

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

    private function dryRun(): bool
    {
        return $this->settings->dryRun;
    }

    private function activityEnabled(): bool
    {
        return $this->settings->activityTypeId > 0 && !empty($this->settings->activityIdField);
    }

    private function restrictToContactIds(): bool
    {
        return !empty($this->settings->restrictToContactIds);
    }

    private function getEnabledContactIds(): array
    {
        $stringList = $this->settings->restrictToContactIds;
        if (!$stringList) {
            return [];
        }
        $values = preg_split('/\D+/', $stringList);
        return array_filter($values, 'is_numeric');
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
        if (!in_array(strtolower($entity), self::ALLOWED_CIVICRM_ENTITIES)) {
            throw new \InvalidArgumentException("Entity '{$entity}' is not allowed.");
        }
        if ($action === 'get') {
            $params['select'] ??= ['*', 'custom.*'];
        }
        SyncLog::debug("Calling CiviCRM API: {$entity}.{$action} with params: " . json_encode($params));
        $request = $this->httpClient
            ->createRequest()
            ->setMethod('POST')
            ->setUrl($this->getEndpoint($entity, $action))
            ->setData([
                'params' => json_encode($params)
            ]);
        if ($this->dryRun() && in_array($action, $this->writingCiviActions)) {
            SyncLog::info("Dry run enabled - skipping {$entity}.{$action} with params: " . json_encode($params));
            return []; // Return an empty array as a placeholder
        }
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
        SyncLog::error("CiviCRM API call failed with status {$response->statusCode}: {$response->content}. \n
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

    private function validateChecksum(int $contactId, string|null $checksum = "none"): bool
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

    private function updateEntities(int $contactId, int $activityId, array $params): bool
    {
        if (empty($params)) {
            return true; // No changes to sync
        }

        $okay = true;
        // Update main entities with multiple fields
        foreach ($params as $entity => $targetParams) {
            if (!$okay) {
                break; // Stop processing if any update failed
            }
            switch ($entity) {
                case 'contact':
                    $okay = $okay && $this->updateContact($contactId, $targetParams);
                    break;
                case 'activity':
                    $okay = $okay && $this->updateActivity($activityId, $targetParams);
                    break;
                case 'subEntities':
                    foreach ($targetParams as $subEntity) {
                        $okay = $okay && $this->updateSubEntity(
                            $subEntity['entity'],
                            $contactId,
                            $subEntity['field'],
                            $subEntity['value'],
                            $subEntity['params'] ?? null
                        );
                    }
                    break;
                default:
                    $okay = false;
                    break; // Unknown entity, skip
            }
        }
        return $okay;
    }

    private function getSubEntity(string $entity, int $contactId, ?array $where): array|null
    {
        if (!$contactId) {
            throw new \InvalidArgumentException("Contact Id is required for fetching sub-entity.");
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
            Yii::error("Multiple sub-entities found for contact Id {$contactId} in entity {$entity}. Returning first result.");
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
            throw new \InvalidArgumentException("Contact Id and CiviCRM field are required for update.");
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
                    throw new \InvalidArgumentException("Id and values needed  for update action.");
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
            return null; // Contact Id is null, cannot fetch activity
        }
        // Get latest activity of $contactid and $this->activitytypeid with status active
        $params = [
            'where' => [
                [
                    'OR',
                    [
                        ['assignee_contact_id', '=', $contactId],
                        ['source_record_id', '=', $contactId],
                        ['target_contact_id', '=', $contactId]
                    ]
                ],
                ['activity_type_id', '=', $this->settings->activityTypeId]
            ],
            'orderBy' => [
                'created_date' => 'DESC',
            ]
        ];
        $activities = $this->call('activity', 'get', $params);
        if (count($activities) == 0) {
            SyncLog::warning("No activity found for contact Id {$contactId}.");
            return null;
        }
        if (count($activities) > 1) {
            foreach ($activities as $activity) {
                SyncLog::warning("Multiple activities found for contact Id {$contactId}");
                if (in_array($activity['status_id'], $this->allowedActivityStati)) {
                    SyncLog::info("Selecting activity Id {$activity['id']} by status {$activity['status_id']}");
                    return $activity;
                }
                SyncLog::warning("No activity in allowed stati. Taking first one…");
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

    public function sync(string $from = self::SRC_HUMHUB, bool $manual = false): bool
    {
        $users = $this->getConnectedUsers();
        if (!count($users)) {
            SyncLog::info("No connected CiviCRM users match your criteria.");
            return true;
        }
        SyncLog::info("Syncing " . count($users) . " connected CiviCRM users.");
        $handled = [];
        if ($this->settings->enableBaseSync) {
            $handled = $this->syncBases($users);
        }

        if ($this->settings->autoFullSync || $manual) {
            $handled = $this->syncUsers($from, $handled);
        }
        return count($handled) > 0;
    }

    /**
     * Summary of syncBase
     * Sync civicrm base data like checksum, activity id and enabled state.
     * @param \humhub\modules\user\models\User $user
     * @return bool
     */
    public function syncBase(User $user): bool
    {
        $profile = $user->profile;
        $contactId = $profile->{$this->settings->contactIdField} ?? null;
        if (!$contactId) {
            return true; // No contact Id, nothing to sync
        }

        $civicrmContact = $this->singleContact($contactId);
        if (!$civicrmContact) {
            SyncLog::error("No CiviCRM contact found for Id {$contactId}. Skipping user {$user->id}.");
            return false;
        }

        SyncLog::info(". . . . . . . . . . . .");
        SyncLog::info("Start base syncing user {$user->id} ({$user->email}) w/ CiviCRM contact {$contactId}.");
        $save = false;

        // Renew checksum
        if (!$this->validateChecksum($contactId, $profile->{$this->settings->checksumField} ?? "none")) {
            $checksum = $this->genChecksum($contactId);
            if ($checksum && $profile->{$this->settings->checksumField} !== $checksum) {
                $profile->{$this->settings->checksumField} = $checksum;
                $save = true;
                SyncLog::info("Updated checksum (CiviCRM Contact Id {$contactId}).");
            }
        }

        // Sync activity Id and account status
        $activity = $this->getActivityFromContact($contactId);
        $activityId = $activity['id'] ?? null;
        if ($activityId) {
            SyncLog::info("Found activity Id {$activityId} for contact Id {$contactId}.");
            if ($profile->{$this->settings->activityIdField} !== $activityId) {
                $profile->{$this->settings->activityIdField} = $activityId;
                SyncLog::info("Updated activity to Id {$activityId}.");
                $save = true;
            }
            $profileEnabled = $this->isProfileEnabled($activity);
            $userEnabled = $user->status === User::STATUS_ENABLED;
            if ($userEnabled !== $profileEnabled) {
                $user->status = $this->isProfileEnabled($activity) ? User::STATUS_ENABLED : User::STATUS_DISABLED;
                SyncLog::info("Adjusted account status to {$user->status}.");
                $save = true;
            }
        } else if ($this->settings->strictDisable) {
            $user->status = User::STATUS_DISABLED;
            SyncLog::info("Disabling account because no activity in CiviCRM and strict module settings.");
            $save = true;
        }

        $result = $save ? $this->saveHumhub($user) : true;
        SyncLog::info("End base syncing user {$user->id} ({$user->email}): " . ($result ? "success" : "failed"));
        return $result;
    }

    private function getConnectedUsers(): array
    {
        $q = User::find()
            ->joinWith('profile');
        if ($this->restrictToContactIds()) {
            SyncLog::info("Restricting all actions to contact IDs: " . json_encode($this->getEnabledContactIds()));
            $q->andWhere(['IN', "profile.{$this->settings->contactIdField}", $this->getEnabledContactIds()]);
        } else {
            $q->andWhere(['<>', "profile.{$this->settings->contactIdField}", 0]);
            if ($this->settings->retryOnMissingField) {
                SyncLog::info("Act only if field '{$this->settings->retryOnMissingField}'
                
                is empty.");
                $q->andWhere([
                    'or',
                    ['IS', $this->settings->retryOnMissingField, null],
                    ['=', $this->settings->retryOnMissingField, ''],
                ]);
            }
            if ($this->settings->limit > 0) {
                SyncLog::info("Limiting user fetch to {$this->settings->limit} users.");
                $q->limit($this->settings->limit);
            }
            if ($this->settings->offset > 0) {
                SyncLog::info("Applying offset of {$this->settings->offset} users.");
                $q->offset($this->settings->offset);
            }
        }
        return $q
            ->all();
    }

    public function syncBases(?array $users = null): array
    {
        $users = $users ?? $this->getConnectedUsers();
        SyncLog::info("Start syncing base data from CiviCRM to HumHub for " . count($users) . " connected users.");
        $handled = [];
        foreach ($users as $user) {
            if (!$this->syncBase($user))
                continue;
            $handled[] = $user;
            $this->printPercent("Base syncing users", count($users), count($handled));
        }
        SyncLog::info("End syncing base data from CiviCRM to HumHub for all connected users.");
        return $handled;
    }

    public function onChange(string $eventSrc, Profile $profile, array $valuesBeforeChange): void
    {
        if (!$this->settings->enableOnChangeSync) {
            return;
        }
        if (!$eventSrc || !count($valuesBeforeChange)) {
            return;
        }

        $contactId = $profile->{$this->settings->contactIdField} ?? null;
        if (!$contactId) {
            return;
        }
        $civicrmContact = $this->singleContact($contactId);
        if (!$civicrmContact) {
            SyncLog::error("Can't sync: CiviCRM contact with Id {$contactId} not found for user " . ($profile->user ? $profile->user->displayName : 'n/a') . " (id: " . ($profile->user ? $profile->user->id : 'n/a') . ").");
            return;
        }
        $activityId = $profile->{$this->settings->activityIdField} ?? null;

        $user = $profile->getUser()->one();
        if ($this->isLocked($user)) {
            SyncLog::info("Skipping on-change sync for user {$user->id} ({$user->email}) - sync lock active.");
            return;
        }

        if ($this->settings->enableBaseSync) {
            $this->syncBase($user);
            $activityId = $profile->{$this->settings->activityIdField} ?? null;
        }
        if ($this->activityEnabled() && !$activityId) {
            SyncLog::error("Can't sync: No activity of type {$this->settings->activityTypeId} Id set up for user {$user->id} ({$user->email}).");
            return;
        }
        SyncLog::info(". . . . . . . . . . . .");
        SyncLog::info("Start on-change syncing user {$user->id} ({$user->email}) from source {$eventSrc}");

        // Combine changed fields and unchanged fields of subentities that have to be synced completely
        $fieldsToSync = array_keys($valuesBeforeChange);
        foreach ($fieldsToSync as $fieldName) {
            $siblings = $this->settings->fieldMappings->getHumhubSiblings($fieldName, false);
            $fieldsToSync = array_merge($fieldsToSync, $siblings);
        }

        // Prepare api call
        $civiCRMUpdateParams = [];
        foreach ($this->settings->fieldMappings->mappings as $mapping) {
            if (!$mapping->isSrc($eventSrc)) {
                continue; // Skip if the source does not match
            }
            if (!in_array($mapping->getBareHumhubFieldName(), $fieldsToSync)) {
                continue; // Skip if the field was not changed
            }

            SyncLog::info("Processing mapping for HumHub field '{$mapping->humhubField}' to CiviCRM field '{$mapping->civiField}'");
            $humhubValue = $this->getHumhubValue($user, $mapping->humhubField);

            if ($this->buildCiviCRMUpdateParams($civiCRMUpdateParams, $mapping, $humhubValue)) {
                SyncLog::debug("Params prepared for CiviCRM field '{$mapping->civiField}' for contact {$contactId}");
            } else {
                SyncLog::error("Failed prepare CiviCRM field params for '{$mapping->civiField}' of contact {$contactId}");
            }
        }
        if (!empty($civiCRMUpdateParams))
            $this->updateEntities($contactId, $activityId, $civiCRMUpdateParams);

        SyncLog::info("On-change sync for user {$user->id} ({$user->email}) from source {$eventSrc}");
    }

    public function buildCiviCRMUpdateParams(array &$params, FieldMapping $mapping, mixed $value): bool
    {
        // Do not gather fields for sub entities
        // This is used for fields like phones, emails, etc. where the entity is not the main entity
        // but a sub-entity like phone, email, etc.
        if ($mapping->isSubEntity()) {
            if (!$mapping->civiEntity || !$mapping->civiField) {
                Yii::error("Invalid CiviCRM field definition for {$mapping->humhubField}, missing entity and/or field keys: " . json_encode($mapping));
                return false; // Skip invalid definitions
            }
            if (!array_key_exists('subEntities', $params)) {
                $params['subEntities'] = [];
            }
            $params['subEntities'][] = [
                'entity' => $mapping->civiEntity,
                'field' => $mapping->civiField,
                'value' => $value,
                'params' => $mapping->params
            ];
            return true;
        }

        if (!array_key_exists($mapping->civiEntity, $params)) {
            $params[$mapping->civiEntity] = [];
        }
        $params[$mapping->civiEntity][$mapping->civiField] = $value;
        return true;
    }


    public function syncUser(User $user, string $from = self::SRC_CIVICRM): bool
    {
        $profile = $user->profile;
        $contactId = $profile->{$this->settings->contactIdField} ?? null;
        if (!$contactId) {
            return true; // No contact Id, nothing to sync
        }
        $civicrmContact = $this->singleContact($contactId);
        if (!$civicrmContact) {
            SyncLog::error("Can't sync: CiviCRM contact with Id {$contactId} not found for user {$user->id} ({$user->email}).");
            return false;
        }
        SyncLog::info(". . . . . . . . . . . .");
        SyncLog::info("Start syncing user {$user->id} ({$user->email}) from source {$from}");
        $activityId = $profile->{$this->settings->activityIdField} ?? null;
        if ($this->activityEnabled() && !$activityId) {
            SyncLog::error("Can't sync: No activity of type {$this->settings->activityTypeId} Id set up for user {$user->id} ({$user->email}).");
            return false;
        }
        $activity = $this->singleActivity($activityId);

        // Sync fields based on mapping
        $params = [];
        // Field config pattern:
        //   "profile.firstname": "contact.first_name",
        //   "profile.organisation_art": "activity.custom_641",
        $saveHumhub = false;
        $civiCRMUpdateParams = [];
        foreach ($this->settings->fieldMappings->mappings as $mapping) {
            // Update humhub from civicrm
            $humhubValue = $this->getHumhubValue($user, $mapping->humhubField);
            $humhubValueStr = json_encode($humhubValue);
            $civiValue = $this->getCiviCRMValue($civicrmContact, $activity, $mapping);
            $civiValueStr = json_encode($civiValue);

            $changed = $humhubValue != $civiValue;
            SyncLog::info("Comparing HumHub value '{$humhubValueStr}' with CiviCRM value '{$civiValueStr}' for field '{$mapping->humhubField}': " . ($changed ? "changed" : "no change"));
            if (!$changed) {
                continue; // No change needed
            }
            switch ($from) {
                case self::SRC_HUMHUB:
                case self::SRC_BOTH:
                    if ($this->buildCiviCRMUpdateParams($civiCRMUpdateParams, $mapping, $humhubValue)) {
                        SyncLog::debug("Params prepared for CiviCRM field '{$mapping->civiField}' for contact {$contactId}");
                    } else {
                        SyncLog::error("Failed prepare CiviCRM field params for '{$mapping->civiField}' of contact {$contactId}");
                    }
                    break;
                case self::SRC_CIVICRM:
                    if ($this->setHumhubValue($user, $mapping->humhubField, $civiValue)) {
                        SyncLog::info("Prepared HumHub field '{$mapping->humhubField}' for user {$user->id}");
                        $saveHumhub = true;
                    } else {
                        SyncLog::error("Failed to update HumHub field '{$mapping->humhubField}' for user {$user->id}");
                    }
                    break;
                default:
                    // No filtering
                    break;
            }
        }
        $result = $saveHumhub ? $this->saveHumhub($user) : true;
        $result = $result && (empty($civiCRMUpdateParams) || $this->updateEntities($contactId, $activityId, $civiCRMUpdateParams));

        SyncLog::info("End syncing user {$user->id} from source {$from}: " . ($result ? "success" : "failed"));
        return $result;
    }

    private function lockFor(User $user, int $seconds): void
    {
        Yii::$app->cache->set("civicrm-sync-block.$user->id", 1, $seconds);
    }
    private function lock(User $user): void
    {
        $this->lockFor($user, 30); // Default lock for 30 seconds
    }
    private function isLocked(User $user): bool
    {
        return Yii::$app->cache->get("civicrm-sync-block.$user->id") === 1;
    }
    private function unlock(User $user): void
    {
        Yii::$app->cache->delete("civicrm-sync-block.$user->id");
    }

    private function saveHumhub(User $user): bool
    {
        if ($this->dryRun()) {
            SyncLog::info("Dry run enabled - skipping save of user {$user->id}.");
            return true;
        }

        $profile = $user->profile;
        $this->lock($user);
        $profileSaved = $profile->save(false);
        $userSaved = $user->save();
        return $profileSaved && $userSaved;
    }

    private function printPercent(string $prefix, int $users, int $handled): string
    {
        $percent = round($handled / $users * 100, 2);
        SyncLog::info("$prefix progress: {$handled}/{$users} ({$percent}%)");
        return $percent;
    }

    public function syncUsers(string $from = self::SRC_BOTH, ?array $users = null): array
    {
        // Logic to sync all users with CiviCRM
        // This could involve fetching all users from the database and updating their CiviCRM records
        $users = $users ?? $this->getConnectedUsers();
        SyncLog::info("Start syncing " . count($users) . " users from source {$from}");
        $handled = [];
        foreach ($users as $user) {
            if (!$this->syncUser($user, $from))
                continue;
            $handled[] = $user;
            $this->printPercent("Syncing users", count($users), count($handled));
        }
        SyncLog::info("End syncing all users from source {$from}");
        return $handled;
    }

    private function getHumhubValue($user, $field)
    {
        [$dataSrc, $fieldName] = explode('.', $field);
        $rawValue = match ($dataSrc) {
            self::HUMHUB_DATA_SRC_USER => $user->$fieldName ?? null,
            self::HUMHUB_DATA_SRC_PROFILE => $user->profile->$fieldName ?? null,
            self::HUMHUB_DATA_SRC_ACCOUNT => $user->account->$fieldName ?? null,
            default => null,
        };

        if ($rawValue === null) {
            return null;
        }
        $profileField = ProfileField::findOne(['internal_name' => $fieldName]);
        if (!$profileField) {
            return $rawValue; // No special handling needed
        }
        return match ($profileField->field_type_class) {
            'humhub\modules\user\models\fieldtype\CheckboxList' => $this->deserializeHumhubArray($rawValue),
            default => $rawValue,
        };
    }

    private function deserializeHumhubArray(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $parts = preg_split("/\r\n|\n|\r/", $value);
        $sanitized = array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));
        $unothered = array_diff($sanitized, ['other']);
        return $unothered;
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

    public function getCiviCRMValue(array $civicrmContact, array $activity, FieldMapping $mapping)
    {
        if (!$civicrmContact || empty($mapping->civiField)) {
            return null;
        }
        // Handle activity fields
        if (strtolower($mapping->civiEntity) == 'activity') {
            return $activity[$mapping->civiField] ?? null;
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