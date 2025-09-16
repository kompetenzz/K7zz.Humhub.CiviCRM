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
}
