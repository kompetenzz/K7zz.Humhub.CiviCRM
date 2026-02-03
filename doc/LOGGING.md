# CiviCRM Sync - Enhanced Logging System

## Overview

The new logging system provides **structured, context-based logs** for all synchronization operations. Each sync session receives a unique ID and automatically tracks performance, changes, and errors.

## Key Improvements

### ✅ Before vs. After

| Feature | Before | After |
|---------|--------|-------|
| **Context** | ❌ No correlation between logs | ✅ Sync-ID connects all logs |
| **Performance** | ❌ No time measurement | ✅ Automatic performance tracking |
| **Error Details** | ❌ Only error message | ✅ Stack trace + full context |
| **Structure** | ❌ Text only | ✅ JSON-formatted with metadata |
| **Changes** | ❌ Hard to track | ✅ All changes tracked |
| **API Calls** | ❌ Not logged | ✅ Every call with params/results |

---

## New Component: SyncContext

The `SyncContext` class tracks a complete sync session:

```php
use k7zz\humhub\civicrm\components\SyncContext;

// Create context
$ctx = new SyncContext($user, 'onChange', $eventSrc);
$ctx->setContactId($contactId);
$ctx->setActivityId($activityId);

// Structured logging
$ctx->log('info', "Message", ['key' => 'value']);
$ctx->logError("Error occurred", $exception);
$ctx->logSuccess(['summary' => 'data']);
```

### Available Methods

#### Basic Logging
```php
// Standard log with context
$ctx->log(string $level, string $message, array $context = []);

// Special log types
$ctx->logError(string $message, ?\Throwable $exception, array $context = []);
$ctx->logSuccess(array $summary = []);
$ctx->logSkipped(string $reason, array $context = []);
```

#### API & Data Tracking
```php
// Track API calls
$ctx->logApiCall(string $entity, string $action, array $params, ?array $result);

// Track field changes
$ctx->logFieldChange(string $field, mixed $from, mixed $to, string $direction);

// Add metadata
$ctx->addMetadata(string $key, mixed $value);
$ctx->addChange(string $field, mixed $oldValue, mixed $newValue);
```

#### Set IDs
```php
$ctx->setContactId(?int $contactId);
$ctx->setActivityId(?int $activityId);
```

---

## Log Output Examples

### Successful onChange Sync

```json
{
  "sync_id": "sync_67890abc1234",
  "operation": "onChange",
  "user_id": 42,
  "contact_id": 1234,
  "activity_id": 5678,
  "duration_ms": 145.23,
  "source": "profile",
  "changed_fields": ["firstname", "email", "phone"],
  "message": "Sync completed successfully",
  "ctx": {
    "total_changes": 2,
    "changes": {
      "firstname": {"old": "Max", "new": "Maximilian"},
      "email": {"old": "max@example.com", "new": "maximilian@example.com"}
    },
    "processed_fields": 3,
    "skipped_fields": 1,
    "updates_sent": true
  },
  "timestamp": "2026-02-03 14:23:45.123456"
}
```

### Error with Full Context

```json
{
  "sync_id": "sync_12345xyz6789",
  "operation": "onLogin",
  "user_id": 42,
  "contact_id": 1234,
  "duration_ms": 23.45,
  "message": "CiviCRM contact not found and recovery failed",
  "ctx": {
    "contact_id": 1234,
    "exception": {
      "class": "Exception",
      "message": "HTTP 404 Not Found",
      "code": 404,
      "file": "/path/to/CiviCRMService.php",
      "line": 712,
      "trace": [
        {"file": "...", "line": 123, "function": "singleContact"},
        {"file": "...", "line": 456, "function": "syncBase"}
      ]
    }
  },
  "timestamp": "2026-02-03 14:23:45.123456"
}
```

### API Call Tracking

```json
{
  "sync_id": "sync_abc123",
  "operation": "onChange",
  "user_id": 42,
  "contact_id": 1234,
  "duration_ms": 12.34,
  "message": "CiviCRM API call",
  "ctx": {
    "api": {
      "entity": "Contact",
      "action": "get",
      "param_count": 1,
      "has_result": true,
      "result_count": 1
    }
  }
}
```

