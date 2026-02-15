<?php
namespace k7zz\humhub\civicrm\services;

use humhub\modules\user\models\GroupUser;
use humhub\modules\user\models\ProfileField;
use humhub\modules\user\models\User;
use humhub\modules\user\models\Profile;
use k7zz\humhub\civicrm\components\SyncLog;
use k7zz\humhub\civicrm\components\SyncContext;
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
        'Contact',
        'Email',
        'Phone',
        'Address',
        'Activity',
        'Website',
        'ActivityContact',
    ];

    public const SOFT_DELETE_ENTITIES = [
        'Contact',
        'Activity',
    ];

    private const API_VERSION = 'v4';
    private const API_PATH = '/civicrm/ajax/api4/';
    private array $allowedActivityStati = [
        'Active' => 9
    ];
    private array $writingCiviActions = ['create', 'update', 'delete'];

    private $apiCache = [];

    private HttpClient $httpClient;
    public CiviCRMSettings $settings;
    private ?SyncContext $syncContext = null;
    public function __construct($humhubSettings = null)
    {
        $this->settings = Yii::createObject(type: CiviCRMSettings::class, params: [$humhubSettings]);

        if ($this->settings->url && $this->settings->secret && $this->settings->siteKey) {
            $this->prepareAPI();
        }

    }

    /**
     * Set the current SyncContext for correlated logging.
     * Call with null to clear the context.
     */
    public function setSyncContext(?SyncContext $ctx): void
    {
        $this->syncContext = $ctx;
    }

    /**
     * Context-aware logging: uses SyncContext if set, otherwise falls back to SyncLog.
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->syncContext) {
            $this->syncContext->log($level, $message, $context);
        } else {
            SyncLog::$level($message, $context);
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

    private function restrictToGroups(): bool
    {
        return $this->settings->includeGroups
            && is_array($this->settings->includeGroups)
            && count($this->settings->includeGroups) > 0;
    }

    private function excludeGroups(): bool
    {
        return $this->settings->excludeGroups
            && is_array($this->settings->excludeGroups)
            && count($this->settings->excludeGroups) > 0;
    }

    private function getCustomFieldIncludes(string $entity): array
    {
        $includes = [];
        $stringList = '';
        switch (strtolower($entity)) {
            case 'contact':
                $stringList = $this->settings->contactCustomFieldGroups;
                break;
            case 'activity':
                $stringList = $this->settings->activityCustomFieldGroups;
                break;
        }
        if (!empty($stringList)) {
            $groups = array_map('trim', explode(',', $stringList));
            foreach ($groups as $group) {
                if ($group) {
                    $includes[] = "{$group}.*";
                }
            }
        } else {
            $includes[] = "custom.*";
        }
        return $includes;
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
        return "{$entity}/{$action}";
    }

    private function getCacheKey(string $entity, string $action, array $params = []): string
    {
        return md5("{$entity}.{$action}:" . json_encode($params));
    }

    private function cache(string $entity, string $action, array $params = [], $response = null): void
    {
        $cacheKey = $this->getCacheKey($entity, $action, $params);
        $this->apiCache[$cacheKey] = $response;
    }

    private function getFromCache(string $entity, string $action, array $params = []): mixed
    {
        $cacheKey = $this->getCacheKey($entity, $action, $params);
        return $this->apiCache[$cacheKey] ?? null;
    }

    private function getApiResult($response)
    {
        return $response->data['values'] ?? $response->data;
    }

    private function getSanitizedEntityName(string $entity): string
    {
        $saneIdx = array_search(strtolower($entity), array_map('strtolower', self::ALLOWED_CIVICRM_ENTITIES));
        if ($saneIdx === false) {
            return '';
        }
        return self::ALLOWED_CIVICRM_ENTITIES[$saneIdx];
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
     * @return array | bool (array on read, true/false on write actions)
     */
    private function callCiviCRM(string $entity, string $action, array $params = []): array|bool
    {
        if (!$this->isPrepared()) {
            $this->prepareAPI();
        }

        $entity = $this->getSanitizedEntityName($entity);
        if (!$entity) {
            $this->log('error', "Invalid CiviCRM entity", [
                'requested_entity' => $entity,
            ]);
            return false;
        }

        if ($action === 'get') {
            if (in_array($entity, self::SOFT_DELETE_ENTITIES)) {
                if (!array_key_exists('where', $params)) {
                    $params['where'] = [];
                }
                $params['where'][] = ['is_deleted', '=', FALSE];
            }
            if (
                !array_key_exists('select', $params)
                || empty($params['select'])
            ) {
                $includes = $this->getCustomFieldIncludes($entity);
                $params['select'] = array_merge(['*'], $includes);
            }
        }

        $isWrite = in_array($action, $this->writingCiviActions);
        if (!$isWrite) {
            $cached = $this->getFromCache($entity, $action, $params);
            if ($cached !== null) {
                $this->log('debug', "Using cached API response", [
                    'entity' => $entity,
                    'action' => $action,
                    'cached' => true,
                ]);
                return $this->getApiResult($cached);
            }
        }

        $this->log('debug', "Calling CiviCRM API", [
            'entity' => $entity,
            'action' => $action,
            'param_count' => count($params),
            'is_write' => $isWrite,
        ]);

        $request = $this->httpClient
            ->createRequest()
            ->setMethod('POST')
            ->setUrl($this->getEndpoint($entity, $action))
            ->setData([
                'params' => json_encode($params)
            ]);

        if ($this->dryRun() && $isWrite) {
            $this->log('warning', "Dry run - skipping write operation", [
                'entity' => $entity,
                'action' => $action,
            ]);
            return true; // Return an empty array as a placeholder
        }

        $response = $request
            ->send();

        if ($response->isOk) {
            if (!empty($response->data['error_message'])) {
                $this->log('error', "CiviCRM API returned error", [
                    'entity' => $entity,
                    'action' => $action,
                    'error_message' => $response->data['error_message'],
                ]);
                return false; // Return false on error
            }
            if ($isWrite) {
                return true;
            }
            $this->cache($entity, $action, $params, $response);
            return $this->getApiResult($response);
        }

        $this->log('error', "CiviCRM API call failed", [
            'entity' => $entity,
            'action' => $action,
            'status_code' => $response->statusCode,
            'response_preview' => substr($response->content, 0, 200),
        ]);

        return $isWrite ? false : []; // Return false on error
    }

    private function get(string $entity, array $where = [], int $limit = 10, int $offset = 0): array
    {
        if (count($where) && !is_array($where[0])) {
            $where = [$where]; // Ensure where is an array of conditions
        }
        $params['where'] = $where;
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        return $this->callCiviCRM($entity, 'get', $params);
    }

    private function genChecksum(int $contactId): ?string
    {
        $params = [
            'contactId' => $contactId,
            'checkPermissions' => true
        ];
        $result = $this->callCiviCRM('Contact', 'getChecksum', $params);
        return $result[0]['checksum'] ?? null; // Return the checksum or null if not found
    }

    private function validateChecksum(int $contactId, string|null $checksum = "none"): bool
    {
        $params = [
            'contactId' => $contactId,
            'checksum' => $checksum,
            'checkPermissions' => true
        ];
        $result = $this->callCiviCRM('Contact', 'validateChecksum', $params);
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

    public function update(string $entity, int $id, array $values = []): bool
    {
        $params['values'] = $values;
        $params['where'] = [
            ['id', '=', $id]
        ];
        return $this->callCiviCRM($entity, 'update', $params);
    }

    public function create(string $entity, array $values = []): bool
    {
        $params['values'] = $values;
        return $this->callCiviCRM($entity, 'create', $params);
    }

    private function joinSubEntityParams(array $targetParams): array
    {
        $joined = [];
        // Combine subentity calls with same params
        foreach ($targetParams as $subEntity) {
            if (!isset($joined[$subEntity['entity']])) {
                $joined[$subEntity['entity']] = [];
            }
            $key = md5(json_encode($subEntity['params'] ?? []));
            if (!isset($joined[$subEntity['entity']][$key])) {
                $joined[$subEntity['entity']][$key] = [
                    'entity' => $subEntity['entity'],
                    'params' => $subEntity['params'] ?? []
                ];
            }
            if (!isset($joined[$subEntity['entity']][$key]['fields'])) {
                $joined[$subEntity['entity']][$key]['fields'] = [];
            }
            $joined[$subEntity['entity']][$key]['fields'][] = [
                $subEntity['field'] => $subEntity['value']
            ];
        }
        return $joined;
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
                    // Join api calls w/ same parameters
                    $joined = $this->joinSubEntityParams($targetParams);
                    foreach ($joined as $subEntityName => $subEntityCalls) {
                        foreach ($subEntityCalls as $key => $subEntity) {
                            $okay = $okay && $this->updateSubEntity(
                                $subEntity['entity'],
                                $contactId,
                                $subEntity['fields'],
                                $subEntity['params'] ?? null
                            );
                        }
                    }
                    break;
                default:
                    $okay = false;
                    break; // Unknown entity, skip
            }
        }
        return $okay;
    }

    private function searchSubEntityValue(string $entity, int $contactId, FieldMapping $mapping, $humhubValue): int
    {
        $subEntities = $this->getSubEntities($entity, $contactId);
        foreach ($subEntities as $subEntity) {
            if ($subEntity[$mapping->civiField] === $humhubValue) {
                return $subEntity['id'];
            }
        }
        return 0;
    }

    private function getSubEntity(string $entity, int $contactId, ?array $where): array|null
    {
        SyncLog::debug("Fetching sub-entity {$entity} for contact Id {$contactId} with additional where: " . json_encode($where));
        $subEntities = $this->getSubEntities($entity, $contactId, $where);
        if (count($subEntities) > 1) {
            Yii::error("Multiple sub-entities found for contact Id {$contactId} in entity {$entity}. Returning first result.");
        }
        return $subEntities[0] ?? null; // Return the first result or an empty
    }

    private function getSubEntities(string $entity, int $contactId, ?array $where = null): array|null
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
        $results = $this->callCiviCRM($entity, 'get', $params);
        return $results;
    }

    private function updateSubEntity(
        string $entity,
        int $contactId,
        array $fields,
        ?array $additionalParams = null
    ): bool {
        if (!$contactId || !$fields || empty($fields)) {
            throw new \InvalidArgumentException("Contact Id and CiviCRM fields are required for update.");
        }
        // If exists, update sub-entity
        $subEntity = $this->getSubEntity($entity, $contactId, $additionalParams);
        if ($subEntity) {
            return $this->update($entity, $subEntity['id'], $fields);
        }

        // If no sub-entity exists, create it
        $fields['contact_id'] = $contactId;
        return $this->create($entity, array_merge($fields, $additionalParams ?? []));
    }

    /**
     * Magic method to handle dynamic method calls for CiviCRM actions.
     * 
     * This allows calling methods like getContact, updateContact, etc.
     * 
     * @param string $name
     * @param array $parameters
     * @return array|bool|null
     * @throws \BadMethodCallException
     */
    function __call($name, $parameters): array|bool|null
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
                        ['target_contact_id', 'CONTAINS', $contactId]
                    ]
                ],
                ['activity_type_id', '=', $this->settings->activityTypeId]
            ],
            'orderBy' => [
                'created_date' => 'DESC',
            ]
        ];
        $activities = $this->callCiviCRM('activity', 'get', $params);
        if (count($activities) == 0) {
            SyncLog::warning("No activity found for contact Id {$contactId}.");
            return null;
        }
        if (count($activities) > 1) {
            foreach ($activities as $activity) {
                SyncLog::warning("Multiple activities found for contact Id {$contactId}");
                if (in_array($activity['status_id'], $this->allowedActivityStati)) {
                    SyncLog::debug("Selecting activity Id {$activity['id']} by status {$activity['status_id']}");
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
            SyncLog::warning("No connected CiviCRM users match your criteria.");
            return true;
        }
        SyncLog::debug("Syncing " . count($users) . " connected CiviCRM users.");
        $handled = [];
        if ($this->settings->enableBaseSync) {
            $handled = $this->syncBases($users);
        }

        if ($this->settings->autoFullSync || $manual) {
            $handled = $this->syncUsers($from, $handled);
        }
        return count($handled) > 0;
    }

    private function getCiviContactByUserIdField(User $user): array|null
    {
        if (!$this->settings->humhubUserIdCiviCRMField) {
            return null;
        }
        [$entity, $field] = explode('.', $this->settings->humhubUserIdCiviCRMField, 2);
        $where = [$field, '=', $user->id];
        switch (strtolower($entity)) {
            case 'contact':
                $results = $this->getContact($where, 1);
                return $results[0] ?? null;
            case 'activity':
                $results = $this->getActivity($where);
                if ($results === null || count($results) == 0) {
                    return null;
                }
                return $this->getContactByActivities($user, $results);
            default:
                return null;
        }
    }

    // Needed if civicrm contact id has changed in civicrm by merging/deduping
    private function getContactByActivities(User $user, array $activities): array|null
    {
        $savedContactId = $user->profile->{$this->settings->contactIdField} ?? null;
        if ($savedContactId) {
            $contact = $this->singleContact($savedContactId);
            if ($contact) {
                SyncLog::debug("getContactByActivities: Found contact by saved contact Id {$savedContactId}. No need for activity check for user {$user->id} ({$user->email}).");
                return $contact;
            }
        }
        $email = $user->email;
        SyncLog::debug("Searching contact by activities for user {$user->id} by email ({$email}).");
        // Search through all activity contacts
        $activityContactIds = [];
        foreach ($activities as $activity) {
            $cIds = $this->get('ActivityContact', [
                'activity_id',
                '=',
                $activity['id']
            ]);
            $activityContactIds = array_merge($activityContactIds, array_column($cIds, 'contact_id'));
        }
        if (count($activityContactIds) == 0) {
            SyncLog::error("No contact Ids found in activities for user {$user->id} ({$user->email}).");
            return null;
        }
        $activityContactIds = array_values(array_unique($activityContactIds));

        $emails = $this->getEmail(
            [
                [
                    'contact_id',
                    'IN',
                    array_unique($activityContactIds)
                ],
                [
                    'email',
                    '=',
                    $email
                ]
            ]
        );
        SyncLog::debug("Found " . count($emails) . " email records matching user {$user->id} ({$user->email}) in activities.");
        SyncLog::debug("Email records: " . json_encode($emails));
        if (count($emails) > 0) {
            $contactId = $emails[0]['contact_id'];
            $contact = $this->singleContact($contactId);
            if ($contact) {
                SyncLog::debug("Found contact Id {$contactId} by activity Id {$activity['id']} for user {$user->id} ({$user->email}).");
                return $contact;
            }
        }
        SyncLog::error("No contact found by activities for user {$user->id} ({$user->email}).");
        return null;
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

        // Create sync context for structured logging
        $ctx = new SyncContext($user, 'syncBase');
        $ctx->setContactId($contactId);
        $this->setSyncContext($ctx);

        if (!$this->mayBeSynced($user)) {
            $ctx->logSkipped('not_eligible_for_sync');
            $this->setSyncContext(null);
            return true;
        }

        // Check if caller already locked - if so, we don't need to lock again
        $wasAlreadyLocked = $this->isLocked($user);
        if (!$wasAlreadyLocked) {
            $this->lock($user);
        }

        try {
            // Fetch CiviCRM contact
            $ctx->log('debug', "Fetching CiviCRM contact");
            $civicrmContact = $this->singleContact($contactId);
            $civiHasHumhubUserId = false;

            // Handle missing contact - try recovery by HumHub user ID field
            if (!$civicrmContact) {
                if ($this->settings->humhubUserIdCiviCRMField) {
                    $ctx->log('warning', "Contact not found - attempting recovery by HumHub user ID", [
                        'original_contact_id' => $contactId,
                    ]);

                    $civicrmContact = $this->getCiviContactByUserIdField($user);
                    if ($civicrmContact) {
                        $civiHasHumhubUserId = true;
                        $newContactId = $civicrmContact['id'];
                        $profile->{$this->settings->contactIdField} = $newContactId;
                        $this->saveHumhub($user);

                        $ctx->setContactId($newContactId);
                        $ctx->log('info', "Contact ID updated after recovery", [
                            'old_contact_id' => $contactId,
                            'new_contact_id' => $newContactId,
                        ]);
                        $contactId = $newContactId;
                    }
                }

                if (!$civicrmContact) {
                    $ctx->logError("CiviCRM contact not found and recovery failed", null, [
                        'contact_id' => $contactId,
                    ]);
                    return false;
                }
            }

            $changes = [];

            // Renew checksum
            $currentChecksum = $profile->{$this->settings->checksumField} ?? null;
            if (!$this->validateChecksum($contactId, $currentChecksum ?? "none")) {
                $ctx->log('debug', "Checksum invalid or expired - generating new one");
                $newChecksum = $this->genChecksum($contactId);

                if ($newChecksum && $currentChecksum !== $newChecksum) {
                    $profile->{$this->settings->checksumField} = $newChecksum;
                    $changes[] = 'checksum';
                    $ctx->log('info', "Checksum updated");
                }
            }

            // Sync activity ID and account status
            $ctx->log('debug', "Fetching activity from contact");
            $activity = $this->getActivityFromContact($contactId);
            $activityId = $activity['id'] ?? null;
            $ctx->setActivityId($activityId);

            if ($activityId) {
                // Update activity ID if changed
                $currentActivityId = $profile->{$this->settings->activityIdField} ?? null;
                if ($currentActivityId !== $activityId) {
                    $profile->{$this->settings->activityIdField} = $activityId;
                    $changes[] = 'activity_id';
                    $ctx->log('info', "Activity ID updated", [
                        'old_activity_id' => $currentActivityId,
                        'new_activity_id' => $activityId,
                    ]);
                }

                // Sync account status based on activity
                $profileEnabled = $this->isProfileEnabled($activity);
                $userEnabled = $user->status === User::STATUS_ENABLED;

                if ($userEnabled !== $profileEnabled) {
                    $oldStatus = $user->status;
                    $user->status = $profileEnabled ? User::STATUS_ENABLED : User::STATUS_DISABLED;
                    $changes[] = 'account_status';

                    $ctx->log('warning', "Account status adjusted", [
                        'old_status' => $oldStatus,
                        'new_status' => $user->status,
                        'activity_status' => $activity['status_id'] ?? null,
                    ]);
                }
            } else if ($this->settings->strictDisable) {
                // No activity found and strict disable is enabled
                if ($user->status !== User::STATUS_DISABLED) {
                    $user->status = User::STATUS_DISABLED;
                    $changes[] = 'account_status';

                    $ctx->log('warning', "Account disabled - no activity found and strict mode enabled", [
                        'strict_disable' => true,
                    ]);
                }
            }

            // Set HumHub user ID in CiviCRM if not already set
            if (!$civiHasHumhubUserId && $this->settings->humhubUserIdCiviCRMField) {
                $ctx->log('debug', "Setting HumHub user ID in CiviCRM");
                $this->setHumhubIdInCiviCRM($contactId, $civicrmContact, $user, $activity);
            }

            // Save changes if any
            $result = true;
            if (!empty($changes)) {
                $ctx->log('info', "Saving HumHub user changes", [
                    'changes' => $changes,
                ]);
                $result = $this->saveHumhub($user);
            }

            if ($result) {
                $ctx->logSuccess([
                    'changes_made' => $changes,
                    'changes_count' => count($changes),
                ]);
            } else {
                $ctx->logError("Failed to save HumHub user");
            }

            return $result;

        } catch (\Throwable $e) {
            $ctx->logError("syncBase failed with exception", $e);
            throw $e;
        } finally {
            // Only unlock if we locked it (not if caller already had it locked)
            if (!$wasAlreadyLocked) {
                $this->unlock($user);
            }
            // Clear sync context
            $this->setSyncContext(null);
        }
    }

    private function setHumhubIdInCiviCRM(int $contactId, array $civicrmContact, User $user, array $activity): void
    {
        [$entity, $field] = explode('.', $this->settings->humhubUserIdCiviCRMField, 2);
        switch (strtolower($entity)) {
            case 'contact':
                $this->updateContact($contactId, [
                    $field => $user->id
                ]);
                SyncLog::debug("Set HumHub user Id {$user->id} in CiviCRM contact Id {$contactId} field {$field}.");
                break;
            case 'activity':
                if ($activity) {
                    $this->updateActivity($activity['id'], [
                        $field => $user->id
                    ]);
                    SyncLog::debug("Set HumHub user Id {$user->id} in CiviCRM activity Id {$activity['id']} field {$field}.");
                }
                break;
        }
    }

    private function getConnectedUsers(): array
    {
        $q = User::find()
            ->joinWith('profile');
        if ($this->restrictToContactIds()) {
            SyncLog::warning("Restricting all actions to contact IDs: " . json_encode($this->getEnabledContactIds()));
            $q->andWhere(['IN', "profile.{$this->settings->contactIdField}", $this->getEnabledContactIds()]);
        } else {
            $q->andWhere(['<>', "profile.{$this->settings->contactIdField}", 0]);
        }
        if ($this->settings->retryOnMissingField) {
            SyncLog::warning("Act only if field '{$this->settings->retryOnMissingField}' is empty.");
            $q->andWhere(condition: [
                'or',
                ['IS', $this->settings->retryOnMissingField, null],
                ['=', $this->settings->retryOnMissingField, ''],
            ]);
        }
        if ($this->settings->limit > 0) {
            SyncLog::warning("Limiting user fetch to {$this->settings->limit} users.");
            $q->limit($this->settings->limit);
        }
        if ($this->settings->offset > 0) {
            SyncLog::warning("Applying offset of {$this->settings->offset} users.");
            $q->offset($this->settings->offset);
        }
        if ($this->restrictToGroups()) {
            SyncLog::info("Restricting all actions to groups: " . json_encode($this->settings->includeGroups));
            $q->andWhere([
                'IN',
                'id',
                GroupUser::find()
                    ->select('user_id')
                    ->where(['IN', 'group_id', $this->settings->includeGroups])
            ]);
        }

        if ($this->excludeGroups()) {
            SyncLog::info("Excluding users from groups: " . json_encode($this->settings->excludeGroups));
            $q->andWhere([
                'NOT IN',
                'id',
                GroupUser::find()
                    ->select('user_id')
                    ->where(['IN', 'group_id', $this->settings->excludeGroups])
            ]);
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
            $this->printPercent("Base syncing users progress", count($users), count($handled));
        }
        SyncLog::info("End syncing base data from CiviCRM to HumHub for all connected users.");
        return $handled;
    }
    public function onLogin(User $user): void
    {
        if (!$this->settings->enableOnLoginSync) {
            return;
        }
        if (!$this->mayBeSynced($user)) {
            return;
        }

        // Create sync context for structured logging
        $ctx = new SyncContext($user, 'onLogin');
        $this->setSyncContext($ctx);

        // Check lock BEFORE starting sync to avoid concurrent login syncs
        if ($this->isLocked($user)) {
            $ctx->logSkipped('user_locked');
            $this->setSyncContext(null);
            return;
        }

        // Set lock to prevent concurrent sync operations
        $this->lock($user);

        try {
            // Sync base data (checksum, activity, account status)
            $this->syncBase($user);

            // Sync user field mappings
            if ($this->settings->autoFullSync) {
                $this->syncUser($user, self::SRC_CIVICRM);
            }

            $ctx->logSuccess();
        } catch (\Throwable $e) {
            $ctx->logError("onLogin failed with exception", $e);
            throw $e;
        } finally {
            // Always release the lock, even if an exception occurs
            $this->unlock($user);
            // Clear sync context
            $this->setSyncContext(null);
        }
    }

    public function onChange(string $eventSrc, Profile $profile, array $valuesBeforeChange): void
    {
        if (!$this->settings->enableOnChangeSync) {
            return;
        }
        if (!$eventSrc || !count($valuesBeforeChange)) {
            return;
        }

        $user = $profile->getUser()->one();
        if (!$this->mayBeSynced($user, $profile)) {
            return;
        }

        // Create sync context for structured logging
        $ctx = new SyncContext($user, 'onChange', $eventSrc);
        $ctx->addMetadata('changed_fields', array_keys($valuesBeforeChange));
        $this->setSyncContext($ctx);

        // Check lock BEFORE expensive operations
        if ($this->isLocked($user)) {
            $ctx->logSkipped('user_locked');
            $this->setSyncContext(null);
            return;
        }

        $contactId = $profile->{$this->settings->contactIdField} ?? null;
        if (!$contactId) {
            $ctx->logError("CiviCRM contact ID not found in profile", null, [
                'profile_id' => $profile->user_id ?? null,
            ]);
            $this->setSyncContext(null);
            return;
        }

        $ctx->setContactId($contactId);

        // Set lock to prevent concurrent onChange executions
        $this->lock($user);

        try {
            // Fetch CiviCRM contact
            $ctx->log('debug', "Fetching CiviCRM contact");
            $civicrmContact = $this->singleContact($contactId);

            if (!$civicrmContact) {
                $ctx->logError("CiviCRM contact not found", null, [
                    'attempted_contact_id' => $contactId,
                ]);
                return;
            }

            $activityId = $profile->{$this->settings->activityIdField} ?? null;
            $ctx->setActivityId($activityId);

            // Run base sync if enabled
            if ($this->settings->enableBaseSync) {
                $ctx->log('debug', "Running base sync");
                $this->syncBase($user);
                $activityId = $profile->{$this->settings->activityIdField} ?? null;
                $ctx->setActivityId($activityId);
            }

            // Verify activity exists if required
            if ($this->activityEnabled() && !$activityId) {
                $ctx->logError("Activity required but not found", null, [
                    'required_activity_type_id' => $this->settings->activityTypeId,
                ]);
                return;
            }

            // Combine changed fields with their siblings
            $fieldsToSync = array_keys($valuesBeforeChange);
            foreach ($fieldsToSync as $fieldName) {
                $siblings = $this->settings->fieldMappings->getHumhubSiblings($fieldName, false);
                $fieldsToSync = array_merge($fieldsToSync, $siblings);
            }
            $fieldsToSync = array_unique($fieldsToSync);

            $ctx->log('debug', "Processing field mappings", [
                'fields_to_sync' => $fieldsToSync,
                'total_mappings' => count($this->settings->fieldMappings->mappings),
            ]);

            // Build update parameters
            $civiCRMUpdateParams = [];
            $processedFields = 0;
            $skippedFields = 0;

            foreach ($this->settings->fieldMappings->mappings as $mapping) {
                if (!$mapping->isSrc($eventSrc)) {
                    continue;
                }
                if (!in_array($mapping->getBareHumhubFieldName(), $fieldsToSync)) {
                    continue;
                }

                $processedFields++;
                $humhubValue = $this->getHumhubValue($user, $mapping->humhubField);

                // Check for duplicate sub-entities
                if ($mapping->isSubEntity()) {
                    $existingSubEntityId = $this->searchSubEntityValue($mapping->civiEntity, $contactId, $mapping, $humhubValue);
                    if ($existingSubEntityId) {
                        $ctx->log('debug', "Skipping duplicate sub-entity", [
                            'entity' => $mapping->civiEntity,
                            'field' => $mapping->civiField,
                            'existing_id' => $existingSubEntityId,
                        ]);
                        $skippedFields++;
                        continue;
                    }
                }

                if ($this->buildCiviCRMUpdateParams($civiCRMUpdateParams, $mapping, $humhubValue)) {
                    $ctx->logFieldChange($mapping->humhubField, $valuesBeforeChange[$mapping->getBareHumhubFieldName()] ?? null, $humhubValue);
                } else {
                    $ctx->logError("Failed to build update params", null, [
                        'field' => $mapping->civiField,
                        'humhub_field' => $mapping->humhubField,
                    ]);
                }
            }

            // Execute updates
            if (!empty($civiCRMUpdateParams)) {
                $ctx->log('info', "Executing CiviCRM updates", [
                    'entities' => array_keys($civiCRMUpdateParams),
                    'update_count' => count($civiCRMUpdateParams),
                ]);
                $this->updateEntities($contactId, $activityId, $civiCRMUpdateParams);
            } else {
                $ctx->log('debug', "No updates needed");
            }

            $ctx->logSuccess([
                'processed_fields' => $processedFields,
                'skipped_fields' => $skippedFields,
                'updates_sent' => !empty($civiCRMUpdateParams),
            ]);

        } catch (\Throwable $e) {
            $ctx->logError("onChange failed with exception", $e);
            throw $e;
        } finally {
            // Always release the lock, even if an exception occurs
            $this->unlock($user);
            // Clear sync context
            $this->setSyncContext(null);
        }
    }

    public function buildCiviCRMUpdateParams(array &$params, FieldMapping $mapping, mixed $value): bool
    {
        // This is used for fields like phones, emails, etc. where the entity is not the main entity
        // but a sub-entity like phone, email, etc.
        // So gather by sub-entity and it's params
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

        // Create sync context for structured logging
        $ctx = new SyncContext($user, 'syncUser', $from);
        $ctx->setContactId($contactId);
        $this->setSyncContext($ctx);

        if (!$this->mayBeSynced($user)) {
            $ctx->logSkipped('not_eligible_for_sync');
            $this->setSyncContext(null);
            return true;
        }

        // Check if caller already locked - if so, we don't need to lock again
        $wasAlreadyLocked = $this->isLocked($user);
        if (!$wasAlreadyLocked) {
            // Lock to prevent concurrent sync operations on same user
            $this->lock($user);
        }

        try {
            // Fetch CiviCRM contact
            $ctx->log('debug', "Fetching CiviCRM contact");
            $civicrmContact = $this->singleContact($contactId);

            if (!$civicrmContact) {
                $ctx->logError("CiviCRM contact not found", null, [
                    'contact_id' => $contactId,
                ]);
                return false;
            }

            // Fetch activity if enabled
            $activityId = $profile->{$this->settings->activityIdField} ?? null;
            $ctx->setActivityId($activityId);

            if ($this->activityEnabled() && !$activityId) {
                $ctx->logError("Activity required but not found", null, [
                    'required_activity_type_id' => $this->settings->activityTypeId,
                ]);
                return false;
            }

            $activity = $activityId ? $this->singleActivity($activityId) : [];

            // Process field mappings
            $ctx->log('debug', "Processing field mappings", [
                'total_mappings' => count($this->settings->fieldMappings->mappings),
                'sync_direction' => $from,
            ]);

            $saveHumhub = false;
            $civiCRMUpdateParams = [];
            $processedFields = 0;
            $changedFields = 0;
            $skippedReadonlyFields = 0;

            foreach ($this->settings->fieldMappings->mappings as $mapping) {
                $processedFields++;

                // Get current values from both systems
                $humhubValue = $this->getHumhubValue($user, $mapping->humhubField);
                $civiValue = $this->getCiviCRMValue($civicrmContact, $activity, $mapping, $humhubValue);

                // Check if values differ
                $changed = $humhubValue != $civiValue;
                if (!$changed) {
                    continue; // No change needed
                }

                $changedFields++;

                // Determine sync direction and build update parameters
                switch ($from) {
                    case self::SRC_HUMHUB:
                    case self::SRC_BOTH:
                        if ($this->buildCiviCRMUpdateParams($civiCRMUpdateParams, $mapping, $humhubValue)) {
                            $ctx->logFieldChange($mapping->humhubField, $civiValue, $humhubValue, 'humhub→civicrm');
                        } else {
                            $ctx->logError("Failed to build CiviCRM update params", null, [
                                'field' => $mapping->civiField,
                                'humhub_field' => $mapping->humhubField,
                            ]);
                        }
                        break;

                    case self::SRC_CIVICRM:
                        if ($this->isHumhubFieldReadOnly($mapping->humhubField)) {
                            $ctx->log('debug', "Skipping read-only HumHub field", [
                                'field' => $mapping->humhubField,
                            ]);
                            $skippedReadonlyFields++;
                            break;
                        }
                        if ($this->setHumhubValue($user, $mapping->humhubField, $civiValue)) {
                            $ctx->logFieldChange($mapping->humhubField, $humhubValue, $civiValue, 'civicrm→humhub');
                            $saveHumhub = true;
                        } else {
                            $ctx->logError("Failed to set HumHub value", null, [
                                'field' => $mapping->humhubField,
                            ]);
                        }
                        break;
                }
            }

            // Save changes to HumHub if needed
            $result = true;
            if ($saveHumhub) {
                $ctx->log('info', "Saving HumHub user changes");
                $result = $this->saveHumhub($user);
                if (!$result) {
                    $ctx->logError("Failed to save HumHub user");
                }
            }

            // Execute CiviCRM updates if needed
            if (!empty($civiCRMUpdateParams)) {
                $ctx->log('info', "Executing CiviCRM updates", [
                    'entities' => array_keys($civiCRMUpdateParams),
                    'update_count' => count($civiCRMUpdateParams),
                ]);
                $updateResult = $this->updateEntities($contactId, $activityId, $civiCRMUpdateParams);
                $result = $result && $updateResult;
                if (!$updateResult) {
                    $ctx->logError("Failed to update CiviCRM entities");
                }
            }

            if ($result) {
                $ctx->logSuccess([
                    'processed_fields' => $processedFields,
                    'changed_fields' => $changedFields,
                    'skipped_readonly_fields' => $skippedReadonlyFields,
                    'humhub_saved' => $saveHumhub,
                    'civicrm_updated' => !empty($civiCRMUpdateParams),
                ]);
            } else {
                $ctx->logError("Sync completed with errors");
            }

            return $result;

        } catch (\Throwable $e) {
            $ctx->logError("syncUser failed with exception", $e);
            throw $e;
        } finally {
            // Only unlock if we locked it (not if caller already had it locked)
            if (!$wasAlreadyLocked) {
                $this->unlock($user);
            }
            // Clear sync context
            $this->setSyncContext(null);
        }
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
            SyncLog::warning("Dry run enabled - skipping save of user {$user->id}.");
            return true;
        }

        // Note: Lock is managed by the caller (onLogin, onChange, syncBase, etc.)
        // The early lock check in onChange() prevents recursive sync when save() triggers onChange events
        $profile = $user->profile;
        $profileSaved = $profile->save(false);
        $userSaved = $user->save();
        return $profileSaved && $userSaved;
    }

    private function printPercent(string $prefix, int $users, int $handled): string
    {
        $percent = round($handled / $users * 100, 2);
        SyncLog::info("$prefix {$handled}/{$users} ({$percent}%)");
        return $percent;
    }

    private function mayBeSynced(User $user, ?Profile $profile = null): bool
    {
        $profile = $profile ?? $user->profile;

        if ($this->restrictToGroups()) {
            SyncLog::debug("Check include groups: " . json_encode($this->settings->includeGroups));
            if (
                !$user->getGroups()
                    ->where(['IN', 'id', $this->settings->includeGroups])
                    ->exists()
            ) {
                SyncLog::debug("User {$user->id} not in include groups.");
                return false;
            }
        }

        if ($this->excludeGroups()) {
            SyncLog::debug("Check exclude groups: " . json_encode($this->settings->excludeGroups));
            if (
                $user->getGroups()
                    ->where(['IN', 'id', $this->settings->excludeGroups])
                    ->exists()
            ) {
                SyncLog::debug("User {$user->id} in exclude groups.");
                return false;
            }
        }

        if ($this->settings->retryOnMissingField) {
            SyncLog::debug("Checking if field '{$this->settings->retryOnMissingField}' is empty.");
            if ($profile->{$this->settings->retryOnMissingField} !== null && $profile->{$this->settings->retryOnMissingField} !== '') {
                SyncLog::debug("Field '{$this->settings->retryOnMissingField}' is not empty.");
                return false;
            }
        }
        if ($this->restrictToContactIds()) {
            SyncLog::debug("Check if civicrm contact ID is in enabled list: " . json_encode($this->getEnabledContactIds()));
            if (!in_array($profile->{$this->settings->contactIdField}, $this->getEnabledContactIds())) {
                SyncLog::debug("User {$user->id} has an invalid civicrm contact ID.");
                return false;
            }
        }
        return true;
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
            $this->printPercent("Syncing users progress", count($users), count($handled));
        }
        SyncLog::info("End syncing all users from source {$from}");
        return $handled;
    }

    private function isHumhubFieldReadOnly(string $field): bool
    {
        [$dataSrc, $fieldName] = explode('.', $field);
        return match ($dataSrc) {
            self::HUMHUB_DATA_SRC_USER => true,
            self::HUMHUB_DATA_SRC_ACCOUNT => in_array($fieldName, ['username', 'authclient', 'authclient_id']),
            default => false,
        };
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
        if ($this->isHumhubFieldReadOnly($field)) {
            SyncLog::debug("Skip setting read-only HumHub field '{$field}' for user {$user->id}");
            return false;
        }
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

    public function getCiviCRMValue(array $civicrmContact, array $activity, FieldMapping $mapping, $humhubValue)
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
            // Detect if value is stored in civicrm subentity with another location type
            $locationIdOfValue = $this->searchSubEntityValue($mapping->civiEntity, $civicrmContact['id'], $mapping, $humhubValue);
            if ($locationIdOfValue > 0) {
                SyncLog::debug("Found matching sub-entity value for '{$mapping->civiField}' in siblings entity '{$mapping->civiEntity}' with Id {$locationIdOfValue}.");
                return $humhubValue;
            }
            // If not check default location type for changes
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