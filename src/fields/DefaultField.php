<?php

namespace GlueAgency\Influx\fields;

use GlueAgency\Influx\sync\FieldContext;

/**
 * Generic strategy for any Craft field that has no dedicated handler. Routes
 * the remote-item node value straight onto the field via `setFieldValue` and
 * lets Craft's normalization do the rest.
 *
 * Registered as the fallback in {@see \GlueAgency\Influx\services\FieldsService}.
 */
class DefaultField extends Field
{
    public function parse(FieldContext $context): mixed
    {
        return $context->mapping->resolve($context->item);
    }
}
