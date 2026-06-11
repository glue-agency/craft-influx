<?php

namespace TDM\Influx\integrations\feedme;

use TDM\Influx\models\Link;

/**
 * Result of converting one Feed Me feed into an Influx link: the (unsaved)
 * link plus everything that could not be carried over. Treat as read-only —
 * instances are built once by {@see FeedMeConverter::convert()} and only
 * consumed afterwards.
 */
class FeedMeConversion
{
    /** The converted, not-yet-saved link. */
    public Link $link;

    /**
     * Human-readable notes about settings that were dropped, approximated or
     * need a manual follow-up in the link builder.
     *
     * @var string[]
     */
    public array $warnings = [];

    /**
     * @param Link $link
     * @param string[] $warnings
     */
    public function __construct(Link $link, array $warnings = [])
    {
        $this->link = $link;
        $this->warnings = $warnings;
    }
}
