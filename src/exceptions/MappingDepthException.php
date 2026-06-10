<?php

namespace TDM\Influx\exceptions;

/**
 * Thrown when sub-mapping recursion (relation → sub-fields → relation → ...)
 * exceeds {@see \TDM\Influx\sync\FieldContext::MAX_DEPTH}.
 */
class MappingDepthException extends InfluxException
{
}
