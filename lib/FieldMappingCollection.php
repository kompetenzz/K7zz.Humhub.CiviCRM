<?php
namespace k7zz\humhub\civicrm\lib;
use k7zz\humhub\civicrm\lib\FieldMapping;

/**
 * Represents a collection of mappings between a HumHub user field and a CiviCRM field.
 */
class FieldMappingCollection
{
    public array $mappings;

    public function __construct(string $json)
    {
        $this->fromString($json);
    }

    public function fromString(string $json)
    {
        $this->mappings = [];
        foreach (json_decode($json, true) as $humhubField => $civiDef) {
            $this->mappings[] = new FieldMapping($humhubField, $civiDef);
        }
    }

    /**
     * * Get all mappings that are civicrm subentity siblings of the given mapping.
     * @param FieldMapping $mapping
     * @return array
     */
    public function getSiblings(FieldMapping $mapping, bool $includeSelf = true): array
    {
        $fields = [];
        foreach ($this->mappings as $candidate) {
            if (
                $candidate->isSubEntity()
                && $candidate->civiEntity === $mapping->civiEntity
                && $candidate->params == $mapping->params
                && ($includeSelf || $candidate !== $mapping)
            ) {
                $fields[] = $candidate;
            }
        }
        return $fields;
    }

    /**
     * * Get HumHub field names that are civicrm subentity siblings of the given HumHub field name.
     * @param string $fieldName The humhub field name to find siblings for.
     * @param bool $includeSelf Whether to include the field itself in the result.
     * @return array
     */
    public function getHumhubSiblings(string $fieldName, bool $includeSelf = true, bool $returnBare = true): array
    {
        $fields = [];
        $srcMapping = null;
        foreach ($this->mappings as $candidate) {
            if (
                $candidate->isSubEntity()
                && $candidate->isFor($fieldName)
            ) {
                $srcMapping = $candidate;
                break;
            }
        }
        if (!$srcMapping) {
            return $fields;
        }
        $siblings = $this->getSiblings($srcMapping, $includeSelf);

        return array_map(fn($m) => $returnBare ? $m->getBareHumhubFieldName() : $m->humhubField, $siblings);
    }
}