---

## Updated Methods

### 1. onChange()
```php
public function onChange(string $eventSrc, Profile $profile, array $valuesBeforeChange): void
```

**New Logs:**
- ✅ Sync start with changed fields
- ✅ Lock status
- ✅ API calls with params/results
- ✅ Processed vs. skipped fields
- ✅ Field changes with before/after
- ✅ Success summary with statistics
- ✅ Exception handling with stack trace

### 2. onLogin()
```php
public function onLogin(User $user): void
```

**New Logs:**
- ✅ Sync start
- ✅ Lock status
- ✅ Success/error with exception details

### 3. syncBase()
```php
public function syncBase(User $user): bool
```

**New Logs:**
- ✅ Contact fetch with API tracking
- ✅ Contact recovery attempts
- ✅ Checksum validation & update
- ✅ Activity fetch & sync
- ✅ Account status changes with reason
- ✅ All changes tracked
- ✅ Success summary with change list

---

## Log Levels

The logs now use consistent log levels:

| Level | Usage | Examples |
|-------|-------|----------|
| **DEBUG** | Detailed steps | API calls, field processing, internal flow |
| **INFO** | Important events | Sync start/end, changes, updates |
| **WARNING** | Degraded cases | Account deactivation, skipped fields, retries |
| **ERROR** | Errors | API errors, contacts not found, exceptions |

---

## Debugging Workflow

### Problem: User sync fails

**1. Find the sync ID**
```bash
grep "user_id.*42" runtime/logs/app.log | grep sync_id
```

**2. All logs of this session**
```bash
grep "sync_67890abc1234" runtime/logs/app.log
```

**3. Errors only**
```bash
grep "sync_67890abc1234" runtime/logs/app.log | grep "LEVEL_ERROR"
```

### Problem: Slow syncs

**Performance analysis:**
```bash
grep "Sync completed" runtime/logs/app.log | grep "duration_ms"
```

Sort by duration:
```bash
grep "Sync completed" runtime/logs/app.log | \
  jq -r '.ctx.duration_ms' | \
  sort -n | \
  tail -10
```

---

## Migration from Old to New Logging

### Old Method (deprecated):
```php
SyncLog::info("Starting sync for user {$user->id}");
SyncLog::error("Contact not found: {$contactId}");
```

### New Method (recommended):
```php
$ctx = new SyncContext($user, 'myOperation');
$ctx->log('info', "Starting sync");
$ctx->logError("Contact not found", null, ['contact_id' => $contactId]);
```

---

## Best Practices

### ✅ DO

```php
// 1. Always create SyncContext
$ctx = new SyncContext($user, 'operationName');

// 2. Set IDs as soon as available
$ctx->setContactId($contactId);
$ctx->setActivityId($activityId);

// 3. Structured context data
$ctx->log('info', "Message", [
    'field' => 'value',
    'count' => 123,
]);

// 4. Always log exceptions
catch (\Throwable $e) {
    $ctx->logError("Operation failed", $e);
    throw $e;
}

// 5. Success summary at the end
$ctx->logSuccess([
    'changes' => $changes,
    'duration' => $duration,
]);
```

### ❌ DON'T

```php
// ❌ Don't: Forget context
SyncLog::info("Sync started"); // No sync_id, no correlation

// ❌ Don't: Exception without context
catch (\Exception $e) {
    SyncLog::error($e->getMessage()); // No stack trace
}

// ❌ Don't: Too many debug logs
$ctx->log('debug', "Step 1");
$ctx->log('debug', "Step 2");
$ctx->log('debug', "Step 3"); // Too verbose

// ❌ Don't: Log sensitive data
$ctx->log('info', "Password: {$password}"); // NEVER!
```

---

## Future Enhancements

Planned improvements:

- [ ] Grafana dashboard for log visualization
- [ ] Automatic alerts for frequent errors
- [ ] Performance regression detection
- [ ] Sync audit trail for compliance

---

## Support

For questions or issues:
- Check logs: `runtime/logs/app.log`
- Log category: `civicrm.sync`
- Create issue with `sync_id` for better debugging
