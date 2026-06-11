<?php

namespace GlueAgency\Influx\exceptions;

/**
 * Thrown when sub-mapping recursion (relation → sub-fields → relation → ...)
 * exceeds {@see \GlueAgency\Influx\sync\FieldContext::MAX_DEPTH}.
 */
class MappingDepthException extends InfluxException
{
}
