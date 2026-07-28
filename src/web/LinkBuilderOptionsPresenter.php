<?php

namespace GlueAgency\Influx\web;

use Craft;
use craft\web\twig\variables\Cp;
use GlueAgency\Influx\enums\ProcessingAction;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\schema\MappableField;

/**
 * Every option list and view-model block the LinkBuilder Vue SPA renders its
 * dropdowns, checkboxes and pickers from.
 *
 * Extracted from {@see \GlueAgency\Influx\services\LinkBuilderService} so the
 * service keeps only the marshalling it owns (bootstrap / save) and this
 * presentation sits next to the plugin's other web-facing shapers
 * ({@see LinkBuilderSerializer}, {@see LogPresenter}, {@see ItemRowPresenter}).
 *
 * Everything here resolves against Craft at call time — registered targets,
 * sections, sites, auth strategies, env vars — which makes it presentation
 * rather than state.
 */
class LinkBuilderOptionsPresenter
{
    /**
     * The `options` block of the bootstrap payload: the always-needed lists the
     * SPA mounts with. Heavier per-tab data (mappable fields, token
     * suggestions) is fetched lazily through its own endpoints, so it isn't
     * here.
     */
    public function bootstrapOptions(): array
    {
        return [
            'elementTypes'      => $this->elementTypeOptions(),
            'sections'          => $this->sectionOptions(),
            'sectionEntryTypes' => $this->sectionEntryTypes(),
            'sites'             => $this->siteOptions(),
            'processingActions' => $this->processingActionOptions(),
            'authTypes'         => $this->authTypeOptions(),
            'authStrategies'    => $this->authStrategyDefinitions(),
        ];
    }

    /**
     * `criteria` and `multiSite` are capability flags the General tab reacts to
     * — which criteria dropdowns to render, and whether multi-site support is
     * offered.
     */
    public function elementTypeOptions(): array
    {
        $out = [];

        foreach (Influx::getInstance()->targets->all() as $target) {
            $out[] = [
                'value'     => $target::elementType(),
                'label'     => $target::friendlyName(),
                'criteria'  => $target::criteriaKeys(),
                'multiSite' => $target::supportsMultiSite(),
            ];
        }

        return $out;
    }

    public function sectionOptions(): array
    {
        $out = [['value' => '', 'label' => Craft::t('influx', '— select —')]];

        foreach (Compat::getAllSections() as $section) {
            $out[] = ['value' => $section->handle, 'label' => $section->name];
        }

        return $out;
    }

    public function sectionEntryTypes(): array
    {
        $out = [];

        foreach (Compat::getAllSections() as $section) {
            $types = [];

            foreach ($section->getEntryTypes() as $type) {
                $types[$type->handle] = $type->name;
            }
            $out[$section->handle] = $types;
        }

        return $out;
    }

    public function siteOptions(): array
    {
        $out = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $out[] = ['value' => $site->handle, 'label' => $site->name];
        }

        return $out;
    }

    /**
     * The builder's processing checkboxes: a terse label with the behaviour
     * spelled out in a note beneath it, in {@see ProcessingAction::optionOrder()}.
     */
    public function processingActionOptions(): array
    {
        $out = [];

        foreach (ProcessingAction::optionOrder() as $action) {
            $out[] = ['value' => $action->value, 'label' => $action->label(), 'note' => $action->note()];
        }

        return $out;
    }

    public function authTypeOptions(): array
    {
        $out = [['value' => '', 'label' => Craft::t('influx', '— none —')]];

        foreach (Influx::getInstance()->auth->all() as $type => $strategy) {
            $out[] = ['value' => $type, 'label' => Craft::t('influx', $strategy::label())];
        }

        return $out;
    }

    /**
     * Per-strategy form schemas consumed by the SPA's Authentication tab.
     * Strategies declare {@see \GlueAgency\Influx\schema\SchemaBuilder} nodes
     * natively via {@see \GlueAgency\Influx\auth\AuthStrategyInterface::schema()}
     * — the same vocabulary the mapping extras use — so this is pure
     * aggregation off the registry's prototypes. Strategies with no extra
     * fields (empty schema) are skipped; the SPA falls back to "no schema"
     * messaging if a stored link is using an auth type that's not registered.
     *
     * @return list<array{type: string, schema: list<array>}>
     */
    public function authStrategyDefinitions(): array
    {
        $out = [];

        foreach (Influx::getInstance()->auth->all() as $type => $strategy) {
            $schema = $strategy::schema();

            if ($schema->isEmpty()) {
                continue;
            }
            $out[] = ['type' => $type, 'schema' => $schema->toArray()];
        }

        return $out;
    }

    /**
     * Group flat mappable fields by their `group` label, serializing each into
     * its wire shape — the grouped tree the Mapping tab renders from.
     *
     * @param list<MappableField> $fields
     * @return list<array{label: string, fields: list<array>}>
     */
    public function groupMappableFields(array $fields): array
    {
        $byLabel = [];

        foreach ($fields as $field) {
            $label = $field->group ?: Craft::t('influx', 'Other');

            if (! isset($byLabel[$label])) {
                $byLabel[$label] = ['label' => $label, 'fields' => []];
            }
            $byLabel[$label]['fields'][] = $field->toArray();
        }

        return array_values($byLabel);
    }

    /**
     * Wrap Craft's {@see \craft\web\twig\variables\Cp::getEnvSuggestions()}
     * into the picker's group shape, marking every entry `type: 'text'` so
     * the TokenizedInput inserts them as literal string segments (e.g.
     * `$API_BASE`, `@webroot`) instead of as chips. Env vars get
     * `kind: 'env'`, aliases `kind: 'alias'` — distinct accent colors in
     * the picker preview help users tell them apart at a glance. Which is which
     * is read off the item's `@` prefix rather than the group label, since that
     * label is translated.
     *
     * @return list<array{kind: string, label: string, data: list<array{name: string, hint?: string, type: string}>}>
     */
    public function envAndAliasSuggestions(): array
    {
        $cp = new Cp();
        $raw = $cp->getEnvSuggestions(true);

        $out = [];

        foreach ($raw as $group) {
            $items = [];

            foreach (($group['data'] ?? []) as $item) {
                $name = (string) ($item['name'] ?? '');

                if ($name === '') {
                    continue;
                }
                $items[] = [
                    'name' => $name,
                    'hint' => (string) ($item['hint'] ?? ''),
                    'type' => 'text',
                ];
            }

            if (! $items) {
                continue;
            }

            $kind = str_starts_with($items[0]['name'], '@') ? 'alias' : 'env';
            $out[] = [
                'kind'  => $kind,
                'label' => $group['label'] ?? Craft::t('influx', 'Environment'),
                'data'  => $items,
            ];
        }

        return $out;
    }
}
