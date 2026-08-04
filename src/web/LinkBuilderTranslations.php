<?php

namespace GlueAgency\Influx\web;

/**
 * The catalogue of UI strings the LinkBuilder Vue SPA passes through its `$t` /
 * `t()` helper. Registered with Craft's View layer by
 * {@see \GlueAgency\Influx\controllers\LinksController::builderScreen()} so
 * `Craft.translations.influx` is populated for any locale that ships a
 * translations file. Without a file, `Craft.t` falls back to the source string
 * — the wrap is forward-compatible.
 *
 * ONLY the builder SPA's own strings (`src/web/assets/cp/src/builder/**`) live
 * here. The shared components it mounts carry theirs in
 * {@see SharedComponentTranslations}, which every app screen registers alongside
 * its own catalogue; the log viewer and debug inspector have
 * {@see LogViewerTranslations} and {@see DebugInspectorTranslations}.
 * Server-built option labels (see {@see LinkBuilderOptionsPresenter}) already
 * run through `Craft::t()` and reach the wire translated.
 *
 * Pinned EXACTLY — in both directions — against the `$t(…)` / `t(…)` calls in
 * `src/web/assets/cp/src/builder/**` by
 * {@see \GlueAgency\Influx\Tests\unit\web\LinkBuilderTranslationsTest}: a string
 * added to a Vue component without being added here fails that test, and so does
 * a leftover entry no component uses. That is what keeps the catalogue from
 * drifting away from the SPA again.
 */
class LinkBuilderTranslations
{
    /**
     * The flat catalogue, deduplicated with first use winning.
     *
     * @return string[]
     */
    public static function strings(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::byComponent()))));
    }

    /**
     * The same strings grouped by the component that consumes them, in the
     * order the SPA renders them — the grouping is the documentation, so a
     * component's strings are found and maintained together.
     *
     * @return array<string, string[]>
     */
    protected static function byComponent(): array
    {
        return [
            'LinkBuilder.vue' => [
                'Loading…',
                'Couldn’t load this link:',
                'Check the Craft logs for the full stack trace, or reload to retry.',
            ],
            'HeaderActions.vue' => [
                'More save options',
                'Save and continue editing',
                'Delete link',
                'Saving…',
                'Save',
                'Are you sure you want to delete this link? Its sync configuration is removed permanently — imported elements stay.',
            ],
            'DetailsSidebar.vue' => [
                'Sample',
                'Fetching…',
                'Fetch failed',
                'Incomplete — no items resolved',
                'Not fetched yet',
                'Fetched — {items} items, {nodes} nodes',
                'Fetching sample',
                'Refetch sample',
                'Fetch sample',
                'Set a Base Endpoint on the General tab first',
                'Last attempt failed: {message}',
                'Sample incomplete: {message}',
                'Last fetched from {url}',
                'Hit the configured endpoint and inspect the response',
                'Mappings',
                '{mapped} of {total} fields mapped',
                '1 field is missing its source node',
                '{count} fields are missing their source node',
                'Auto-match',
                'Map every field whose handle matches a node in the sample. Existing mappings are left alone.',
                'Fetch a sample first — its nodes are what gets matched.',
            ],
            'store.js' => [
                'Add at least one site endpoint, or turn site-specific endpoints off.',
                'Link saved.',
                "Couldn't save link.",
                'Sample failed: {message}',
                'Sample incomplete: {message}',
                'Nothing left to auto-match.',
                'Auto-matched {count} fields.',
                'Link deleted.',
                "Couldn't delete link.",
            ],
            'tabs/GeneralTab.vue' => [
                'Name',
                'What this link will be called in the control panel.',
                'Handle',
                'Identifier used in console commands and event keys.',
                'Element',
                'Element type',
                'Section',
                'Entry type',
                '— select —',
                'Endpoint',
                'Base Endpoint',
                'JSON URL, or an <code>@alias</code> pointing to a local JSON file.',
                'Site-specific endpoints',
                'Enable if the external service supports resource localisation.',
                'The link runs once per listed site and writes localized data to the same canonical element.',
                'Sliding-window presets',
                'Enable if the external service supports synchronisation by offset.',
                'Each preset becomes a button on the link page and a <code>--offset=KEY</code> option on the console command.',
                'Resource Endpoint supported',
                'Resource Endpoint',
                'URL pattern for the per-element "Sync from remote" button. Type the URL and use the picker to inline a token where the cursor is — chips show you where each placeholder lives.',
                'Processing actions',
            ],
            'tabs/PaginationTab.vue' => [
                'Root node',
                'The main node containing every element that needs to be parsed by the mappings.',
                '— response root —',
                'Search nodes…',
                'Run “Fetch sample” to discover nodes.',
                'Pagination',
                'Paginator node',
                'The node containing the URL of the next page to fetch.',
                '— no paginator —',
                'Total-count node',
                'The node containing the total number of items.',
                '— none —',
                'Page-count node',
                'The node containing the total number of pages.',
                'Nodes',
            ],
            'tabs/MappingTab.vue' => [
                'Couldn’t load mappable fields:',
                'Loading mappable fields…',
                'Pick an element type (and a section, for entries) on the General tab to see destination fields here.',
                'Match key',
                'Match attribute',
                'Select an attribute…',
                'Search attributes…',
            ],
            'tabs/MappingGroup.vue' => [
                'Clear every mapping in this group',
                'clear nodes',
                'Fields filled in by Auto-match',
                'auto',
                'Fields whose source node isn’t in the fetched sample',
                'missing',
                'Fields with an active source node',
                'mapped',
                'Total fields in this group',
                'Field',
                'Source node',
                'Default value',
            ],
            'tabs/MappingRow.vue' => [
                'Hide options',
                'Configure',
                'Filled in by Auto-match',
                'auto',
                'Source node isn’t in the fetched sample. Pick a new node or clear the mapping if no longer in use.',
                'missing mapping',
                '— no mapping —',
                'Search options…',
                '— use default —',
            ],
            'schema/inputs/SubFieldRows.vue' => [
                'Clear every source node and default in this group',
                'clear nodes',
                'Sub-fields with an active source node',
                'Sub-fields whose source node isn’t in the fetched sample',
                'Total sub-fields in this group',
            ],
            'schema/inputs/MatrixFields.vue' => [
                'This block type has no mappable sub-fields.',
            ],
            'tabs/AuthTab.vue' => [
                'Authentication type',
                'How Influx should authenticate against the remote API.',
                'No SPA-side schema is registered for auth type',
            ],
            'tabs/SettingsTab.vue' => [
                'Take a DB backup before every run',
                'Off by default. Mainly useful for destructive processing actions.',
            ],
            'OffsetPresetsTable.vue' => [
                'Handle',
                'Since',
                'Anything <code>DateTime::modify</code> accepts.',
                'Query param',
                'Date format',
                'Anything <code>DateTime::format</code> accepts.',
                'e.g. last24h',
                'Delete row {idx}',
                'Add a preset',
            ],
            'SiteEndpointsTable.vue' => [
                'Site',
                'Endpoint URL',
                'Reorder',
                '— select a site —',
                'Add a site endpoint',
            ],
            'SearchableSelect.vue' => [
                'Search…',
                'Clear search',
                'not in the sample',
                'No matches for',
                'No options available.',
                'Custom node',
                'Select…',
            ],
            'components/TokenPickerMenu.vue' => [
                'Filter tokens…',
            ],
            'components/TokenChip.vue' => [
                'Remove {name}',
            ],
        ];
    }
}
