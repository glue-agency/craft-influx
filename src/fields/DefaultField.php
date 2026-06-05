<?php

namespace TDM\Influx\fields;

/**
 * Generic strategy for any Craft field that has no dedicated handler. Routes
 * the remote-item node value straight onto the field via `setFieldValue` and
 * lets Craft's normalization do the rest.
 *
 * Registered as the fallback in {@see \TDM\Influx\services\FieldsService}.
 */
class DefaultField extends Field
{
    public function parseField(): mixed
    {
        return $this->fetchSimpleValue();
    }
}
