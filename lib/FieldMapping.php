<?php
namespace k7zz\humhub\civicrm\lib;

use k7zz\humhub\civicrm\components\SyncLog;

/**
 * Represents a mapping between a HumHub user field and a CiviCRM field.
 *
 * This class encapsulates the details of how a specific HumHub user attribute
 * corresponds to a CiviCRM field, including any additional parameters needed
 * for the mapping.
 */
class FieldMapping
{
    public string $humhubField; // e.g. "account.email", "profile.firstname", ""
    public string $civiEntity; // e.g. "contact", "email", "phone"
    public string $civiField; // e.g. "first_name", "custom_123", "phone"
    public array $params;

    /**
     * * Creates a FieldMapping instance from a JSON string.
     * Handles humhubField and civiFieldDefinition in "entity.field" or {
     * "entity": "entity", "field": "field", "params": { ... }
     *
     * } format.
     * @param string $humhubField
     * @param mixed $civiFieldDefinition
     */
    public function __construct($humhubField, $civiFieldDefinition)
    {
        $this->humhubField = $humhubField;

        if (is_string($civiFieldDefinition)) {
            // Not deserialized
            if (str_starts_with(trim($civiFieldDefinition), '{')) {
                $data = json_decode($civiFieldDefinition, true);
                if (isset($data['entity'], $data['field'])) {
                    $this->civiEntity = $data['entity'];
                    $this->civiField = $data['field'];
                    $this->params = $data['params'] ?? [];
                    return;
                } else {
                    throw new \InvalidArgumentException("Invalid JSON format for FieldMapping");
                }
            }
            // Deserialized "entity.field" format 
            else {
                [$this->civiEntity, $this->civiField] = explode('.', $civiFieldDefinition, 2);
                SyncLog::debug("Parsed FieldMapping: humhubField={$this->humhubField}, civiEntity={$this->civiEntity}, civiField={$this->civiField} from definition {$civiFieldDefinition}");
                $this->params = [];
            }
        }
        // Deserialized {"entity": "entity", "field": "field", "params": { ... }} format
        else if (is_array($civiFieldDefinition)) {
            if (isset($civiFieldDefinition['entity'], $civiFieldDefinition['field'])) {
                $this->civiEntity = $civiFieldDefinition['entity'];
                $this->civiField = $civiFieldDefinition['field'];
                $this->params = $civiFieldDefinition['params'] ?? [];
                return;
            } else {
                throw new \InvalidArgumentException("Invalid array format for FieldMapping");
            }
        }
        // Invalid type
        else {
            throw new \InvalidArgumentException("Invalid type " . gettype($civiFieldDefinition) . " for civiFieldDefinition");
        }
    }


    public function isSubEntity(): bool
    {
        return $this->civiEntity !== 'contact' && $this->civiEntity !== 'activity';
    }

    public function isSrc($src): string
    {
        return str_starts_with($this->humhubField, $src . '.');
    }

    public function getBareHumhubFieldName(): string
    {
        [$dataSrc, $fieldName] = explode('.', $this->humhubField);
        return $fieldName;
    }

    public function getHumhubFieldSrc(): string
    {
        [$dataSrc, $fieldName] = explode('.', $this->humhubField);
        return $dataSrc;
    }

    public function fullHumhubFieldName(string $bareFieldName): string
    {
        return $this->getHumhubFieldSrc() . '.' . $bareFieldName;
    }

    public function isFor(string $bareOrFullFieldName, string $src = null): bool
    {
        if ($this->humhubField === $bareOrFullFieldName)
            return true;
        if ($src !== null) {
            return $this->humhubField === $src . '.' . $bareOrFullFieldName;
        }
        return $this->getBareHumhubFieldName() === $bareOrFullFieldName;
    }
}
