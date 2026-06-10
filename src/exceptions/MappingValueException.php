<?php

namespace TDM\Influx\exceptions;

/**
 * Thrown by field strategies when a remote value is present but malformed
 * (e.g. an unparseable date). Distinct from returning null, which means
 * "no data — leave the field untouched": malformed data must surface as a
 * per-mapping error row instead of being silently dropped.
 */
class MappingValueException extends InfluxException
{
}
