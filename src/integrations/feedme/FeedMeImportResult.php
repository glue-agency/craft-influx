<?php

namespace GlueAgency\Influx\integrations\feedme;

use GlueAgency\Influx\models\Link;

/**
 * Outcome of importing one Feed Me feed as an Influx link: the converted
 * link, the conversion warnings, and whether the save went through. Built by
 * {@see \GlueAgency\Influx\integrations\feedme\services\FeedMeService::importFeeds()}
 * and rendered by the `influx/feed-me` console command — the service stays
 * output-free. Treat as read-only.
 */
class FeedMeImportResult
{
    /**
     * The source `feedme_feeds` row this result was built from.
     */
    public array $feed = [];

    /**
     * The converted link — saved unless {@see $saved} says otherwise (or the
     * run was a dry run).
     */
    public Link $link;

    /**
     * Conversion warnings carried over from {@see FeedMeConversion::$warnings}.
     *
     * @var string[]
     */
    public array $warnings = [];

    /**
     * Whether the link was saved. Always false on dry runs — the caller knows
     * it asked for one.
     */
    public bool $saved = false;

    /**
     * Validation error summary when the save was rejected.
     *
     * @var string[]
     */
    public array $errors = [];

    /**
     * @param array $feed
     * @param Link $link
     * @param string[] $warnings
     * @param bool $saved
     * @param string[] $errors
     */
    public function __construct(array $feed, Link $link, array $warnings = [], bool $saved = false, array $errors = [])
    {
        $this->feed = $feed;
        $this->link = $link;
        $this->warnings = $warnings;
        $this->saved = $saved;
        $this->errors = $errors;
    }
}
