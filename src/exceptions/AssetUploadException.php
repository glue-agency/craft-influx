<?php

namespace GlueAgency\Influx\exceptions;

/**
 * Thrown by {@see \GlueAgency\Influx\services\AssetUploadService} when a download
 * or upload fails — carries the actual cause (HTTP status, volume error,
 * element validation errors) instead of collapsing everything into null.
 */
class AssetUploadException extends InfluxException
{
}
