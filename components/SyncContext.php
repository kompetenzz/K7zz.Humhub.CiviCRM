<?php
namespace k7zz\humhub\civicrm\components;

use humhub\modules\user\models\User;

/**
 * SyncContext tracks a complete synchronization session
 *
 * Provides:
 * - Unique sync ID for correlation across log entries
 * - Automatic performance tracking
 * - Structured context data
 * - Exception handling with full stack traces
 */
class SyncContext
{
    private string $syncId;
    private string $operation;
    private int $userId;
    private ?int $contactId = null;
    private ?int $activityId = null;
    private float $startTime;
    private array $metadata = [];
    private array $changes = [];
    private ?string $source = null;

    public function __construct(User $user, string $operation, ?string $source = null)
    {
        $this->syncId = uniqid('sync_', true);
        $this->operation = $operation;
        $this->userId = $user->id;
        $this->source = $source;
        $this->startTime = microtime(true);

        $this->log('info', "Sync started", [
            'user_email' => $user->email,
            'source' => $source,
        ]);
    }

    public function setContactId(?int $contactId): self
    {
        $this->contactId = $contactId;
        return $this;
    }

    public function setActivityId(?int $activityId): self
    {
        $this->activityId = $activityId;
        return $this;
    }

    public function addMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    public function addChange(string $field, mixed $oldValue, mixed $newValue): self
    {
        $this->changes[$field] = [
            'old' => $oldValue,
            'new' => $newValue,
        ];
        return $this;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $duration = microtime(true) - $this->startTime;

        $logContext = array_merge([
            'sync_id' => $this->syncId,
            'operation' => $this->operation,
            'user_id' => $this->userId,
            'contact_id' => $this->contactId,
            'activity_id' => $this->activityId,
            'duration_ms' => round($duration * 1000, 2),
            'source' => $this->source,
        ], $this->metadata, $context);

        // Remove null values to keep logs clean
        $logContext = array_filter($logContext, fn($v) => $v !== null);

        SyncLog::$level($message, $logContext);
    }

    public function logApiCall(string $entity, string $action, array $params = [], ?array $result = null): void
    {
        $this->log('debug', "CiviCRM API call", [
            'api' => [
                'entity' => $entity,
                'action' => $action,
                'param_count' => count($params),
                'has_result' => $result !== null,
                'result_count' => $result ? count($result) : 0,
            ],
        ]);
    }

    public function logError(string $message, ?\Throwable $exception = null, array $context = []): void
    {
        $errorContext = $context;

        if ($exception) {
            $errorContext['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => array_slice($exception->getTrace(), 0, 5), // First 5 stack frames
            ];
        }

        $this->log('error', $message, $errorContext);
    }

    public function logSuccess(array $summary = []): void
    {
        $this->log('info', "Sync completed successfully", array_merge([
            'total_changes' => count($this->changes),
            'changes' => $this->changes,
        ], $summary));
    }

    public function logSkipped(string $reason, array $context = []): void
    {
        $this->log('debug', "Sync skipped", array_merge([
            'reason' => $reason,
        ], $context));
    }

    public function logFieldChange(string $field, mixed $from, mixed $to, string $direction = 'humhub→civicrm'): void
    {
        $this->addChange($field, $from, $to);
        $this->log('debug', "Field sync", [
            'field' => $field,
            'direction' => $direction,
            'from' => $this->formatValue($from),
            'to' => $this->formatValue($to),
        ]);
    }

    private function formatValue(mixed $value): mixed
    {
        if (is_string($value) && strlen($value) > 100) {
            return substr($value, 0, 100) . '... (truncated)';
        }
        return $value;
    }

    public function getSyncId(): string
    {
        return $this->syncId;
    }

    public function getDuration(): float
    {
        return microtime(true) - $this->startTime;
    }
}
